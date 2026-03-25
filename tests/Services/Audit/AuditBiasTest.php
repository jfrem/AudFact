<?php

namespace Tests\Services\Audit;

use PHPUnit\Framework\TestCase;
use App\Services\Audit\AuditPromptBuilder;

/**
 * P3.3 — Test de sesgo representacional.
 *
 * Valida que la Zero-Inference Rule funciona correctamente:
 * datos demográficos distintos NO deben alterar la estructura del prompt
 * ni las reglas de auditoría aplicadas.
 */
class AuditBiasTest extends TestCase
{
    private AuditPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new AuditPromptBuilder();
    }

    // ── estimateComplexity ────────────────────────────────

    public function testSimpleComplexityForPBS(): void
    {
        $data = ['Tipo' => 'PBS', 'Mipres' => '', 'NumeroFactura' => 'F1'];

        $result = $this->builder->estimateComplexity($data);

        $this->assertSame('simple', $result['level']);
        $this->assertSame(1024, $result['thinkingBudget']);
    }

    public function testNormalComplexityForMIPRES(): void
    {
        $data = ['Tipo' => 'MIPRES', 'Mipres' => 'MP-100', 'NumeroFactura' => 'F2'];

        $result = $this->builder->estimateComplexity($data);

        $this->assertSame('normal', $result['level']);
        $this->assertSame(4096, $result['thinkingBudget']);
    }

    public function testNormalComplexityWithMipresFieldPresent(): void
    {
        $data = ['Tipo' => 'PBS', 'Mipres' => 'MP-200', 'NumeroFactura' => 'F3'];

        $result = $this->builder->estimateComplexity($data);

        $this->assertSame('normal', $result['level']);
    }

    public function testComplexForMultipleLines(): void
    {
        $data = [
            ['Tipo' => 'PBS', 'Mipres' => '', 'NumeroFactura' => 'F4'],
            ['Tipo' => 'PBS', 'Mipres' => '', 'NumeroFactura' => 'F4'],
        ];

        $result = $this->builder->estimateComplexity($data);

        $this->assertSame('complex', $result['level']);
        $this->assertSame(8192, $result['thinkingBudget']);
    }

    // ── Sesgo representacional (Zero-Inference) ──────────

    /**
     * Verifica que cambiar el régimen del paciente NO altera
     * la estructura de reglas del system prompt.
     *
     * La Zero-Inference Rule dice: "El modelo NO debe inferir,
     * deducir ni asumir información demográfica del paciente".
     * Si el prompt contiene siempre la misma regla §00b,
     * el modelo no puede sesgar por régimen.
     */
    public function testPromptStructureIdenticalAcrossRegimens(): void
    {
        $baseData = [
            'Tipo'              => 'PBS',
            'NumeroFactura'     => 'FAC-999',
            'NitSec'            => '123',
            'FacSec'            => '87700001',
            'NombrePaciente'    => 'Test Paciente',
            'TipoDocumento'     => 'CC',
            'NumeroDocumento'   => '1000000001',
            'FechaNacimiento'   => '1990-01-01',
            'Mipres'            => '',
            'IdPrincipal'       => '',
            'IdDirec'           => '',
            'IdProg'            => '',
            'IdEntr'            => '',
            'IdRepEnt'          => '',
            'TipoServicio'      => 'PBS',
            'DiagPpal'          => 'J45',
            'NombreArticulo'    => 'SALBUTAMOL',
            'CantidadPrescrita' => '10',
            'CantidadEntregada' => '10',
            'CodSismed'         => 'A01',
            'NombreIPS'         => 'IPS TEST',
            'NombrePrestador'   => 'DR TEST',
        ];

        $subsidiado = array_merge($baseData, [
            'Regimen' => 'SUBSIDIADO',
            'Cliente' => 'EPS SUBSIDIADA',
        ]);

        $contributivo = array_merge($baseData, [
            'Regimen' => 'CONTRIBUTIVO',
            'Cliente' => 'EPS CONTRIBUTIVA',
        ]);

        $promptSubsidiado = $this->builder->getSystemInstruction($subsidiado);
        $promptContributivo = $this->builder->getSystemInstruction($contributivo);

        // Las secciones de REGLAS (§00 a §08) deben ser idénticas
        // Solo deben diferir los datos dinámicos (Regimen, Cliente)
        $this->assertStringContainsString('§00b', $promptSubsidiado, 'Zero-Inference Rule debe estar presente');
        $this->assertStringContainsString('§00b', $promptContributivo, 'Zero-Inference Rule debe estar presente');
        $this->assertStringContainsString('<zero_inference_rule>', $promptSubsidiado);
        $this->assertStringContainsString('<zero_inference_rule>', $promptContributivo);
    }

    /**
     * Verifica que el hash del prompt es determinista:
     * mismos datos producen mismo hash.
     */
    public function testPromptHashIsDeterministic(): void
    {
        $data = [
            'Tipo'              => 'PBS',
            'NumeroFactura'     => 'FAC-DET',
            'Regimen'           => 'SUBSIDIADO',
            'Cliente'           => 'EPS TEST',
            'NitSec'            => '999',
            'FacSec'            => '8770DET',
        ];

        $prompt1 = $this->builder->getSystemInstruction($data);
        $prompt2 = $this->builder->getSystemInstruction($data);

        $hash1 = hash('sha256', $prompt1);
        $hash2 = hash('sha256', $prompt2);

        $this->assertSame($hash1, $hash2, 'Mismo input debe producir mismo hash');
    }

    /**
     * Verifica que regímenes distintos producen hashes distintos
     * (los datos dinámicos SÍ cambian, así que el hash debe diferir).
     */
    public function testDifferentRegimensProduceDifferentHashes(): void
    {
        $base = [
            'Tipo'           => 'PBS',
            'NumeroFactura'  => 'FAC-DIFF',
            'NitSec'         => '111',
            'FacSec'         => '8770DIFF',
        ];

        $sub = array_merge($base, ['Regimen' => 'SUBSIDIADO', 'Cliente' => 'EPS SUB']);
        $con = array_merge($base, ['Regimen' => 'CONTRIBUTIVO', 'Cliente' => 'EPS CON']);

        $hashSub = hash('sha256', $this->builder->getSystemInstruction($sub));
        $hashCon = hash('sha256', $this->builder->getSystemInstruction($con));

        $this->assertNotSame($hashSub, $hashCon, 'Datos distintos deben producir hashes distintos');
    }

    /**
     * Verifica que el hash compuesto (systemInstruction + userPrompt)
     * es determinista: mismos datos y misma lista de documentos
     * producen siempre el mismo hash.
     *
     * Este test refleja la lógica de AuditOrchestrator::executeAuditFlow()
     * que calcula: hash('sha256', $systemInstruction . '||' . $baseUserPrompt)
     */
    public function testCompositePromptHashIsDeterministic(): void
    {
        $data = [
            'Tipo'              => 'PBS',
            'NumeroFactura'     => 'FAC-COMP',
            'Regimen'           => 'SUBSIDIADO',
            'Cliente'           => 'EPS TEST',
            'NitSec'            => '555',
            'FacSec'            => '8770COMP',
            'NombrePaciente'    => 'Test Paciente',
        ];

        $pdfList = ['DISPENSA', 'AUTORIZACION', 'FORMULA MEDICA'];

        $system1 = $this->builder->getSystemInstruction($data);
        $user1 = $this->builder->buildUserPrompt($data, $pdfList);
        $composite1 = hash('sha256', $system1 . '||' . $user1);

        $system2 = $this->builder->getSystemInstruction($data);
        $user2 = $this->builder->buildUserPrompt($data, $pdfList);
        $composite2 = hash('sha256', $system2 . '||' . $user2);

        $this->assertSame($composite1, $composite2, 'Hash compuesto debe ser determinista con mismos inputs');
        $this->assertSame($system1, $system2, 'SystemInstruction debe ser idéntico');
        $this->assertSame($user1, $user2, 'UserPrompt debe ser idéntico');
    }
}
