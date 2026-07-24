<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\DocumentQuality;
use App\Services\Audit\GeminiConfig;
use App\Services\Audit\GeminiCallMetrics;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\Telemetry\TelemetryPublisher;
use Core\Env;
use Core\Logger;
use Core\RedisUnavailableException;
use RuntimeException;

final class DocumentExtractionWorker extends AuditEventConsumer
{
    private const DEFAULT_SYSTEM_PROMPT = <<<TEXT
        Eres un extractor documental determinístico.
        Analiza un único documento.
        No inventes valores.
        Si un dato no es visible o no es legible, omítelo o usa el valor nativo null de JSON (sin comillas).
        Para verificaciones visuales usa presente=false cuando el elemento no sea visible.
        Invoca cada función permitida exactamente una vez en el mismo turno.
        No devuelvas texto libre; responde únicamente con function calls.
    TEXT;

    private const DEFAULT_CACHE_TTL = 86400;
    private const DEFAULT_EXTRACTOR_VERSION = 'gemini-3.x-parallel-fc-v1';
    private const PROMPT_DEDUP_MIN_CHARS = 15;
    private const ACCEPTED_FINISH_REASON = 'STOP';
    private const ERROR_MISSING_CANDIDATE = 'GEMINI_EXTRACTION_MISSING_CANDIDATE';
    private const ERROR_UNSAFE_FINISH_REASON = 'GEMINI_EXTRACTION_UNSAFE_FINISH_REASON';
    private const ERROR_MISSING_FUNCTION_CALL = 'GEMINI_EXTRACTION_MISSING_FUNCTION_CALL';
    private const ERROR_INVALID_ARGS = 'GEMINI_EXTRACTION_INVALID_ARGS';
    private const ERROR_UNEXPECTED_FUNCTION_CALL = 'GEMINI_EXTRACTION_UNEXPECTED_FUNCTION_CALL';
    private const ERROR_DUPLICATE_FUNCTION_CALL = 'GEMINI_EXTRACTION_DUPLICATE_FUNCTION_CALL';

    private AuditStateStore $stateStore;
    private GeminiGateway $gateway;
    private TelemetryPublisher $telemetryPublisher;
    private int $cacheTtl;
    private string $extractorVersion;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore                    $stateStore   = null,
        ?GeminiGateway                      $gateway      = null,
        ?\Core\RedisClient                  $redis        = null,
        ?AuditEventPublisher                $publisher    = null,
        ?string                             $consumerName = null,
        ?int                                $cacheTtl     = null,
        ?TelemetryPublisher                 $telemetryPublisher = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore   = $stateStore ?? new AuditStateStore($this->redis);
        $this->gateway      = $gateway    ?? GeminiGateway::create();
        $this->telemetryPublisher = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->consumerName = $consumerName ?? self::defaultConsumerName('extractor');

        $resolvedTtl          = $cacheTtl ?? (int) Env::get('AUDIT_EXTRACTION_CACHE_TTL', self::DEFAULT_CACHE_TTL);
        $this->cacheTtl       = $resolvedTtl > 0 ? $resolvedTtl : self::DEFAULT_CACHE_TTL;

        $rawVersion           = trim((string) Env::get('AUDIT_VERSION_EXTRACTOR', self::DEFAULT_EXTRACTOR_VERSION));
        $sanitized            = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $rawVersion);
        $this->extractorVersion = (is_string($sanitized) && $sanitized !== '')
            ? $sanitized
            : self::DEFAULT_EXTRACTOR_VERSION;
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_DOCUMENTS;
    }

    protected function group(): string
    {
        return 'extractors';
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

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
        $attachmentId  = $this->requiredString($payload, 'attachment_id');
        $disDetNro     = $this->requiredString($payload, 'dis_det_nro');
        $contract      = $this->requiredArray($payload, 'extraction_contract');
        $documentType  = $this->resolveDocumentType($payload);
        $contractHash  = (string) ($payload['contract_hash'] ?? $contract['contract_hash'] ?? '');
        $blobKey       = $this->requiredString($payload, 'blob_reference_key');
        $documentHash  = $this->requiredString($payload, 'document_hash');

        $telemetryMeta = [
            'worker' => $this->consumer(),
            'document_type' => $documentType,
        ];

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
            $document = $this->readDownloadedDocument($blobKey, $documentHash);
            $integrity = DocumentIntegrityValidator::validate($document);
            if (!$integrity['valid']) {
                $this->handleRejectedDocument($event, $payload, $document, $integrity);
                $this->telemetryPublisher->rejected(
                    $event->auditId,
                    'extraction',
                    self::elapsedMs($extractionStartedAt),
                    $event->documentId,
                    $disDetNro,
                    array_merge($telemetryMeta, [
                        'reason' => (string) ($integrity['reason'] ?? 'UNKNOWN_FILE_INTEGRITY_FAILURE'),
                    ]),
                    $event->jobId
                );
                return;
            }

            $userPrompt = $this->buildUserPrompt($documentType, $payload, $contract);
            $systemPrompt = $this->buildSystemPrompt($payload, $contract);
            $promptContextHash = $this->promptContextHash($userPrompt, $systemPrompt);
            $compositeCacheKey = $this->compositeCacheKey($documentHash, $contractHash, $promptContextHash);

            try {
                $extraction = $this->resolveExtraction(
                    $compositeCacheKey,
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
                    $reason = $this->classifyGeminiContentError($geminiError);
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

            $documentState = $this->buildDocumentState(
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
                    'cache_hit' => (bool) ($documentState['cache_hit'] ?? false),
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
        $extracted = $this->cacheGet($cacheKey);
        if ($extracted !== null) {
            return [
                'extracted' => $extracted,
                'cache_hit' => true,
                'gemini_duration_ms' => 0,
                'gemini_metrics' => GeminiCallMetrics::cacheHit([
                    'task_type' => GeminiGateway::TASK_EXTRACTION,
                    'document_type' => $documentType,
                ]),
            ];
        }

        $response = $this->gateway->sendWithFunctionCalling(
            $userPrompt,
            [[
                'mime' => $document['mime'],
                'data' => $document['data'],
                'label' => $documentType,
            ]],
            $systemPrompt,
            [['functionDeclarations' => $this->contractFunctionDeclarations($contract)]],
            $this->buildToolConfig($contract),
            GeminiGateway::TASK_EXTRACTION,
            GeminiConfig::generationOverridesFromEnv('GEMINI_EXTRACTION', [
                'maxOutputTokens' => 4096,
            ]),
            [
                'dis_det_nro' => $disDetNro,
                'audit_id' => $event->auditId,
                'document_id' => $event->documentId,
                'document_type' => $documentType,
            ]
        );

        $geminiDurationMs = (int) ($response['X-Audit-Metrics']['duration_ms'] ?? 0);
        $geminiMetrics = is_array($response['X-Audit-Metrics'] ?? null)
            ? $response['X-Audit-Metrics']
            : null;
        unset($response['X-Audit-Metrics']);

        $extracted = $this->parseGeminiResponse($response, $contract);
        $extracted = $this->annotateItemSegmentation($documentType, $payload, $contract, $extracted);
        $this->cachePut($cacheKey, $extracted);

        return [
            'extracted' => $extracted,
            'cache_hit' => false,
            'gemini_duration_ms' => $geminiDurationMs,
            'gemini_metrics' => $geminiMetrics,
        ];
    }

    /**
     * @param  array<string,mixed> $document
     * @param  array{
     *   extracted:array<string,mixed>,
     *   cache_hit:bool,
     *   gemini_duration_ms:int,
     *   gemini_metrics:?array<string,mixed>
     * } $extraction
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
            'status'                  => 'extracted',
            'document_hash'           => $documentHash,
            'contract_hash'           => $contractHash,
            'prompt_context_hash'      => $promptContextHash,
            'cache_hit'               => $extraction['cache_hit'],
            'mime'                    => $document['mime'],
            'extraction_result'       => $extraction['extracted'],
            'extracted_at'            => gmdate('Y-m-d\TH:i:s\Z'),
            'extraction_duration_ms'  => $extractionDurationMs,
            'download_duration_ms'    => (int) ($document['duration_ms'] ?? 0),
            'gemini_duration_ms'      => $extraction['gemini_duration_ms'],
        ];

        if ($extraction['gemini_metrics'] !== null) {
            $documentState['gemini_metrics'] = $extraction['gemini_metrics'];
        }

        return $documentState;
    }

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

    /**
     * @param array<string,mixed> $documentState
     */
    private function logDocumentExtracted(AuditEvent $event, array $documentState, float $totalDurationMs): void
    {
        $downloadDurationMs = (int) ($documentState['download_duration_ms'] ?? 0);
        $geminiDurationMs = (int) ($documentState['gemini_duration_ms'] ?? 0);
        $localCpuDurationMs = $totalDurationMs - $downloadDurationMs - $geminiDurationMs;

        Logger::info('Document extraction event processed', [
            'auditId' => $event->auditId,
            'documentId' => $event->documentId,
            'cache_hit' => (bool) ($documentState['cache_hit'] ?? false),
            'total_duration_ms' => (int) $totalDurationMs,
            'download_duration_ms' => $downloadDurationMs,
            'gemini_duration_ms' => $geminiDurationMs,
            'local_cpu_duration_ms' => (int) $localCpuDurationMs,
        ]);
    }

    /**
     * Maneja el rechazo de un documento por fallo de validación de integridad.
     *
     * Marca el documento como rechazado en Redis y publica un evento explícito
     * para que la etapa de reglas genere el hallazgo canónico sin invocar Gemini.
     *
     * @param array<string,mixed> $integrity
     */
    private function handleRejectedDocument(
        AuditEvent $event,
        array $payload,
        array $document,
        array $integrity
    ): void {
        $documentType = $this->resolveDocumentType($payload);
        $reason = (string) ($integrity['reason'] ?? 'UNKNOWN_FILE_INTEGRITY_FAILURE');

        $patch = [
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

        $this->publishDocumentRejected($event, $payload, $patch);

        Logger::info('Document rejected by integrity validation', [
            'auditId'    => $event->auditId,
            'documentId' => $event->documentId,
            'reason'     => $reason,
            'mime'       => $patch['mime'],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $rejectionState
     */
    private function publishDocumentRejected(AuditEvent $event, array $payload, array $rejectionState): void
    {
        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            documentId: $event->documentId,
            payload: array_merge($payload, $rejectionState),
            parentEventId: $event->eventId,
        ));
    }

    private function cacheGet(string $cacheKey): ?array
    {
        try {
            $raw = $this->redis->get($cacheKey);
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer extraction cache', 0, $e);
        }

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Extraction cache corrupto en Redis');
        }

        return $decoded;
    }

    private function cachePut(string $cacheKey, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar extraction cache');
        }

        if (!$this->redis->set($cacheKey, $encoded, $this->cacheTtl)) {
            throw new RuntimeException('No se pudo escribir extraction cache en Redis');
        }
    }

    private static function assertHash(string $documentHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $documentHash)) {
            throw new RuntimeException("document_hash inválido: {$documentHash}");
        }
    }

    /**
     * Construye la cache key compuesta.
     * Cualquier cambio en documento, contrato, prompt efectivo o versión invalida el cache.
     */
    private function compositeCacheKey(string $documentHash, string $contractHash, string $promptContextHash): string
    {
        self::assertHash($documentHash);

        if ($contractHash === '' || $promptContextHash === '') {
            throw new RuntimeException("Faltan hashes de contrato o prompt para documentHash {$documentHash}");
        }

        $composite = hash('sha256', $documentHash . $contractHash . $promptContextHash . $this->extractorVersion);
        return "extraction:cache:v1:{$composite}";
    }

    private function promptContextHash(string $userPrompt, string $systemPrompt): string
    {
        return DocumentExtractionContractBuilder::hashPayload([
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
        ]);
    }

    private function buildSystemPrompt(array $payload, array $contract): string
    {
        $customPrompt = trim((string) ($payload['system_prompt'] ?? ''));
        if ($customPrompt === '') {
            return self::DEFAULT_SYSTEM_PROMPT;
        }

        $customPrompt = $this->removeContractRedundantPromptSentences(
            $customPrompt,
            $this->contractDescriptionTexts($contract, $payload)
        );

        if ($customPrompt === '') {
            return self::DEFAULT_SYSTEM_PROMPT;
        }

        return self::DEFAULT_SYSTEM_PROMPT . "\n\n" . $customPrompt;
    }

    private function removeContractRedundantPromptSentences(string $systemPrompt, array $descriptions): string
    {
        if ($descriptions === []) {
            return $systemPrompt;
        }

        $descriptionIndex = [];
        foreach ($descriptions as $description) {
            foreach (array_merge([$description], $this->splitPromptSentences($description)) as $fragment) {
                $normalized = $this->normalizePromptFragment($fragment);
                if (mb_strlen($normalized) > self::PROMPT_DEDUP_MIN_CHARS) {
                    $descriptionIndex[$normalized] = true;
                }
            }
        }

        $normalizedDescriptions = array_keys($descriptionIndex);
        if ($normalizedDescriptions === []) {
            return $systemPrompt;
        }

        $keptSentences = [];
        foreach ($this->splitPromptSentences($systemPrompt) as $sentence) {
            $normalized = $this->normalizePromptFragment($sentence);
            if (!$this->promptSentenceCoveredByDescription($normalized, $normalizedDescriptions)) {
                $keptSentences[] = $sentence;
            }
        }

        return trim(implode(' ', $keptSentences), " \t\n\r\0\x0B,");
    }

    /**
     * @return array<string>
     */
    private function splitPromptSentences(string $text): array
    {
        $parts = preg_split('/\R+|(?<=[.!?])\s+/', $text);
        if ($parts === false) {
            return [trim($text)];
        }

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $sentences[] = $part;
            }
        }

        return $sentences;
    }

    private function normalizePromptFragment(string $text): string
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text));
        if (!is_string($cleaned)) {
            return '';
        }

        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        return is_string($cleaned) ? trim($cleaned) : '';
    }

    /**
     * @param array<int,string> $normalizedDescriptions
     */
    private function promptSentenceCoveredByDescription(string $normalizedSentence, array $normalizedDescriptions): bool
    {
        if (mb_strlen($normalizedSentence) <= self::PROMPT_DEDUP_MIN_CHARS) {
            return false;
        }

        foreach ($normalizedDescriptions as $description) {
            if (str_contains($description, $normalizedSentence) || str_contains($normalizedSentence, $description)) {
                return true;
            }
        }

        return false;
    }

    private function contractDescriptionTexts(array $contract, array $payload): array
    {
        $descriptions = [];

        $declarations = $contract['function_declarations'] ?? [];
        foreach (is_array($declarations) ? $declarations : [] as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }

            foreach ($this->contractFieldSchemas($declaration) as $schema) {
                $description = $this->evidenceValueDescription($schema);
                if ($description !== null) {
                    $descriptions[] = $description;
                }
            }
        }

        $visualChecks = $payload['visual_checks'] ?? [];
        foreach (is_array($visualChecks) ? $visualChecks : [] as $check) {
            $description = is_array($check) ? ($check['description'] ?? null) : null;
            if (is_string($description) && trim($description) !== '') {
                $descriptions[] = trim($description);
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $descriptions))));
    }

    /**
     * @return array<int|string,array>
     */
    private function contractFieldSchemas(array $declaration): array
    {
        $name = $declaration['name'] ?? '';
        $schemas = match ($name) {
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS => $declaration['parameters']['properties']['fields']['properties'] ?? [],
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS => $declaration['parameters']['properties']['items']['items']['properties'] ?? [],
            default => [],
        };

        return is_array($schemas) ? $schemas : [];
    }

    private function evidenceValueDescription(array $schema): ?string
    {
        $description = $schema['properties']['valor']['description'] ?? $schema['description'] ?? null;
        if (!is_string($description)) {
            return null;
        }

        $description = trim($description);
        return $description !== '' ? $description : null;
    }

    private function buildUserPrompt(string $documentType, array $payload, array $contract): string
    {
        $parts = [
            "Documento objetivo: {$documentType}.",
            'Extrae solo la información visible en este documento.',
            'No completes campos con inferencias desde otros documentos.',
        ];

        $fieldGroups = $this->contractFieldGroups($contract);
        if ($this->hasIdentitySeparationFields($payload['fields_config'] ?? [])) {
            $parts[] = implode("\n", [
                '### Regla de identidad',
                '',
                'Si una linea combina tipo de documento, numero y nombre, separalos en sus campos correspondientes.',
                '',
                '**Ejemplos**',
                '- `CC 94229637 NORENA AGUDELO` => TipoDocumentoPaciente, DocumentoPaciente, NombrePaciente.',
                '- `Medico: 12345678-PEREZ ANA MARIA` => DocumentoMedico, Medico.',
                '',
                'Solo extrae datos visibles y requeridos; no infieras ni completes identidades faltantes.',
            ]);
        }

        if ($fieldGroups['fields'] !== []) {
            $parts[] = 'Campos para `extract_fields`: ' . implode(', ', $fieldGroups['fields']) . '.';
        }

        if ($fieldGroups['items'] !== []) {
            $parts[] = 'Campos para `extract_items`: ' . implode(', ', $fieldGroups['items']) . '.';
        }

        $visualChecks = $payload['visual_checks'] ?? [];
        if ($this->contractRequiresFunction($contract, DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS)) {
            $parts[] = 'Checks visuales esperados:';
            foreach (is_array($visualChecks) ? $visualChecks : [] as $check) {
                if (!is_array($check)) {
                    continue;
                }
                $name = trim((string) ($check['check'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $description = trim((string) ($check['description'] ?? ''));
                $parts[] = $description !== '' ? "- {$name}: {$description}" : "- {$name}";
            }
            if ($this->hasVisualCheck($visualChecks, 'VigenciaEntrega')) {
                $parts[] = 'Para VigenciaEntrega, si el valor es visible retorna valor numerico, unidad="dias" y fecha_base con el nombre del campo fecha desde el cual se cuenta.';
            }
        }

        if ($fieldGroups['items'] !== [] && $this->requiresSegmentedDispensaItems($documentType, $payload)) {
            $parts[] = 'Este documento contiene multiples lineas de producto.';
            $parts[] = 'Debes usar `items` con una entrada por cada fila visible.';
            $parts[] = 'No colapses cantidades, lotes, fechas de vencimiento ni codigos de articulo en `fields`.';
        }

        $dispensedNames = $this->buildDispensedItemsContext($documentType, $payload, $fieldGroups);
        if ($dispensedNames !== []) {
            $parts[] = 'Candidatos de articulo para busqueda en prescripcion:';
            foreach ($dispensedNames as $name) {
                $parts[] = "- {$name}";
            }
            $parts[] = 'En `items`, extrae solo articulos visibles que coincidan de forma exacta u homologa con esos candidatos.';
            $parts[] = 'Devuelve el nombre tal como aparece en el documento.';
        }

        $parts[] = 'Invoca exactamente una vez cada función en el mismo turno: '
            . implode(', ', $this->requiredFunctionNames($contract)) . '.';

        return implode("\n", $parts);
    }

    private function buildToolConfig(array $contract): array
    {
        return [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => $this->requiredFunctionNames($contract),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function contractFunctionDeclarations(array $contract): array
    {
        $declarations = $this->requiredArray(
            $contract,
            'function_declarations',
            'extraction_contract sin function_declarations'
        );

        foreach ($declarations as $index => $declaration) {
            if (!is_array($declaration) || trim((string) ($declaration['name'] ?? '')) === '') {
                throw new RuntimeException("extraction_contract function_declaration inválida en posición {$index}");
            }
        }

        return array_values($declarations);
    }

    /**
     * @return array<int,string>
     */
    private function requiredFunctionNames(array $contract): array
    {
        $names = $this->requiredArray(
            $contract,
            'required_function_names',
            'extraction_contract sin required_function_names'
        );

        $normalized = [];
        foreach ($names as $index => $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new RuntimeException("extraction_contract required_function_name inválido en posición {$index}");
            }

            $normalized[] = trim($name);
        }

        return array_values(array_unique($normalized));
    }

    private function contractRequiresFunction(array $contract, string $functionName): bool
    {
        return in_array($functionName, $this->requiredFunctionNames($contract), true);
    }

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
            return 'EMPTY_PDF_NO_PAGES';
        }
        if (stripos($msg, 'could not be decoded') !== false) {
            return 'GEMINI_DECODE_FAILURE';
        }
        return 'GEMINI_CONTENT_REJECTED';
    }

    private function hasVisualCheck(mixed $visualChecks, string $expectedName): bool
    {
        if (!is_array($visualChecks)) {
            return false;
        }

        foreach ($visualChecks as $check) {
            if (is_array($check) && trim((string) ($check['check'] ?? '')) === $expectedName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{fields:array<int,string>,items:array<int,string>}
     */
    private function contractFieldGroups(array $contract): array
    {
        $groups = is_array($contract['field_groups'] ?? null) ? $contract['field_groups'] : [];

        return [
            'fields' => $this->stringList($groups['fields'] ?? []),
            'items' => $this->stringList($groups['items'] ?? []),
        ];
    }

    /**
     * @param  mixed $fieldsConfig
     */
    private function hasIdentitySeparationFields(mixed $fieldsConfig): bool
    {
        if (!is_array($fieldsConfig)) {
            return false;
        }

        foreach ($fieldsConfig as $fieldConfig) {
            if (!is_array($fieldConfig)) {
                continue;
            }

            $valueType = $this->optionalFieldValueTypeFromConfig($fieldConfig);
            if ($valueType?->isIdentityPromptValue()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed> $fieldConfig
     */
    private function optionalFieldValueTypeFromConfig(array $fieldConfig): ?AuditFieldValueType
    {
        $tipoDato = trim((string) ($fieldConfig['tipoDato'] ?? ''));
        if ($tipoDato === '') {
            return null;
        }

        try {
            return AuditFieldValueType::fromInput($tipoDato);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values($strings);
    }

    private function parseGeminiResponse(array $response, array $contract): array
    {
        $requiredNames = $this->requiredFunctionNames($contract);
        $candidate = $this->extractPrimaryCandidate($response);
        $this->assertSuccessfulFinishReason($candidate);

        $parts = $this->extractCandidateParts($candidate);
        $calls = $this->extractFunctionCalls($parts, $requiredNames);

        return $this->validateParallelExtractionPayload($calls);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractPrimaryCandidate(array $response): array
    {
        $candidate = $response['candidates'][0] ?? null;
        if (!is_array($candidate)) {
            throw new RuntimeException(self::ERROR_MISSING_CANDIDATE);
        }

        return $candidate;
    }

    private function assertSuccessfulFinishReason(array $candidate): void
    {
        $finishReason = trim((string) ($candidate['finishReason'] ?? ''));
        if ($finishReason !== self::ACCEPTED_FINISH_REASON) {
            $reportedReason = $finishReason !== '' ? $finishReason : 'UNKNOWN';
            throw new RuntimeException(self::ERROR_UNSAFE_FINISH_REASON . ": {$reportedReason}");
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractCandidateParts(array $candidate): array
    {
        $parts = $candidate['content']['parts'] ?? null;
        if (!is_array($parts)) {
            throw new RuntimeException(self::ERROR_MISSING_FUNCTION_CALL);
        }

        return $parts;
    }

    /**
     * @param  array<int,array<string,mixed>> $parts
     * @param  array<int,string> $requiredNames
     * @return array<string,array<string,mixed>>
     */
    private function extractFunctionCalls(array $parts, array $requiredNames): array
    {
        $calls = [];
        foreach ($parts as $part) {
            $functionCall = $part['functionCall'] ?? null;
            if (!is_array($functionCall)) {
                continue;
            }

            $name = trim((string) ($functionCall['name'] ?? ''));
            if ($name === '' || !in_array($name, $requiredNames, true)) {
                $reportedName = $name !== '' ? $name : 'UNKNOWN';
                throw new RuntimeException(self::ERROR_UNEXPECTED_FUNCTION_CALL . ": {$reportedName}");
            }

            if (array_key_exists($name, $calls)) {
                throw new RuntimeException(self::ERROR_DUPLICATE_FUNCTION_CALL . ": {$name}");
            }

            $args = $functionCall['args'] ?? null;
            if (!is_array($args)) {
                throw new RuntimeException(self::ERROR_INVALID_ARGS . ": {$name}");
            }

            $calls[$name] = $args;
        }

        foreach ($requiredNames as $requiredName) {
            if (!array_key_exists($requiredName, $calls)) {
                throw new RuntimeException(self::ERROR_MISSING_FUNCTION_CALL . ": {$requiredName}");
            }
        }

        return $calls;
    }

    /**
     * @param  array<string,array<string,mixed>> $calls
     * @return array<string,mixed>
     */
    private function validateParallelExtractionPayload(array $calls): array
    {
        $fields = $this->optionalFunctionArray(
            $calls,
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
            'fields',
            'Gemini retornó extract_fields sin fields'
        );
        $items = $this->optionalFunctionArray(
            $calls,
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
            'items',
            'Gemini retornó extract_items sin items'
        );
        $visualChecks = $this->optionalFunctionArray(
            $calls,
            DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            'visual_checks',
            'Gemini retornó detect_visual_checks sin visual_checks'
        );

        $qualityArgs = $this->requiredFunctionArgs(
            $calls,
            DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY
        );
        $documentQuality = $this->validateDocumentQuality($qualityArgs['document_quality'] ?? null);
        $qualityNotes = $this->requiredArray($qualityArgs, 'quality_notes', 'Gemini retornó assess_document_quality sin quality_notes');
        $this->validateItems($items);
        $this->validateVisualChecks($visualChecks);

        return [
            'fields' => $fields,
            'items' => $items,
            'visual_checks' => $visualChecks,
            'document_quality' => $documentQuality,
            'quality_notes' => array_values($qualityNotes),
        ];
    }

    /**
     * @param array<string,mixed> $extracted
     * @return array<string,mixed>
     */
    private function annotateItemSegmentation(string $documentType, array $payload, array $contract, array $extracted): array
    {
        if (!$this->contractRequiresFunction($contract, DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS)) {
            return $extracted;
        }

        if (!$this->requiresSegmentedDispensaItems($documentType, $payload)) {
            return $extracted;
        }

        $items = $extracted['items'] ?? [];
        $sourceTruthItems = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];
        $extractedItemsCount = is_array($items) ? count($items) : 0;
        $expectedItemsCount = count($sourceTruthItems);

        if ($extractedItemsCount < $expectedItemsCount) {
            $extracted['extraction_warnings'] = $extracted['extraction_warnings'] ?? [];
            $extracted['extraction_warnings'][] = [
                'code' => 'ITEM_SEGMENTATION_INCOMPLETE',
                'severity' => 'warning',
                'scope' => 'items',
                'document_type' => $documentType,
                'expected_items_count' => $expectedItemsCount,
                'extracted_items_count' => $extractedItemsCount,
            ];
        }

        return $extracted;
    }

    /**
     * @param  array<string,array<string,mixed>> $calls
     * @return array<string,mixed>
     */
    private function requiredFunctionArgs(array $calls, string $functionName): array
    {
        $args = $calls[$functionName] ?? null;
        if (!is_array($args)) {
            throw new RuntimeException(self::ERROR_MISSING_FUNCTION_CALL . ": {$functionName}");
        }

        return $args;
    }

    /**
     * @param  array<string,array<string,mixed>> $calls
     * @return array<string|int,mixed>
     */
    private function optionalFunctionArray(
        array $calls,
        string $functionName,
        string $key,
        string $errorMessage
    ): array {
        if (!array_key_exists($functionName, $calls)) {
            return [];
        }

        return $this->requiredArray($calls[$functionName], $key, $errorMessage);
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

    private function resolveDocumentType(array $payload): string
    {
        return trim((string) ($payload['tipo_documento'] ?? 'DOCUMENTO'));
    }

    private static function elapsedMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function requiresSegmentedDispensaItems(string $documentType, array $payload): bool
    {
        $sourceTruthItems = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];

        return strtoupper(trim($documentType)) === 'DISPENSA' && count($sourceTruthItems) > 1;
    }

    /**
     * @return string[]
     */
    private function buildDispensedItemsContext(string $documentType, array $payload, array $fieldGroups): array
    {
        if (!DocumentExtractionContractBuilder::isPrescriptionDocument($documentType)) {
            return [];
        }

        if (!in_array('NombreArticulo', $fieldGroups['items'] ?? [], true)) {
            return [];
        }

        $fdvItems = $payload['fuente_verdad']['items'] ?? [];
        if (!is_array($fdvItems) || $fdvItems === []) {
            return [];
        }

        $names = [];
        foreach ($fdvItems as $item) {
            $name = trim((string) ($item['NombreArticulo'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $unique = array_values(array_unique($names));

        if ($unique !== []) {
            Logger::info('Prescription selective extraction enabled', [
                'document_type' => $documentType,
                'dispensed_items_count' => count($unique),
            ]);
        }

        return $unique;
    }

    private function validateDocumentQuality(mixed $documentQuality): string
    {
        if (!is_string($documentQuality) || trim($documentQuality) === '') {
            throw new RuntimeException('Gemini retornó extraction payload sin document_quality');
        }

        return DocumentQuality::fromString(trim($documentQuality))->value;
    }

    /**
     * @param array<int,mixed> $items
     */
    private function validateItems(array $items): void
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Gemini retornó item inválido en posición {$index}");
            }
        }
    }

    /**
     * @param array<int,mixed> $visualChecks
     */
    private function validateVisualChecks(array $visualChecks): void
    {
        foreach ($visualChecks as $index => $visualCheck) {
            if (!is_array($visualCheck)) {
                throw new RuntimeException("Gemini retornó visual_check inválido en posición {$index}");
            }

            $check = $visualCheck['check'] ?? null;
            if (!is_string($check) || trim($check) === '') {
                throw new RuntimeException("Gemini retornó visual_check sin check en posición {$index}");
            }

            if (!array_key_exists('presente', $visualCheck) || !is_bool($visualCheck['presente'])) {
                throw new RuntimeException("Gemini retornó visual_check sin presente booleano en posición {$index}");
            }

            if (
                array_key_exists('valor', $visualCheck)
                && $visualCheck['valor'] !== null
                && !is_int($visualCheck['valor'])
                && !is_float($visualCheck['valor'])
                && !is_string($visualCheck['valor'])
            ) {
                throw new RuntimeException("Gemini retornó visual_check.valor inválido en posición {$index}");
            }

            foreach (['unidad', 'fecha_base'] as $optionalStringKey) {
                if (
                    array_key_exists($optionalStringKey, $visualCheck)
                    && $visualCheck[$optionalStringKey] !== null
                    && !is_string($visualCheck[$optionalStringKey])
                ) {
                    throw new RuntimeException("Gemini retornó visual_check.{$optionalStringKey} inválido en posición {$index}");
                }
            }
        }
    }
}
