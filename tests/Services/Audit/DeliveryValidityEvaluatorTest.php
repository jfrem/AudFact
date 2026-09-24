<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\DeliveryValidityEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testUsesClientConfiguredDaysWhenVisualNotPresent(): void
    {
        // Arrange:
        // 34 días después de 2025-07-27 -> 2025-08-30. Con 30 días de cliente, debe superar vigencia.
        $audit = self::buildAudit(
            deliveryDate: '2025-08-30',
            authorizationDate: '2025-07-27',
            visualResult: ['check' => 'VigenciaEntrega', 'presente' => false, 'valor' => null],
            diasVigencia: 30
        );

        // Act:
        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        // Assert:
        $this->assertCount(1, $result);
        $this->assertSame('VALOR_DISTINTO', $result[0]['resultado']);
        $this->assertStringContainsString('vigencia configurada del cliente: 30 días', $result[0]['detalle']);
        $this->assertStringContainsString('30 dias', $result[0]['valorFuenteVerdad']);
    }

    public function testVisualEvidenceTakesPrecedenceOverClientConfig(): void
    {
        // Arrange:
        // Documento impreso dice 45 días. Cliente configurado con 30 días.
        // Entrega a 40 días: dentro de 45 días (visual), pero fuera de 30 días (cliente).
        // Debe prevalecer la evidencia visual -> COINCIDE.
        $audit = self::buildAudit(
            deliveryDate: '2025-09-05',
            authorizationDate: '2025-07-27',
            visualResult: self::completeVisualResult(45),
            diasVigencia: 30
        );

        // Act:
        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        // Assert:
        $this->assertCount(1, $result);
        $this->assertSame('COINCIDE', $result[0]['resultado']);
        $this->assertStringContainsString('45 dias', $result[0]['valorFuenteVerdad']);
        $this->assertStringNotContainsString('vigencia configurada del cliente', $result[0]['detalle']);
        $this->assertStringNotContainsString('por defecto', $result[0]['detalle']);
    }

    public function testFallsBackToDefaultWhenClientConfigIsNull(): void
    {
        // Arrange:
        $audit = self::buildAudit(
            deliveryDate: '2025-08-20',
            authorizationDate: '2025-07-27',
            visualResult: ['check' => 'VigenciaEntrega', 'presente' => false, 'valor' => null],
            diasVigencia: null
        );

        // Act:
        $result = DeliveryValidityEvaluator::evaluate($audit, []);

        // Assert:
        $this->assertCount(1, $result);
        $this->assertSame('COINCIDE', $result[0]['resultado']);
        $this->assertStringContainsString('vigencia por defecto del sistema', $result[0]['detalle']);
        $this->assertStringContainsString('60 dias', $result[0]['valorFuenteVerdad']);
    }

    #[DataProvider('clientValidityCases')]
    public function testClientValidityBoundariesAndFallback(
        mixed $configuredDays,
        string $deliveryDate,
        string $expectedResult,
        string $expectedOrigin
    ): void {
        // Arrange:
        $incompleteVisual = array_replace(self::completeVisualResult(30), ['valor' => null]);
        $audit = self::buildAudit($deliveryDate, '2025-07-27', $incompleteVisual);
        $audit['dias_vigencia'] = $configuredDays;

        // Act:
        $findings = DeliveryValidityEvaluator::evaluate($audit, []);

        // Assert:
        $this->assertCount(1, $findings);
        $this->assertSame($expectedResult, $findings[0]['resultado']);
        $this->assertStringContainsString($expectedOrigin, $findings[0]['detalle']);
    }

    public static function clientValidityCases(): iterable
    {
        yield 'exact client limit' => [30, '2025-08-26', 'COINCIDE', 'configurada del cliente: 30 días'];
        yield 'day after client limit' => [30, '2025-08-27', 'VALOR_DISTINTO', 'configurada del cliente: 30 días'];
        yield 'explicit default length' => [60, '2025-08-27', 'COINCIDE', 'configurada del cliente: 60 días'];
        yield 'null' => [null, '2025-08-27', 'COINCIDE', 'por defecto del sistema'];
        yield 'zero' => [0, '2025-08-27', 'COINCIDE', 'por defecto del sistema'];
        yield 'negative' => [-30, '2025-08-27', 'COINCIDE', 'por defecto del sistema'];
        yield 'unknown transport type' => ['30', '2025-08-27', 'COINCIDE', 'por defecto del sistema'];
    }

    // ─── Helpers ───────────────────────────────────────────────

    private static function buildAudit(
        ?string $deliveryDate,
        ?string $authorizationDate,
        array $visualResult,
        ?int $diasVigencia = null
    ): array {
        $header = [];
        if ($deliveryDate !== null) {
            $header['FechaEntrega'] = $deliveryDate;
        }
        if ($authorizationDate !== null) {
            $header['FechaAutorizacion'] = $authorizationDate;
        }

        $audit = [
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

        $audit['dias_vigencia'] = $diasVigencia;

        return $audit;
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
