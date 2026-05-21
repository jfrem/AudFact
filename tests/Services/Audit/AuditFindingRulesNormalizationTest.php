<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\AuditFindingRules;
use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\IdentityDocNormalizer;
use App\Services\Audit\TextNormalization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para AuditFindingRules — normalización NORM-001.
 *
 * Cubre:
 * - normalizeIdentityDocType(): aliases RIPS/BDUA colombianos
 * - normalizeDateToIso(): formatos numéricos + fechas narrativas en español
 * - stripAccents(): remoción determinística de diacríticos
 */
final class AuditFindingRulesNormalizationTest extends TestCase
{
    // ─── IDENTITY_DOC_TYPE — Aliases RIPS/BDUA ───────────────────────────────

    /**
     * @return array<string,array{string,string}>
     */
    public static function identityDocTypeProvider(): array
    {
        return [
            // Cédula de Ciudadanía
            'CC literal'                   => ['CC', 'CC'],
            'Cédula ciudadanía (acentos)'  => ['Cédula ciudadanía', 'CC'],
            'Cédula de Ciudadanía'         => ['Cédula de Ciudadanía', 'CC'],
            'CEDULA DE CIUDADANIA'         => ['CEDULA DE CIUDADANIA', 'CC'],
            'cedula'                       => ['cedula', 'CC'],
            'C.C.'                         => ['C.C.', 'CC'],

            // Tarjeta de Identidad
            'TI literal'                   => ['TI', 'TI'],
            'Tarjeta de Identidad'         => ['Tarjeta de Identidad', 'TI'],
            'TARJETA IDENTIDAD'            => ['TARJETA IDENTIDAD', 'TI'],

            // Cédula de Extranjería
            'CE literal'                   => ['CE', 'CE'],
            'Cédula de Extranjería'        => ['Cédula de Extranjería', 'CE'],
            'CEDULA EXTRANJERIA'           => ['CEDULA EXTRANJERIA', 'CE'],

            // Registro Civil
            'RC literal'                   => ['RC', 'RC'],
            'Registro Civil'               => ['Registro Civil', 'RC'],
            'Registro Civil de Nacimiento' => ['Registro Civil de Nacimiento', 'RC'],

            // Pasaporte
            'PA literal'                   => ['PA', 'PA'],
            'Pasaporte'                    => ['Pasaporte', 'PA'],
            'PT como pasaporte'            => ['PT', 'PA'],

            // Permiso Especial de Permanencia
            'PE literal'                   => ['PE', 'PE'],
            'PEP'                          => ['PEP', 'PE'],
            'Permiso Especial Permanencia' => ['Permiso Especial de Permanencia', 'PE'],

            // Permiso por Protección Temporal
            'PPT literal'                  => ['PPT', 'PPT'],
            'Permiso Protección Temporal'  => ['Permiso de Protección Temporal', 'PPT'],

            // Menor sin identificación
            'MS literal'                   => ['MS', 'MS'],
            'Menor sin Identificación'     => ['Menor sin Identificación', 'MS'],

            // Adulto sin identificación
            'AS literal'                   => ['AS', 'AS'],
            'Adulto sin Identificación'    => ['Adulto sin Identificación', 'AS'],

            // NUIP
            'NUIP literal'                 => ['NUIP', 'NUIP'],

            // Salvoconducto
            'SC literal'                   => ['SC', 'SC'],
            'Salvoconducto'                => ['Salvoconducto', 'SC'],
        ];
    }

    #[DataProvider('identityDocTypeProvider')]
    public function testNormalizeIdentityDocType(string $input, string $expected): void
    {
        $this->assertSame($expected, IdentityDocNormalizer::normalizeDocType($input));
    }

    public function testNormalizeIdentityDocTypePreservesUnknownCodes(): void
    {
        // Códigos desconocidos se retornan normalizados pero no mapeados
        $this->assertSame('XX', IdentityDocNormalizer::normalizeDocType('XX'));
        $this->assertSame('DESCONOCIDO', IdentityDocNormalizer::normalizeDocType('Desconocido'));
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function identityDocNumberProvider(): array
    {
        return [
            'documento limpio' => ['94229637', '94229637'],
            'documento con nombre concatenado' => ['94229637-NOREÑA AGUDELO JUAN JOSE', '94229637'],
            'tipo documento nombre' => ['CC 94229637 NOREÑA AGUDELO JUAN JOSE', '94229637'],
            'miles con puntos' => ['94.229.637 - NOREÑA AGUDELO', '94229637'],
            'token alfanumérico' => ['PA AB123456 PEREZ ANA', 'AB123456'],
        ];
    }

    #[DataProvider('identityDocNumberProvider')]
    public function testNormalizeIdentityDocNumber(string $input, string $expected): void
    {
        $this->assertSame($expected, IdentityDocNormalizer::normalizeDocNumber($input));
    }

    public function testNormalizeIdentityDocNumberPreservesAmbiguousNameFirstValue(): void
    {
        $this->assertSame(
            'NOREÑA AGUDELO 94229637',
            IdentityDocNormalizer::normalizeDocNumber('NOREÑA AGUDELO 94229637')
        );
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function mixedIdentityNameProvider(): array
    {
        return [
            'documento guion nombre' => ['94229637-NOREÑA AGUDELO JUAN JOSE', 'NOREÑA AGUDELO JUAN JOSE'],
            'tipo documento nombre' => ['CC 94229637 NOREÑA AGUDELO JUAN JOSE', 'NOREÑA AGUDELO JUAN JOSE'],
            'medico label' => ['Médico: 12345678-PEREZ ANA MARIA', 'PEREZ ANA MARIA'],
            'nit como prefijo removible en nombre' => ['NIT 900123456 DISCOLMETS SA', 'DISCOLMETS SA'],
        ];
    }

    #[DataProvider('mixedIdentityNameProvider')]
    public function testNormalizePersonNameFromMixedIdentityLine(string $input, string $expected): void
    {
        $this->assertSame($expected, IdentityDocNormalizer::normalizePersonNameFromMixedIdentityLine($input));
    }

    public function testNormalizePersonNameFromMixedIdentityLinePreservesCleanName(): void
    {
        $this->assertSame(
            'NOREÑA AGUDELO JUAN JOSE',
            IdentityDocNormalizer::normalizePersonNameFromMixedIdentityLine('NOREÑA AGUDELO JUAN JOSE')
        );
    }

    // ─── DATE — Formatos numéricos existentes ────────────────────────────────

    /**
     * @return array<string,array{string,string}>
     */
    public static function numericDateProvider(): array
    {
        return [
            'ISO Y-m-d'                 => ['2026-05-04', '2026-05-04'],
            'Y/m/d'                     => ['2026/05/04', '2026-05-04'],
            'd/m/Y'                     => ['04/05/2026', '2026-05-04'],
            'd-m-Y'                     => ['04-05-2026', '2026-05-04'],
            'd.m.Y'                     => ['04.05.2026', '2026-05-04'],
            'ISO con hora'              => ['2026-05-04 14:30:00', '2026-05-04'],
            'Separador espacios d m Y'   => ['25 3 2026', '2026-03-25'],
            'Separador espacios Y m d'   => ['2026 3 25', '2026-03-25'],
            'Separador espacios padded'  => ['25 03 2026', '2026-03-25'],
            'Año 2 dígitos d m y'       => ['25 3 26', '2026-03-25'],
            'Ambigüedad d m Y (año fin)' => ['10 11 2026', '2026-11-10'],
            'Ambigüedad Y m d (año ini)' => ['2026 10 11', '2026-10-11'],
            'Hora 12H con PM'           => ['2026-05-04 2:30 PM', '2026-05-04'],
            'Narrativa con hora'        => ['25 de marzo de 2026 10:00', '2026-03-25'],
        ];
    }

    #[DataProvider('numericDateProvider')]
    public function testNormalizeDateToIsoNumericFormats(string $input, string $expected): void
    {
        $this->assertSame($expected, AuditFindingRules::normalizeDateToIso($input));
    }

    // ─── DATE — Fechas narrativas en español ─────────────────────────────────

    /**
     * @return array<string,array{string,string}>
     */
    public static function narrativeDateProvider(): array
    {
        return [
            '4 de mayo de 2026'       => ['4 de mayo de 2026', '2026-05-04'],
            'Mayo 4, 2026'            => ['Mayo 4, 2026', '2026-05-04'],
            '4-mayo-2026'             => ['4-mayo-2026', '2026-05-04'],
            '4/mayo/2026'             => ['4/mayo/2026', '2026-05-04'],
            '4 may 2026 (abrev)'      => ['4 may 2026', '2026-05-04'],
            '15 de diciembre de 2025' => ['15 de diciembre de 2025', '2025-12-15'],
            '1 ene 2026'              => ['1 ene 2026', '2026-01-01'],
            'Septiembre 30, 2025'     => ['Septiembre 30, 2025', '2025-09-30'],
            '28 de febrero de 2026'   => ['28 de febrero de 2026', '2026-02-28'],
        ];
    }

    #[DataProvider('narrativeDateProvider')]
    public function testNormalizeDateToIsoNarrativeFormats(string $input, string $expected): void
    {
        $this->assertSame($expected, AuditFindingRules::normalizeDateToIso($input));
    }

    public function testNormalizeDateToIsoReturnsNullForInvalidNarrativeDate(): void
    {
        // 30 de febrero no existe
        $this->assertNull(AuditFindingRules::normalizeDateToIso('30 de febrero de 2026'));
    }

    public function testNormalizeDateToIsoReturnsNullForIncompleteNarrativeDate(): void
    {
        // Sin año → no se puede resolver
        $this->assertNull(AuditFindingRules::normalizeDateToIso('4 de mayo'));
    }

    public function testNormalizeDateToIsoReturnsNullForEmptyString(): void
    {
        $this->assertNull(AuditFindingRules::normalizeDateToIso(''));
        $this->assertNull(AuditFindingRules::normalizeDateToIso('   '));
    }

    // ─── stripAccents — Determinístico ───────────────────────────────────────

    /**
     * @return array<string,array{string,string}>
     */
    public static function stripAccentsProvider(): array
    {
        return [
            'acentos agudos'       => ['GARCÍA LÓPEZ', 'GARCIA LOPEZ'],
            'eñe'                  => ['MUÑOZ PEÑA', 'MUNOZ PENA'],
            'minúsculas'           => ['café résumé', 'cafe resume'],
            'diéresis'             => ['über Ñoño', 'uber Nono'],
            'circunflejo'          => ['fête hôtel', 'fete hotel'],
            'grave'                => ['àèìòù', 'aeiou'],
            'cedilla'              => ['François', 'Francois'],
            'tilde portugués'      => ['São João', 'Sao Joao'],
            'sin acentos (noop)'   => ['GARCIA LOPEZ', 'GARCIA LOPEZ'],
            'string vacío'         => ['', ''],
        ];
    }

    #[DataProvider('stripAccentsProvider')]
    public function testStripAccents(string $input, string $expected): void
    {
        $this->assertSame($expected, TextNormalization::stripAccents($input));
    }

    // ─── normalizeForComparison — Integración ────────────────────────────────

    public function testNormalizeForComparisonRoutesDateCorrectly(): void
    {
        $this->assertSame('2026-05-04', AuditFindingRules::normalizeForComparison(AuditFieldValueType::DATE, '04/05/2026'));
    }

    public function testNormalizeForComparisonRoutesNarrativeDateCorrectly(): void
    {
        $this->assertSame('2026-05-04', AuditFindingRules::normalizeForComparison(AuditFieldValueType::DATE, '4 de mayo de 2026'));
    }

    public function testNormalizeForComparisonRoutesIdentityDocTypeCorrectly(): void
    {
        $this->assertSame('CC', AuditFindingRules::normalizeForComparison(AuditFieldValueType::IDENTITY_DOC_TYPE, 'Cédula de Ciudadanía'));
        $this->assertSame('TI', AuditFindingRules::normalizeForComparison(AuditFieldValueType::IDENTITY_DOC_TYPE, 'Tarjeta de Identidad'));
        $this->assertSame('CE', AuditFindingRules::normalizeForComparison(AuditFieldValueType::IDENTITY_DOC_TYPE, 'Cédula de Extranjería'));
    }

    public function testNormalizeForComparisonRoutesIdentityDocNumberCorrectly(): void
    {
        $this->assertSame(
            '94229637',
            AuditFindingRules::normalizeForComparison(AuditFieldValueType::IDENTITY_DOC_NUMBER, '94229637-NOREÑA AGUDELO JUAN JOSE')
        );
    }

    public function testNormalizeForComparisonRoutesQuantityCorrectly(): void
    {
        // Sin separador de miles, directo
        $this->assertSame('1500', AuditFindingRules::normalizeForComparison(AuditFieldValueType::QUANTITY, '1500'));
        // Formato europeo: punto miles + coma decimal → 1500
        $this->assertSame('1500', AuditFindingRules::normalizeForComparison(AuditFieldValueType::QUANTITY, '1.500,00'));
        // Entero simple
        $this->assertSame('30', AuditFindingRules::normalizeForComparison(AuditFieldValueType::QUANTITY, '30'));
    }

    public function testNormalizeForComparisonRoutesMoneyCorrectly(): void
    {
        $this->assertSame('1500', AuditFindingRules::normalizeForComparison(AuditFieldValueType::MONEY, '1.500,00'));
        $this->assertSame('1500', AuditFindingRules::normalizeForComparison(AuditFieldValueType::MONEY, '1,500.00'));
    }
}
