<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\GeminiGateway;
use Core\Env;
use Core\Logger;
use Core\RedisUnavailableException;
use RuntimeException;

final class DocumentExtractionWorker extends AuditEventConsumer
{
    private const DOCUMENT_QUALITY_ENUM = [
        'legible',
        'parcialmente_legible',
        'ilegible',
    ];

    private const DEFAULT_SYSTEM_PROMPT = <<<TEXT
        Eres un extractor documental determinístico.
        Analiza un único documento.
        No inventes valores.
        Si un dato no es visible o no es legible, omítelo o usa el valor nativo null de JSON (sin comillas).
        Para verificaciones visuales usa presente=false cuando el elemento no sea visible.
        Invoca exactamente una vez la función extract_document_data.
    TEXT;

    private const DEFAULT_CACHE_TTL = 86400;
    private const DEFAULT_EXTRACTOR_VERSION = 'gemini-3.x-items-v2';

    private AuditStateStore $stateStore;
    private AttachmentDownloadServiceInterface $downloader;
    private GeminiGateway $gateway;
    private int $cacheTtl;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore                    $stateStore   = null,
        ?AttachmentDownloadServiceInterface $downloader   = null,
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

        $resolvedTtl       = $cacheTtl ?? (int) Env::get('AUDIT_EXTRACTION_CACHE_TTL', self::DEFAULT_CACHE_TTL);
        $this->cacheTtl    = $resolvedTtl > 0 ? $resolvedTtl : self::DEFAULT_CACHE_TTL;
    }

    public function processEvent(AuditEvent $event): void
    {
        $this->handle($event);
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
        $schema        = $this->requiredArray($payload, 'extraction_schema');
        $documentType  = $this->resolveDocumentType($payload);
        $functionName  = $this->resolveFunctionName($schema);

        $document     = $this->downloader->download($attachmentId, $disDetNro);
        $documentHash = hash('sha256', $document['data']);

        $extracted = $this->cacheGet($documentHash);
        $cacheHit = $extracted !== null;
        $geminiDurationMs = 0;
        $geminiMetrics = null;

        if ($extracted === null) {
            $response = $this->gateway->sendWithFunctionCalling(
                $this->buildUserPrompt($documentType, $payload, $functionName),
                [[
                    'mime' => $document['mime'],
                    'data' => $document['data'],
                    'label' => $documentType,
                ]],
                $this->buildSystemPrompt($payload),
                [['functionDeclarations' => [$schema]]],
                $this->buildToolConfig($schema),
                [],
                [
                    'dis_det_nro' => $disDetNro,
                    'audit_id' => $event->auditId,
                    'document_id' => $event->documentId,
                    'document_type' => $documentType,
                    'task_type' => 'extraction',
                ]
            );

            $geminiDurationMs = (int) ($response['X-Audit-Metrics']['duration_ms'] ?? 0);
            $geminiMetrics = is_array($response['X-Audit-Metrics'] ?? null)
                ? $response['X-Audit-Metrics']
                : null;
            unset($response['X-Audit-Metrics']);

            $extracted = $this->parseGeminiResponse($response, $schema);
            $this->enforceItemSegmentation($documentType, $payload, $extracted);
            $this->cachePut($documentHash, $extracted);
        }

        $extractionDurationMs = (int) ((microtime(true) - $totalStartTime) * 1000);

        $documentState = [
            'status'                  => 'extracted',
            'document_hash'           => $documentHash,
            'cache_hit'               => $cacheHit,
            'mime'                    => $document['mime'],
            'extraction_result'       => $extracted,
            'extracted_at'            => gmdate('Y-m-d\TH:i:s\Z'),
            'extraction_duration_ms'  => $extractionDurationMs,
            'download_duration_ms'    => (int) ($document['duration_ms'] ?? 0),
            'gemini_duration_ms'      => $geminiDurationMs,
        ];

        if ($geminiMetrics !== null) {
            $documentState['gemini_metrics'] = $geminiMetrics;
        }

        if (!$this->stateStore->markDocumentExtracted($event->auditId, $event->documentId, $documentState)) {
            throw new RuntimeException('No se pudo persistir la extracción del documento en Redis');
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            documentId: $event->documentId,
            payload: array_merge($payload, $documentState),
            parentEventId: $event->eventId,
        ));

        $totalDurationMs = (microtime(true) - $totalStartTime) * 1000;
        $downloadDurationMs = (int) ($document['duration_ms'] ?? 0);
        $localCpuDurationMs = $totalDurationMs - $downloadDurationMs - $geminiDurationMs;

        Logger::info('Document extraction event processed', [
            'auditId' => $event->auditId,
            'documentId' => $event->documentId,
            'cache_hit' => $cacheHit,
            'total_duration_ms' => (int) $totalDurationMs,
            'download_duration_ms' => $downloadDurationMs,
            'gemini_duration_ms' => $geminiDurationMs,
            'local_cpu_duration_ms' => (int) $localCpuDurationMs,
        ]);
    }

    // ─── Extraction cache (absorbed from ExtractionCache) ────────────────────

    private function cacheGet(string $documentHash): ?array
    {
        self::assertHash($documentHash);

        try {
            $raw = $this->redis->get(self::cacheKey($documentHash));
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

    private function cachePut(string $documentHash, array $payload): void
    {
        self::assertHash($documentHash);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar extraction cache');
        }

        if (!$this->redis->set(self::cacheKey($documentHash), $encoded, $this->cacheTtl)) {
            throw new RuntimeException('No se pudo escribir extraction cache en Redis');
        }
    }

    private static function cacheKey(string $documentHash): string
    {
        self::assertHash($documentHash);
        $version = trim((string) Env::get('AUDIT_VERSION_EXTRACTOR', self::DEFAULT_EXTRACTOR_VERSION));
        $sanitizedVersion = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $version);
        if (!is_string($sanitizedVersion) || $sanitizedVersion === '') {
            $sanitizedVersion = self::DEFAULT_EXTRACTOR_VERSION;
        }

        return "extraction:cache:{$sanitizedVersion}:{$documentHash}";
    }

    private static function assertHash(string $documentHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $documentHash)) {
            throw new RuntimeException("document_hash inválido: {$documentHash}");
        }
    }

    // ─── Gemini interaction helpers ──────────────────────────────────────────

    private function buildSystemPrompt(array $payload): string
    {
        $systemPrompt = trim((string) ($payload['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            return self::DEFAULT_SYSTEM_PROMPT;
        }

        return self::DEFAULT_SYSTEM_PROMPT . "\n\n" . $systemPrompt;
    }

    private function buildUserPrompt(string $documentType, array $payload, string $functionName): string
    {
        $parts = [
            "Documento objetivo: {$documentType}.",
            'Extrae solo la información visible en este documento.',
            'No completes campos con inferencias desde otros documentos.',
        ];

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
        }

        if ($this->requiresSegmentedDispensaItems($documentType, $payload)) {
            $parts[] = 'Este documento contiene multiples lineas de producto.';
            $parts[] = 'Debes usar `items` con una entrada por cada fila visible.';
            $parts[] = 'No colapses cantidades, lotes, fechas de vencimiento ni codigos de articulo en `fields`.';
        }

        // Extracción selectiva: para prescripciones, filtrar items al subconjunto dispensado
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

        $parts[] = "Invoca exactamente una vez la función {$functionName}.";

        return implode("\n", $parts);
    }

    private function buildToolConfig(array $schema): array
    {
        return [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => [$this->resolveFunctionName($schema)],
            ],
        ];
    }

    private function parseGeminiResponse(array $response, array $schema): array
    {
        $expectedName = $this->resolveFunctionName($schema);

        $parts = $response['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            throw new RuntimeException('Respuesta Gemini sin functionCall');
        }

        foreach ($parts as $part) {
            $functionCall = $part['functionCall'] ?? null;
            if (!is_array($functionCall)) {
                continue;
            }

            if (($functionCall['name'] ?? null) !== $expectedName) {
                continue;
            }

            $args = $functionCall['args'] ?? null;
            if (!is_array($args)) {
                break;
            }

            return $this->validateExtractionPayload($args);
        }

        throw new RuntimeException("Gemini no invocó {$expectedName}");
    }

    private function validateExtractionPayload(array $args): array
    {
        $fields = $this->requiredArray($args, 'fields', 'Gemini retornó extraction payload sin fields');
        $visualChecks = $this->requiredArray($args, 'visual_checks', 'Gemini retornó extraction payload sin visual_checks');
        $documentQuality = $this->validateDocumentQuality($args['document_quality'] ?? null);
        $items = $this->normalizeOptionalArray($args['items'] ?? []);
        $qualityNotes = $this->normalizeOptionalArray($args['quality_notes'] ?? []);
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

    private function resolveFunctionName(array $schema): string
    {
        $name = is_string($schema['name'] ?? null) ? trim((string) $schema['name']) : '';
        return $name !== '' ? $name : 'extract_document_data';
    }

    private function requiresSegmentedDispensaItems(string $documentType, array $payload): bool
    {
        $sourceTruthItems = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];

        return strtoupper(trim($documentType)) === 'DISPENSA' && count($sourceTruthItems) > 1;
    }

    /**
     * Detecta dinámicamente si un tipo de documento es prescriptivo.
     */
    private function isPrescriptionDocument(string $documentType): bool
    {
        $normalized = strtoupper(trim($documentType));
        $prescriptionTokens = ['FORMULA', 'PRESCRIPCION', 'RECETA', 'ORDEN MEDICA'];

        foreach ($prescriptionTokens as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrae nombres de artículos dispensados de la FDV para inyección selectiva en el prompt.
     * Solo aplica a documentos prescriptivos. Retorna [] si no aplica (fallback: sin filtro).
     *
     * @return string[]
     */
    private function buildDispensedItemsContext(string $documentType, array $payload): array
    {
        if (!$this->isPrescriptionDocument($documentType)) {
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

    /**
     * @return array<int|string,mixed>
     */
    private function normalizeOptionalArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function validateDocumentQuality(mixed $documentQuality): string
    {
        if (!is_string($documentQuality) || trim($documentQuality) === '') {
            throw new RuntimeException('Gemini retornó extraction payload sin document_quality');
        }

        $normalized = trim($documentQuality);
        if (!in_array($normalized, self::DOCUMENT_QUALITY_ENUM, true)) {
            throw new RuntimeException("Gemini retornó document_quality inválido: {$normalized}");
        }

        return $normalized;
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
        }
    }
}
