<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use App\Services\Audit\GeminiGateway;
use App\Services\Audit\GeminiGatewayFactory;
use Core\Env;
use Core\Logger;
use GuzzleHttp\Client;
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

    private AuditStateStore $stateStore;
    private InternalAuditApiClient $apiClient;
    private ExtractionCache $cache;
    private GeminiGateway $gateway;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?InternalAuditApiClient $apiClient = null,
        ?ExtractionCache $cache = null,
        ?GeminiGateway $gateway = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->apiClient = $apiClient ?? new InternalAuditApiClient();
        $this->cache = $cache ?? new ExtractionCache($this->redis);
        $this->gateway = $gateway ?? GeminiGatewayFactory::create();
        $this->consumerName = $consumerName ?? ('extractor-' . getmypid());
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

        $payload = $event->payload;
        $downloadUrl = $this->requiredString($payload, 'download_url');
        $schema = $this->requiredArray($payload, 'extraction_schema');
        $documentType = $this->resolveDocumentType($payload);
        $functionName = $this->resolveFunctionName($schema);
        $disDetNro = $this->requiredString($payload, 'dis_det_nro');

        $document = $this->apiClient->downloadAttachment($downloadUrl);
        $documentHash = hash('sha256', $document['data']);

        $extracted = $this->cache->get($documentHash);
        $cacheHit = $extracted !== null;
        $geminiDurationMs = 0;

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
                [
                    'X-Audit-Context-DisDetNro' => $disDetNro,
                    'X-Audit-Context-AuditId' => $event->auditId,
                    'X-Audit-Context-DocumentId' => $event->documentId,
                    'X-Audit-Context-DocumentType' => $documentType,
                ]
            );

            $geminiDurationMs = (int) ($response['X-Audit-Metrics']['duration_ms'] ?? 0);
            unset($response['X-Audit-Metrics']);

            $extracted = $this->parseGeminiResponse($response, $schema);
            $this->enforceItemSegmentation($documentType, $payload, $extracted);
            $this->cache->put($documentHash, $extracted);
        }

        $documentState = [
            'status' => 'extracted',
            'document_hash' => $documentHash,
            'cache_hit' => $cacheHit,
            'mime' => $document['mime'],
            'extraction_result' => $extracted,
            'extracted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

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
        $fields = $this->requireArrayPayload($args, 'fields', 'Gemini retornó extraction payload sin fields');
        $visualChecks = $this->requireArrayPayload($args, 'visual_checks', 'Gemini retornó extraction payload sin visual_checks');
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

    private function requiredArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException("document_registered sin {$key}");
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
     * @return array<int|string,mixed>
     */
    private function requireArrayPayload(array $payload, string $key, string $errorMessage): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException($errorMessage);
        }

        return $value;
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
