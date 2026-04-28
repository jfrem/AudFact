<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiGateway;
use App\Services\Audit\SemanticMatchJudge;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SemanticMatchJudgeTest extends TestCase
{
    public function testGeminiFailureReturnsCleanNonCacheableFallback(): void
    {
        $gateway = new ThrowingSemanticGeminiGateway();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
            'Cureband premium gasa antiadherente esteril',
            ['document_type' => 'AUTORIZACION']
        );

        $this->assertFalse($result['is_match']);
        $this->assertSame(
            'No fue posible confirmar equivalencia semántica; requiere revisión humana.',
            $result['reasoning']
        );
        $this->assertStringNotContainsString('Error de evaluación semántica', $result['reasoning']);
        $this->assertStringNotContainsString('Budget 0 is invalid', $result['reasoning']);
        $this->assertSame(['maxOutputTokens' => 256, 'thinkingLevel' => 'low'], $gateway->lastGenerationOverrides);
    }

    public function testMalformedFunctionCallKeepsGeminiMetricsAndCleanFallback(): void
    {
        $gateway = new MalformedSemanticGeminiGateway();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
            'Cureband premium gasa antiadherente esteril',
            ['document_type' => 'AUTORIZACION']
        );

        $this->assertFalse($result['is_match']);
        $this->assertSame(
            'No fue posible confirmar equivalencia semántica; requiere revisión humana.',
            $result['reasoning']
        );
        $this->assertSame(268, $result['gemini_metrics']['total_tokens'] ?? null);
        $this->assertSame(['maxOutputTokens' => 256, 'thinkingLevel' => 'low'], $gateway->lastGenerationOverrides);
    }
}

final class ThrowingSemanticGeminiGateway extends GeminiGateway
{
    /** @var array<string,mixed> */
    public array $lastGenerationOverrides = [];

    public function __construct()
    {
    }

    /**
     * @param  array<int,array<string,mixed>> $files
     * @param  array<int,array<string,mixed>> $tools
     * @param  array<string,mixed> $toolConfig
     * @param  array<string,mixed> $generationOverrides
     * @param  array<string,mixed>|null $debugContext
     * @return array<string,mixed>
     */
    public function sendWithFunctionCalling(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastGenerationOverrides = $generationOverrides;

        throw new RuntimeException('Budget 0 is invalid. This model only works in thinking mode.');
    }
}

final class MalformedSemanticGeminiGateway extends GeminiGateway
{
    /** @var array<string,mixed> */
    public array $lastGenerationOverrides = [];

    public function __construct()
    {
    }

    /**
     * @param  array<int,array<string,mixed>> $files
     * @param  array<int,array<string,mixed>> $tools
     * @param  array<string,mixed> $toolConfig
     * @param  array<string,mixed> $generationOverrides
     * @param  array<string,mixed>|null $debugContext
     * @return array<string,mixed>
     */
    public function sendWithFunctionCalling(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastGenerationOverrides = $generationOverrides;

        return [
            'candidates' => [[
                'content' => [],
                'finishReason' => 'MALFORMED_FUNCTION_CALL',
            ]],
            'X-Audit-Metrics' => [
                'task_type' => 'semantic_match',
                'total_tokens' => 268,
            ],
        ];
    }
}
