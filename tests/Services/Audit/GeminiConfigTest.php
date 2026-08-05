<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiConfig;
use App\Services\Audit\GeminiGateway;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeminiConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['GEMINI_TEST_A', 'GEMINI_TEST_B', 'GEMINI_TEST_C'] as $prefix) {
            putenv("{$prefix}_MAX_OUTPUT_TOKENS");
            putenv("{$prefix}_THINKING_BUDGET");
            putenv("{$prefix}_THINKING_LEVEL");
        }
    }

    public function testBuildsGenerationOverridesFromTaskPrefix(): void
    {
        putenv('GEMINI_TEST_A_MAX_OUTPUT_TOKENS=256');
        putenv('GEMINI_TEST_A_THINKING_LEVEL=low');

        $overrides = GeminiConfig::generationOverridesFromEnv('GEMINI_TEST_A');

        $this->assertSame(256, $overrides['maxOutputTokens']);
        $this->assertSame('low', $overrides['thinkingLevel']);
    }

    public function testSkipsEmptyGenerationOverrideValues(): void
    {
        putenv('GEMINI_TEST_B_MAX_OUTPUT_TOKENS=');
        putenv('GEMINI_TEST_B_THINKING_BUDGET=');
        putenv('GEMINI_TEST_B_THINKING_LEVEL=');

        $this->assertSame([], GeminiConfig::generationOverridesFromEnv('GEMINI_TEST_B'));
    }

    public function testUsesDefaultsWhenTaskValuesAreMissing(): void
    {
        $overrides = GeminiConfig::generationOverridesFromEnv('GEMINI_TEST_C', [
            'maxOutputTokens' => 2048,
            'thinkingLevel' => 'low',
        ]);

        $this->assertSame(2048, $overrides['maxOutputTokens']);
        $this->assertSame('low', $overrides['thinkingLevel']);
    }

    public function testThinkingLevelTakesPrecedenceOverThinkingBudgetWithoutMediaResolutionByDefault(): void
    {
        $config = new GeminiConfig(
            model: 'gemini-3.5-flash',
            mediaResolution: 'medium',
            thinkingBudget: 128,
            thinkingLevel: 'low'
        );

        $generationConfig = $config->toGenerationConfig([
            'thinkingBudget' => 0,
            'thinkingLevel' => 'high',
        ]);

        $this->assertSame(['thinkingLevel' => 'high'], $generationConfig['thinkingConfig']);
        $this->assertArrayNotHasKey('responseMimeType', $generationConfig);
        $this->assertArrayNotHasKey('mediaResolution', $generationConfig);
    }

    public function testDoesNotIncludeMediaResolutionEvenWhenRequested(): void
    {
        $config = new GeminiConfig(
            model: 'gemini-3.5-flash',
            mediaResolution: 'medium'
        );

        $generationConfig = $config->toGenerationConfig(includeMediaResolution: true);

        $this->assertArrayNotHasKey('mediaResolution', $generationConfig);
    }

    public function testGatewayAppliesMediaResolutionOnlyForExtractionProfile(): void
    {
        $gateway = new GeminiGateway(
            new Client(),
            'test-key',
            new GeminiConfig(
                model: 'gemini-3.5-flash',
                mediaResolution: 'medium'
            )
        );
        $buildPayload = new \ReflectionMethod(GeminiGateway::class, 'buildPayload');
        $buildPayload->setAccessible(true);

        $semanticPayload = $buildPayload->invoke(
            $gateway,
            'prompt',
            [],
            'system',
            [['functionDeclarations' => [$this->toolDeclaration()]]],
            $this->toolConfig(),
            GeminiGateway::TASK_SEMANTIC_MATCH,
            []
        );

        $extractionPayload = $buildPayload->invoke(
            $gateway,
            'prompt',
            [['mime' => 'application/pdf', 'data' => base64_encode('pdf'), 'label' => 'DOC']],
            'system',
            [['functionDeclarations' => [$this->toolDeclaration()]]],
            $this->toolConfig(),
            GeminiGateway::TASK_EXTRACTION,
            []
        );

        $this->assertArrayNotHasKey('mediaResolution', $semanticPayload['generationConfig']);
        $this->assertArrayNotHasKey('mediaResolution', $extractionPayload['generationConfig']);
    }

    public function testGatewayRejectsFilesForSemanticProfile(): void
    {
        $gateway = new GeminiGateway(
            new Client(),
            'test-key',
            new GeminiConfig(model: 'gemini-3.5-flash')
        );
        $buildPayload = new \ReflectionMethod(GeminiGateway::class, 'buildPayload');
        $buildPayload->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('semantic_match no acepta archivos');

        $buildPayload->invoke(
            $gateway,
            'prompt',
            [['mime' => 'application/pdf', 'data' => base64_encode('pdf'), 'label' => 'DOC']],
            'system',
            [['functionDeclarations' => [$this->toolDeclaration()]]],
            $this->toolConfig(),
            GeminiGateway::TASK_SEMANTIC_MATCH,
            []
        );
    }

    public function testRejectsInvalidGenerationOverridePrefix(): void
    {
        $this->expectException(RuntimeException::class);

        GeminiConfig::generationOverridesFromEnv('GEMINI-TEST');
    }

    /**
     * @return array<string,mixed>
     */
    private function toolDeclaration(): array
    {
        return [
            'name' => 'test_function',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                ],
                'required' => ['ok'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toolConfig(): array
    {
        return [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => ['test_function'],
            ],
        ];
    }
}
