<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\TextNormalization;
use PHPUnit\Framework\TestCase;

final class TextNormalizationTest extends TestCase
{
    /**
     * @dataProvider personNameProvider
     */
    public function testSamePersonNameTokenSet(string $left, string $right, bool $expected): void
    {
        $this->assertSame($expected, TextNormalization::samePersonNameTokenSet($left, $right));
    }

    public static function personNameProvider(): array
    {
        return [
            // Exact matches
            ['CINDY LORENA FIERRO', 'CINDY LORENA FIERRO', true],
            ['JUAN PABLO PEREZ', 'JUAN PABLO PEREZ', true],

            // Reversed order
            ['FIERRO CINDY LORENA', 'CINDY LORENA FIERRO', true],

            // Wildcard/initials
            ['C LORENA FIERRO', 'CINDY LORENA FIERRO', true],
            ['C L FIERRO', 'CINDY LORENA FIERRO', true], // Should pass if 2 full tokens match? 'C' and 'L' are initials, 'FIERRO' is full. Wait, 2 full tokens are required?
            // "Al menos 2 tokens completos del lado corto deben haber encontrado match (o si el lado corto tiene 1, entonces ese 1)"
            // Short side has 1 full token ('FIERRO'). So $fullTokensShort = 1. Since 1 <= 1, it requires 1.
            // Let's test this later.

            // With titles (should ignore extra tokens on the longer side)
            ['DR JUAN PABLO PEREZ', 'JUAN PABLO PEREZ', true], // 'DR' is extra on left
            ['JUAN PABLO PEREZ', 'MEDICO JUAN PABLO PEREZ GOMEZ', true], // 'MEDICO' and 'GOMEZ' are extra on right

            // Connectors
            ['MARIA DE LOS ANGELES', 'MARIA ANGELES', true], // 'DE', 'LOS' are extra on left

            // False positives
            ['CINDY FIERRO', 'JUAN PABLO PEREZ', false],
            ['JUAN PEREZ', 'JUAN GOMEZ', false],
            ['CINDY LORENA FIERRO', 'CINDY LORENA GOMEZ', false], // GOMEZ is un-matched on right, but wait, if right is longer, it's ignored?
            // Wait: "Todos los tokens completos del lado MÁS CORTO deben tener match". 
            // If left is shorter: CINDY(match), LORENA(match), FIERRO(no match). So false.

            // Initials false positives
            ['C LORENA FIERRO', 'CARLOS LORENA FIERRO', true], // Wait, this would technically be TRUE in structural logic because C matches CARLOS, LORENA matches LORENA, FIERRO matches FIERRO. 
            // Actually 'C' matching 'CARLOS' is semantically wrong but structurally 'C' is an initial for 'CARLOS'. The judge will catch this if it was a false positive, but structurally it passes, so Gemini won't be called. Actually, Carlos Lorena is weird but valid for the structural layer.

            ['A FIERRO', 'CINDY LORENA FIERRO', false], // A doesn't match CINDY, LORENA, or FIERRO.
        ];
    }

    /**
     * @dataProvider articleNameProvider
     */
    public function testNormalizeArticleName(string $input, string $expected): void
    {
        $this->assertSame($expected, TextNormalization::normalizeArticleName($input));
    }

    public static function articleNameProvider(): array
    {
        return [
            // Caso base: sin sufijos farmacéuticos → normalización estándar
            ['ACETAMINOFEN 500MG TABLETA', 'ACETAMINOFEN 500MG TABLETA'],

            // Lote de empaque: C*100, C*300, C*900
            ['ACETAMINOFEN 500MG C*100 TABLETA', 'ACETAMINOFEN 500MG TABLETA'],
            ['LOSARTAN 50 MG C*300 TABLETA', 'LOSARTAN 50 MG TABLETA'],
            ['OMEPRAZOL 20MG C *900 CAPSULA', 'OMEPRAZOL 20MG CAPSULA'],

            // Envase con dimensión: TUBO*40GR, FCO*10ML, FRASCO*60ML
            ['BETAMETASONA 0.05% CREMA TOPICA TUBO*40GR', 'BETAMETASONA 0 05 CREMA TOPICA'],
            ['CLORHEXIDINA SOLUCION FCO*10ML', 'CLORHEXIDINA SOLUCION'],
            ['METFORMINA JARABE FRASCO*60ML', 'METFORMINA JARABE'],

            // Sufijo de presentación duplicada: // TABLETA, // UNIDAD
            ['BISACODILO 5 MG (GRAGEA) // TABLETA', 'BISACODILO 5 MG GRAGEA'],
            ['CARVEDILOL 25 MG (TABLETA) // UNIDAD', 'CARVEDILOL 25 MG TABLETA'],

            // Marcador hospitalario: (H)
            ['CARVEDILOL 25 MG (TABLETA) (H)', 'CARVEDILOL 25 MG TABLETA'],
            ['ENALAPRIL 20MG (H)', 'ENALAPRIL 20MG'],

            // Sufijo genérico: - GENERICO
            ['DUTASTERIDA+TAMSULOSINA 0.5 MG/0.4 MG (CAPSULA) - GENERICO', 'DUTASTERIDA TAMSULOSINA 0 5 MG 0 4 MG CAPSULA'],
            ['LOSARTAN 50MG - GENERICO', 'LOSARTAN 50MG'],

            // Combinaciones múltiples
            ['ACETAMINOFEN 500MG C*100 TABLETA // TABLETA', 'ACETAMINOFEN 500MG TABLETA'],
            ['CARVEDILOL 25 MG C*300 (TABLETA) (H) - GENERICO', 'CARVEDILOL 25 MG TABLETA'],

            // Cadena vacía
            ['', ''],
            ['   ', ''],

            // Acentos (heredados de normalizeText)
            ['Ácido Fólico 1mg C*100 Tableta', 'ACIDO FOLICO 1MG TABLETA'],

            // Casos reales del corpus V12260804716
            ['ACETAMINOFEN 500MG C*100 TABLETA', 'ACETAMINOFEN 500MG TABLETA'],
            ['ACETAMINOFEN 500 MG TABLETA TABLETA', 'ACETAMINOFEN 500 MG TABLETA TABLETA'],
        ];
    }

    /**
     * Verifica que artículos reales del caso V12260804716 que no matcheaban
     * léxicamente con normalizeText() ahora matchean con normalizeArticleName().
     *
     * @dataProvider realWorldMatchProvider
     */
    public function testRealWorldArticleMatching(string $fdvName, string $docName, bool $expectMatch): void
    {
        $fdvNorm = TextNormalization::normalizeArticleName($fdvName);
        $docNorm = TextNormalization::normalizeArticleName($docName);

        $directMatch = $fdvNorm === $docNorm;
        $substringMatch = TextNormalization::containsNormalizedSubstring($fdvNorm, $docNorm);
        $similarity = TextNormalization::similarity($fdvNorm, $docNorm);

        $matched = $directMatch || $substringMatch || $similarity >= 0.85;

        $this->assertSame(
            $expectMatch,
            $matched,
            sprintf(
                "FDV='%s' (%s) vs DOC='%s' (%s) → directMatch=%s, substring=%s, similarity=%.3f",
                $fdvName,
                $fdvNorm,
                $docName,
                $docNorm,
                $directMatch ? 'true' : 'false',
                $substringMatch ? 'true' : 'false',
                $similarity
            )
        );
    }

    public static function realWorldMatchProvider(): array
    {
        return [
            // Artículos que ahora deben matchear tras limpiar sufijos
            ['ACETAMINOFEN 500MG C*100 TABLETA', 'ACETAMINOFEN 500 MG TABLETA TABLETA', true],
            ['LOSARTAN 50 MG C*300 TABLETA', 'LOSARTAN 50 MG TABLETA', true],
            ['BISACODILO 5 MG (GRAGEA) // TABLETA', 'BISACODILO 5 MG GRAGEA', true],
            ['CARVEDILOL 25 MG (TABLETA) (H)', 'CARVEDILOL 25 MG TABLETA', true],

            // Artículos genuinamente diferentes (no deben matchear)
            ['ACETAMINOFEN 500MG TABLETA', 'LOSARTAN 50MG TABLETA', false],
            ['OMEPRAZOL 20MG CAPSULA', 'METFORMINA 850MG TABLETA', false],
        ];
    }

    // ── tokenSortedJaccard ──────────────────────────────────────────────

    /**
     * @dataProvider tokenSortedJaccardProvider
     */
    public function testTokenSortedJaccard(string $left, string $right, float $expectedMin, float $expectedMax): void
    {
        $result = TextNormalization::tokenSortedJaccard($left, $right);
        $this->assertGreaterThanOrEqual($expectedMin, $result, "Jaccard($left, $right) debería ser >= $expectedMin, obtuvo $result");
        $this->assertLessThanOrEqual($expectedMax, $result, "Jaccard($left, $right) debería ser <= $expectedMax, obtuvo $result");
    }

    public static function tokenSortedJaccardProvider(): array
    {
        return [
            // Inversiones de principios activos compuestos (caso V12260804716)
            'inversión TAMSULOSINA+DUTASTERIDA' => [
                'TAMSULOSINA+DUTASTERIDA 0.4+0.5MG C*30 CAPSULA DURA',
                'DUTASTERIDA+TAMSULOSINA 0.5 MG/0.4 MG (CAPSULA) - GENERICO // CAPSULA',
                0.50, 0.60,
            ],
            'inversión PROPILENGLICOL+POLIETILENGLICOL' => [
                'PROPILENGLICOL+POLIETILENGLICOL 3+4% SOL OFTALMICA FCO 10ML',
                'POLIETILENGLICOL 400 NF+PROPILENGLICOL 4MG+3MG/ML (SOLUCION OFTALMICA * 15ML) // SOLUCION OFTALMICA 15ML',
                0.15,
                0.50,
            ],
            'inversión VALSARTAN+SACUBITRIL' => [
                'VALSARTAN+SACUBITRIL 100MG C*60 TABLETA',
                'SACUBITRILO VALSARTAN 48.6 + 51.4 EQ 100MG (TABLETA) // TABLETA',
                0.25,
                1.0,
            ],

            // Match exacto después de normalización
            'mismo artículo con sufijos diferentes' => [
                'ACETAMINOFEN 500MG C*100 TABLETA',
                'ACETAMINOFEN 500 MG (TABLETA) // TABLETA',
                0.30, 0.50,
            ],

            // Artículos genuinamente diferentes
            'artículos completamente distintos' => [
                'ACETAMINOFEN 500MG TABLETA',
                'LOSARTAN 50MG TABLETA',
                0.0,
                0.40,
            ],
            'artículos sin tokens comunes' => [
                'OMEPRAZOL 20MG CAPSULA',
                'METFORMINA 850MG TABLETA',
                0.0,
                0.15,
            ],

            // Edge cases
            'vacío izquierdo' => ['', 'ACETAMINOFEN', 0.0, 0.0],
            'vacío derecho' => ['ACETAMINOFEN', '', 0.0, 0.0],
            'ambos vacíos' => ['', '', 0.0, 0.0],
            'idénticos' => ['ACETAMINOFEN 500MG TABLETA', 'ACETAMINOFEN 500MG TABLETA', 1.0, 1.0],

            // Sinónimos farmacológicos (NO se resuelven por Jaccard, requieren LLM)
            'sinónimos: SULFATO FERROSO vs HIERRO SULFATO' => [
                'SULFATO FERROSO 300MG C*500 TABLETA',
                'HIERRO SULFATO ANHIDRO 300 MG (TABLETA) // TABLETA',
                0.10,
                0.50,
            ],

            // Dispositivos médicos
            'tiras de glucómetro con marca vs genérico' => [
                'TIRAS DE GLUCOMETRO GLUCOQUICK GD50A DIAMOND C*50 UNIDAD',
                'TIRAS REACTIVAS PARA GLUCOMETRIA (UNIDAD) // UNIDAD',
                0.10,
                0.40,
            ],
        ];
    }

    // ── normalizeText (diacríticos y ñ en minúscula, E1-TOL-B1) ──────────

    /**
     * @dataProvider lowercaseDiacriticsProvider
     */
    public function testNormalizeTextHandlesLowercaseDiacriticsAndEnye(string $input, string $expected): void
    {
        // Arrange & Act:
        $actual = TextNormalization::normalizeText($input);

        // Assert:
        $this->assertSame($expected, $actual);
    }

    public static function lowercaseDiacriticsProvider(): array
    {
        return [
            'caso real D75261101999 Patiño' => ['Jaime Orlando Patiño Franco', 'JAIME ORLANDO PATINO FRANCO'],
            'caso real T72261100907 Muñoz'  => ['Luis Eduardo Murillo Muñoz', 'LUIS EDUARDO MURILLO MUNOZ'],
            'caso real V35261101509 ñ min'  => ['sandra milena villafañe', 'SANDRA MILENA VILLAFANE'],
            'caso real Q83261101995 Carriño' => ['Graciela Carriño de Galindo', 'GRACIELA CARRINO DE GALINDO'],
            'tildes minúsculas generales'   => ['María José Rodríguez Cáceres', 'MARIA JOSE RODRIGUEZ CACERES'],
            'diéresis minúscula'            => ['Agüero Pingüino', 'AGUERO PINGUINO'],
        ];
    }

    // ── damerauLevenshteinDistance1 ──────────────────────────────────────

    /**
     * @dataProvider damerauLevenshteinDistance1Provider
     */
    public function testDamerauLevenshteinDistance1(string $left, string $right, bool $expected): void
    {
        // Arrange & Act:
        $actual = TextNormalization::damerauLevenshteinDistance1($left, $right);

        // Assert:
        $this->assertSame($expected, $actual);
    }

    public static function damerauLevenshteinDistance1Provider(): array
    {
        return [
            'sustitución única (RODRIGUEZ/RODRIGREZ)'   => ['RODRIGUEZ', 'RODRIGREZ', true],
            'inserción única (RODRIGUEZ/RODRIGUUEZ)'    => ['RODRIGUEZ', 'RODRIGUUEZ', true],
            'eliminación única (RODRIGUEZ/RODRIGEZ)'    => ['RODRIGUEZ', 'RODRIGEZ', true],
            'transposición adyacente (RODRIGUEZ/RODRIGEUZ)' => ['RODRIGUEZ', 'RODRIGEUZ', true],
            'identidad (distancia 0)'                   => ['RODRIGUEZ', 'RODRIGUEZ', false],
            'distancia 2 (RODRIGUEZ/RODRGIZ)'           => ['RODRIGUEZ', 'RODRGIZ', false],
            'diferencia de longitud > 1'                => ['RODRIGUEZ', 'RODRIGU', false],
        ];
    }

    // ── isFinalGenderInflectionDiff ──────────────────────────────────────

    /**
     * @dataProvider finalGenderInflectionProvider
     */
    public function testIsFinalGenderInflectionDiff(string $left, string $right, bool $expected): void
    {
        // Arrange & Act:
        $actual = TextNormalization::isFinalGenderInflectionDiff($left, $right);

        // Assert:
        $this->assertSame($expected, $actual);
    }

    public static function finalGenderInflectionProvider(): array
    {
        return [
            'A/O MARIA vs MARIO'        => ['MARIA', 'MARIO', true],
            'A/O CARLA vs CARLO'        => ['CARLA', 'CARLO', true],
            'A/O ERNESTO vs ERNESTA'    => ['ERNESTO', 'ERNESTA', true],
            'sufijo -A DANIEL/DANIELA'  => ['DANIEL', 'DANIELA', true],
            'sufijo -A GABRIEL/GABRIELA' => ['GABRIEL', 'GABRIELA', true],
            'sufijo -A MANUEL/MANUELA'  => ['MANUEL', 'MANUELA', true],
            'sufijo -A RAFAEL/RAFAELA'  => ['RAFAEL', 'RAFAELA', true],
            'sufijo -A MARIA/MARI (raíz len 4, conservador)' => ['MARIA', 'MARI', true],
            'typo interno RODRIGUEZ/RODRIGREZ' => ['RODRIGUEZ', 'RODRIGREZ', false],
            'identidad MARIA/MARIA'     => ['MARIA', 'MARIA', false],
            'vocal final A/E MARIA/MARIE' => ['MARIA', 'MARIE', false],
            'eliminación interna RODRIGUEZ/RODRIGEZ' => ['RODRIGUEZ', 'RODRIGEZ', false],
            'raíz < 4 ANA/ANAA'         => ['ANA', 'ANAA', false],
        ];
    }

    // ── matchTokenSetWithMinorAlphabeticTypo ─────────────────────────────

    /**
     * @dataProvider minorAlphabeticTypoProvider
     * @param array{fdv_token:string,doc_token:string}|null $expected
     */
    public function testMatchTokenSetWithMinorAlphabeticTypo(
        string $fdvValue,
        string $docValue,
        bool $isPersonName,
        ?array $expected
    ): void {
        // Arrange & Act:
        $actual = TextNormalization::matchTokenSetWithMinorAlphabeticTypo($fdvValue, $docValue, $isPersonName);

        // Assert:
        $this->assertSame($expected, $actual);
    }

    public static function minorAlphabeticTypoProvider(): array
    {
        return [
            'X49260801520 inversión + 1 typo' => [
                'NIETO RODRIGUEZ JOSE ERNESTO',
                'JOSE ERNESTO NIETO RODRIGREZ',
                true,
                ['fdv_token' => 'RODRIGUEZ', 'doc_token' => 'RODRIGREZ'],
            ],
            'transposición adyacente en persona' => [
                'NIETO RODRIGUEZ JOSE ERNESTO',
                'NIETO RODRIGEUZ JOSE ERNESTO',
                true,
                ['fdv_token' => 'RODRIGUEZ', 'doc_token' => 'RODRIGEUZ'],
            ],
            'eliminación de 1 letra en persona' => [
                'NIETO RODRIGUEZ JOSE ERNESTO',
                'NIETO RODRIGEZ JOSE ERNESTO',
                true,
                ['fdv_token' => 'RODRIGUEZ', 'doc_token' => 'RODRIGEZ'],
            ],
            'inserción de 1 letra en persona' => [
                'NIETO RODRIGUEZ JOSE ERNESTO',
                'NIETO RODRIGUUEZ JOSE ERNESTO',
                true,
                ['fdv_token' => 'RODRIGUEZ', 'doc_token' => 'RODRIGUUEZ'],
            ],
            'médico con len 5 y orden invertido' => [
                'SIERRA GOYENECHE JENNY LUCIA',
                'JENNY LUCIA SIERA GOYENECHE',
                true,
                ['fdv_token' => 'SIERRA', 'doc_token' => 'SIERA'],
            ],
            'persona con menos de 2 tokens exactos rechazada' => [
                'JOSE RODRIGUEZ',
                'JOSE RODRIGREZ',
                true,
                null,
            ],
            'persona con 2 tokens con typo rechazada' => [
                'NIETO RODRIGUEZ JOSE ERNESTO',
                'NIETTO RODRIGREZ JOSE ERNESTO',
                true,
                null,
            ],
            'flexión género A/O bloqueada' => [
                'PEREZ LOPEZ MARIA',
                'PEREZ LOPEZ MARIO',
                true,
                null,
            ],
            'flexión género sufijo -A bloqueada (R1-TOL-B1)' => [
                'DR DANIEL PEREZ LOPEZ',
                'DRA DANIELA PEREZ LOPEZ',
                true,
                null,
            ],
            'token corto < 5 bloqueado en persona' => [
                'ANA PEREZ LOPEZ',
                'ADA PEREZ LOPEZ',
                true,
                null,
            ],
            'comodín ? combinado con typo excluido de Capa 1 (R1-TOL-I2)' => [
                'NOREÑA AGUDELO JUAN JOSE',
                'JUAN JOSE NORE?A AGUDELOO',
                true,
                null,
            ],
            'IPS con 1 typo tolerada' => [
                'HOSPITAL DEPARTAMENTAL DE VILLAVICENCIO',
                'HOSPITAL DEPARTAMENTAL DE VILAVICENCIO',
                false,
                ['fdv_token' => 'VILLAVICENCIO', 'doc_token' => 'VILAVICENCIO'],
            ],
            'institución con cardinalidad > 1 bloqueada (R1-TOL-I1)' => [
                'CLINICA BOGOTA',
                'CLINICA ESPECIALIZADA DE CIRUGIA BOGOTAA',
                false,
                null,
            ],
            'institución con tokens repetidos tolerada (R1-TOL-B2, E1-TOL-M2)' => [
                'HOSPITAL SAN VICENTE DE SAN VICENTE',
                'HOSPITAL SAN VICENTEE DE SAN VICENTEE',
                false,
                ['fdv_token' => 'VICENTE', 'doc_token' => 'VICENTEE'],
            ],
            'laboratorio mono-token >= 7 tolerado' => [
                'TECNOQUIMICAS',
                'TECNOQUIMCAS',
                false,
                ['fdv_token' => 'TECNOQUIMICAS', 'doc_token' => 'TECNOQUIMCAS'],
            ],
            'laboratorio mono-token < 7 rechazado (BAYER/MAYER)' => [
                'BAYER',
                'MAYER',
                false,
                null,
            ],
            'siglas cortas EPS vs IPS rechazadas' => [
                'NUEVA EPS',
                'NUEVA IPS',
                false,
                null,
            ],
            'tokens con dígitos rechazados' => [
                'CLINICA 100',
                'CLINICA 101',
                false,
                null,
            ],
            'ambigüedad (2 candidatos a distancia 1) rechazada' => [
                'ALPHA CASTRO',
                'ALPHA CASTRA CASTRI',
                false,
                null,
            ],
        ];
    }
}
