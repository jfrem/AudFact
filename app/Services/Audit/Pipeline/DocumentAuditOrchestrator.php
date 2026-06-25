<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\Telemetry\TelemetryPublisher;
use InvalidArgumentException;
use RuntimeException;

final class DocumentAuditOrchestrator extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private AuditDataService $dataService;
    private DocumentExtractionContractBuilder $contractBuilder;
    private TelemetryPublisher $telemetryPublisher;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore                  $stateStore      = null,
        ?AuditDataService                 $dataService     = null,
        ?DocumentExtractionContractBuilder $contractBuilder = null,
        ?\Core\RedisClient                $redis           = null,
        ?AuditEventPublisher              $publisher       = null,
        ?string                           $consumerName    = null,
        ?TelemetryPublisher               $telemetryPublisher = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore      = $stateStore      ?? new AuditStateStore($this->redis);
        $this->dataService     = $dataService     ?? new AuditDataService();
        $this->contractBuilder = $contractBuilder ?? new DocumentExtractionContractBuilder();
        $this->telemetryPublisher = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->consumerName    = $consumerName    ?? self::defaultConsumerName('orchestrator');
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_INBOX;
    }

    protected function group(): string
    {
        return 'orchestrator';
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

            $this->stateStore->patchAudit($event->auditId, [
                'fac_nit_sec' => $context['nitSec'],
                'dis_id' => $context['disId'],
                'numero_factura' => $context['numeroFactura'],
            ]);
            $this->stateStore->setAuditDocumentsTotal($event->auditId, count($context['configuredDocuments']));

            $this->registerDocuments($event, $context);

            $this->telemetryPublisher->completed(
                $event->auditId,
                'orchestration',
                self::elapsedMs($startedAt),
                null,
                $identity['dis_det_nro'],
                array_merge($meta, ['documents_total' => count($context['configuredDocuments'])]),
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
            throw new RuntimeException('audit_created sin audit_id');
        }

        $disId = trim((string) ($event->payload['dis_id'] ?? ''));
        if ($disId === '') {
            throw new RuntimeException('audit_created sin dis_id');
        }

        $disDetNro = trim((string) ($event->payload['dis_det_nro'] ?? ''));
        if ($disDetNro === '') {
            throw new RuntimeException('audit_created sin dis_det_nro');
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
     * @throws RuntimeException Si el evento y la FDV representan identidades distintas.
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
        throw new RuntimeException(sprintf(
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
            throw new RuntimeException('FDV incompleto: nitSec, disId o NumeroFactura vacíos.');
        }

        $auditConfig     = $this->dataService->getAuditConfig($nitSec);
        $clientDocuments = $this->dataService->getClientDocuments($nitSec);
        if ($clientDocuments === []) {
            throw new RuntimeException("Catálogo documental vacío para NitSec {$nitSec}");
        }

        $attachments = $this->dataService->getAttachments($numeroFactura, $nitSec);
        if ($attachments === []) {
            throw new RuntimeException("Adjuntos no encontrados para {$numeroFactura}");
        }

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
    private function registerDocuments(AuditEvent $event, array $context): void
    {
        foreach ($context['configuredDocuments'] as $configuredDocument) {
            $catalogDocument = $context['catalogById'][$configuredDocument['doc_id']] ?? null;
            if ($catalogDocument === null) {
                throw new RuntimeException("DOCUMENT_CONFIG_NOT_FOUND: docId {$configuredDocument['doc_id']}");
            }

            $attachment = $this->matchAttachment($configuredDocument, $catalogDocument, $context['attachments']);
            if ($attachment === null) {
                throw new RuntimeException('REQUIRED_ATTACHMENT_MISSING: ' . $configuredDocument['document_name']);
            }

            $storage = (string) ($attachment['TipoAlmacenamiento'] ?? '');
            if ($storage !== 'BLOB' && $storage !== 'URL') {
                throw new RuntimeException(
                    'ATTACHMENT_NO_CONTENT: ' . $configuredDocument['document_name']
                    . " (TipoAlmacenamiento='{$storage}')"
                );
            }

            $documentId = AuditEvent::uuidV4();
            $documentState = $this->buildDocumentState(
                $documentId, $configuredDocument, $catalogDocument, $attachment, $context
            );

            $this->stateStore->registerDocument($event->auditId, $documentId, $documentState);

            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
                auditId: $event->auditId,
                jobId: $event->jobId,
                documentId: $documentId,
                payload: $documentState,
                parentEventId: $event->eventId,
            ));
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
        array $context
    ): array {
        $attachmentId = (string) ($attachment['id_documento'] ?? '');
        $contractHash = (string) ($configuredDocument['extraction_contract']['contract_hash'] ?? '');

        return [
            'document_id'        => $documentId,
            'doc_id'             => (string) $configuredDocument['doc_id'],
            'tipo_documento'     => $configuredDocument['document_name'],
            'nombre_alternativo' => (string) ($catalogDocument['NitMedDocCodAlt'] ?? ''),
            'status'             => 'registered',
            'attachment_id'      => $attachmentId,
            'download_url'       => '/dispensation/' . rawurlencode((string) $context['numeroFactura'])
                                    . '/attachments/download/' . rawurlencode($attachmentId),
            'tipo_almacenamiento'=> (string) ($attachment['TipoAlmacenamiento'] ?? ''),
            'dis_det_nro'        => $context['numeroFactura'],
            'numero_factura'     => $context['numeroFactura'],
            'dis_id'             => $context['disId'],
            'fac_nit_sec'        => $context['nitSec'],
            'extraction_contract' => $configuredDocument['extraction_contract'],
            'fields_config'      => $configuredDocument['fields'],
            'visual_checks'      => $configuredDocument['visual_checks'],
            'system_prompt'      => $context['auditConfig']['systemPrompt'] ?? null,
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

    private function matchAttachment(array $configuredDocument, array $catalogDocument, array $attachments): ?array
    {
        $docId = (int) $configuredDocument['doc_id'];
        foreach ($attachments as $attachment) {
            if ((int) ($attachment['id_documento'] ?? 0) === $docId) {
                return $attachment;
            }
        }

        $candidates = array_filter([
            (string) ($configuredDocument['document_name'] ?? ''),
            (string) ($catalogDocument['NitMedDocNom'] ?? ''),
            (string) ($catalogDocument['NitMedDocCodAlt'] ?? ''),
        ]);
        $normalizedCandidates = array_map([DocumentExtractionContractBuilder::class, 'normalizeDocumentName'], $candidates);

        foreach ($attachments as $attachment) {
            $attachmentNames = array_filter([
                (string) ($attachment['nombre_documento'] ?? ''),
                (string) ($attachment['nombre_alternativo'] ?? ''),
            ]);

            foreach ($attachmentNames as $attachmentName) {
                if (in_array(DocumentExtractionContractBuilder::normalizeDocumentName($attachmentName), $normalizedCandidates, true)) {
                    return $attachment;
                }
            }
        }

        return null;
    }

    private function buildConfiguredDocuments(array $auditConfig): array
    {
        $documents = $auditConfig['documents'] ?? null;
        if (!is_array($documents) || $documents === []) {
            throw new InvalidArgumentException('audit-config.documents es requerido');
        }

        $normalized = [];
        foreach ($documents as $documentName => $documentConfig) {
            if (!is_string($documentName) || !is_array($documentConfig)) {
                throw new InvalidArgumentException('Documento inválido en audit-config');
            }

            $docId = (int) ($documentConfig['docId'] ?? 0);
            if ($docId < 1) {
                throw new InvalidArgumentException("Documento sin docId válido: {$documentName}");
            }

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

    private static function elapsedMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
