<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Audit\GeminiCallMetrics;
use Core\Logger;
use Core\RedisClient;

final class ArticleSemanticMatchJudge
{
    private const CACHE_TTL = 2592000; // 30 dias
    private const CACHE_NAMESPACE = 'audfact:semantic:match:v3:article';
    private const CACHE_NAMESPACE_PERSON = 'audfact:semantic:match:v1:person';
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

        $callPurpose = (string) ($context['call_purpose'] ?? 'article_homologation');
        $hash = $this->buildCacheKey($expected, $actual, $callPurpose);
        $cached = $this->getFromCache($hash);

        if ($cached !== null) {
            Logger::info('ArticleSemanticMatchJudge: cache hit', ['hash' => $hash]);
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

    private function buildCacheKey(string $expected, string $actual, string $callPurpose): string
    {
        $namespace = $callPurpose === 'person_name_match'
            ? self::CACHE_NAMESPACE_PERSON
            : self::CACHE_NAMESPACE;
        $elements = [trim(strtolower($expected)), trim(strtolower($actual))];
        sort($elements);
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
            Logger::warning('ArticleSemanticMatchJudge: cache read error', ['error' => $e->getMessage()]);
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
            Logger::warning('ArticleSemanticMatchJudge: cache write error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array{is_match: bool, reasoning: string, gemini_metrics?: array<string,mixed>, cache_hit?: bool, cacheable?: bool}
     */
    private function callGemini(string $expected, string $actual, array $context): array
    {
        $callPurpose = (string) ($context['call_purpose'] ?? 'article_homologation');
        $isPersonName = $callPurpose === 'person_name_match';

        if ($isPersonName) {
            [$prompt, $systemInstruction, $schema, $toolConfig] = $this->buildPersonNameContract($expected, $actual);
        } else {
            [$prompt, $systemInstruction, $schema, $toolConfig] = $this->buildArticleContract($expected, $actual);
        }

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
            $response = $this->gateway->sendWithFunctionCalling(
                $prompt,
                [],
                $systemInstruction,
                [['functionDeclarations' => [$schema]]],
                $toolConfig,
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
            if (!is_array($parts) || !isset($parts[0]['functionCall']['args'])) {
                Logger::warning('ArticleSemanticMatchJudge: respuesta Gemini sin functionCall válido', [
                    'finishReason' => (string) ($response['candidates'][0]['finishReason'] ?? ''),
                    'finishMessage' => (string) ($response['candidates'][0]['finishMessage'] ?? ''),
                    'expected' => $expected,
                    'actual' => $actual,
                ]);

                return $this->buildFailedResult($metrics);
            }

            $args = $parts[0]['functionCall']['args'];
            return [
                'is_match' => $this->isConservativeMatch($args, $callPurpose),
                'reasoning' => $this->buildReasoning($args, $callPurpose),
                'gemini_metrics' => $metrics,
                'cache_hit' => false,
            ];

        } catch (\Throwable $e) {
            Logger::error('ArticleSemanticMatchJudge: falla al llamar a Gemini', [
                'error' => $e->getMessage(),
                'expected' => $expected,
                'actual' => $actual,
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
     * @param array<string,mixed> $args
     */
    private function isConservativeMatch(array $args, string $callPurpose = 'article_homologation'): bool
    {
        // Person name: solo requiere is_match
        if ($callPurpose === 'person_name_match') {
            return ($args['is_match'] ?? null) === true;
        }

        // Article homologation: validación conservadora multi-criterio
        return ($args['is_match'] ?? null) === true
            && ($args['same_clinical_use'] ?? null) === true
            && ($args['same_dimensions_or_dose'] ?? null) === true
            && ($args['same_material_or_technology'] ?? null) === true
            && ($args['presentation_compatible'] ?? null) === true
            && ($args['unresolved_differences'] ?? null) === false;
    }

    /**
     * @param array<string,mixed> $args
     */
    private function buildReasoning(array $args, string $callPurpose = 'article_homologation'): string
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

    // ─── Contract Builders ──────────────────────────────────────

    /**
     * Contrato Gemini para homologación de artículos farmacéuticos.
     *
     * @return array{0: string, 1: string, 2: array<string,mixed>, 3: array<string,mixed>}
     */
    private function buildArticleContract(string $expected, string $actual): array
    {
        $prompt = "Producto Esperado (Registro de Dispensación): \"{$expected}\"\nProducto Entregado (Documento): \"{$actual}\"";

        $systemInstruction = "Eres un auditor experto de salud en Colombia. Determina si el Producto Esperado y el Producto Entregado son comercial o clínicamente intercambiables. Responde is_match=true SOLO si no hay diferencias materiales, de presentación, dimensión/dosis o uso clínico que impidan homologación determinística. Ante duda, diferencias no resueltas o evidencia insuficiente, is_match=false.";

        $schema = [
            'name' => 'report_semantic_match',
            'description' => 'Reporta si dos productos farmacéuticos son homologables.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'is_match' => [
                        'type' => 'boolean',
                        'description' => 'True solo si todos los criterios críticos soportan equivalencia determinística.'
                    ],
                    'same_clinical_use' => [
                        'type' => 'boolean',
                        'description' => 'True si el uso clínico/comercial auditable es equivalente.'
                    ],
                    'same_dimensions_or_dose' => [
                        'type' => 'boolean',
                        'description' => 'True si medida, dosis o concentración relevantes son equivalentes.'
                    ],
                    'same_material_or_technology' => [
                        'type' => 'boolean',
                        'description' => 'True si material, tecnología o característica crítica son equivalentes.'
                    ],
                    'presentation_compatible' => [
                        'type' => 'boolean',
                        'description' => 'True si presentación, empaque o cantidad comercial no contradicen la equivalencia.'
                    ],
                    'unresolved_differences' => [
                        'type' => 'boolean',
                        'description' => 'True si existe alguna diferencia relevante sin resolver.'
                    ],
                    'reasoning' => [
                        'type' => 'string',
                        'description' => 'Justificación clínica o comercial breve.'
                    ]
                ],
                'required' => [
                    'is_match',
                    'same_clinical_use',
                    'same_dimensions_or_dose',
                    'same_material_or_technology',
                    'presentation_compatible',
                    'unresolved_differences',
                    'reasoning',
                ]
            ]
        ];

        $toolConfig = [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => ['report_semantic_match'],
            ],
        ];

        return [$prompt, $systemInstruction, $schema, $toolConfig];
    }

    /**
     * Contrato Gemini para verificación de identidad de persona.
     *
     * Schema simplificado: solo is_match + reasoning.
     * No requiere campos farmacéuticos.
     *
     * @return array{0: string, 1: string, 2: array<string,mixed>, 3: array<string,mixed>}
     */
    private function buildPersonNameContract(string $expected, string $actual): array
    {
        $prompt = "Nombre Esperado (Registro de Dispensación): \"{$expected}\"\nNombre del Documento: \"{$actual}\"";

        $systemInstruction = 'Eres un auditor experto en verificación de identidad en el sector salud colombiano. '
            . 'Determina si el Nombre Esperado y el Nombre del Documento se refieren a la misma persona. '
            . 'Considera variaciones comunes: iniciales, apellidos parciales, títulos profesionales, '
            . 'abreviaciones de nombres y diferente orden de componentes. '
            . 'Responde is_match=true SOLO si hay suficiente evidencia de que se trata de la misma persona. '
            . 'Ante duda o evidencia insuficiente, is_match=false.';

        $schema = [
            'name' => 'report_person_name_match',
            'description' => 'Reporta si dos nombres de persona se refieren al mismo individuo.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'is_match' => [
                        'type' => 'boolean',
                        'description' => 'True si ambos nombres se refieren a la misma persona.'
                    ],
                    'reasoning' => [
                        'type' => 'string',
                        'description' => 'Justificación breve de la decisión.'
                    ]
                ],
                'required' => ['is_match', 'reasoning']
            ]
        ];

        $toolConfig = [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => ['report_person_name_match'],
            ],
        ];

        return [$prompt, $systemInstruction, $schema, $toolConfig];
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
