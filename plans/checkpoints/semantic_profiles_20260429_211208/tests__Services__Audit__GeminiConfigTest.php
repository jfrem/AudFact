<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiConfig;
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

    public function testThinkingLevelTakesPrecedenceOverThinkingBudget(): void
    {
        $config = new GeminiConfig(
            model: 'gemini-3.1-pro-preview',
            responseMimeType: 'application/json',
            mediaResolution: 'MEDIA_RESOLUTION_HIGH',
            thinkingBudget: 128,
            thinkingLevel: 'low'
        );

        $generationConfig = $config->toGenerationConfig([
            'thinkingBudget' => 0,
            'thinkingLevel' => 'high',
        ]);

        $this->assertSame(['thinkingLevel' => 'high'], $generationConfig['thinkingConfig']);
        $this->assertArrayNotHasKey('responseMimeType', $generationConfig);
        $this->assertSame('MEDIA_RESOLUTION_HIGH', $generationConfig['mediaResolution']);
    }

    public function testRejectsInvalidGenerationOverridePrefix(): void
    {
        $this->expectException(RuntimeException::class);

        GeminiConfig::generationOverridesFromEnv('GEMINI-TEST');
    }
}
