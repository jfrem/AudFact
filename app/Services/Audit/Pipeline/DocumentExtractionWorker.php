<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\DocumentQuality;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\GeminiConfig;
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
    private const ACCEPTED_FINISH_REASON = 'STOP';
    private const ERROR_MISSING_CANDIDATE = 'GEMINI_EXTRACTION_MISSING_CANDIDATE';
    private const ERROR_UNSAFE_FINISH_REASON = 'GEMINI_EXTRACTION_UNSAFE_FINISH_REASON';
    private const ERROR_MISSING_FUNCTION_CALL = 'GEMINI_EXTRACTION_MISSING_FUNCTION_CALL';
    private const ERROR_INVALID_ARGS = 'GEMINI_EXTRACTION_INVALID_ARGS';
    private const ERROR_UNEXPECTED_FUNCTION_CALL = 'GEMINI_EXTRACTION_UNEXPECTED_FUNCTION_CALL';
    private const ERROR_DUPLICATE_FUNCTION_CALL = 'GEMINI_EXTRACTION_DUPLICATE_FUNCTION_CALL';

    private AuditStateStore $stateStore;
    private AttachmentDownloadService $downloader;
    private GeminiGateway $gateway;
    private int $cacheTtl;
    private string $extractorVersion;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore                    $stateStore   = null,
        ?AttachmentDownloadService          $downloader   = null,
        ?GeminiGateway                      $gateway      = null,
        ?\Core\RedisClient                  $redis        = null,
        ?AuditEventPublisher                $publisher    = null,
        ?string                             $consumerName = null,
        ?int                                $cacheTtl     = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore   = $stateStore ?? new AuditStateStore($this->redis);
        $this->downloader   = $downloader ?? new AttachmentDownloadService();
        $this->gateway      = $gateway    ?? GeminiGateway::create();
        $this->consumerName = $consumerName ?? ('extractor-' . getmypid());

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
        $totalStartTime = microtime(true);
        if ($event->eventType !== AuditEvent::TYPE_DOCUMENT_REGISTERED) {
            return;
        }

        if ($event->auditId === null || $event->documentId === null) {
            throw new RuntimeException('document_registered sin audit_id o document_id');
        }

        $payload       = $event->payload;
        $attachmentId  = $this->requiredString($payload, 'attachment_id');
        $disDetNro     = $this->requiredString($payload, 'dis_det_nro');
        $contract      = $this->requiredArray($payload, 'extraction_contract');
        $documentType  = $this->resolveDocumentType($payload);
        $contractHash  = (string) ($payload['contract_hash'] ?? $contract['contract_hash'] ?? '');
        $targetContextHash = (string) ($payload['target_context_hash'] ?? '');

        $downloaded = $this->downloadDocumentWithHash($attachmentId, $disDetNro);
        $document = $downloaded['document'];
        $documentHash = $downloaded['document_hash'];

        $compositeCacheKey = $this->compositeCacheKey($documentHash, $contractHash, $targetContextHash);

        $extraction = $this->resolveExtraction(
            $compositeCacheKey,
            $document,
            $documentType,
            $payload,
            $contract,
            $disDetNro,
            $event
        );

        $extractionDurationMs = (int) ((microtime(true) - $totalStartTime) * 1000);

        $documentState = $this->buildDocumentState(
            $documentHash,
            $document,
            $extraction,
            $extractionDurationMs,
            $contractHash,
            $targetContextHash
        );

        if (!$this->stateStore->markDocumentExtracted($event->auditId, $event->documentId, $documentState)) {
            throw new RuntimeException('No se pudo persistir la extracción del documento en Redis');
        }

        $this->publishDocumentExtracted($event, $payload, $documentState);

        $totalDurationMs = (microtime(true) - $totalStartTime) * 1000;
        $this->logDocumentExtracted($event, $documentState, $totalDurationMs);
    }

    /**
     * @return array{document:array<string,mixed>,document_hash:string}
     */
    private function downloadDocumentWithHash(string $attachmentId, string $disDetNro): array
    {
        $document = $this->downloader->download($attachmentId, $disDetNro);

        return [
            'document' => $document,
            'document_hash' => hash('sha256', $document['data']),
        ];
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
        string $disDetNro,
        AuditEvent $event
    ): array {
        $extracted = $this->cacheGet($cacheKey);
        if ($extracted !== null) {
            return [
                'extracted' => $extracted,
                'cache_hit' => true,
                'gemini_duration_ms' => 0,
                'gemini_metrics' => null,
            ];
        }

        $response = $this->gateway->sendWithFunctionCalling(
            $this->buildUserPrompt($documentType, $payload, $contract),
            [[
                'mime' => $document['mime'],
                'data' => $document['data'],
                'label' => $documentType,
            ]],
            $this->buildSystemPrompt($payload),
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
        $this->enforceItemSegmentation($documentType, $payload, $extracted);
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
        string $targetContextHash = ''
    ): array {
        $documentState = [
            'status'                  => 'extracted',
            'document_hash'           => $documentHash,
            'contract_hash'           => $contractHash,
            'target_context_hash'     => $targetContextHash,
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
     * Cualquier cambio en document, contrato, FDV o versión invalida el cache.
     */
    private function compositeCacheKey(string $documentHash, string $contractHash, string $targetContextHash): string
    {
        self::assertHash($documentHash);

        if ($contractHash === '' || $targetContextHash === '') {
            throw new RuntimeException("Faltan hashes de contrato o contexto para documentHash {$documentHash}");
        }

        $composite = hash('sha256', $documentHash . $contractHash . $targetContextHash . $this->extractorVersion);
        return "extraction:cache:v1:{$composite}";
    }

    private function buildSystemPrompt(array $payload): string
    {
        $systemPrompt = trim((string) ($payload['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            return self::DEFAULT_SYSTEM_PROMPT;
        }

        return self::DEFAULT_SYSTEM_PROMPT . "\n\n" . $systemPrompt;
    }

    private function buildUserPrompt(string $documentType, array $payload, array $contract): string
    {
        $parts = [
            "Documento objetivo: {$documentType}.",
            'Extrae solo la información visible en este documento.',
            'No completes campos con inferencias desde otros documentos.',
        ];

        $fieldGroups = $this->contractFieldGroups($contract);
        if ($fieldGroups['fields'] !== []) {
            $parts[] = 'Campos para `extract_fields`: ' . implode(', ', $fieldGroups['fields']) . '.';
        } else {
            $parts[] = '`extract_fields` debe retornar fields como objeto vacío.';
        }

        if ($fieldGroups['items'] !== []) {
            $parts[] = 'Campos para `extract_items`: ' . implode(', ', $fieldGroups['items']) . '.';
        } else {
            $parts[] = '`extract_items` debe retornar items como arreglo vacío.';
        }

        $visualChecks = $payload['visual_checks'] ?? [];
        if (is_array($visualChecks) && $visualChecks !== []) {
            $parts[] = 'Checks visuales esperados:';
            foreach ($visualChecks as $check) {
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
            $parts[] = 'Para checks de vigencia o plazo, si el valor es visible retorna valor numerico, unidad="dias" y fecha_base con el nombre del campo fecha desde el cual se cuenta.';
        } else {
            $parts[] = '`detect_visual_checks` debe retornar visual_checks como arreglo vacío.';
        }

        if ($this->requiresSegmentedDispensaItems($documentType, $payload)) {
            $parts[] = 'Este documento contiene multiples lineas de producto.';
            $parts[] = 'Debes usar `items` con una entrada por cada fila visible.';
            $parts[] = 'No colapses cantidades, lotes, fechas de vencimiento ni codigos de articulo en `fields`.';
        }

        $dispensedNames = $this->buildDispensedItemsContext($documentType, $payload);
        if ($dispensedNames !== []) {
            $parts[] = 'Articulos efectivamente dispensados al paciente (Registro de Dispensación):';
            foreach ($dispensedNames as $name) {
                $parts[] = "- {$name}";
            }
            $parts[] = 'En `items`, extrae UNICAMENTE los articulos que coincidan (exactos u homologos) con la lista anterior.';
            $parts[] = 'Si un articulo prescrito no aparece en la lista de dispensados, omitelo de `items`.';
            $parts[] = 'Incluye los nombres tal como aparecen en el documento, no los de la lista.';
        }

        $parts[] = 'Invoca exactamente una vez cada función en el mismo turno: '
            . implode(', ', $this->requiredFunctionNames($contract)) . '.';

        $targetContext = $payload['target_context'] ?? null;
        if (is_array($targetContext)) {
            $parts[] = '';
            $parts[] = '--- Valores de referencia (Registro de Dispensación) ---';
            $parts[] = 'IMPORTANTE: Estos son los valores del registro de dispensación, SOLO referencia.';
            $parts[] = 'Extrae lo que el documento muestra independientemente de si coincide con estos valores.';
            $parts[] = 'No limites tu extracción a estos valores ni los uses para completar datos no visibles.';

            $tcFields = is_array($targetContext['fields'] ?? null) ? $targetContext['fields'] : [];
            if ($tcFields !== []) {
                $parts[] = 'Campos de cabecera esperados:';
                foreach ($tcFields as $tcName => $tcMeta) {
                    $fdvVal = $tcMeta['valorFuenteVerdad'] ?? 'N/A';
                    $parts[] = "- {$tcName}: {$fdvVal}";
                }
            }

            $tcItems = is_array($targetContext['items'] ?? null) ? $targetContext['items'] : [];
            if ($tcItems !== []) {
                $parts[] = 'Campos de línea esperados:';
                foreach ($tcItems as $tcName => $tcMeta) {
                    $vals = is_array($tcMeta['valoresFuenteVerdad'] ?? null)
                        ? implode(', ', $tcMeta['valoresFuenteVerdad'])
                        : 'N/A';
                    $parts[] = "- {$tcName}: [{$vals}]";
                }
            }
        }

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
        $fieldsArgs = $calls[DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS] ?? [];
        $itemsArgs = $calls[DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS] ?? [];
        $visualArgs = $calls[DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS] ?? [];
        $qualityArgs = $calls[DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY] ?? [];

        $fields = $this->requiredArray($fieldsArgs, 'fields', 'Gemini retornó extract_fields sin fields');
        $items = $this->requiredArray($itemsArgs, 'items', 'Gemini retornó extract_items sin items');
        $visualChecks = $this->requiredArray($visualArgs, 'visual_checks', 'Gemini retornó detect_visual_checks sin visual_checks');
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

    private function enforceItemSegmentation(string $documentType, array $payload, array $extracted): void
    {
        if (!$this->requiresSegmentedDispensaItems($documentType, $payload)) {
            return;
        }

        $items = $extracted['items'] ?? [];
        $sourceTruthItems = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];
        if (!is_array($items) || count($items) < count($sourceTruthItems)) {
            throw new RuntimeException('Gemini no segmentó todos los items visibles de la dispensa');
        }
    }

    private function requiredArray(array $payload, string $key, ?string $errorMessage = null): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException($errorMessage ?? "document_registered sin {$key}");
        }

        return $value;
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("document_registered sin {$key}");
        }

        return $value;
    }

    private function resolveDocumentType(array $payload): string
    {
        return trim((string) ($payload['tipo_documento'] ?? 'DOCUMENTO'));
    }

    private function requiresSegmentedDispensaItems(string $documentType, array $payload): bool
    {
        $sourceTruthItems = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];

        return strtoupper(trim($documentType)) === 'DISPENSA' && count($sourceTruthItems) > 1;
    }

    /**
     * Extrae nombres de artículos dispensados de la FDV para inyección selectiva en el prompt.
     * Solo aplica a documentos prescriptivos. Retorna [] si no aplica (fallback: sin filtro).
     *
     * @return string[]
     */
    private function buildDispensedItemsContext(string $documentType, array $payload): array
    {
        if (!DocumentExtractionContractBuilder::isPrescriptionDocument($documentType)) {
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
