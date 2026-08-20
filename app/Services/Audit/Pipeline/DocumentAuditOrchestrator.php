<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\Telemetry\TelemetryPublisher;
use Core\Logger;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class DocumentAuditOrchestrator extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private AuditDataService $dataService;
    private DocumentExtractionContractBuilder $contractBuilder;
    private DocumentAttachmentMatcher $attachmentMatcher;
    private TelemetryPublisher $telemetryPublisher;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore                  $stateStore      = null,
        ?AuditDataService                 $dataService     = null,
        ?DocumentExtractionContractBuilder $contractBuilder = null,
        ?DocumentAttachmentMatcher        $attachmentMatcher = null,
        ?\Core\RedisClient                $redis           = null,
        ?AuditEventPublisher              $publisher       = null,
        ?string                           $consumerName    = null,
        ?TelemetryPublisher               $telemetryPublisher = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore      = $stateStore      ?? new AuditStateStore($this->redis);
        $this->dataService     = $dataService     ?? new AuditDataService();
        $this->contractBuilder = $contractBuilder ?? new DocumentExtractionContractBuilder();
        $this->attachmentMatcher = $attachmentMatcher ?? new DocumentAttachmentMatcher();
        $this->telemetryPublisher = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->consumerName    = $consumerName    ?? self::defaultConsumerName(AuditEventPublisher::GROUP_ORCHESTRATOR);
    }

    protected function streams(): array
    {
        return [
            AuditEventPublisher::STREAM_INBOX_PRIORITY,
            AuditEventPublisher::STREAM_INBOX_BATCH,
        ];
    }

    protected function group(): string
    {
        return AuditEventPublisher::GROUP_ORCHESTRATOR;
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function handle(AuditEvent $event): void
    {
        if ($event->eventType !== AuditEvent::TYPE_AUDIT_CREATED) {
            return;
        }

        $identity = $this->assertAuditCreated($event);
        $startedAt = hrtime(true);
        $meta = ['worker' => $this->consumer()];
        $this->telemetryPublisher->started(
            $event->auditId,
            'orchestration',
            null,
            $identity['dis_det_nro'],
            $meta,
            $event->jobId
        );

        try {
            if (!$this->stateStore->markAuditStarted($event->auditId)) {
                throw new RuntimeException('No se pudo marcar el inicio de procesamiento activo');
            }

            $context = $this->buildAuditContext($identity['dis_id'], $identity['dis_det_nro']);
            $this->assertIdentityContract($event, $identity, $context);
            $this->filterDocumentsByAutorizacion($event, $context);
            $this->assertConfiguredDocumentsHaveCatalog($context);
            $matchResult = $this->attachmentMatcher->matchAll(
                $context['configuredDocuments'],
                $context['catalogById'],
                $context['attachments']
            );
            $matchResult = $this->applyMissingAuthorizationRule($event, $context, $matchResult);

            $auditPatch = [
                'fac_nit_sec' => $context['nitSec'],
                'dis_id' => $context['disId'],
                'numero_factura' => $context['numeroFactura'],
            ];
            if (!empty($context['syntheticRejections'])) {
                $auditPatch['synthetic_rejections'] = $context['syntheticRejections'];
            }
            if (!$this->stateStore->patchAudit($event->auditId, $auditPatch)) {
                throw new RuntimeException('No se pudo actualizar el contexto de auditoría en Redis');
            }

            if (!$this->stateStore->setAuditDocumentsTotal($event->auditId, count($context['configuredDocuments']))) {
                throw new RuntimeException('No se pudo registrar el total de documentos de la auditoría');
            }

            $this->registerDocuments($event, $context, $matchResult);

            $this->telemetryPublisher->completed(
                $event->auditId,
                'orchestration',
                self::elapsedMs($startedAt),
                null,
                $identity['dis_det_nro'],
                array_merge(
                    $meta,
                    ['documents_total' => count($context['configuredDocuments'])],
                    $this->matchTelemetry($matchResult)
                ),
                $event->jobId
            );
        } catch (\Throwable $error) {
            $this->telemetryPublisher->failed(
                $event->auditId,
                'orchestration',
                self::elapsedMs($startedAt),
                null,
                $identity['dis_det_nro'],
                array_merge($meta, ['error_class' => get_class($error)]),
                $event->jobId
            );
            throw $error;
        }
    }

    /**
     * @return array{dis_id:string,dis_det_nro:string}
     */
    private function assertAuditCreated(AuditEvent $event): array
    {
        if ($event->auditId === null) {
            throw new InvalidArgumentException('audit_created sin audit_id');
        }

        $disId = trim((string) ($event->payload['dis_id'] ?? ''));
        if ($disId === '') {
            throw new InvalidArgumentException('audit_created sin dis_id');
        }

        $disDetNro = trim((string) ($event->payload['dis_det_nro'] ?? ''));
        if ($disDetNro === '') {
            throw new InvalidArgumentException('audit_created sin dis_det_nro');
        }

        return [
            'dis_id' => $disId,
            'dis_det_nro' => $disDetNro,
        ];
    }

    /**
     * Valida el contrato de identidad entre el evento de entrada y la FDV.
     *
     * Contrato canónico:
     * - payload.dis_id viene de Factura.DisId y selecciona la FDV.
     * - FDV.header.DisId viene de vw_discolnet_dispensas.DisIdF.
     * - payload.dis_det_nro debe coincidir con FDV.header.NumeroFactura.
     *
     * @param  array{dis_id:string,dis_det_nro:string} $identity
     * @param  array{
     *   nitSec:string, disId:string, numeroFactura:string,
     * } $context
     * @throws DomainException Si el evento y la FDV representan identidades distintas.
     */
    private function assertIdentityContract(AuditEvent $event, array $identity, array $context): void
    {
        $disId = $identity['dis_id'];
        $disDetNro = $identity['dis_det_nro'];

        $this->assertIdentityValue(
            'payload.dis_id',
            $disId,
            'FDV.DisId',
            (string) ($context['disId'] ?? ''),
            $disDetNro,
        );

        $this->assertIdentityValue(
            'payload.dis_det_nro',
            $disDetNro,
            'FDV.NumeroFactura',
            (string) ($context['numeroFactura'] ?? ''),
        );

        $this->assertOptionalIdentityValue(
            'payload.fac_nit_sec',
            $event->payload['fac_nit_sec'] ?? null,
            'FDV.NitSec',
            (string) ($context['nitSec'] ?? ''),
            $disDetNro,
        );
    }

    private function assertOptionalIdentityValue(
        string $eventLabel,
        mixed $eventValue,
        string $fdvLabel,
        string $fdvValue,
        string $disDetNro
    ): void {
        $eventValue = trim((string) ($eventValue ?? ''));
        if ($eventValue === '') {
            return;
        }

        $this->assertIdentityValue($eventLabel, $eventValue, $fdvLabel, $fdvValue, $disDetNro);
    }

    private function assertIdentityValue(
        string $eventLabel,
        string $eventValue,
        string $fdvLabel,
        string $fdvValue,
        ?string $disDetNro = null
    ): void {
        $eventValue = trim($eventValue);
        $fdvValue = trim($fdvValue);
        if ($eventValue === $fdvValue) {
            return;
        }

        $suffix = $disDetNro !== null ? sprintf(' para DisDetNro "%s"', $disDetNro) : '';
        throw new DomainException(sprintf(
            'AUDIT_IDENTITY_MISMATCH: %s "%s" difiere de %s "%s"%s',
            $eventLabel,
            $eventValue,
            $fdvLabel,
            $fdvValue,
            $suffix
        ));
    }

    /**
     * @return array{
     *   nitSec:string, disId:string, numeroFactura:string,
     *   fuenteVerdad:array<string,mixed>, auditConfig:array<string,mixed>,
     *   configuredDocuments:array<int,array<string,mixed>>,
     *   catalogById:array<int,array<string,mixed>>,
     *   attachments:array<int,array<string,mixed>>
     * }
     */
    private function buildAuditContext(string $requestedDisId, string $requestedDisDetNro): array
    {
        $fuenteVerdad  = $this->dataService->getDispensation([
            'dis_id' => $requestedDisId,
            'dis_det_nro' => $requestedDisDetNro,
        ]);
        $header        = $fuenteVerdad['header'];
        $nitSec        = trim((string) ($header['NitSec']        ?? ''));
        $disId         = trim((string) ($header['DisId']         ?? ''));
        $numeroFactura = trim((string) ($header['NumeroFactura'] ?? ''));

        if ($nitSec === '' || $disId === '' || $numeroFactura === '') {
            throw new DomainException('FDV incompleto: nitSec, disId o NumeroFactura vacíos.');
        }

        $auditConfig     = $this->dataService->getAuditConfig($nitSec);
        $clientDocuments = $this->dataService->getClientDocuments($nitSec);
        if ($clientDocuments === []) {
            throw new DomainException("Catálogo documental vacío para NitSec {$nitSec}");
        }

        $attachments = $this->dataService->getAttachments($numeroFactura, $nitSec);

        return [
            'nitSec' => $nitSec,
            'disId' => $disId,
            'numeroFactura' => $numeroFactura,
            'fuenteVerdad' => $fuenteVerdad,
            'auditConfig' => $auditConfig,
            'configuredDocuments' => $this->buildConfiguredDocuments($auditConfig),
            'catalogById' => $this->indexClientDocumentsById($clientDocuments),
            'attachments' => $attachments,
        ];
    }

    /**
     * @param  array<string,mixed> $context
     */
    private function registerDocuments(
        AuditEvent $event,
        array $context,
        DocumentAttachmentMatchResult $matchResult
    ): void
    {
        $source = (string) ($event->payload['source'] ?? ($event->jobId === null ? 'single' : 'batch'));
        $isPriority = AuditEventPublisher::isPriorityEvent($event);

        foreach ($matchResult->matches as $match) {
            $configuredDocument = $match['logical_document'];
            $attachment = $match['physical_attachment'];
            $documentId = AuditEvent::uuidV4();
            $catalogDocument = $context['catalogById'][$configuredDocument['doc_id']] ?? [];

            $documentState = $this->buildDocumentState(
                $documentId,
                $configuredDocument,
                $catalogDocument,
                $attachment,
                $context,
                (string) $match['strategy'],
                $match['candidate_attachment_ids'],
                $source,
                $isPriority
            );

            if (!$this->stateStore->registerDocument($event->auditId, $documentId, $documentState)) {
                throw new RuntimeException('No se pudo registrar el documento en Redis');
            }

            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
                auditId: $event->auditId,
                jobId: $event->jobId,
                documentId: $documentId,
                payload: $documentState,
                parentEventId: $event->eventId,
            ));

            Logger::info('Adjunto físico asociado a documento lógico', [
                'audit_id' => $event->auditId,
                'document_id' => $documentId,
                'logical_doc_id' => (string) $match['logical_doc_id'],
                'attachment_id' => (string) $match['attachment_id'],
                'attachment_match_strategy' => (string) $match['strategy'],
            ]);
        }

        foreach ($matchResult->rejections as $rejection) {
            $configuredDocument = $rejection['logical_document'];
            $attachment = is_array($rejection['physical_attachment'] ?? null)
                ? $rejection['physical_attachment']
                : [];
            $documentId = AuditEvent::uuidV4();
            $catalogDocument = $context['catalogById'][$configuredDocument['doc_id']] ?? [];
            $candidateAttachmentIds = array_values(array_map(
                'strval',
                $rejection['candidate_attachment_ids']
            ));
            $rejectionReason = (string) $rejection['reason'];
            $rejectedAt = gmdate('Y-m-d\TH:i:s\Z');
            $documentState = $this->buildDocumentState(
                $documentId,
                $configuredDocument,
                $catalogDocument,
                $attachment,
                $context,
                null,
                $candidateAttachmentIds,
                $source,
                $isPriority
            );

            if (!$this->stateStore->registerDocument($event->auditId, $documentId, $documentState)) {
                throw new RuntimeException('No se pudo registrar el documento previo al rechazo en Redis');
            }

            $rejectionPatch = [
                'rejection_category' => DocumentMappingRejectionReason::CATEGORY,
                'rejection_origin' => self::class,
                'rejection_reason' => $rejectionReason,
                'logical_doc_id' => (string) $rejection['logical_doc_id'],
                'candidate_attachment_ids' => $candidateAttachmentIds,
                'rejected_at' => $rejectedAt,
            ];
            if (!$this->stateStore->markDocumentRejected($event->auditId, $documentId, $rejectionPatch)) {
                throw new RuntimeException('No se pudo marcar el rechazo de mapping en Redis');
            }

            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
                auditId: $event->auditId,
                jobId: $event->jobId,
                documentId: $documentId,
                payload: [
                    'rejection_reason' => $rejectionReason,
                    'rejection_category' => DocumentMappingRejectionReason::CATEGORY,
                    'rejection_origin' => self::class,
                    'document_type' => $configuredDocument['document_name'],
                    'logical_doc_id' => (string) $rejection['logical_doc_id'],
                    'candidate_attachment_ids' => $candidateAttachmentIds,
                    'rejected_at' => $rejectedAt,
                    'source' => $source,
                    'is_priority' => $isPriority,
                ],
                parentEventId: $event->eventId,
            ));

            Logger::warning('Documento lógico rechazado durante asociación física', [
                'audit_id' => $event->auditId,
                'document_id' => $documentId,
                'logical_doc_id' => (string) $rejection['logical_doc_id'],
                'candidate_attachment_ids' => $candidateAttachmentIds,
                'rejection_reason' => $rejectionReason,
            ]);
        }
    }

    /**
     * @param  array<string,mixed> $configuredDocument
     * @param  array<string,mixed> $catalogDocument
     * @param  array<string,mixed> $attachment
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildDocumentState(
        string $documentId,
        array $configuredDocument,
        array $catalogDocument,
        array $attachment,
        array $context,
        ?string $matchStrategy,
        array $matchCandidates,
        ?string $source = null,
        bool $isPriority = false
    ): array {
        $attachmentId = (string) ($attachment['attachment_id'] ?? '');
        $contractHash = (string) ($configuredDocument['extraction_contract']['contract_hash'] ?? '');

        return [
            'document_id'        => $documentId,
            'doc_id'             => (string) $configuredDocument['doc_id'],
            'tipo_documento'     => $configuredDocument['document_name'],
            'nombre_alternativo' => (string) ($catalogDocument['NitMedDocCodAlt'] ?? ''),
            'status'             => 'registered',
            'attachment_id'      => $attachmentId,
            'physical_catalog_id' => is_scalar($attachment['physical_catalog_id'] ?? null)
                ? (string) $attachment['physical_catalog_id']
                : null,
            'physical_document_name' => (string) ($attachment['physical_document_name'] ?? ''),
            'attachment_match_strategy' => $matchStrategy,
            'attachment_match_candidates' => array_values(array_map('strval', $matchCandidates)),
            'download_url'       => $attachmentId === ''
                ? null
                : '/dispensation/' . rawurlencode((string) $context['numeroFactura'])
                    . '/attachments/download/' . rawurlencode($attachmentId),
            'tipo_almacenamiento' => (string) ($attachment['storage_type'] ?? ''),
            'dis_det_nro'        => $context['numeroFactura'],
            'numero_factura'     => $context['numeroFactura'],
            'dis_id'             => $context['disId'],
            'fac_nit_sec'        => $context['nitSec'],
            'source'             => $source,
            'is_priority'        => $isPriority,
            'extraction_contract' => $configuredDocument['extraction_contract'],
            'fields_config'      => $configuredDocument['fields'],
            'visual_checks'      => $configuredDocument['visual_checks'],
            'system_prompt'      => $context['auditConfig']['systemPrompt'] ?? null,
            'factor_conv'        => $context['auditConfig']['factorConv'] ?? false,
            'fuente_verdad'      => $context['fuenteVerdad'],
            'contract_hash'      => $contractHash,
        ];
    }

    private function indexClientDocumentsById(array $clientDocuments): array
    {
        $indexed = [];
        foreach ($clientDocuments as $document) {
            $docId = (int) ($document['NitMedDocId'] ?? 0);
            if ($docId < 1) {
                continue;
            }
            $indexed[$docId] = $document;
        }

        return $indexed;
    }

    /** @param array<string,mixed> $context */
    private function assertConfiguredDocumentsHaveCatalog(array $context): void
    {
        foreach ($context['configuredDocuments'] as $configuredDocument) {
            $docId = (int) ($configuredDocument['doc_id'] ?? 0);
            if (!isset($context['catalogById'][$docId])) {
                throw new DomainException("DOCUMENT_CONFIG_NOT_FOUND: docId {$docId}");
            }
        }
    }

    /**
     * Conserva la regla de negocio Autorizacion=R sin ejecutar un segundo matching.
     * Solo la ausencia física inequívoca se transforma en el hallazgo sintético existente;
     * ambigüedad, falta de contenido o reutilización permanecen como rechazos de mapping.
     *
     * @param array<string,mixed> $context
     */
    private function applyMissingAuthorizationRule(
        AuditEvent $event,
        array &$context,
        DocumentAttachmentMatchResult $matchResult
    ): DocumentAttachmentMatchResult {
        $authorizationMode = trim((string) (
            $context['fuenteVerdad']['header']['Autorizacion'] ?? 'S'
        ));
        if ($authorizationMode !== 'R') {
            return $matchResult;
        }

        $authorizationIndex = null;
        $authorizationDocument = null;
        foreach ($context['configuredDocuments'] as $index => $configuredDocument) {
            if (($configuredDocument['document_name_normalized'] ?? '') === 'AUTORIZACION') {
                $authorizationIndex = $index;
                $authorizationDocument = $configuredDocument;
                break;
            }
        }
        if ($authorizationIndex === null || !is_array($authorizationDocument)) {
            return $matchResult;
        }

        $logicalDocId = (string) $authorizationDocument['doc_id'];
        $missingRejectionIndex = null;
        foreach ($matchResult->rejections as $index => $rejection) {
            if (
                (string) ($rejection['logical_doc_id'] ?? '') === $logicalDocId
                && ($rejection['reason'] ?? '') === DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_MISSING
            ) {
                $missingRejectionIndex = $index;
                break;
            }
        }
        if ($missingRejectionIndex === null) {
            return $matchResult;
        }

        array_splice($context['configuredDocuments'], $authorizationIndex, 1);
        $context['syntheticRejections'] ??= [];
        $context['syntheticRejections'][] = [
            'documentName' => $authorizationDocument['document_name'],
            'doc_id' => $logicalDocId,
            'approved' => false,
            'payload' => [
                'state' => false,
                'Dispensa' => $context['numeroFactura'],
                'fechaAuditoria' => date('Y-m-d H:i:s.v'),
                'hallazgos' => [
                    [
                        'Codigo' => 'AUT',
                        'Descripcion' => 'Autorización requerida por el cliente pero no adjuntada.',
                    ],
                ],
            ],
        ];

        $rejections = $matchResult->rejections;
        unset($rejections[$missingRejectionIndex]);

        Logger::info('Orchestrator: AUTORIZACION faltante convertida en hallazgo sintético', [
            'audit_id' => $event->auditId,
            'dis_det_nro' => $context['numeroFactura'],
            'doc_id' => $logicalDocId,
        ]);

        return new DocumentAttachmentMatchResult(
            $matchResult->matches,
            array_values($rejections)
        );
    }

    /** @return array<string,int> */
    private function matchTelemetry(DocumentAttachmentMatchResult $matchResult): array
    {
        $telemetry = [
            'attachment_matches_exact_name' => 0,
            'attachment_matches_validated_id' => 0,
            'attachment_matches_unique_alias' => 0,
            'attachment_mapping_rejections' => count($matchResult->rejections),
        ];

        foreach ($matchResult->matches as $match) {
            $key = 'attachment_matches_' . (string) ($match['strategy'] ?? '');
            if (array_key_exists($key, $telemetry)) {
                $telemetry[$key]++;
            }
        }

        return $telemetry;
    }



    private function buildConfiguredDocuments(array $auditConfig): array
    {
        $documents = $auditConfig['documents'] ?? null;
        if (!is_array($documents) || $documents === []) {
            throw new InvalidArgumentException('audit-config.documents es requerido');
        }

        $normalized = [];
        $seenDocIds = [];
        foreach ($documents as $documentName => $documentConfig) {
            if (!is_string($documentName) || !is_array($documentConfig)) {
                throw new InvalidArgumentException('Documento inválido en audit-config');
            }

            $docId = (int) ($documentConfig['docId'] ?? 0);
            if ($docId < 1) {
                throw new InvalidArgumentException("Documento sin docId válido: {$documentName}");
            }

            if (isset($seenDocIds[$docId])) {
                throw new InvalidArgumentException("audit-config.documents contiene docId duplicado: {$docId}");
            }
            $seenDocIds[$docId] = true;

            $fields = $this->normalizeSchemaFields($documentConfig['fields'] ?? []);
            $visualChecks = $this->normalizeSchemaVisualChecks($documentConfig['visualChecks'] ?? []);

            $normalized[] = [
                'doc_id' => $docId,
                'document_name' => trim($documentName),
                'document_name_normalized' => DocumentExtractionContractBuilder::normalizeDocumentName($documentName),
                'fields' => $fields,
                'extraction_contract' => $this->contractBuilder->build(trim($documentName), $fields, $visualChecks),
                'visual_checks' => $visualChecks,
            ];
        }

        usort(
            $normalized,
            static fn(array $a, array $b): int => $a['doc_id'] <=> $b['doc_id']
        );

        return $normalized;
    }

    private function normalizeSchemaFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            throw new InvalidArgumentException('fields debe ser array');
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (is_array($field) && isset($field['campoNombre'])) {
                if (trim((string) ($field['tipoDato'] ?? '')) === '') {
                    throw new InvalidArgumentException('Campo sin tipoDato en fields');
                }
                $normalized[] = $field;
            } else {
                throw new InvalidArgumentException('Campo inválido en fields');
            }
        }

        return $normalized;
    }

    private function normalizeSchemaVisualChecks(mixed $checks): array
    {
        if (!is_array($checks)) {
            throw new InvalidArgumentException('visualChecks debe ser array');
        }

        $normalized = [];
        foreach ($checks as $check) {
            if (!is_array($check) || !is_string($check['check'] ?? null) || trim((string) $check['check']) === '') {
                throw new InvalidArgumentException('Visual check inválido');
            }

            $normalized[] = [
                'check' => trim((string) $check['check']),
                'description' => isset($check['description']) && is_string($check['description'])
                    ? trim($check['description'])
                    : '',
                'severity' => isset($check['severity']) && is_string($check['severity']) && trim($check['severity']) !== ''
                    ? strtoupper(trim($check['severity']))
                    : 'ALTA',
                'codigoCampo' => $check['codigoCampo'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Filtra documentos de autorización según la regla E2E del campo Autorizacion de la FDV.
     *
     * - 'N': Excluye el documento de AUTORIZACION completamente (no se envía a Gemini).
     * - 'R': Se conserva para reconciliarlo una sola vez con el resultado global del matcher.
     * - 'S': No modifica nada (flujo normal).
     *
     * @param array<string,mixed> $context Pasado por referencia para mutar configuredDocuments.
     */
    private function filterDocumentsByAutorizacion(AuditEvent $event, array &$context): void
    {
        $autorizacion = trim((string) ($context['fuenteVerdad']['header']['Autorizacion'] ?? 'S'));

        if ($autorizacion === 'S') {
            return;
        }
        if ($autorizacion !== 'N') {
            return;
        }

        $authDocIndex = null;
        foreach ($context['configuredDocuments'] as $index => $doc) {
            if (($doc['document_name_normalized'] ?? '') === 'AUTORIZACION') {
                $authDocIndex = $index;
                break;
            }
        }

        $disDetNro = $context['numeroFactura'];

        foreach ($context['configuredDocuments'] as &$configuredDoc) {
            if (isset($configuredDoc['fields']) && is_array($configuredDoc['fields'])) {
                $configuredDoc['fields'] = array_values(array_filter(
                    $configuredDoc['fields'],
                    fn($field) => !in_array($field['campoNombre'] ?? '', ['FechaAutorizacion', 'NumeroAutorizacion'], true)
                ));
            }
        }
        unset($configuredDoc);

        if ($authDocIndex !== null) {
            $authDoc = $context['configuredDocuments'][$authDocIndex];
            array_splice($context['configuredDocuments'], $authDocIndex, 1);
            Logger::info('Orchestrator: documento AUTORIZACION excluido por regla E2E (Autorizacion=N)', [
                'dis_det_nro' => $disDetNro,
                'doc_id' => $authDoc['doc_id'],
                'audit_id' => $event->auditId,
            ]);
        }
    }

    private static function elapsedMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
