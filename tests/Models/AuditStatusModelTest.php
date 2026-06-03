<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\AuditStatusModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuditStatusModelTest extends TestCase
{
    public function testManualReviewWithPersistedAuditPayloadIsAuditExecuted(): void
    {
        $summary = $this->buildAuditSummary(
            [
                'FacSec' => 'DIS26-5-12-10-44-32-17794FE34E27F8AB1975AD0BC1E33F6C9EC-1-1',
                'FacNro' => 'D13260500540',
                'EstAud' => 0,
                'EstadoDetallado' => 'manual_review',
                'DocumentosProcesados' => 3,
            ],
            [
                'findings' => [
                    ['campo' => 'Lote', 'resultado' => 'COINCIDE'],
                ],
                'metrics' => ['total_campos' => 1],
                'timings' => ['docs_total' => 3],
            ]
        );

        $this->assertTrue($summary['auditExecuted']);
    }

    public function testPendingWithoutPersistedAuditPayloadIsNotAuditExecuted(): void
    {
        $summary = $this->buildAuditSummary(
            [
                'FacSec' => 'PENDING',
                'FacNro' => 'PENDING',
                'EstAud' => 0,
                'EstadoDetallado' => 'pending',
                'DocumentosProcesados' => 0,
            ],
            [
                'findings' => [],
                'metrics' => ['total_campos' => 0],
                'timings' => null,
            ]
        );

        $this->assertFalse($summary['auditExecuted']);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildAuditSummary(array $row, array $payload): array
    {
        $reflection = new ReflectionClass(AuditStatusModel::class);
        /** @var AuditStatusModel $model */
        $model = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('buildAuditSummary');
        $method->setAccessible(true);

        return $method->invoke($model, $row, $payload);
    }
}
