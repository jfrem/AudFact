<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\AuditStatusModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuditStatusModelTest extends TestCase
{
    public function testManualReviewSummaryIsAuditExecuted(): void
    {
        $summary = $this->invokeMethod('normalizeAuditSummary', [
            [
                'DisId' => 'DIS26-5-12-10-44-32-17794FE34E27F8AB1975AD0BC1E33F6C9EC-1-1',
                'FacNro' => 'D13260500540',
                'EstAud' => 0,
                'EstadoDetallado' => 'manual_review',
                'DocumentosProcesados' => 3,
                'TotalCampos' => 1,
                'Discrepancias' => 0,
                'NoConcluyentes' => 0,
            ]
        ]);

        $this->assertTrue($summary['auditExecuted']);
        $this->assertSame(1, $summary['findingsCount']);
    }

    public function testManualReviewDetailWithPersistedAuditPayloadIsAuditExecuted(): void
    {
        $payload = json_encode([
            'items' => [
                ['campo' => 'Lote', 'resultado' => 'COINCIDE'],
            ],
            'field_decisions' => [],
            'document_decisions' => [],
            'metrics' => ['total_campos' => 1],
            'timings' => ['docs_total' => 3],
        ]);

        $detail = $this->invokeMethod('normalizeAuditDetail', [
            [
                'FacSec' => 'DIS26-5-12-10-44-32-17794FE34E27F8AB1975AD0BC1E33F6C9EC-1-1',
                'FacNro' => 'D13260500540',
                'EstAud' => 0,
                'EstadoDetallado' => 'manual_review',
                'DocumentosProcesados' => 3,
                'Hallazgos' => $payload,
            ]
        ]);

        $this->assertTrue($detail['auditExecuted']);
        $this->assertSame(1, $detail['findingsCount']);
    }

    public function testPendingWithoutPersistedAuditPayloadIsNotAuditExecuted(): void
    {
        $summary = $this->invokeMethod('normalizeAuditSummary', [
            [
                'DisId' => 'PENDING',
                'FacNro' => 'PENDING',
                'EstAud' => 0,
                'EstadoDetallado' => 'pending',
                'DocumentosProcesados' => 0,
                'TotalCampos' => 0,
                'Discrepancias' => 0,
                'NoConcluyentes' => 0,
            ]
        ]);

        $this->assertFalse($summary['auditExecuted']);
    }

    /**
     * @param string $methodName
     * @param array<int,mixed> $args
     * @return array<string,mixed>
     */
    private function invokeMethod(string $methodName, array $args): array
    {
        $reflection = new ReflectionClass(AuditStatusModel::class);
        /** @var AuditStatusModel $model */
        $model = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($model, $args);
    }
}
