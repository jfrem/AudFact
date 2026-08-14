<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\AuditFindingRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para AuditFindingRules::isMeaningfulFdvValue().
 *
 * Verifica que valores vacíos o placeholder de la Fuente de Verdad
 * (ceros, nulos, cadenas vacías) se descarten antes de llegar al
 * motor de reglas, evitando falsos positivos en auditoría.
 */
final class AuditFindingRulesFdvFilterTest extends TestCase
{
    /**
     * @return array<string,array{mixed,bool,?AuditFieldValueType}>
     */
    public static function fdvValueProvider(): array
    {
        return [
            // ── Valores que deben filtrarse (false) ──
            'null'                 => [null, false, null],
            'cadena vacía'         => ['', false, null],
            'espacios'             => ['   ', false, null],
            'string null'          => ['null', false, null],
            'string NULL'          => ['NULL', false, null],
            'cero string'          => ['0', false, null],
            'doble cero'           => ['00', false, null],
            'punto cero cero'      => ['.00', false, null],
            'cero punto cero cero' => ['0.00', false, null],
            'cero punto cero'      => ['0.0', false, null],
            'cero int'             => [0, false, null],
            'cero float'           => [0.0, false, null],
            'cero con espacios'    => [' 0 ', false, null],
            'cero cientifico'      => ['0E0', false, null],
            'cero negativo'        => ['-0', false, null],
            'cero negativo float'  => ['-0.0', false, null],

            // ── Valores que deben pasar (true) ──
            'id numérico'          => ['12345', true, null],
            'texto simple'         => ['Activo', true, null],
            'doc tipo CC'          => ['CC', true, null],
            'uno'                  => ['1', true, null],
            'cero con prefijo'     => ['0001', true, null],
            'nombre paciente'      => ['Juan Pérez', true, null],
            'fecha ISO'            => ['2026-05-04', true, null],
            'nit'                  => ['900123456-7', true, null],
            'cantidad uno'         => ['1.0', true, null],
            'cantidad decimal'     => ['3.50', true, null],
            'código alfanumérico'  => ['A0', true, null],
            'false boolean'        => [false, true, null],
            
            // ── Valores numéricos explícitos (isNumericForSchema) que deben pasar (true) ──
            'cero en MONEY'        => ['0', true, \App\Services\Audit\AuditFieldValueType::MONEY],
            'cero int en QUANTITY' => [0, true, \App\Services\Audit\AuditFieldValueType::QUANTITY],
            '0.00 en MONEY'        => ['0.00', true, \App\Services\Audit\AuditFieldValueType::MONEY],
        ];
    }

    #[DataProvider('fdvValueProvider')]
    public function testIsMeaningfulFdvValue(mixed $input, bool $expected, ?\App\Services\Audit\AuditFieldValueType $type = null): void
    {
        $this->assertSame(
            $expected,
            AuditFindingRules::isMeaningfulFdvValue($input, $type),
            sprintf(
                'isMeaningfulFdvValue(%s, %s) debería retornar %s',
                var_export($input, true),
                $type?->value ?? 'null',
                $expected ? 'true' : 'false'
            )
        );
    }

    /**
     * Invariante: isPresent("0") sigue retornando true.
     * Verifica que no hubo regresión en el predicado genérico.
     */
    public function testIsPresentZeroUnchanged(): void
    {
        $this->assertTrue(
            AuditFindingRules::isPresent('0'),
            'isPresent("0") debe seguir retornando true (no fue modificado)'
        );
    }
}
