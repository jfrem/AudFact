<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Debug;

use App\Services\Audit\Debug\GeminiCallMetrics;
use PHPUnit\Framework\TestCase;

final class GeminiCallMetricsTest extends TestCase
{
    public function testBuildsMetricsFromGeminiUsageMetadata(): void
    {
        $metrics = GeminiCallMetrics::fromResponse(
            [
                'modelVersion' => 'gemini-3.1-pro-preview',
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
                'cache_hit' => false,
            ],
            [
                'duration_ms' => 0,
                'prompt_tokens' => 0,
                'output_tokens' => 0,
                'thoughts_tokens' => 0,
                'total_tokens' => 0,
                'cache_hit' => true,
            ],
        ]);

        $this->assertSame(2, $summary['count']);
        $this->assertSame(1, $summary['cache_hits']);
        $this->assertSame(50, $summary['avg_ms']);
        $this->assertSame(10, $summary['prompt_tokens']);
        $this->assertSame(5, $summary['output_tokens']);
        $this->assertSame(20, $summary['thoughts_tokens']);
        $this->assertSame(35, $summary['total_tokens']);
    }
}
