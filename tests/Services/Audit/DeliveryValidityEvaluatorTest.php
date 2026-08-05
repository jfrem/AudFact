<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\DeliveryValidityEvaluator;
use PHPUnit\Framework\TestCase;

final class DeliveryValidityEvaluatorTest extends TestCase
{
    public function testEvaluatesWithVisualEvidenceAndFdvDates(): void
    {
        $audit = self::buildAudit(
            deliveryDate: '2025-07-29',
            authorizationDate: '2025-07-27',
            visualResult: self::completeVisualResult(60)
        );

        $findings = self::matchedFindings('2025-07-29', '2025-07-27');
        $result   = DeliveryValidityEvaluator::evaluate($audit, $findings);

        $this->assertCount(1, $result);
        $this->assertSame('VigenciaEntrega', $result[0]['campo']);
        $this->assertSame('COINCIDE', $result[0]['resultado']);
        $this->assertStringNotContainsString('por defecto', $result[0]['detalle']);
    }

    public function testFallsBackToDefaultsWhenVisualNotPresent(): void
    {
        $audit = self::buildAudit(
            deliveryDate: '2025-07-29',
            authorizationDate: '2025-07-27',
            visualResult: ['check' => 'VigenciaEntrega', 'presente' => false, 'valor' => null]
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertCount(1, $result);
        $this->assertSame('COINCIDE', $result[0]['resultado']);
        $this->assertStringContainsString('por defecto del sistema', $result[0]['detalle']);
        $this->assertStringContainsString('60 dias', $result[0]['valorFuenteVerdad']);
    }

    public function testFallsBackToDefaultsWhenVisualValueIsNull(): void
    {
        $audit = self::buildAudit(
            deliveryDate: '2025-07-29',
            authorizationDate: '2025-07-27',
            visualResult: [
                'check'      => 'VigenciaEntrega',
                'presente'   => true,
                'valor'      => null,
                'unidad'     => 'dias',
                'fecha_base' => 'FechaAutorizacion',
            ]
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertCount(1, $result);
        $this->assertSame('COINCIDE', $result[0]['resultado']);
        $this->assertStringContainsString('por defecto del sistema', $result[0]['detalle']);
    }

    public function testUsesVisualDaysOverDefaultWhenComplete(): void
    {
        $audit = self::buildAudit(
            deliveryDate: '2025-07-29',
            authorizationDate: '2025-07-27',
            visualResult: self::completeVisualResult(30)
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertCount(1, $result);
        $this->assertSame('COINCIDE', $result[0]['resultado']);
        $this->assertStringContainsString('30 dias', $result[0]['valorFuenteVerdad']);
        $this->assertStringNotContainsString('por defecto', $result[0]['detalle']);
    }

    public function testDetectsExpiredDeliveryWithFdv(): void
    {
        $audit = self::buildAudit(
            deliveryDate: '2025-10-01',
            authorizationDate: '2025-07-27',
            visualResult: self::completeVisualResult(60)
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertCount(1, $result);
        $this->assertSame('VALOR_DISTINTO', $result[0]['resultado']);
        $this->assertStringContainsString('supera la vigencia', $result[0]['detalle']);
    }

    public function testDetectsExpiredDeliveryWithDefaults(): void
    {
        $audit = self::buildAudit(
            deliveryDate: '2025-10-01',
            authorizationDate: '2025-07-27',
            visualResult: ['check' => 'VigenciaEntrega', 'presente' => false]
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertCount(1, $result);
        $this->assertSame('VALOR_DISTINTO', $result[0]['resultado']);
        $this->assertStringContainsString('por defecto del sistema', $result[0]['detalle']);
    }

    public function testReturnsInconclusiveWhenFdvHasNoDeliveryDate(): void
    {
        $audit = self::buildAudit(
            deliveryDate: null,
            authorizationDate: '2025-07-27',
            visualResult: self::completeVisualResult(60)
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertCount(1, $result);
        $this->assertSame('NO_CONCLUYENTE', $result[0]['resultado']);
        $this->assertStringContainsString('fuente de verdad', $result[0]['detalle']);
    }

    public function testReturnsInconclusiveWhenFdvHasNoBaseDate(): void
    {
        $audit = self::buildAudit(
            deliveryDate: '2025-07-29',
            authorizationDate: null,
            visualResult: self::completeVisualResult(60)
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertCount(1, $result);
        $this->assertSame('NO_CONCLUYENTE', $result[0]['resultado']);
    }

    public function testReturnsEmptyWhenNoCandidateExists(): void
    {
        $audit = [
            'documents' => [
                'doc1' => [
                    'tipo_documento'  => 'DISPENSA',
                    'visual_checks'   => [],
                    'fuente_verdad'   => ['header' => [], 'items' => []],
                ],
            ],
        ];

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertSame([], $result);
    }

    public function testDeliveryOnExactLimitDateIsWithinValidity(): void
    {
        // 2025-07-27 + 60 days = 2025-09-25
        $audit = self::buildAudit(
            deliveryDate: '2025-09-25',
            authorizationDate: '2025-07-27',
            visualResult: self::completeVisualResult(60)
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertSame('COINCIDE', $result[0]['resultado']);
    }

    public function testDeliveryOneDayAfterLimitIsExpired(): void
    {
        // 2025-07-27 + 60 days = 2025-09-25
        $audit = self::buildAudit(
            deliveryDate: '2025-09-26',
            authorizationDate: '2025-07-27',
            visualResult: self::completeVisualResult(60)
        );

        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        $this->assertSame('VALOR_DISTINTO', $result[0]['resultado']);
    }

    // ─── Helpers ───────────────────────────────────────────────

    private static function buildAudit(?string $deliveryDate, ?string $authorizationDate, array $visualResult): array
    {
        $header = [];
        if ($deliveryDate !== null) {
            $header['FechaEntrega'] = $deliveryDate;
        }
        if ($authorizationDate !== null) {
            $header['FechaAutorizacion'] = $authorizationDate;
        }

        return [
            'documents' => [
                'dispensa' => [
                    'tipo_documento' => 'DISPENSA',
                    'visual_checks'  => [],
                    'fuente_verdad'  => ['header' => $header, 'items' => []],
                ],
                'autorizacion' => [
                    'tipo_documento'    => 'AUTORIZACION',
                    'visual_checks'     => [['check' => 'VigenciaEntrega', 'severity' => 'alta']],
                    'normalized_result' => [
                        'visual_checks_resultado' => [$visualResult],
                    ],
                    'fuente_verdad' => ['header' => $header, 'items' => []],
                ],
            ],
        ];
    }

    private static function completeVisualResult(int $days): array
    {
        return [
            'check'      => 'VigenciaEntrega',
            'presente'   => true,
            'valor'      => $days,
            'unidad'     => 'dias',
            'fecha_base' => 'FechaAutorizacion',
            'severidad'  => 'ALTA',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function matchedFindings(string $deliveryDate, string $authDate): array
    {
        return [
            [
                'campo'              => 'FechaEntrega',
                'valorFuenteVerdad'  => $deliveryDate,
                'valorDocumento'     => $deliveryDate,
                'resultado'          => 'COINCIDE',
                'severidad'          => 'alta',
                'documento'          => 'DISPENSA',
            ],
            [
                'campo'              => 'FechaAutorizacion',
                'valorFuenteVerdad'  => $authDate,
                'valorDocumento'     => $authDate,
                'resultado'          => 'COINCIDE',
                'severidad'          => 'alta',
                'documento'          => 'AUTORIZACION',
            ],
        ];
    }
}
