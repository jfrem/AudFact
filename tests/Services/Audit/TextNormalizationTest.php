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
                $fdvName, $fdvNorm, $docName, $docNorm,
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
}
