<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\DocumentQuality;
use Core\Logger;
use RuntimeException;

final class DocumentNormalizer extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->consumerName = $consumerName ?? ('normalizer-' . getmypid());
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
            ],
            parentEventId: $event->eventId,
        ));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function normalize(array $payload): array
    {
        $rawType = $this->resolveDocumentType($payload);
        $extraction = $this->resolveExtractionResult($payload);

        $normalizationLog = [];
        $fieldsNormalized = $this->normalizeFields($extraction['fields'] ?? [], $normalizationLog);
        $itemsNormalized = $this->normalizeItems($extraction['items'] ?? [], $normalizationLog);
        $visualChecksResultado = $this->normalizeVisualChecks(
            $payload['visual_checks'] ?? [],
            $extraction['visual_checks'] ?? [],
            $normalizationLog
        );
        $documentQuality = $this->normalizeDocumentQuality($extraction['document_quality'] ?? null);
        $qualityNotes = $this->normalizeQualityNotes($extraction['quality_notes'] ?? [], $normalizationLog);

        return [
            'tipo_documento' => $rawType,
            'fields_normalized' => $fieldsNormalized,
            'items_normalized' => $itemsNormalized,
            'visual_checks_resultado' => $visualChecksResultado,
            'document_quality' => $documentQuality,
            'quality_notes' => $qualityNotes,
            'normalization_log' => $normalizationLog,
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
     * @param array<int,array<string,mixed>> $normalizationLog
     * @return array<string,mixed>
     */
    private function normalizeFields(mixed $fields, array &$normalizationLog): array
    {
        if (!is_array($fields)) {
            throw new RuntimeException('extraction_result.fields debe ser array');
        }

        $normalized = [];
        foreach ($fields as $field => $value) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }

            [$canonical, $normalizedValue] = $this->normalizeFieldWithLog(
                trim($field), $value, $normalizationLog
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
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItems(mixed $items, array &$normalizationLog): array
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

                [$canonical, $normalizedValue] = $this->normalizeFieldWithLog(
                    trim($field), $value, $normalizationLog,
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

    /**
     * Normaliza un campo individual con soporte para shape v1 de evidencia.
     *
     * Dos modos de operación:
     * - **Legacy (escalar):** string/int/null → normaliza y envuelve en shape v1 mínimo
     * - **v1 (objeto de evidencia):** array con 'valor' → normaliza el escalar,
     *   preserva metadatos (confianza, estadoExtraccion, valores, evidencia, ubicacion)
     *
     * @param  array<int,array<string,mixed>> $log
     * @param  array<string,mixed>            $logContext
     * @return array{0:string,1:mixed}        [canonicalName, normalizedValue]
     */
    private function normalizeFieldWithLog(
        string $originalField,
        mixed $value,
        array &$log,
        array $logContext = []
    ): array {
        if (is_array($value) && array_key_exists('valor', $value)) {
            return $this->normalizeEvidenceField($originalField, $value, $log, $logContext);
        }

        [$normalizedValue, $scalarOps] = $this->normalizeScalarWithOperations($value);
        [$normalizedValue, $fieldOps]  = $this->normalizeFieldValueWithOperations($originalField, $normalizedValue);

        foreach (array_merge($scalarOps, $fieldOps) as $op) {
            $this->appendLog($log, $op, array_merge($logContext, ['field' => $originalField]));
        }

        $v1Shape = [
            'valor'             => $normalizedValue,
            'valores'           => $normalizedValue !== null ? [$normalizedValue] : [],
            'presente'          => $normalizedValue !== null,
            'confianza'         => null,
            'estadoExtraccion'  => $normalizedValue !== null ? 'FOUND' : 'NOT_FOUND',
            'evidencia'         => null,
            'ubicacion'         => null,
        ];

        $this->appendLog($log, 'legacy_scalar_wrapped_v1', array_merge($logContext, ['field' => $originalField]));

        return [$originalField, $v1Shape];
    }

    /**
     * Normaliza un campo con shape v1 de evidencia preservando metadatos.
     *
     * @param  array<string,mixed>            $evidence  Objeto v1 {valor, valores, presente, ...}
     * @param  array<int,array<string,mixed>> $log
     * @param  array<string,mixed>            $logContext
     * @return array{0:string,1:array<string,mixed>}
     */
    private function normalizeEvidenceField(
        string $originalField,
        array $evidence,
        array &$log,
        array $logContext = []
    ): array {
        $rawValor = $evidence['valor'] ?? null;
        [$normalizedValor, $scalarOps] = $this->normalizeScalarWithOperations($rawValor);
        [$normalizedValor, $fieldOps]  = $this->normalizeFieldValueWithOperations($originalField, $normalizedValor);

        foreach (array_merge($scalarOps, $fieldOps) as $op) {
            $this->appendLog($log, $op, array_merge($logContext, ['field' => $originalField, 'context' => 'v1_valor']));
        }

        $rawValores = is_array($evidence['valores'] ?? null) ? $evidence['valores'] : [];
        $normalizedValores = [];
        foreach ($rawValores as $v) {
            [$nv, ] = $this->normalizeScalarWithOperations($v);
            if ($nv !== null) {
                $normalizedValores[] = $nv;
            }
        }

        $v1Shape = [
            'valor'             => $normalizedValor,
            'valores'           => $normalizedValores !== [] ? $normalizedValores : ($normalizedValor !== null ? [$normalizedValor] : []),
            'presente'          => (bool) ($evidence['presente'] ?? ($normalizedValor !== null)),
            'confianza'         => AuditFindingRules::normalizeNullableString($evidence['confianza'] ?? null),
            'estadoExtraccion'  => $this->normalizeEstadoExtraccion($evidence['estadoExtraccion'] ?? null),
            'evidencia'         => AuditFindingRules::normalizeNullableString($evidence['evidencia'] ?? null),
            'ubicacion'         => AuditFindingRules::normalizeNullableString($evidence['ubicacion'] ?? null),
        ];

        $this->appendLog($log, 'v1_evidence_normalized', array_merge($logContext, ['field' => $originalField]));

        return [$originalField, $v1Shape];
    }

    /**
     * Normaliza el enum estadoExtraccion a valores válidos.
     */
    private function normalizeEstadoExtraccion(mixed $value): string
    {
        if (!is_string($value)) {
            return 'FOUND';
        }

        $upper = strtoupper(trim($value));
        $valid = ['FOUND', 'FOUND_IN_LIST', 'NOT_FOUND', 'AMBIGUOUS', 'ILLEGIBLE'];

        return in_array($upper, $valid, true) ? $upper : 'FOUND';
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
                    'rol' => $this->normalizeRole($check['rol'] ?? null),
                    'omitirSi' => $check['omitirSi'] ?? null,
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

    private function normalizeRole(mixed $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        return $normalized !== '' ? $normalized : 'AUTORITATIVO';
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

        $normalized = AuditFindingRules::normalizeToken($value);
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

        $normalized = AuditFindingRules::normalizeToken($value);
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
    private function normalizeFieldValueWithOperations(string $field, mixed $value): array
    {
        if (!is_string($value)) {
            return [$value, []];
        }

        if (AuditFieldValueType::fromFieldName($field) === AuditFieldValueType::DATE) {
            $normalizedDate = AuditFindingRules::normalizeDateToIso($value);
            if ($normalizedDate !== null) {
                $operations = $normalizedDate === $value ? [] : ['date_normalized_to_iso'];
                return [$normalizedDate, $operations];
            }
        }

        return [$value, []];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (is_array($value) && array_key_exists('valor', $value)) {
                $inner = $value['valor'];
                if ($inner !== null && $inner !== '') {
                    return false;
                }
                continue;
            }

            if ($value !== null && $value !== '') {
                return false;
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
