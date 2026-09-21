<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Audit\GeminiCallMetrics;
use Core\Logger;
use Core\RedisClient;

final class SemanticMatchJudge
{
    /** Propósito de arbitraje semántico para artículos o insumos farmacéuticos. */
    public const PURPOSE_PRODUCT_MATCH = 'article_homologation';

    /** Propósito de arbitraje semántico para nombres de personas (médicos, pacientes). */
    public const PURPOSE_PERSON_MATCH  = 'person_name_match';

    private const CACHE_TTL = 2592000; // 30 dias
    private const CACHE_NAMESPACE_PRODUCT = 'audfact:semantic:match:v5:product';
    private const CACHE_NAMESPACE_PERSON  = 'audfact:semantic:match:v2:person';
    private const FALLBACK_REASONING = 'No fue posible confirmar equivalencia semántica; requiere revisión humana.';

    private GeminiGateway $gateway;
    private RedisClient $redis;
    private SemanticMatchPromptBuilder $promptBuilder;

    public function __construct(
        GeminiGateway $gateway,
        ?RedisClient $redis = null,
        ?SemanticMatchPromptBuilder $promptBuilder = null
    ) {
        $this->gateway = $gateway;
        $this->redis = $redis ?? RedisClient::getInstance();
        $this->promptBuilder = $promptBuilder ?? new SemanticMatchPromptBuilder();
    }

    /**
     * @param array<string,mixed> $context
     * @return array{is_match: bool, reasoning: string, gemini_metrics?: array<string,mixed>, cache_hit?: bool}
     */
    public function evaluate(string $expected, string $actual, array $context = []): array
    {
        if (trim($expected) === '' || trim($actual) === '') {
            return ['is_match' => false, 'reasoning' => 'Valores vacíos.'];
        }

        $callPurpose = (string) ($context['call_purpose'] ?? self::PURPOSE_PRODUCT_MATCH);
        $docContext = isset($context['document_context']) ? trim((string) $context['document_context']) : null;
        $hash = $this->buildCacheKey($expected, $actual, $callPurpose, $docContext);
        $cached = $this->getFromCache($hash);

        if ($cached !== null) {
            Logger::info('SemanticMatchJudge: cache hit', ['hash' => $hash]);
            $cached['cache_hit'] = true;
            $cached['gemini_metrics'] = $this->enrichMetrics(
                GeminiCallMetrics::cacheHit([
                    'task_type' => GeminiGateway::TASK_SEMANTIC_MATCH,
                    'document_type' => (string) ($context['document_type'] ?? ''),
                ]),
                $context
            );
            return $cached;
        }

        $result = $this->callGemini($expected, $actual, $context);
        if (($result['cacheable'] ?? true) === true) {
            $this->putInCache($hash, $result);
        }
        unset($result['cacheable']);

        return $result;
    }

    private function buildCacheKey(string $expected, string $actual, string $callPurpose, ?string $docContext = null): string
    {
        $namespace = $callPurpose === self::PURPOSE_PERSON_MATCH
            ? self::CACHE_NAMESPACE_PERSON
            : self::CACHE_NAMESPACE_PRODUCT;
        $elements = [trim(strtolower($expected)), trim(strtolower($actual))];
        sort($elements, SORT_STRING);
        if ($docContext !== null && $docContext !== '') {
            $elements[] = 'ctx:' . strtolower($docContext);
        }
        $payload = implode('|', $elements);
        $hash = hash('sha256', $payload);
        return "{$namespace}:{$hash}";
    }

    /**
     * @return array{is_match: bool, reasoning: string}|null
     */
    private function getFromCache(string $key): ?array
    {
        if (!$this->redis->isAvailable()) {
            return null;
        }

        try {
            $raw = $this->redis->get($key);
            if ($raw === null) {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['is_match'], $decoded['reasoning'])) {
                return [
                    'is_match' => (bool) $decoded['is_match'],
                    'reasoning' => (string) $decoded['reasoning'],
                ];
            }
        } catch (\Throwable $e) {
            Logger::warning('SemanticMatchJudge: cache read error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function putInCache(string $key, array $result): void
    {
        if (!$this->redis->isAvailable()) {
            return;
        }

        try {
            $payload = [
                'is_match' => (bool) ($result['is_match'] ?? false),
                'reasoning' => (string) ($result['reasoning'] ?? ''),
            ];
            $this->redis->set($key, json_encode($payload, JSON_UNESCAPED_UNICODE), self::CACHE_TTL);
        } catch (\Throwable $e) {
            Logger::warning('SemanticMatchJudge: cache write error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array{is_match: bool, reasoning: string, gemini_metrics?: array<string,mixed>, cache_hit?: bool, cacheable?: bool}
     */
    private function callGemini(string $expected, string $actual, array $context): array
    {
        $callPurpose = (string) ($context['call_purpose'] ?? self::PURPOSE_PRODUCT_MATCH);
        $isPersonName = $callPurpose === self::PURPOSE_PERSON_MATCH;
        [$prompt, $systemInstruction, $schema] = $this->promptBuilder->buildContract(
            $callPurpose,
            $expected,
            $actual,
            $context
        );

        $debugContext = array_filter(
            array_intersect_key($context, array_flip([
                'audit_id',
                'document_id',
                'dis_det_nro',
                'document_type',
                'field',
                'tipoCampo',
                'tipoDato',
                'call_purpose',
            ])),
            static fn(mixed $v): bool => $v !== null,
        );

        try {
            $response = $this->gateway->sendWithStructuredOutput(
                $prompt,
                [],
                $systemInstruction,
                $schema,
                GeminiGateway::TASK_SEMANTIC_MATCH,
                GeminiConfig::generationOverridesFromEnv('GEMINI_SEMANTIC', [
                    'maxOutputTokens' => 2048,
                ]),
                $debugContext
            );

            $metrics = is_array($response['X-Audit-Metrics'] ?? null)
                ? $response['X-Audit-Metrics']
                : GeminiCallMetrics::failed([
                    'task_type' => GeminiGateway::TASK_SEMANTIC_MATCH,
                    'document_type' => (string) ($context['document_type'] ?? ''),
                ]);
            $metrics = $this->enrichMetrics($metrics, $context);

            $parts = $response['candidates'][0]['content']['parts'] ?? null;
            $rawText = $parts[0]['text'] ?? null;
            $args = (is_string($rawText) && trim($rawText) !== '')
                ? json_decode($rawText, true)
                : null;

            if (!is_array($args)) {
                Logger::warning('SemanticMatchJudge: respuesta Gemini sin JSON estructurado válido', [
                    'finishReason' => (string) ($response['candidates'][0]['finishReason'] ?? ''),
                    'finishMessage' => (string) ($response['candidates'][0]['finishMessage'] ?? ''),
                    'call_purpose' => $callPurpose,
                    'expected' => $isPersonName ? '[REDACTED_PERSON_NAME]' : $expected,
                    'actual' => $isPersonName ? '[REDACTED_PERSON_NAME]' : $actual,
                ]);

                return $this->buildFailedResult($metrics);
            }

            return [
                'is_match' => $this->isConservativeMatch($args, $callPurpose),
                'reasoning' => $this->buildReasoning($args, $callPurpose),
                'gemini_metrics' => $metrics,
                'cache_hit' => false,
            ];

        } catch (\Throwable $e) {
            Logger::error('SemanticMatchJudge: falla al llamar a Gemini', [
                'error' => $e->getMessage(),
                'call_purpose' => $callPurpose,
                'document_type' => (string) ($context['document_type'] ?? ''),
                'expected' => $isPersonName ? '[REDACTED_PERSON_NAME]' : $expected,
                'actual' => $isPersonName ? '[REDACTED_PERSON_NAME]' : $actual,
            ]);
            return $this->buildFailedResult($this->enrichMetrics(GeminiCallMetrics::failed([
                'task_type' => GeminiGateway::TASK_SEMANTIC_MATCH,
                'document_type' => (string) ($context['document_type'] ?? ''),
            ]), $context));
        }
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function enrichMetrics(array $metrics, array $context): array
    {
        foreach (['field', 'tipoCampo', 'tipoDato', 'document_type', 'call_purpose'] as $key) {
            if (isset($context[$key])) {
                $metrics[$key] = $context[$key];
            }
        }

        return $metrics;
    }

    /**
     * Valida de manera conservadora los criterios de equivalencia semántica.
     *
     * Para productos e insumos farmacéuticos, exige consistencia total (uso clínico, dosis,
     * tecnología y presentación) y ausencia explícita de discrepancias sin resolver.
     *
     * @param array<string,mixed> $args
     */
    private function isConservativeMatch(array $args, string $callPurpose = self::PURPOSE_PRODUCT_MATCH): bool
    {
        if ($callPurpose === self::PURPOSE_PERSON_MATCH) {
            return ($args['is_match'] ?? null) === true;
        }

        return ($args['is_match'] ?? null) === true
            && ($args['same_clinical_use'] ?? null) === true
            && ($args['same_dimensions_or_dose'] ?? null) === true
            && ($args['same_material_or_technology'] ?? null) === true
            && ($args['presentation_compatible'] ?? null) === true
            && ($args['unresolved_differences'] ?? null) === false;
    }

    /**
     * Construye la justificación técnica de la decisión de arbitraje.
     *
     * @param array<string,mixed> $args
     */
    private function buildReasoning(array $args, string $callPurpose = self::PURPOSE_PRODUCT_MATCH): string
    {
        $reasoning = trim((string) ($args['reasoning'] ?? 'Sin justificación'));
        if ($reasoning === '') {
            $reasoning = 'Sin justificación';
        }

        if (($args['is_match'] ?? null) === true && !$this->isConservativeMatch($args, $callPurpose)) {
            return 'Evidencia semántica insuficiente: ' . $reasoning;
        }

        return $reasoning;
    }

    /** @return array{is_match: bool, reasoning: string, gemini_metrics: array<string,mixed>, cache_hit: bool, cacheable: bool} */
    private function buildFailedResult(array $metrics): array
    {
        return [
            'is_match' => false,
            'reasoning' => self::FALLBACK_REASONING,
            'gemini_metrics' => $metrics,
            'cache_hit' => false,
            'cacheable' => false,
        ];
    }
}
