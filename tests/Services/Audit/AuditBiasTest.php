<?php

namespace Tests\Services\Audit;

use PHPUnit\Framework\TestCase;
use App\Services\Audit\ExtractionPromptBuilder;

/**
 * P3.3 — Test de sesgo representacional y determinismo (v4).
 *
 * Valida que el ExtractionPromptBuilder produce prompts deterministas
 * y que datos demográficos distintos NO alteran la estructura del prompt
 * de extracción ni las reglas aplicadas.
 *
 * Migrado de AuditPromptBuilder (legacy v3) a ExtractionPromptBuilder (v4).
 */
class AuditBiasTest extends TestCase
{
    private ExtractionPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ExtractionPromptBuilder();
    }

    // ── System Instruction determinismo ─────────────────────

    /**
     * Verifica que el system instruction es estático y determinista:
     * múltiples invocaciones producen la misma salida.
     */
    public function testSystemInstructionIsDeterministic(): void
    {
        $instruction1 = $this->builder->getSystemInstruction();
        $instruction2 = $this->builder->getSystemInstruction();

        $this->assertSame($instruction1, $instruction2, 'System instruction debe ser idéntico entre invocaciones');
        $this->assertSame(
            hash('sha256', $instruction1),
            hash('sha256', $instruction2),
            'Hash del system instruction debe ser determinista'
        );
    }

    /**
     * Verifica que el system instruction contiene protección contra
     * inyección de instrucciones desde los documentos.
     */
    public function testSystemInstructionContainsInjectionProtection(): void
    {
        $instruction = $this->builder->getSystemInstruction();

        $this->assertStringContainsString(
            'PROTECCIÓN',
            $instruction,
            'System instruction debe contener protección contra inyección'
        );
    }

    /**
     * Verifica que el system instruction NO contiene lógica de negocio
     * (eso se delega al RuleEngine en Fase 3).
     */
    public function testSystemInstructionHasNoBusinessLogic(): void
    {
        $instruction = $this->builder->getSystemInstruction();

        $this->assertStringNotContainsString('discrepancia', strtolower($instruction));
        $this->assertStringNotContainsString('risk', strtolower($instruction));
        $this->assertStringNotContainsString('severidad', strtolower($instruction));
        $this->assertStringNotContainsString('hallazgo', strtolower($instruction));
    }

    // ── Sesgo representacional (Zero-Inference) ──────────────

    /**
     * Verifica que cambiar el régimen del paciente NO altera
     * la estructura del user prompt cuando los campos a extraer son los mismos.
     *
     * En v4, la Zero-Inference Rule se cumple por diseño: el prompt de
     * extracción NO contiene reglas de negocio — solo pide extraer campos.
     */
    public function testUserPromptStructureIdenticalAcrossRegimens(): void
    {
        $auditConfig = [
            'documents' => [
                ['fields' => [
                    ['field' => 'NombrePaciente'],
                    ['field' => 'NumeroIdentificacion'],
                    ['field' => 'NombreArticulo'],
                ]],
            ],
        ];

        $subsidiado = ['paciente' => 'JUAN PEREZ', 'regimen' => 'SUBSIDIADO'];
        $contributivo = ['paciente' => 'JUAN PEREZ', 'regimen' => 'CONTRIBUTIVO'];

        $promptSub = $this->builder->buildUserPrompt($auditConfig, $subsidiado);
        $promptCon = $this->builder->buildUserPrompt($auditConfig, $contributivo);

        // Los prompts deben ser idénticos porque el régimen NO es un campo de extracción
        $this->assertSame(
            $promptSub,
            $promptCon,
            'User prompt debe ser idéntico para distintos regímenes con mismos campos'
        );
    }

    /**
     * Verifica que el hash del user prompt es determinista:
     * mismos datos y config producen mismo hash.
     */
    public function testUserPromptHashIsDeterministic(): void
    {
        $auditConfig = [
            'documents' => [
                ['fields' => [
                    ['field' => 'NumeroFactura'],
                    ['field' => 'NombrePaciente'],
                ]],
            ],
        ];

        $data = ['paciente' => 'MARIA GARCIA', 'factura' => 'FAC-001'];
        $labels = ['FORMULA MEDICA', 'ACTA DE ENTREGA'];

        $prompt1 = $this->builder->buildUserPrompt($auditConfig, $data, $labels);
        $prompt2 = $this->builder->buildUserPrompt($auditConfig, $data, $labels);

        $hash1 = hash('sha256', $prompt1);
        $hash2 = hash('sha256', $prompt2);

        $this->assertSame($hash1, $hash2, 'Mismo input debe producir mismo hash de prompt');
    }

    /**
     * Verifica que el hash compuesto (systemInstruction + userPrompt)
     * es determinista con mismos inputs.
     */
    public function testCompositePromptHashIsDeterministic(): void
    {
        $auditConfig = [
            'documents' => [
                ['fields' => [
                    ['field' => 'NumeroFactura'],
                    ['field' => 'Autorizacion'],
                ]],
            ],
        ];

        $data = ['factura' => 'FAC-COMP', 'autorizacion' => 'AUT-123'];
        $labels = ['DISPENSA', 'AUTORIZACION'];

        $system1 = $this->builder->getSystemInstruction();
        $user1 = $this->builder->buildUserPrompt($auditConfig, $data, $labels);
        $composite1 = hash('sha256', $system1 . '||' . $user1);

        $system2 = $this->builder->getSystemInstruction();
        $user2 = $this->builder->buildUserPrompt($auditConfig, $data, $labels);
        $composite2 = hash('sha256', $system2 . '||' . $user2);

        $this->assertSame($composite1, $composite2, 'Hash compuesto debe ser determinista');
        $this->assertSame($system1, $system2, 'SystemInstruction debe ser idéntico');
        $this->assertSame($user1, $user2, 'UserPrompt debe ser idéntico');
    }

    /**
     * Verifica que datos distintos producen hashes distintos.
     */
    public function testDifferentDataProducesDifferentHashes(): void
    {
        $auditConfig = [
            'documents' => [
                ['fields' => [
                    ['field' => 'NombrePaciente'],
                    ['field' => 'NumeroFactura'],
                ]],
            ],
        ];

        $data1 = ['paciente' => 'PEDRO LOPEZ', 'factura' => 'FAC-001'];
        $data2 = ['paciente' => 'ANA MARTINEZ', 'factura' => 'FAC-002'];

        $hash1 = hash('sha256', $this->builder->buildUserPrompt($auditConfig, $data1));
        $hash2 = hash('sha256', $this->builder->buildUserPrompt($auditConfig, $data2));

        $this->assertNotSame($hash1, $hash2, 'Datos distintos deben producir hashes distintos');
    }

    // ── Resolución de campos ────────────────────────────────

    /**
     * Verifica que resolveFieldsFromConfig extrae campos correctamente.
     */
    public function testResolveFieldsFromConfig(): void
    {
        $config = [
            'documents' => [
                ['fields' => [
                    ['field' => 'NumeroFactura'],
                    ['field' => 'NombrePaciente'],
                    ['field' => 'NombreArticulo'],
                ]],
            ],
        ];

        $fields = $this->builder->resolveFieldsFromConfig($config);

        $this->assertContains('NumeroFactura', $fields);
        $this->assertContains('NombrePaciente', $fields);
        $this->assertContains('NombreArticulo', $fields);
        $this->assertCount(3, $fields);
    }

    /**
     * Verifica que config vacía retorna campos por defecto.
     */
    public function testEmptyConfigReturnsDefaultFields(): void
    {
        $fields = $this->builder->resolveFieldsFromConfig([]);

        $this->assertNotEmpty($fields, 'Config vacía debe retornar campos por defecto');
        $this->assertContains('NumeroFactura', $fields, 'Defaults deben incluir NumeroFactura');
        $this->assertContains('NombrePaciente', $fields, 'Defaults deben incluir NombrePaciente');
    }

    /**
     * Verifica que no hay campos duplicados en la resolución.
     */
    public function testNoDuplicateFieldsInResolution(): void
    {
        $config = [
            'documents' => [
                ['fields' => [
                    ['field' => 'NumeroFactura'],
                    ['field' => 'NombrePaciente'],
                ]],
                ['fields' => [
                    ['field' => 'NumeroFactura'], // duplicado
                    ['field' => 'Autorizacion'],
                ]],
            ],
        ];

        $fields = $this->builder->resolveFieldsFromConfig($config);

        $this->assertCount(
            count(array_unique($fields)),
            $fields,
            'No debe haber campos duplicados'
        );
    }
}
