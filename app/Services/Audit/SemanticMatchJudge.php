<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\Logger;
use Core\RedisClient;
use RuntimeException;

final class SemanticMatchJudge
{
    private const CACHE_TTL = 2592000; // 30 dias

    private GeminiGateway $gateway;
    private RedisClient $redis;

    public function __construct(GeminiGateway $gateway, ?RedisClient $redis = null)
    {
        $this->gateway = $gateway;
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    /**
     * @param array<string,mixed> $context
     * @return array{is_match: bool, reasoning: string}
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
            return $cached;
        }

        $result = $this->callGemini($expected, $actual, $context);
        $this->putInCache($hash, $result);

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
            $this->redis->set($key, json_encode($result, JSON_UNESCAPED_UNICODE), self::CACHE_TTL);
        } catch (\Throwable $e) {
            Logger::warning('SemanticMatchJudge: cache write error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array{is_match: bool, reasoning: string}
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

        $debugContext = [];
        if (isset($context['audit_id'])) {
            $debugContext['audit_id'] = $context['audit_id'];
        }
        if (isset($context['document_id'])) {
            $debugContext['document_id'] = $context['document_id'];
        }
        if (isset($context['dis_det_nro'])) {
            $debugContext['dis_det_nro'] = $context['dis_det_nro'];
        }
        if (isset($context['document_type'])) {
            $debugContext['document_type'] = $context['document_type'];
        }

        try {
            $response = $this->gateway->sendWithFunctionCalling(
                $prompt,
                [],
                $systemInstruction,
                [['functionDeclarations' => [$schema]]],
                $toolConfig,
                [],
                $debugContext
            );

            $parts = $response['candidates'][0]['content']['parts'] ?? null;
            if (!is_array($parts) || !isset($parts[0]['functionCall']['args'])) {
                throw new RuntimeException('Respuesta Gemini sin functionCall válido');
            }

            $args = $parts[0]['functionCall']['args'];
            return [
                'is_match' => (bool) ($args['is_match'] ?? false),
                'reasoning' => (string) ($args['reasoning'] ?? 'Sin justificación'),
            ];

        } catch (\Throwable $e) {
            Logger::error('SemanticMatchJudge: falla al llamar a Gemini', [
                'error' => $e->getMessage(),
                'expected' => $expected,
                'actual' => $actual,
            ]);
            return [
                'is_match' => false,
                'reasoning' => 'Error de evaluación semántica: ' . $e->getMessage(),
            ];
        }
    }
}
