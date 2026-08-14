<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\GeminiCallMetrics;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\GeminiConfig;
use App\Services\Audit\Telemetry\TelemetryPublisher;
use Core\Env;
use Core\Logger;
use Core\RedisUnavailableException;
use RuntimeException;

/**
 * Worker de extracción documental con Gemini.
 *
 * Responsabilidades de este worker (post-refactoring):
 * - Leer el BLOB del documento desde Redis.
 * - Orquestar integridad, caché, llamada a Gemini y persistencia de estado.
 * - Publicar evento document_extracted o document_rejected.
 *
 * Responsabilidades delegadas:
 * - Caché Redis:       ExtractionCacheManager
 * - Construcción de prompts: ExtractionPromptBuilder
 * - Parsing Gemini:   GeminiResponseParser
 *
 * @see ExtractionCacheManager
 * @see ExtractionPromptBuilder
 * @see GeminiResponseParser
 */
final class DocumentExtractionWorker extends AuditEventConsumer
{
    private const DEFAULT_CACHE_TTL         = 86400;
    private const DEFAULT_EXTRACTOR_VERSION = 'gemini-3.6-flash';

    private AuditStateStore $stateStore;
    private GeminiGateway $gateway;
    private TelemetryPublisher $telemetryPublisher;
    private string $consumerName;
    private ExtractionCacheManager $cacheManager;
    private ExtractionPromptBuilder $promptBuilder;
    private GeminiResponseParser $responseParser;

    public function __construct(
        ?AuditStateStore       $stateStore          = null,
        ?GeminiGateway         $gateway             = null,
        ?\Core\RedisClient     $redis               = null,
        ?AuditEventPublisher   $publisher           = null,
        ?string                $consumerName        = null,
        ?int                   $cacheTtl            = null,
        ?TelemetryPublisher    $telemetryPublisher  = null,
        ?ExtractionCacheManager  $cacheManager      = null,
        ?ExtractionPromptBuilder $promptBuilder      = null,
        ?GeminiResponseParser    $responseParser     = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore          = $stateStore ?? new AuditStateStore($this->redis);
        $this->gateway             = $gateway    ?? GeminiGateway::create();
        $this->telemetryPublisher  = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->consumerName        = $consumerName ?? self::defaultConsumerName(AuditEventPublisher::GROUP_EXTRACTORS);

        $resolvedTtl = $cacheTtl ?? (int) Env::get('AUDIT_EXTRACTION_CACHE_TTL', self::DEFAULT_CACHE_TTL);
        $resolvedTtl = $resolvedTtl > 0 ? $resolvedTtl : self::DEFAULT_CACHE_TTL;

        $rawVersion       = trim((string) Env::get('AUDIT_VERSION_EXTRACTOR', self::DEFAULT_EXTRACTOR_VERSION));
        $sanitized        = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $rawVersion);
        $extractorVersion = (is_string($sanitized) && $sanitized !== '')
            ? $sanitized
            : self::DEFAULT_EXTRACTOR_VERSION;

        $this->promptBuilder  = $promptBuilder  ?? new ExtractionPromptBuilder();
        $this->cacheManager   = $cacheManager   ?? new ExtractionCacheManager($this->redis, $resolvedTtl, $extractorVersion);
        $this->responseParser = $responseParser ?? new GeminiResponseParser($this->gateway, $this->redis, $this->promptBuilder);
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_DOCUMENTS;
    }

    protected function group(): string
    {
        return AuditEventPublisher::GROUP_EXTRACTORS;
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    // ─── Punto de entrada del evento ─────────────────────────────────────────

    protected function handle(AuditEvent $event): void
    {
        if ($event->eventType !== AuditEvent::TYPE_DOCUMENT_DOWNLOADED) {
            return;
        }

        $totalStartTime = microtime(true);
        if ($event->auditId === null || $event->documentId === null) {
            throw new RuntimeException('document_downloaded sin audit_id o document_id');
        }

        $payload       = $event->payload;
        $this->requiredString($payload, 'attachment_id'); // guarda contractual — campo obligatorio en document_downloaded
        $disDetNro     = $this->requiredString($payload, 'dis_det_nro');
        $contract      = $this->requiredArray($payload, 'extraction_contract');
        $documentType  = $this->resolveDocumentType($payload);
        $contractHash  = (string) ($payload['contract_hash'] ?? $contract['contract_hash'] ?? '');
        $blobKey       = $this->requiredString($payload, 'blob_reference_key');
        $documentHash  = $this->requiredString($payload, 'document_hash');

        $telemetryMeta       = ['worker' => $this->consumer(), 'document_type' => $documentType];
        $extractionStartedAt = hrtime(true);

        $this->telemetryPublisher->started(
            $event->auditId,
            'extraction',
            $event->documentId,
            $disDetNro,
            $telemetryMeta,
            $event->jobId
        );

        try {
            $document  = $this->readDownloadedDocument($blobKey, $documentHash);
            $integrity = DocumentIntegrityValidator::validate($document);

            if (!$integrity['valid']) {
                $this->handleRejectedDocument($event, $payload, $document, $integrity);
                $this->telemetryPublisher->rejected(
                    $event->auditId,
                    'extraction',
                    self::elapsedMs($extractionStartedAt),
                    $event->documentId,
                    $disDetNro,
                    array_merge($telemetryMeta, ['reason' => (string) ($integrity['reason'] ?? '')]),
                    $event->jobId
                );
                return;
            }

            $userPrompt        = $this->promptBuilder->buildUserPrompt($documentType, $payload, $contract);
            $systemPrompt      = $this->promptBuilder->buildSystemPrompt($payload, $contract);
            $promptContextHash = $this->promptBuilder->promptContextHash($userPrompt, $systemPrompt);
            $cacheKey          = $this->cacheManager->computeCacheKey($documentHash, $contractHash, $promptContextHash);

            try {
                $extraction = $this->resolveExtraction(
                    $cacheKey,
                    $document,
                    $documentType,
                    $payload,
                    $contract,
                    $userPrompt,
                    $systemPrompt,
                    $disDetNro,
                    $event
                );
            } catch (RuntimeException $geminiError) {
                if ($this->isGeminiDocumentContentError($geminiError)) {
                    $reason    = $this->classifyGeminiContentError($geminiError);
                    $integrity = [
                        'valid'         => false,
                        'reason'        => $reason,
                        'declared_mime' => $document['mime'] ?? '',
                        'detected_mime' => null,
                        'size_bytes'    => strlen(base64_decode($document['data'] ?? '', true) ?: ''),
                    ];
                    $this->handleRejectedDocument($event, $payload, $document, $integrity);
                    $this->telemetryPublisher->rejected(
                        $event->auditId,
                        'extraction',
                        self::elapsedMs($extractionStartedAt),
                        $event->documentId,
                        $disDetNro,
                        array_merge($telemetryMeta, ['reason' => $reason]),
                        $event->jobId
                    );
                    return;
                }
                throw $geminiError;
            }

            $extractionDurationMs = (int) ((microtime(true) - $totalStartTime) * 1000);
            $documentState        = $this->buildDocumentState(
                $documentHash,
                $document,
                $extraction,
                $extractionDurationMs,
                $contractHash,
                $promptContextHash
            );

            if (!$this->stateStore->markDocumentExtracted($event->auditId, $event->documentId, $documentState)) {
                throw new RuntimeException('No se pudo persistir la extracción del documento en Redis');
            }

            $this->publishDocumentExtracted($event, $payload, $documentState);

            $totalDurationMs = (microtime(true) - $totalStartTime) * 1000;
            $this->logDocumentExtracted($event, $documentState, $totalDurationMs);
            $this->telemetryPublisher->completed(
                $event->auditId,
                'extraction',
                self::elapsedMs($extractionStartedAt),
                $event->documentId,
                $disDetNro,
                array_merge($telemetryMeta, [
                    'cache_hit'          => (bool) ($documentState['cache_hit'] ?? false),
                    'gemini_duration_ms' => (int) ($documentState['gemini_duration_ms'] ?? 0),
                ]),
                $event->jobId
            );
        } catch (\Throwable $error) {
            $this->telemetryPublisher->failed(
                $event->auditId,
                'extraction',
                self::elapsedMs($extractionStartedAt),
                $event->documentId,
                $disDetNro,
                array_merge($telemetryMeta, ['error_class' => get_class($error)]),
                $event->jobId
            );
            throw $error;
        }
    }

    // ─── Lectura del BLOB ────────────────────────────────────────────────────

    /**
     * @return array{mime:string,data:string,duration_ms?:int}
     */
    private function readDownloadedDocument(string $blobKey, string $expectedDocumentHash): array
    {
        self::assertHash($expectedDocumentHash);

        try {
            $documentJson = $this->redis->get($blobKey);
        } catch (RedisUnavailableException $error) {
            throw new RuntimeException('Redis no disponible al leer BLOB documental', 0, $error);
        }

        if ($documentJson === null || $documentJson === '') {
            throw new RuntimeException("BLOB expirado o no encontrado en Redis para key: {$blobKey}");
        }

        $document = json_decode($documentJson, true);
        if (!is_array($document)) {
            throw new RuntimeException("Formato de BLOB inválido en Redis para key: {$blobKey}");
        }

        $mime = $document['mime'] ?? null;
        $data = $document['data'] ?? null;
        if (!is_string($mime) || trim($mime) === '') {
            throw new RuntimeException("BLOB documental sin MIME válido en Redis para key: {$blobKey}");
        }
        if (!is_string($data) || $data === '') {
            throw new RuntimeException("BLOB documental sin data base64 en Redis para key: {$blobKey}");
        }

        $actualDocumentHash = hash('sha256', $data);
        if (!hash_equals($expectedDocumentHash, $actualDocumentHash)) {
            throw new RuntimeException('document_hash no coincide con el BLOB descargado');
        }

        return $document;
    }

    // ─── Extracción (caché + Gemini) ─────────────────────────────────────────

    /**
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $payload
     * @param  array<string,mixed> $contract
     * @return array{
     *   extracted:array<string,mixed>,
     *   cache_hit:bool,
     *   gemini_duration_ms:int,
     *   gemini_metrics:?array<string,mixed>
     * }
     */
    private function resolveExtraction(
        string $cacheKey,
        array $document,
        string $documentType,
        array $payload,
        array $contract,
        string $userPrompt,
        string $systemPrompt,
        string $disDetNro,
        AuditEvent $event
    ): array {
        $extracted = $this->cacheManager->get($cacheKey);
        if ($extracted !== null) {
            return [
                'extracted'          => $extracted,
                'cache_hit'          => true,
                'gemini_duration_ms' => 0,
                'gemini_metrics'     => GeminiCallMetrics::cacheHit([
                    'task_type'     => GeminiGateway::TASK_EXTRACTION,
                    'document_type' => $documentType,
                ]),
            ];
        }

        $response = $this->gateway->sendWithFunctionCalling(
            $userPrompt,
            [[
                'mime'  => $document['mime'],
                'data'  => $document['data'],
                'label' => $documentType,
            ]],
            $systemPrompt,
            [['functionDeclarations' => $this->promptBuilder->contractFunctionDeclarations($contract)]],
            $this->promptBuilder->buildToolConfig($contract),
            GeminiGateway::TASK_EXTRACTION,
            GeminiConfig::generationOverridesFromEnv('GEMINI_EXTRACTION', ['maxOutputTokens' => 4096]),
            [
                'dis_det_nro'   => $disDetNro,
                'audit_id'      => $event->auditId,
                'document_id'   => $event->documentId,
                'document_type' => $documentType,
            ]
        );

        $geminiDurationMs = (int) ($response['X-Audit-Metrics']['duration_ms'] ?? 0);
        $geminiMetrics    = is_array($response['X-Audit-Metrics'] ?? null) ? $response['X-Audit-Metrics'] : null;
        unset($response['X-Audit-Metrics']);

        $extracted = $this->responseParser->parse(
            $response,
            $contract,
            $document,
            $documentType,
            $payload,
            [
                'dis_det_nro'   => $disDetNro,
                'audit_id'      => $event->auditId,
                'document_id'   => $event->documentId,
                'document_type' => $documentType,
            ]
        );

        $extracted = $this->annotateItemSegmentation($documentType, $payload, $contract, $extracted);
        $this->cacheManager->put($cacheKey, $extracted);

        return [
            'extracted'          => $extracted,
            'cache_hit'          => false,
            'gemini_duration_ms' => $geminiDurationMs,
            'gemini_metrics'     => $geminiMetrics,
        ];
    }

    // ─── Estado del documento ────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $document
     * @param array{extracted:array<string,mixed>,cache_hit:bool,gemini_duration_ms:int,gemini_metrics:?array<string,mixed>} $extraction
     * @return array<string,mixed>
     */
    private function buildDocumentState(
        string $documentHash,
        array $document,
        array $extraction,
        int $extractionDurationMs,
        string $contractHash = '',
        string $promptContextHash = ''
    ): array {
        $documentState = [
            'status'                 => 'extracted',
            'document_hash'          => $documentHash,
            'contract_hash'          => $contractHash,
            'prompt_context_hash'    => $promptContextHash,
            'cache_hit'              => $extraction['cache_hit'],
            'mime'                   => $document['mime'],
            'extraction_result'      => $extraction['extracted'],
            'extracted_at'           => gmdate('Y-m-d\TH:i:s\Z'),
            'extraction_duration_ms' => $extractionDurationMs,
            'download_duration_ms'   => (int) ($document['duration_ms'] ?? 0),
            'gemini_duration_ms'     => $extraction['gemini_duration_ms'],
        ];

        if ($extraction['gemini_metrics'] !== null) {
            $documentState['gemini_metrics'] = $extraction['gemini_metrics'];
        }

        return $documentState;
    }

    // ─── Publicación de eventos ──────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $documentState
     */
    private function publishDocumentExtracted(AuditEvent $event, array $payload, array $documentState): void
    {
        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            documentId: $event->documentId,
            payload: array_merge($payload, $documentState),
            parentEventId: $event->eventId,
        ));
    }

    /** @param array<string,mixed> $documentState */
    private function logDocumentExtracted(AuditEvent $event, array $documentState, float $totalDurationMs): void
    {
        $downloadDurationMs  = (int) ($documentState['download_duration_ms'] ?? 0);
        $geminiDurationMs    = (int) ($documentState['gemini_duration_ms'] ?? 0);
        $localCpuDurationMs  = $totalDurationMs - $downloadDurationMs - $geminiDurationMs;

        Logger::info('Document extraction event processed', [
            'auditId'               => $event->auditId,
            'documentId'            => $event->documentId,
            'cache_hit'             => (bool) ($documentState['cache_hit'] ?? false),
            'total_duration_ms'     => (int) $totalDurationMs,
            'download_duration_ms'  => $downloadDurationMs,
            'gemini_duration_ms'    => $geminiDurationMs,
            'local_cpu_duration_ms' => (int) $localCpuDurationMs,
        ]);
    }

    // ─── Manejo de documentos rechazados ─────────────────────────────────────

    /**
     * @param array<string,mixed> $integrity
     */
    private function handleRejectedDocument(
        AuditEvent $event,
        array $payload,
        array $document,
        array $integrity
    ): void {
        $documentType = $this->resolveDocumentType($payload);
        $reason       = (string) ($integrity['reason'] ?? '');
        if (!DocumentRejectionReason::isAllowed($reason)) {
            throw new \DomainException('La extracción intentó publicar una razón documental no permitida.');
        }

        $patch = [
            'rejection_class'      => DocumentRejectionReason::REJECTION_CLASS,
            'rejection_reason'     => $reason,
            'rejection_origin'     => static::class,
            'document_type'        => $documentType,
            'mime'                 => (string) ($integrity['declared_mime'] ?? $document['mime'] ?? ''),
            'detected_mime'        => $integrity['detected_mime'] ?? null,
            'size_bytes'           => (int) ($integrity['size_bytes'] ?? 0),
            'download_duration_ms' => (int) ($document['duration_ms'] ?? 0),
            'rejected_at'          => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (!$this->stateStore->markDocumentRejected(
            (string) $event->auditId,
            (string) $event->documentId,
            $patch
        )) {
            throw new RuntimeException('No se pudo marcar el documento como rechazado en Redis');
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            documentId: $event->documentId,
            payload: array_merge($payload, $patch),
            parentEventId: $event->eventId,
        ));

        Logger::info('Document rejected by integrity validation', [
            'auditId'    => $event->auditId,
            'documentId' => $event->documentId,
            'reason'     => $reason,
            'mime'       => $patch['mime'],
        ]);
    }

    // ─── Helpers de segmentación ──────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $extracted
     * @return array<string,mixed>
     */
    private function annotateItemSegmentation(
        string $documentType,
        array $payload,
        array $contract,
        array $extracted
    ): array {
        if (!$this->promptBuilder->contractRequiresFunction($contract, DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS)) {
            return $extracted;
        }

        $sourceTruthItems   = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];
        $fieldsConfig       = is_array($payload['fields_config'] ?? null) ? $payload['fields_config'] : [];
        $aggregatedItems    = FdvItemAggregator::aggregate($sourceTruthItems, $fieldsConfig, $documentType);
        $items              = $extracted['items'] ?? [];
        $extractedItemsCount = is_array($items) ? count($items) : 0;
        $expectedItemsCount  = count($aggregatedItems);

        if ($extractedItemsCount < $expectedItemsCount) {
            $extracted['extraction_warnings']   = $extracted['extraction_warnings'] ?? [];
            $extracted['extraction_warnings'][] = [
                'code'                  => 'ITEM_SEGMENTATION_INCOMPLETE',
                'severity'              => 'warning',
                'scope'                 => 'items',
                'document_type'         => $documentType,
                'expected_items_count'  => $expectedItemsCount,
                'extracted_items_count' => $extractedItemsCount,
            ];
        }

        return $extracted;
    }

    // ─── Clasificación de errores Gemini ──────────────────────────────────────

    private function isGeminiDocumentContentError(RuntimeException $e): bool
    {
        if ($e->getCode() !== 400) {
            return false;
        }

        $msg = $e->getMessage();
        return stripos($msg, 'no pages') !== false
            || stripos($msg, 'could not be decoded') !== false;
    }

    private function classifyGeminiContentError(RuntimeException $e): string
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'no pages') !== false) {
            return DocumentRejectionReason::EMPTY_PDF_NO_PAGES;
        }
        if (stripos($msg, 'could not be decoded') !== false) {
            return DocumentRejectionReason::GEMINI_DECODE_FAILURE;
        }

        throw new \DomainException('Error de contenido Gemini sin clasificación permitida.', 0, $e);
    }

    // ─── Utilidades ──────────────────────────────────────────────────────────

    private static function assertHash(string $documentHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $documentHash)) {
            throw new RuntimeException("document_hash inválido: {$documentHash}");
        }
    }

    private function resolveDocumentType(array $payload): string
    {
        return trim((string) ($payload['tipo_documento'] ?? 'DOCUMENTO'));
    }

    private static function elapsedMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function requiredArray(array $payload, string $key, ?string $errorMessage = null): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException($errorMessage ?? "document_downloaded sin {$key}");
        }

        return $value;
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("document_downloaded sin {$key}");
        }

        return $value;
    }
}
