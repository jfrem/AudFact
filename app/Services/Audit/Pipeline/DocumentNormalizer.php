<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingRules;
use App\Services\Audit\DocumentQuality;
use App\Services\Audit\IdentityDocNormalizer;
use App\Services\Audit\TextNormalization;
use App\Services\Audit\Telemetry\TelemetryPublisher;
use Core\Logger;
use RuntimeException;

final class DocumentNormalizer extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private TelemetryPublisher $telemetryPublisher;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null,
        ?TelemetryPublisher $telemetryPublisher = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->telemetryPublisher = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->consumerName = $consumerName ?? self::defaultConsumerName('normalizer');
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_DOCUMENTS;
    }

    protected function group(): string
    {
        return 'normalizers';
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function handle(AuditEvent $event): void
    {
        $start = microtime(true);

        if ($event->eventType !== AuditEvent::TYPE_DOCUMENT_EXTRACTED) {
            return;
        }

        if ($event->auditId === null || $event->documentId === null) {
            throw new RuntimeException('document_extracted sin audit_id o document_id');
        }

        $disDetNro = self::optionalString($event->payload, 'dis_det_nro');
        $meta = ['worker' => $this->consumer()];
        $telemetryStartedAt = hrtime(true);
        $this->telemetryPublisher->started(
            $event->auditId,
            'normalization',
            $event->documentId,
            $disDetNro,
            $meta,
            $event->jobId
        );

        try {
            $normalized = $this->normalize($event->payload);
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            $documentState = [
                'status'                    => 'normalized',
                'normalized_result'         => $normalized,
                'normalized_at'             => gmdate('Y-m-d\TH:i:s\Z'),
                'normalization_duration_ms' => $durationMs,
            ];

            if (!$this->stateStore->markDocumentNormalized($event->auditId, $event->documentId, $documentState)) {
                throw new RuntimeException('No se pudo persistir la normalización del documento en Redis');
            }

            Logger::info('Document normalization event processed', [
                'auditId'                   => $event->auditId,
                'documentId'                => $event->documentId,
                'normalization_duration_ms' => $durationMs,
            ]);

            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
                auditId: $event->auditId,
                jobId: $event->jobId,
                documentId: $event->documentId,
                payload: [
                    'tipo_documento' => (string) ($normalized['tipo_documento'] ?? ''),
                    'fields_normalized' => $normalized['fields_normalized'] ?? [],
                    'items_normalized' => $normalized['items_normalized'] ?? [],
                    'visual_checks_resultado' => $normalized['visual_checks_resultado'] ?? [],
                    'document_quality' => $normalized['document_quality'] ?? null,
                    'quality_notes' => $normalized['quality_notes'] ?? [],
                    'normalization_log' => $normalized['normalization_log'] ?? [],
                    'extraction_warnings' => $normalized['extraction_warnings'] ?? [],
                ],
                parentEventId: $event->eventId,
            ));
            $this->telemetryPublisher->completed(
                $event->auditId,
                'normalization',
                self::elapsedMs($telemetryStartedAt),
                $event->documentId,
                $disDetNro,
                $meta,
                $event->jobId
            );
        } catch (\Throwable $error) {
            $this->telemetryPublisher->failed(
                $event->auditId,
                'normalization',
                self::elapsedMs($telemetryStartedAt),
                $event->documentId,
                $disDetNro,
                array_merge($meta, ['error_class' => get_class($error)]),
                $event->jobId
            );
            throw $error;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function normalize(array $payload): array
    {
        $rawType = $this->resolveDocumentType($payload);
        $extraction = $this->resolveExtractionResult($payload);
        $fieldValueTypes = $this->buildFieldValueTypes($payload['fields_config'] ?? null);

        $normalizationLog = [];
        $fieldsNormalized = $this->normalizeFields($extraction['fields'] ?? [], $normalizationLog, $fieldValueTypes);
        $itemsNormalized = $this->normalizeItems($extraction['items'] ?? [], $normalizationLog, $fieldValueTypes);
        $visualChecksResultado = $this->normalizeVisualChecks(
            $payload['visual_checks'] ?? [],
            $extraction['visual_checks'] ?? [],
            $normalizationLog
        );
        $documentQuality = $this->normalizeDocumentQuality($extraction['document_quality'] ?? null);
        $qualityNotes = $this->normalizeQualityNotes($extraction['quality_notes'] ?? [], $normalizationLog);
        $extractionWarnings = is_array($extraction['extraction_warnings'] ?? null) ? $extraction['extraction_warnings'] : [];

        return [
            'tipo_documento' => $rawType,
            'fields_normalized' => $fieldsNormalized,
            'items_normalized' => $itemsNormalized,
            'visual_checks_resultado' => $visualChecksResultado,
            'document_quality' => $documentQuality,
            'quality_notes' => $qualityNotes,
            'normalization_log' => $normalizationLog,
            'extraction_warnings' => $extractionWarnings,
        ];
    }

    /**
     * @param  array<string,mixed> $payload
     */
    private function resolveDocumentType(array $payload): string
    {
        $rawType = trim((string) ($payload['tipo_documento'] ?? ''));
        if ($rawType === '') {
            throw new RuntimeException('document_extracted sin tipo_documento');
        }

        return $rawType;
    }

    private static function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function elapsedMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resolveExtractionResult(array $payload): array
    {
        $extraction = $payload['extraction_result'] ?? null;
        if (!is_array($extraction)) {
            throw new RuntimeException('document_extracted sin extraction_result válido');
        }

        return $extraction;
    }

    /**
     * @return array<string,AuditFieldValueType>
     */
    private function buildFieldValueTypes(mixed $fieldsConfig): array
    {
        if (!is_array($fieldsConfig)) {
            throw new RuntimeException('document_extracted sin fields_config válido');
        }

        $types = [];
        foreach ($fieldsConfig as $fieldConfig) {
            if (!is_array($fieldConfig)) {
                continue;
            }

            $field = trim((string) ($fieldConfig['campoNombre'] ?? ''));
            if ($field === '') {
                continue;
            }

            $types[$field] = AuditFieldValueType::fromInput((string) ($fieldConfig['tipoDato'] ?? ''));
        }

        return $types;
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     * @param array<string,AuditFieldValueType> $fieldValueTypes
     * @return array<string,mixed>
     */
    private function normalizeFields(mixed $fields, array &$normalizationLog, array $fieldValueTypes): array
    {
        if (!is_array($fields)) {
            throw new RuntimeException('extraction_result.fields debe ser array');
        }

        $normalized = [];
        foreach ($fields as $field => $value) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }

            $canonicalField = trim($field);
            if (!isset($fieldValueTypes[$canonicalField])) {
                $this->appendLog($normalizationLog, 'unconfigured_field_dropped', ['field' => $canonicalField]);
                continue;
            }

            [$canonical, $normalizedValue] = $this->normalizeFieldWithLog(
                $canonicalField, $value, $fieldValueTypes[$canonicalField], $normalizationLog
            );

            if (!array_key_exists($canonical, $normalized) || $normalized[$canonical] === null) {
                $normalized[$canonical] = $normalizedValue;
            }
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     * @param array<string,AuditFieldValueType> $fieldValueTypes
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItems(mixed $items, array &$normalizationLog, array $fieldValueTypes): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [];
            foreach ($item as $field => $value) {
                if (!is_string($field) || trim($field) === '') {
                    continue;
                }

                $canonicalField = trim($field);
                if (!isset($fieldValueTypes[$canonicalField])) {
                    $this->appendLog($normalizationLog, 'unconfigured_item_field_dropped', [
                        'field' => $canonicalField,
                        'item_index' => $index,
                    ]);
                    continue;
                }

                [$canonical, $normalizedValue] = $this->normalizeFieldWithLog(
                    $canonicalField,
                    $value,
                    $fieldValueTypes[$canonicalField],
                    $normalizationLog,
                    ['item_index' => $index]
                );

                $row[$canonical] = $normalizedValue;
            }

            if ($this->isEmptyRow($row)) {
                $this->appendLog($normalizationLog, 'empty_item_row_dropped', ['item_index' => $index]);
                continue;
            }

            ksort($row);
            $normalized[] = $row;
        }

        return array_values($normalized);
    }

    private function normalizeFieldWithLog(
        string $originalField,
        mixed $value,
        AuditFieldValueType $valueType,
        array &$log,
        array $logContext = []
    ): array {
        if (!is_array($value) || !array_key_exists('valor', $value)) {
            throw new RuntimeException("El campo '{$originalField}' no cumple con shape v1 (se esperaba un array con la clave 'valor').");
        }

        return $this->normalizeEvidenceField($originalField, $valueType, $value, $log, $logContext);
    }

    /**
     * Normaliza un campo con shape v1 de evidencia preservando metadatos.
     *
     * @param  array<string,mixed>            $evidence  Objeto v1 {valor, valores, presente, ...}
     * @param  array<int,array<string,mixed>> $log
     * @param  array<string,mixed>            $logContext
     * @return array{0:string,1:ExtractedEvidence}
     */
    private function normalizeEvidenceField(
        string $originalField,
        AuditFieldValueType $valueType,
        array $evidence,
        array &$log,
        array $logContext = []
    ): array {
        $rawValor = $evidence['valor'] ?? null;
        [$normalizedValor, $valorOps] = $this->normalizeEvidenceScalar($originalField, $valueType, $rawValor);
        $this->appendFieldNormalizationOperations($log, $valorOps, $originalField, 'v1_valor', $logContext);

        $rawValores = is_array($evidence['valores'] ?? null) ? $evidence['valores'] : [];
        $normalizedValores = [];
        foreach ($rawValores as $v) {
            [$normalizedValue, $valueOps] = $this->normalizeEvidenceScalar($originalField, $valueType, $v);
            $this->appendFieldNormalizationOperations($log, $valueOps, $originalField, 'v1_valores', $logContext);
            if ($normalizedValue !== null) {
                $normalizedValores[] = $normalizedValue;
            }
        }

        $dto = new ExtractedEvidence(
            valor: $normalizedValor,
            valores: $normalizedValores !== [] ? $normalizedValores : ($normalizedValor !== null ? [$normalizedValor] : []),
            presente: (bool) ($evidence['presente'] ?? ($normalizedValor !== null)),
            estadoExtraccion: ExtractionState::fromInput($evidence['estadoExtraccion'] ?? null),
        );

        $this->appendLog($log, 'v1_evidence_normalized', array_merge($logContext, ['field' => $originalField]));

        return [$originalField, $dto];
    }

    /**
     * @return array{0:mixed,1:array<int,string>}
     */
    private function normalizeEvidenceScalar(string $field, AuditFieldValueType $valueType, mixed $value): array
    {
        [$normalized, $scalarOps] = $this->normalizeScalarWithOperations($value);
        [$normalized, $fieldOps] = $this->normalizeFieldValueWithOperations($field, $valueType, $normalized);

        return [$normalized, array_merge($scalarOps, $fieldOps)];
    }

    /**
     * @param  array<int,array<string,mixed>> $log
     * @param  array<int,string> $operations
     * @param  array<string,mixed> $logContext
     */
    private function appendFieldNormalizationOperations(
        array &$log,
        array $operations,
        string $field,
        string $context,
        array $logContext
    ): void {
        foreach ($operations as $operation) {
            $this->appendLog($log, $operation, array_merge($logContext, [
                'field' => $field,
                'context' => $context,
            ]));
        }
    }



    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     * @return array<int,array{check:string,presente:bool,detalle:?string,severidad:string}>
     */
    private function normalizeVisualChecks(
        mixed $configuredChecks,
        mixed $extractedChecks,
        array &$normalizationLog
    ): array {
        $result = $this->buildConfiguredVisualCheckMap($configuredChecks, $normalizationLog);
        $result = $this->mergeExtractedVisualChecks($result, $extractedChecks, $normalizationLog);

        ksort($result);
        return array_values($result);
    }

    /**
     * @param  array<int,array<string,mixed>> $normalizationLog
     * @return array<string,array{check:string,presente:bool,detalle:?string,severidad:string}>
     */
    private function buildConfiguredVisualCheckMap(mixed $configuredChecks, array &$normalizationLog): array
    {
        $result = [];
        if (is_array($configuredChecks)) {
            foreach ($configuredChecks as $check) {
                if (!is_array($check)) {
                    continue;
                }

                $name = trim((string) ($check['check'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $result[$name] = [
                    'check' => $name,
                    'presente' => false,
                    'detalle' => AuditFindingRules::normalizeNullableString($check['description'] ?? null),
                    'severidad' => $this->normalizeSeverity($check['severity'] ?? null),
                    'valor' => null,
                    'unidad' => null,
                    'fecha_base' => null,
                ];

                $this->appendLog($normalizationLog, 'visual_check_defaulted', [
                    'check' => $name,
                    'presente' => false,
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<string,array{check:string,presente:bool,detalle:?string,severidad:string}> $result
     * @param  array<int,array<string,mixed>> $normalizationLog
     * @return array<string,array{check:string,presente:bool,detalle:?string,severidad:string}>
     */
    private function mergeExtractedVisualChecks(array $result, mixed $extractedChecks, array &$normalizationLog): array
    {
        if (is_array($extractedChecks)) {
            foreach ($extractedChecks as $check) {
                if (!is_array($check)) {
                    continue;
                }

                $name = trim((string) ($check['check'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $base = $result[$name] ?? [
                    'check' => $name,
                    'presente' => false,
                    'detalle' => null,
                    'severidad' => 'CRITICO',
                ];

                $base['presente'] = (bool) ($check['presente'] ?? false);
                $detail = AuditFindingRules::normalizeNullableString($check['detalle'] ?? null);
                if ($detail !== null) {
                    $base['detalle'] = $detail;
                }

                $base['severidad'] = $this->normalizeSeverity($check['severidad'] ?? null);
                $base['valor'] = $this->normalizeVisualIntegerValue($check['valor'] ?? null, $normalizationLog, $name);
                $base['unidad'] = $this->normalizeVisualUnit($check['unidad'] ?? null, $normalizationLog, $name);
                $base['fecha_base'] = $this->normalizeVisualDateBase($check['fecha_base'] ?? null, $normalizationLog, $name);
                $result[$name] = $base;

                $this->appendLog($normalizationLog, 'visual_check_result_normalized', [
                    'check' => $name,
                    'presente' => $base['presente'],
                ]);
            }
        }

        return $result;
    }

    private function normalizeDocumentQuality(mixed $value): string
    {
        return DocumentQuality::fromString((string) $value)->value;
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     * @return array<int,string>
     */
    private function normalizeQualityNotes(mixed $notes, array &$normalizationLog): array
    {
        if (!is_array($notes)) {
            return [];
        }

        $normalized = [];
        foreach ($notes as $index => $note) {
            $string = AuditFindingRules::normalizeNullableString($note);
            if ($string === null) {
                if (is_string($note) && trim($note) === '') {
                    $this->appendLog($normalizationLog, 'quality_note_empty_dropped', [
                        'note_index' => $index,
                    ]);
                }
                continue;
            }

            $normalized[] = $string;
        }

        $unique = array_values(array_unique($normalized));
        if (count($unique) !== count($normalized)) {
            $this->appendLog($normalizationLog, 'quality_notes_deduplicated');
        }

        return $unique;
    }

    /**
     * @return array{0:mixed,1:array<int,string>}
     */
    private function normalizeScalarWithOperations(mixed $value): array
    {
        if ($value === null) {
            return [null, []];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            $operations = [];
            if ($trimmed !== $value) {
                $operations[] = 'string_trimmed';
            }
            if ($trimmed === '') {
                $operations[] = 'empty_string_to_null';
                return [null, $operations];
            }

            if (strtolower($trimmed) === 'null') {
                $operations[] = 'literal_null_string_to_null';
                return [null, $operations];
            }

            return [$trimmed, $operations];
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return [$value, []];
        }

        return [null, ['unsupported_value_to_null']];
    }

    private function normalizeSeverity(mixed $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        return $normalized !== '' ? $normalized : 'CRITICO';
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     */
    private function normalizeVisualIntegerValue(mixed $value, array &$normalizationLog, string $check): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $this->appendLog($normalizationLog, 'visual_value_normalized_to_integer', ['check' => $check]);
            return (int) trim($value);
        }

        $this->appendLog($normalizationLog, 'visual_value_invalid', ['check' => $check]);
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     */
    private function normalizeVisualUnit(mixed $value, array &$normalizationLog, string $check): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = TextNormalization::normalizeToken($value);
        if (in_array($normalized, ['DIA', 'DIAS'], true)) {
            if ($normalized !== 'DIAS') {
                $this->appendLog($normalizationLog, 'visual_unit_normalized', ['check' => $check]);
            }
            return 'dias';
        }

        $this->appendLog($normalizationLog, 'visual_unit_invalid', ['check' => $check]);
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     */
    private function normalizeVisualDateBase(mixed $value, array &$normalizationLog, string $check): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = TextNormalization::normalizeToken($value);
        $map = [
            'FECHAAUTORIZACION' => 'FechaAutorizacion',
            'FECHADEAUTORIZACION' => 'FechaAutorizacion',
            'AUTORIZACION' => 'FechaAutorizacion',
            'FECHAFORMULA' => 'FechaFormula',
            'FECHADEFORMULA' => 'FechaFormula',
            'FORMULA' => 'FechaFormula',
            'FECHAENTREGA' => 'FechaEntrega',
            'FECHADEENTREGA' => 'FechaEntrega',
            'ENTREGA' => 'FechaEntrega',
        ];

        if (isset($map[$normalized])) {
            if ($map[$normalized] !== trim($value)) {
                $this->appendLog($normalizationLog, 'visual_date_base_normalized', ['check' => $check]);
            }
            return $map[$normalized];
        }

        $this->appendLog($normalizationLog, 'visual_date_base_invalid', ['check' => $check]);
        return null;
    }


    /**
     * @return array{0:mixed,1:array<int,string>}
     */
    private function normalizeFieldValueWithOperations(string $field, AuditFieldValueType $valueType, mixed $value): array
    {
        if (!is_string($value)) {
            return [$value, []];
        }

        if ($valueType === AuditFieldValueType::DATE) {
            $normalizedDate = AuditFindingRules::normalizeDateToIso($value);
            if ($normalizedDate !== null) {
                $operations = $normalizedDate === $value ? [] : ['date_normalized_to_iso'];
                return [$normalizedDate, $operations];
            }
        }

        if ($valueType === AuditFieldValueType::IDENTITY_DOC_NUMBER) {
            $normalizedDocument = IdentityDocNormalizer::normalizeDocNumber($value);
            $operations = $normalizedDocument === $value ? [] : ['identity_doc_number_normalized'];
            return [$normalizedDocument, $operations];
        }

        if ($valueType === AuditFieldValueType::PERSON_NAME) {
            $normalizedName = IdentityDocNormalizer::normalizePersonNameFromMixedIdentityLine($value);
            $operations = $normalizedName === $value ? [] : ['person_name_identity_prefix_removed'];
            return [$normalizedName, $operations];
        }

        if ($valueType === AuditFieldValueType::AUTH_NUMBER) {
            $normalizedAuth = AuditFindingRules::normalizeAuthNumber($value);
            $operations = $normalizedAuth === $value ? [] : ['auth_number_prefix_removed'];
            return [$normalizedAuth, $operations];
        }

        return [$value, []];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value instanceof ExtractedEvidence) {
                if ($value->valor !== null && $value->valor !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     * @param array<string,mixed> $context
     */
    private function appendLog(array &$normalizationLog, string $operation, array $context = []): void
    {
        $normalizationLog[] = array_merge(['operation' => $operation], $context);
    }
}
