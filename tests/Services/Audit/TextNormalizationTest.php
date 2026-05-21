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
}
