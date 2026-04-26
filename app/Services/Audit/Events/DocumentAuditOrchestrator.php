<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use RuntimeException;

final class DocumentAuditOrchestrator extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private InternalAuditApiClient $apiClient;
    private SchemaBuilder $schemaBuilder;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?InternalAuditApiClient $apiClient = null,
        ?SchemaBuilder $schemaBuilder = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->apiClient = $apiClient ?? new InternalAuditApiClient();
        $this->schemaBuilder = $schemaBuilder ?? new SchemaBuilder();
        $this->consumerName = $consumerName ?? ('orchestrator-' . getmypid());
    }

    public function processEvent(AuditEvent $event): void
    {
        $this->handle($event);
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

        $disDetNro = $this->assertAuditCreated($event);
        $context = $this->buildAuditContext($disDetNro);

        $this->stateStore->patchAudit($event->auditId, [
            'fac_nit_sec' => $context['nitSec'],
            'fac_sec' => $context['facSec'],
            'numero_factura' => $context['numeroFactura'],
        ]);
        $this->stateStore->setAuditDocumentsTotal($event->auditId, count($context['schemaDocuments']));

        $this->registerDocuments($event, $disDetNro, $context);
    }

    private function assertAuditCreated(AuditEvent $event): string
    {
        if ($event->auditId === null) {
            throw new RuntimeException('audit_created sin audit_id');
        }

        $disDetNro = trim((string) ($event->payload['dis_det_nro'] ?? ''));
        if ($disDetNro === '') {
            throw new RuntimeException('audit_created sin dis_det_nro');
        }

        return $disDetNro;
    }

    /**
     * @return array{
     *   nitSec:string, facSec:string, numeroFactura:string,
     *   fuenteVerdad:array<string,mixed>, auditConfig:array<string,mixed>,
     *   schemaDocuments:array<int,array<string,mixed>>,
     *   catalogById:array<int,array<string,mixed>>,
     *   attachments:array<int,array<string,mixed>>
     * }
     */
    private function buildAuditContext(string $disDetNro): array
    {
        $fuenteVerdad = $this->apiClient->getDispensation($disDetNro);
        $header = $fuenteVerdad['header'];
        $nitSec = trim((string) ($header['NitSec'] ?? ''));
        $facSec = trim((string) ($header['FacSec'] ?? ''));
        $numeroFactura = trim((string) ($header['NumeroFactura'] ?? ''));

        if ($nitSec === '' || $facSec === '' || $numeroFactura === '') {
            throw new RuntimeException('FDV incompleta para registrar documentos');
        }

        $auditConfig = $this->apiClient->getAuditConfig($nitSec);
        if ($auditConfig === null || !($auditConfig['activo'] ?? false)) {
            throw new RuntimeException("audit-config no disponible para NitSec {$nitSec}");
        }

        $clientDocuments = $this->apiClient->getClientDocuments($nitSec);
        if ($clientDocuments === []) {
            throw new RuntimeException("Catálogo documental vacío para NitSec {$nitSec}");
        }

        $attachments = $this->apiClient->getAttachments($disDetNro, $nitSec);
        if ($attachments === []) {
            throw new RuntimeException("Adjuntos no encontrados para {$disDetNro}");
        }

        return [
            'nitSec' => $nitSec,
            'facSec' => $facSec,
            'numeroFactura' => $numeroFactura,
            'fuenteVerdad' => $fuenteVerdad,
            'auditConfig' => $auditConfig,
            'schemaDocuments' => $this->schemaBuilder->build($auditConfig),
            'catalogById' => $this->indexClientDocumentsById($clientDocuments),
            'attachments' => $attachments,
        ];
    }

    /**
     * @param  array<string,mixed> $context
     */
    private function registerDocuments(AuditEvent $event, string $disDetNro, array $context): void
    {
        foreach ($context['schemaDocuments'] as $schemaDocument) {
            $catalogDocument = $context['catalogById'][$schemaDocument['doc_id']] ?? null;
            if ($catalogDocument === null) {
                throw new RuntimeException("DOCUMENT_CONFIG_NOT_FOUND: docId {$schemaDocument['doc_id']}");
            }

            $attachment = $this->matchAttachment($schemaDocument, $catalogDocument, $context['attachments']);
            if ($attachment === null) {
                throw new RuntimeException('REQUIRED_ATTACHMENT_MISSING: ' . $schemaDocument['document_name']);
            }

            $documentId = AuditEvent::uuidV4();
            $documentState = $this->buildDocumentState(
                $documentId, $schemaDocument, $catalogDocument, $attachment, $disDetNro, $context
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
     * @param  array<string,mixed> $schemaDocument
     * @param  array<string,mixed> $catalogDocument
     * @param  array<string,mixed> $attachment
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildDocumentState(
        string $documentId,
        array $schemaDocument,
        array $catalogDocument,
        array $attachment,
        string $disDetNro,
        array $context
    ): array {
        $attachmentId = (string) ($attachment['id_documento'] ?? '');

        return [
            'document_id' => $documentId,
            'doc_id' => (string) $schemaDocument['doc_id'],
            'tipo_documento' => $schemaDocument['document_name'],
            'nombre_alternativo' => (string) ($catalogDocument['NitMedDocCodAlt'] ?? ''),
            'status' => 'registered',
            'attachment_id' => $attachmentId,
            'download_url' => $this->apiClient->buildDownloadUrl($disDetNro, $attachmentId),
            'tipo_almacenamiento' => (string) ($attachment['TipoAlmacenamiento'] ?? ''),
            'dis_det_nro' => $disDetNro,
            'numero_factura' => $context['numeroFactura'],
            'fac_sec' => $context['facSec'],
            'fac_nit_sec' => $context['nitSec'],
            'extraction_schema' => $schemaDocument['extraction_schema'],
            'visual_checks' => $schemaDocument['visual_checks'],
            'system_prompt' => $context['auditConfig']['systemPrompt'] ?? null,
            'fuente_verdad' => $context['fuenteVerdad'],
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

    private function matchAttachment(array $schemaDocument, array $catalogDocument, array $attachments): ?array
    {
        $docId = (int) $schemaDocument['doc_id'];
        foreach ($attachments as $attachment) {
            if ((int) ($attachment['id_documento'] ?? 0) === $docId) {
                return $attachment;
            }
        }

        $candidates = array_filter([
            (string) ($schemaDocument['document_name'] ?? ''),
            (string) ($catalogDocument['NitMedDocNom'] ?? ''),
            (string) ($catalogDocument['NitMedDocCodAlt'] ?? ''),
        ]);
        $normalizedCandidates = array_map([SchemaBuilder::class, 'normalizeName'], $candidates);

        foreach ($attachments as $attachment) {
            $attachmentNames = array_filter([
                (string) ($attachment['nombre_documento'] ?? ''),
                (string) ($attachment['nombre_alternativo'] ?? ''),
            ]);

            foreach ($attachmentNames as $attachmentName) {
                if (in_array(SchemaBuilder::normalizeName($attachmentName), $normalizedCandidates, true)) {
                    return $attachment;
                }
            }
        }

        return null;
    }
}
