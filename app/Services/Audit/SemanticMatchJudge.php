<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Audit\Debug\GeminiCallMetrics;
use Core\Logger;
use Core\RedisClient;

final class SemanticMatchJudge
{
    private const CACHE_TTL = 2592000; // 30 dias
    private const FALLBACK_REASONING = 'No fue posible confirmar equivalencia semántica; requiere revisión humana.';

    private GeminiGateway $gateway;
    private RedisClient $redis;

    public function __construct(GeminiGateway $gateway, ?RedisClient $redis = null)
    {
        $this->gateway = $gateway;
        $this->redis = $redis ?? RedisClient::getInstance();
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

        $hash = $this->buildCacheKey($expected, $actual);
        $cached = $this->getFromCache($hash);

        if ($cached !== null) {
            Logger::info('SemanticMatchJudge: cache hit', ['hash' => $hash]);
            $cached['cache_hit'] = true;
            return $cached;
        }

        $result = $this->callGemini($expected, $actual, $context);
        if (($result['cacheable'] ?? true) === true) {
            $this->putInCache($hash, $result);
        }
        unset($result['cacheable']);

        return $result;
    }

    private function buildCacheKey(string $expected, string $actual): string
    {
        $elements = [trim(strtolower($expected)), trim(strtolower($actual))];
        sort($elements);
        $payload = implode('|', $elements);
        $hash = hash('sha256', $payload);
        return "audfact:semantic:match:article:{$hash}";
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
        $prompt = "Producto Esperado (Registro de Dispensación): \"{$expected}\"\nProducto Entregado (Documento): \"{$actual}\"";

        $systemInstruction = "Eres un auditor experto de salud en Colombia. Determina si el Producto Esperado y el Producto Entregado son comercial o clínicamente intercambiables (ej. genérico vs marca, diferente gramaje si la dosis es adaptable). Responde is_match=true SOLO si tienes una confianza determinística absoluta en la homologación. Debes proveer un reasoning breve (max 100 caracteres) de tu decisión.";

        $schema = [
            'name' => 'report_semantic_match',
            'description' => 'Reporta si dos productos farmacéuticos son homologables.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'is_match' => [
                        'type' => 'boolean',
                        'description' => 'True si son equivalentes, false en caso contrario.'
                    ],
                    'reasoning' => [
                        'type' => 'string',
                        'description' => 'Justificación clínica o comercial, máximo 100 caracteres.'
                    ]
                ],
                'required' => ['is_match', 'reasoning']
            ]
        ];

        $toolConfig = [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => ['report_semantic_match'],
            ],
        ];

        $debugContext = array_filter(
            array_intersect_key($context, array_flip(['audit_id', 'document_id', 'dis_det_nro', 'document_type'])),
            static fn(mixed $v): bool => $v !== null,
        );
        $debugContext['task_type'] = 'semantic_match';

        try {
            $response = $this->gateway->sendWithFunctionCalling(
                $prompt,
                [],
                $systemInstruction,
                [['functionDeclarations' => [$schema]]],
                $toolConfig,
                GeminiConfig::generationOverridesFromEnv('GEMINI_SEMANTIC', [
                    'maxOutputTokens' => 2048,
                ]),
                $debugContext
            );

            $metrics = is_array($response['X-Audit-Metrics'] ?? null)
                ? $response['X-Audit-Metrics']
                : GeminiCallMetrics::failed([
                    'task_type' => 'semantic_match',
                    'document_type' => (string) ($context['document_type'] ?? ''),
                ]);

            $parts = $response['candidates'][0]['content']['parts'] ?? null;
            if (!is_array($parts) || !isset($parts[0]['functionCall']['args'])) {
                Logger::warning('SemanticMatchJudge: respuesta Gemini sin functionCall válido', [
                    'finishReason' => (string) ($response['candidates'][0]['finishReason'] ?? ''),
                    'finishMessage' => (string) ($response['candidates'][0]['finishMessage'] ?? ''),
                    'expected' => $expected,
                    'actual' => $actual,
                ]);

                return $this->buildFailedResult($metrics);
            }

            $args = $parts[0]['functionCall']['args'];
            return [
                'is_match' => (bool) ($args['is_match'] ?? false),
                'reasoning' => (string) ($args['reasoning'] ?? 'Sin justificación'),
                'gemini_metrics' => $metrics,
                'cache_hit' => false,
            ];

        } catch (\Throwable $e) {
            Logger::error('SemanticMatchJudge: falla al llamar a Gemini', [
                'error' => $e->getMessage(),
                'expected' => $expected,
                'actual' => $actual,
            ]);
            return $this->buildFailedResult(GeminiCallMetrics::failed([
                'task_type' => 'semantic_match',
                'document_type' => (string) ($context['document_type'] ?? ''),
            ]));
        }
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
