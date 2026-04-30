<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use InvalidArgumentException;
use RuntimeException;

final class DocumentAuditOrchestrator extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private AuditDataServiceInterface $dataService;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore             $stateStore   = null,
        ?AuditDataServiceInterface   $dataService  = null,
        ?\Core\RedisClient           $redis        = null,
        ?AuditEventPublisher         $publisher    = null,
        ?string                      $consumerName = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore   = $stateStore   ?? new AuditStateStore($this->redis);
        $this->dataService  = $dataService  ?? new AuditDataService();
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
            'schemaDocuments' => $this->buildSchema($auditConfig),
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
            'document_id'        => $documentId,
            'doc_id'             => (string) $schemaDocument['doc_id'],
            'tipo_documento'     => $schemaDocument['document_name'],
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
            'extraction_schema'  => $schemaDocument['extraction_schema'],
            'fields_config'      => $schemaDocument['fields'],
            'visual_checks'      => $schemaDocument['visual_checks'],
            'system_prompt'      => $context['auditConfig']['systemPrompt'] ?? null,
            'fuente_verdad'      => $context['fuenteVerdad'],
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
        $normalizedCandidates = array_map([self::class, 'normalizeName'], $candidates);

        foreach ($attachments as $attachment) {
            $attachmentNames = array_filter([
                (string) ($attachment['nombre_documento'] ?? ''),
                (string) ($attachment['nombre_alternativo'] ?? ''),
            ]);

            foreach ($attachmentNames as $attachmentName) {
                if (in_array(self::normalizeName($attachmentName), $normalizedCandidates, true)) {
                    return $attachment;
                }
            }
        }

        return null;
    }

    private function buildSchema(array $auditConfig): array
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
            $fieldNames = $this->extractFieldNames($fields);

            $normalized[] = [
                'doc_id' => $docId,
                'document_name' => trim($documentName),
                'document_name_normalized' => self::normalizeName($documentName),
                'fields' => $fields,
                'extraction_schema' => $this->buildExtractionSchema($fieldNames, $visualChecks),
                'visual_checks' => $visualChecks,
            ];
        }

        usort(
            $normalized,
            static fn(array $a, array $b): int => $a['doc_id'] <=> $b['doc_id']
        );

        return $normalized;
    }

    private function buildExtractionSchema(array $fieldNames, array $visualChecks = []): array
    {
        $fieldProperties = $this->buildFieldProperties($fieldNames);

        return [
            'name'        => 'extract_document_data',
            'description' => 'Extrae datos estructurados y verificaciones visuales de un documento de auditoria farmaceutica.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'fields'           => ['type' => 'object', 'properties' => $fieldProperties],
                    'items'            => ['type' => 'array',  'items' => ['type' => 'object', 'properties' => $fieldProperties]],
                    'visual_checks'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'check'     => ['type' => 'string'],
                                'presente'  => ['type' => 'boolean'],
                                'detalle'   => ['type' => 'string'],
                                'severidad' => ['type' => 'string'],
                            ],
                            'required' => ['check', 'presente'],
                        ],
                    ],
                    'document_quality' => [
                        'type' => 'string',
                        'enum' => ['legible', 'parcialmente_legible', 'ilegible'],
                    ],
                    'quality_notes'    => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['fields', 'visual_checks', 'document_quality'],
            ],
        ];
    }

    private function buildFieldProperties(array $fieldNames): array
    {
        $properties = [];
        foreach ($fieldNames as $name) {
            $properties[$name] = ['type' => 'string'];
        }
        return $properties;
    }

    public static function normalizeName(string $value): string
    {
        $upper = strtoupper(trim($value));
        $ascii = strtr($upper, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U',
            'Ñ' => 'N',
            '_' => ' ',
            '-' => ' ',
        ]);

        $ascii = preg_replace('/\s+/', ' ', $ascii);
        return trim((string) $ascii);
    }

    private function extractFieldNames(array $fields): array
    {
        return array_map(fn(array $f): string => $f['campoNombre'], $fields);
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
            ];
        }

        return $normalized;
    }
}
