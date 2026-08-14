<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\AuditComparisonType;
use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\Pipeline\InternalIntegrityEvaluator;
use PHPUnit\Framework\TestCase;

final class InternalIntegrityEvaluatorTest extends TestCase
{
    private string $documentType = 'ACTA DE ENTREGA';

    private array $defaultNEntregaConfig = [
        'NEntrega' => [
            'campoNombre' => 'NEntrega',
            'codigoCampo' => 'NENT',
            'tipoCampo'   => 'I',
            'tipoDato'    => 'text',
            'severity'    => 'alta',
        ],
    ];

    public function testReturnsEmptyWhenNoInternalFields(): void
    {
        $sourceTruth = [
            'items' => [
                ['NEntrega' => '1', 'MipresNoEntrega' => '2'],
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, []);
        $this->assertSame([], $findings);
    }

    public function testReturnsEmptyWhenItemsMissing(): void
    {
        $sourceTruth = ['items' => []];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $this->defaultNEntregaConfig);
        $this->assertSame([], $findings);

        $sourceTruthNoItems = [];
        $findings2 = InternalIntegrityEvaluator::evaluate($sourceTruthNoItems, $this->documentType, $this->defaultNEntregaConfig);
        $this->assertSame([], $findings2);
    }

    public function testReturnsEmptyWhenFieldHasNoRule(): void
    {
        $sourceTruth = [
            'items' => [
                ['CampoDesconocido' => '1', 'OtraColumna' => '2'],
            ],
        ];

        $unregisteredConfig = [
            'CampoDesconocido' => [
                'campoNombre' => 'CampoDesconocido',
                'codigoCampo' => 'UNK',
                'tipoCampo'   => 'I',
                'tipoDato'    => 'text',
                'severity'    => 'alta',
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $unregisteredConfig);
        $this->assertSame([], $findings);
    }

    public function testEmitsMatchFindingWhenValuesMatch(): void
    {
        $sourceTruth = [
            'items' => [
                ['NEntrega' => '1', 'MipresNoEntrega' => '1'],
                ['NEntrega' => '02', 'MipresNoEntrega' => '2'], // Normalización entera
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $this->defaultNEntregaConfig);
        $this->assertCount(2, $findings);

        foreach ($findings as $finding) {
            $this->assertSame(AuditFindingResult::MATCH->value, $finding['resultado']);
            $this->assertNull($finding['detalle']);
            $this->assertSame('integrity', $finding['tipo_auditoria']);
            $this->assertSame('NEntrega', $finding['campo']);
        }
    }

    public function testDetectsDiscrepancy(): void
    {
        $sourceTruth = [
            'items' => [
                ['NEntrega' => '2', 'MipresNoEntrega' => '1'],
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $this->defaultNEntregaConfig);

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        $this->assertSame('2', $finding['valorFuenteVerdad']);
        $this->assertSame('1', $finding['valorDocumento']);
        $this->assertSame(AuditFindingResult::MISMATCH->value, $finding['resultado']);
        $this->assertSame(AuditSeverity::HIGH->value, $finding['severidad']);
        $this->assertSame('NENT', $finding['codigoCampo']);
        $this->assertSame('NEntrega', $finding['campo']);
        $this->assertSame($this->documentType, $finding['documento']);
        $this->assertSame(AuditFieldValueType::TEXT->value, $finding['valueType']);
        $this->assertSame('integrity', $finding['tipo_auditoria']);
        $this->assertStringContainsString('El ERP registra la entrega 2, pero DatosMipresDetalle reporta la entrega 1', $finding['detalle']);
    }

    public function testMultipleItemsMixedFindings(): void
    {
        $sourceTruth = [
            'items' => [
                ['NEntrega' => '1', 'MipresNoEntrega' => '1'], // Match
                ['NEntrega' => '2', 'MipresNoEntrega' => '1'], // Discrepancy
                ['NEntrega' => '3', 'MipresNoEntrega' => '2'], // Discrepancy
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $this->defaultNEntregaConfig);

        $this->assertCount(3, $findings);
        $this->assertSame(AuditFindingResult::MATCH->value, $findings[0]['resultado']);
        $this->assertNull($findings[0]['detalle']);
        $this->assertSame(AuditFindingResult::MISMATCH->value, $findings[1]['resultado']);
        $this->assertSame('2', $findings[1]['valorFuenteVerdad']);
        $this->assertSame(AuditFindingResult::MISMATCH->value, $findings[2]['resultado']);
        $this->assertSame('3', $findings[2]['valorFuenteVerdad']);
    }

    public function testSkipsItemsMissingColumns(): void
    {
        $sourceTruth = [
            'items' => [
                ['NEntrega' => '1'], // Falta MipresNoEntrega
                ['MipresNoEntrega' => '1'], // Falta NEntrega
                ['OtroCampo' => 'valor'],
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $this->defaultNEntregaConfig);
        $this->assertSame([], $findings);
    }

    public function testDetailMessageContainsBothValues(): void
    {
        $sourceTruth = [
            'items' => [
                ['NEntrega' => '5', 'MipresNoEntrega' => '3'],
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $this->defaultNEntregaConfig);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('5', $findings[0]['detalle']);
        $this->assertStringContainsString('3', $findings[0]['detalle']);
    }

    public function testFindingStructure(): void
    {
        $sourceTruth = [
            'items' => [
                ['NEntrega' => '2', 'MipresNoEntrega' => '1'],
            ],
        ];

        $findings = InternalIntegrityEvaluator::evaluate($sourceTruth, $this->documentType, $this->defaultNEntregaConfig);
        $this->assertCount(1, $findings);

        $expectedKeys = [
            'valorFuenteVerdad',
            'valorDocumento',
            'resultado',
            'severidad',
            'codigoCampo',
            'campo',
            'documento',
            'valueType',
            'detalle',
            'tipo_auditoria',
        ];

        $actualKeys = array_keys($findings[0]);
        sort($expectedKeys);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys);
    }

    public function testAuditComparisonTypeFromTipoCampoInternal(): void
    {
        $this->assertSame(AuditComparisonType::INTERNAL, AuditComparisonType::fromTipoCampo('I'));
        $this->assertSame(AuditComparisonType::INTERNAL, AuditComparisonType::fromTipoCampo('i'));
        $this->assertSame('internal', AuditComparisonType::INTERNAL->value);
    }
}
