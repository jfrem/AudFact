<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiGateway;
use App\Services\Audit\ArticleSemanticMatchJudge;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArticleSemanticMatchJudgeTest extends TestCase
{
    public function testGeminiFailureReturnsCleanNonCacheableFallback(): void
    {
        $gateway = new ThrowingSemanticGeminiGateway();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new ArticleSemanticMatchJudge($gateway, $redis);

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
        $this->assertSame(['maxOutputTokens' => 2048], $gateway->lastGenerationOverrides);
        $this->assertSame(GeminiGateway::TASK_SEMANTIC_MATCH, $gateway->lastTaskType);
    }

    public function testMalformedFunctionCallKeepsGeminiMetricsAndCleanFallback(): void
    {
        $gateway = new MalformedSemanticGeminiGateway();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new ArticleSemanticMatchJudge($gateway, $redis);

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
        $this->assertSame(['maxOutputTokens' => 2048], $gateway->lastGenerationOverrides);
        $this->assertSame(GeminiGateway::TASK_SEMANTIC_MATCH, $gateway->lastTaskType);
    }

    public function testGoldenCaseFalsePositiveIsRejectedByConservativeEvidence(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => true,
            'same_material_or_technology' => false,
            'presentation_compatible' => false,
            'unresolved_differences' => true,
            'confidence' => 'media',
            'reasoning' => 'Misma medida, presentación distinta.',
        ]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new ArticleSemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
            'Cureband premium gasa antiadherente esteril- 7.5cm x 7.5cm- sobre CAJA 18 unds',
            ['document_type' => 'AUTORIZACION']
        );

        $this->assertFalse($result['is_match']);
        $this->assertStringStartsWith('Evidencia semántica insuficiente:', $result['reasoning']);
        $this->assertSame(GeminiGateway::TASK_SEMANTIC_MATCH, $gateway->lastTaskType);
        $this->assertSame([], $gateway->lastFiles);
    }

    public function testSemanticCacheUsesVersionedContractNamespace(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => true,
            'same_material_or_technology' => true,
            'presentation_compatible' => true,
            'unresolved_differences' => false,
            'confidence' => 'alta',
            'reasoning' => 'Equivalentes.',
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->with($this->stringContains('audfact:semantic:match:v3:article:'))
            ->willReturn(null);
        $redis->expects($this->once())
            ->method('set')
            ->with(
                $this->stringContains('audfact:semantic:match:v3:article:'),
                $this->isType('string'),
                $this->equalTo(2592000)
            )
            ->willReturn(true);

        $judge = new ArticleSemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate('PRODUCTO A', 'PRODUCTO A');

        $this->assertTrue($result['is_match']);
    }

    public function testSemanticCacheHitReturnsLocalGeminiMetrics(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => false,
            'same_clinical_use' => false,
            'same_dimensions_or_dose' => false,
            'same_material_or_technology' => false,
            'presentation_compatible' => false,
            'unresolved_differences' => true,
            'confidence' => 'baja',
            'reasoning' => 'No debería invocar Gemini.',
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->willReturn(json_encode([
                'is_match' => false,
                'reasoning' => 'Resultado desde cache.',
            ]));
        $redis->expects($this->never())->method('set');

        $judge = new ArticleSemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'DULOXETINA 60MG C*30 CAPSULA',
            'DULOXETINA 60 MG-BLISTER 28 unds',
            ['document_type' => 'DISPENSA', 'field' => 'NombreArticulo']
        );

        $this->assertFalse($result['is_match']);
        $this->assertSame('Resultado desde cache.', $result['reasoning']);
        $this->assertTrue($result['cache_hit'] ?? false);
        $this->assertSame('semantic_match', $result['gemini_metrics']['task_type'] ?? null);
        $this->assertSame('DISPENSA', $result['gemini_metrics']['document_type'] ?? null);
        $this->assertTrue($result['gemini_metrics']['cache_hit'] ?? false);
        $this->assertSame('', $gateway->lastTaskType);
    }
}

final class ThrowingSemanticGeminiGateway extends GeminiGateway
{
    /** @var array<string,mixed> */
    public array $lastGenerationOverrides = [];
    public string $lastTaskType = '';

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
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastTaskType = $taskType;
        $this->lastGenerationOverrides = $generationOverrides;

        throw new RuntimeException('Budget 0 is invalid. This model only works in thinking mode.');
    }
}

final class MalformedSemanticGeminiGateway extends GeminiGateway
{
    /** @var array<string,mixed> */
    public array $lastGenerationOverrides = [];
    public string $lastTaskType = '';

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
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastTaskType = $taskType;
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

final class RecordingSemanticGeminiGateway extends GeminiGateway
{
    public string $lastTaskType = '';
    /** @var array<int,array<string,mixed>> */
    public array $lastFiles = [];

    /**
     * @param array<string,mixed> $args
     */
    public function __construct(private array $args)
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
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastTaskType = $taskType;
        $this->lastFiles = $files;

        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'name' => 'report_semantic_match',
                            'args' => $this->args,
                        ],
                    ]],
                ],
                'finishReason' => 'STOP',
            ]],
            'X-Audit-Metrics' => [
                'task_type' => 'semantic_match',
                'total_tokens' => 100,
            ],
        ];
    }
}
