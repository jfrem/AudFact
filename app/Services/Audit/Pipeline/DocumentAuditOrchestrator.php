<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use InvalidArgumentException;
use RuntimeException;

final class DocumentAuditOrchestrator extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private AuditDataServiceInterface $dataService;
    private DocumentExtractionContractBuilder $contractBuilder;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore                  $stateStore      = null,
        ?AuditDataServiceInterface        $dataService     = null,
        ?DocumentExtractionContractBuilder $contractBuilder = null,
        ?\Core\RedisClient                $redis           = null,
        ?AuditEventPublisher              $publisher       = null,
        ?string                           $consumerName    = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore      = $stateStore      ?? new AuditStateStore($this->redis);
        $this->dataService     = $dataService     ?? new AuditDataService();
        $this->contractBuilder = $contractBuilder ?? new DocumentExtractionContractBuilder();
        $this->consumerName    = $consumerName    ?? ('orchestrator-' . getmypid());
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
        $this->stateStore->setAuditDocumentsTotal($event->auditId, count($context['configuredDocuments']));

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
     *   configuredDocuments:array<int,array<string,mixed>>,
     *   catalogById:array<int,array<string,mixed>>,
     *   attachments:array<int,array<string,mixed>>
     * }
     */
    private function buildAuditContext(string $disDetNro): array
    {
        $fuenteVerdad  = $this->dataService->getDispensation($disDetNro);
        $header        = $fuenteVerdad['header'];
        $nitSec        = trim((string) ($header['NitSec']        ?? ''));
        $facSec        = trim((string) ($header['FacSec']        ?? ''));
        $numeroFactura = trim((string) ($header['NumeroFactura'] ?? ''));

        if ($nitSec === '' || $facSec === '' || $numeroFactura === '') {
            throw new RuntimeException('FDV incompleta para registrar documentos');
        }

        $auditConfig     = $this->dataService->getAuditConfig($nitSec);
        $clientDocuments = $this->dataService->getClientDocuments($nitSec);
        if ($clientDocuments === []) {
            throw new RuntimeException("Catálogo documental vacío para NitSec {$nitSec}");
        }

        $attachments = $this->dataService->getAttachments($disDetNro, $nitSec);
        if ($attachments === []) {
            throw new RuntimeException("Adjuntos no encontrados para {$disDetNro}");
        }

        return [
            'nitSec' => $nitSec,
            'facSec' => $facSec,
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
    private function registerDocuments(AuditEvent $event, string $disDetNro, array $context): void
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

            $documentId = AuditEvent::uuidV4();
            $documentState = $this->buildDocumentState(
                $documentId, $configuredDocument, $catalogDocument, $attachment, $disDetNro, $context
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
        string $disDetNro,
        array $context
    ): array {
        $attachmentId = (string) ($attachment['id_documento'] ?? '');
        $targetContext = $this->buildTargetContext(
            $configuredDocument['document_name'],
            $configuredDocument['fields'],
            $configuredDocument['visual_checks'],
            $context['fuenteVerdad']
        );
        $contractHash = (string) ($configuredDocument['extraction_contract']['contract_hash'] ?? '');
        $targetContextHash = DocumentExtractionContractBuilder::hashPayload($targetContext);

        return [
            'document_id'        => $documentId,
            'doc_id'             => (string) $configuredDocument['doc_id'],
            'tipo_documento'     => $configuredDocument['document_name'],
            'nombre_alternativo' => (string) ($catalogDocument['NitMedDocCodAlt'] ?? ''),
            'status'             => 'registered',
            'attachment_id'      => $attachmentId,
            'download_url'       => '/dispensation/' . rawurlencode($disDetNro)
                                    . '/attachments/download/' . rawurlencode($attachmentId),
            'tipo_almacenamiento'=> (string) ($attachment['TipoAlmacenamiento'] ?? ''),
            'dis_det_nro'        => $disDetNro,
            'numero_factura'     => $context['numeroFactura'],
            'fac_sec'            => $context['facSec'],
            'fac_nit_sec'        => $context['nitSec'],
            'extraction_contract' => $configuredDocument['extraction_contract'],
            'fields_config'      => $configuredDocument['fields'],
            'visual_checks'      => $configuredDocument['visual_checks'],
            'system_prompt'      => $context['auditConfig']['systemPrompt'] ?? null,
            'fuente_verdad'      => $context['fuenteVerdad'],
            'target_context'     => $targetContext,
            'target_context_hash'=> $targetContextHash,
            'contract_hash'      => $contractHash,
        ];
    }

    /**
     * Construye el FDV-lite (target_context) por documento.
     *
     * Extrae solo los campos configurados para ese documento desde la fuente de verdad,
     * incluyendo tipoCampo, valueType y rol. Esto permite:
     * 1. Inyectar contexto mínimo en el prompt de Gemini (anti-sesgo)
     * 2. Calcular target_context_hash para invalidar cache al cambiar FDV
     * 3. Trazar qué datos tenía la FDV al momento de la extracción
     *
     * @param  array<int,array<string,mixed>> $fieldsConfig   Campos activos del audit-config
     * @param  array<int,array<string,mixed>> $visualChecks   Checks visuales activos
     * @param  array<string,mixed>            $fuenteVerdad   FDV completa {header, items}
     * @return array{fields:array<string,mixed>,items:array<string,mixed>,visualChecks:array<string,mixed>}
     */
    private function buildTargetContext(string $documentName, array $fieldsConfig, array $visualChecks, array $fuenteVerdad): array
    {
        $header = is_array($fuenteVerdad['header'] ?? null) ? $fuenteVerdad['header'] : [];
        $items  = is_array($fuenteVerdad['items'] ?? null) ? $fuenteVerdad['items'] : [];

        $targetFields = [];
        $targetItems  = [];

        foreach ($fieldsConfig as $fieldConfig) {
            $name      = trim((string) ($fieldConfig['campoNombre'] ?? ''));
            $tipoCampo = (string) ($fieldConfig['tipoCampo'] ?? 'E');
            $rol       = (string) ($fieldConfig['rol'] ?? 'AUTORITATIVO');
            if ($name === '') {
                continue;
            }

            $valueType = AuditFieldValueType::fromFieldName($name);
            $isItem    = $this->contractBuilder->isItemField(
                $documentName, $name, $tipoCampo
            );

            if ($isItem) {
                $valores = [];
                foreach ($items as $item) {
                    $v = trim((string) ($item[$name] ?? ''));
                    if ($v !== '') {
                        $valores[] = $v;
                    }
                }
                $targetItems[$name] = [
                    'tipoCampo'           => $tipoCampo,
                    'valueType'           => $valueType->value,
                    'rol'                 => $rol,
                    'valoresFuenteVerdad' => $valores,
                ];
            } else {
                $valor = trim((string) ($header[$name] ?? ''));
                $targetFields[$name] = [
                    'tipoCampo'          => $tipoCampo,
                    'valueType'          => $valueType->value,
                    'rol'                => $rol,
                    'valorFuenteVerdad'  => $valor !== '' ? $valor : null,
                ];
            }
        }

        $targetVisualChecks = [];
        foreach ($visualChecks as $check) {
            $checkName = trim((string) ($check['check'] ?? ''));
            if ($checkName !== '') {
                $targetVisualChecks[$checkName] = [
                    'tipoCampo' => 'V',
                    'rol'       => (string) ($check['rol'] ?? 'AUTORITATIVO'),
                    'expected'  => 'PRESENTE',
                ];
            }
        }

        return [
            'fields'       => $targetFields,
            'items'        => $targetItems,
            'visualChecks' => $targetVisualChecks,
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
                $normalized[] = array_merge(
                    ['rol' => 'AUTORITATIVO', 'omitirSi' => null],
                    $field
                );
            } elseif (is_string($field) && trim($field) !== '') {
                $normalized[] = [
                    'campoNombre' => trim($field),
                    'tipoCampo'   => 'E',
                    'severity'    => 'alta',
                    'rol'         => 'AUTORITATIVO',
                    'omitirSi'    => null,
                ];
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
                'rol' => isset($check['rol']) && is_string($check['rol']) && trim($check['rol']) !== ''
                    ? strtoupper(trim($check['rol']))
                    : 'AUTORITATIVO',
                'omitirSi' => $check['omitirSi'] ?? null,
            ];
        }

        return $normalized;
    }
}
