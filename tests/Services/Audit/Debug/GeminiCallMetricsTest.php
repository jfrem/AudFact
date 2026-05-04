<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Debug;

use App\Services\Audit\GeminiCallMetrics;
use PHPUnit\Framework\TestCase;

final class GeminiCallMetricsTest extends TestCase
{
    public function testBuildsMetricsFromGeminiUsageMetadata(): void
    {
        $metrics = GeminiCallMetrics::fromResponse(
            [
                'modelVersion' => 'gemini-3.1-pro-preview',
                'candidates' => [[
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 1464,
                    'candidatesTokenCount' => 569,
                    'thoughtsTokenCount' => 2073,
                    'totalTokenCount' => 4106,
                ],
            ],
            24435,
            [
                'task_type' => 'extraction',
                'document_type' => 'DISPENSA',
            ]
        );

        $this->assertSame(24435, $metrics['duration_ms']);
        $this->assertSame('gemini-3.1-pro-preview', $metrics['model']);
        $this->assertSame('extraction', $metrics['task_type']);
        $this->assertSame('DISPENSA', $metrics['document_type']);
        $this->assertSame('STOP', $metrics['finish_reason']);
        $this->assertFalse($metrics['cache_hit']);
        $this->assertSame(1464, $metrics['prompt_tokens']);
        $this->assertSame(569, $metrics['output_tokens']);
        $this->assertSame(2073, $metrics['thoughts_tokens']);
        $this->assertSame(4106, $metrics['total_tokens']);
    }

    public function testSummarizesMetricsWithoutPayloadContent(): void
    {
        $summary = GeminiCallMetrics::summarize([
            [
                'duration_ms' => 100,
                'prompt_tokens' => 10,
                'output_tokens' => 5,
                'thoughts_tokens' => 20,
                'total_tokens' => 35,
                'finish_reason' => 'STOP',
                'cache_hit' => false,
            ],
            [
                'duration_ms' => 0,
                'prompt_tokens' => 0,
                'output_tokens' => 0,
                'thoughts_tokens' => 0,
                'total_tokens' => 0,
                'finish_reason' => 'MAX_TOKENS',
                'cache_hit' => true,
            ],
            [
                'duration_ms' => 50,
                'prompt_tokens' => 4,
                'output_tokens' => 2,
                'thoughts_tokens' => 1,
                'total_tokens' => 7,
                'finish_reason' => 'STOP',
                'cache_hit' => false,
            ],
        ]);

        $this->assertSame(3, $summary['count']);
        $this->assertSame(1, $summary['cache_hits']);
        $this->assertSame(50, $summary['avg_ms']);
        $this->assertSame(14, $summary['prompt_tokens']);
        $this->assertSame(7, $summary['output_tokens']);
        $this->assertSame(21, $summary['thoughts_tokens']);
        $this->assertSame(42, $summary['total_tokens']);
        $this->assertSame(['MAX_TOKENS' => 1, 'STOP' => 2], $summary['finish_reasons']);
    }

    public function testSummarizesLegacyMetricsWithoutFinishReasons(): void
    {
        $summary = GeminiCallMetrics::summarize([
            [
                'duration_ms' => 100,
                'prompt_tokens' => 10,
                'output_tokens' => 5,
                'thoughts_tokens' => 20,
                'total_tokens' => 35,
                'cache_hit' => false,
            ],
        ]);

        $this->assertSame(1, $summary['count']);
        $this->assertArrayNotHasKey('finish_reasons', $summary);
    }
}
