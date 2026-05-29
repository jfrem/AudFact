<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditTimingSummarizer;
use PHPUnit\Framework\TestCase;

final class AuditTimingSummarizerTest extends TestCase
{
    public function testResolvesProcessingQueueAndElapsedDurations(): void
    {
        $audit = [
            'created_at' => '2026-05-23T10:00:00.000000Z',
            'started_at' => '2026-05-23T10:00:02.500000Z',
            'rules_evaluated_at' => '2026-05-23T10:00:10.000000Z',
            'aggregation_timings' => [
                'sql_persist_ms' => 250,
                'redis_complete_ms' => 25,
            ],
            'event_timings' => [[
                'event_type' => 'audit_created',
                'stream' => 'audit.inbox',
                'queue_wait_ms' => 2500,
                'handle_duration_ms' => 40,
                'ack_duration_ms' => 2,
            ], [
                'event_type' => 'document_registered',
                'stream' => 'audit.documents',
                'queue_wait_ms' => 1200,
                'handle_duration_ms' => 100,
                'ack_duration_ms' => 3,
            ]],
            'documents' => [
                [
                    'extraction_duration_ms' => 100,
                    'normalization_duration_ms' => 20,
                    'policy_duration_ms' => 30,
                    'download_duration_ms' => 15,
                    'gemini_duration_ms' => 80,
                    'cache_hit' => true,
                    'gemini_metrics' => [
                        'duration_ms' => 80,
                        'prompt_tokens' => 10,
                        'output_tokens' => 5,
                        'total_tokens' => 15,
                    ],
                ],
            ],
        ];

        $now = new \DateTimeImmutable('2026-05-23T10:00:12.500000Z');

        $summary = AuditTimingSummarizer::buildPhaseTimings($audit, $now);

        $this->assertSame(10000, $summary['processing_duration_ms']);
        $this->assertSame(2500, $summary['queue_wait_ms']);
        $this->assertSame(12500, $summary['total_elapsed_ms']);
        $this->assertSame(12500, $summary['pipeline']['created_to_completed_ms']);
        $this->assertSame(2500, $summary['pipeline']['created_to_started_ms']);
        $this->assertSame(2500, $summary['pipeline']['rules_to_completed_ms']);
        $this->assertSame(2, $summary['event_telemetry']['count']);
        $this->assertSame(2500, $summary['event_telemetry']['by_stream']['audit.inbox']['queue_wait']['avg_ms']);
        $this->assertSame(1200, $summary['event_telemetry']['by_stream']['audit.documents']['queue_wait']['avg_ms']);
        $this->assertSame(250, $summary['aggregation']['sql_persist_ms']);
        $this->assertSame(1.0, $summary['cache_hit_rate']);
        $this->assertSame(1, $summary['extraction']['count']);
        $this->assertSame(15, $summary['gemini_total']['total_tokens']);
    }

    public function testUsesCompletedAtAsTerminalDurationEnd(): void
    {
        $audit = [
            'created_at' => '2026-05-23T10:00:00.000000Z',
            'started_at' => '2026-05-23T10:00:01.000000Z',
            'completed_at' => '2026-05-23T10:00:04.250000Z',
        ];

        $now = new \DateTimeImmutable('2026-05-23T10:01:00.000000Z');

        $this->assertSame(3250, AuditTimingSummarizer::resolveDurationMs($audit, $now));
        $this->assertSame(4250, AuditTimingSummarizer::resolveTotalElapsedMs($audit, $now));
    }

    public function testFallsBackToCreatedAtWhenStartedAtIsMissing(): void
    {
        $audit = [
            'created_at' => '2026-05-23T10:00:00.000000Z',
        ];

        $now = new \DateTimeImmutable('2026-05-23T10:00:03.000000Z');

        $this->assertSame(3000, AuditTimingSummarizer::resolveDurationMs($audit, $now));
        $this->assertSame(0, AuditTimingSummarizer::resolveQueueWaitMs($audit));
    }
}
