<?php
declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\FdvItemAggregator;
use PHPUnit\Framework\TestCase;

final class FdvItemAggregatorTest extends TestCase
{
    private string $documentType = 'AUTORIZACION';

    public function testReturnsOriginalWhenEmptyItems(): void
    {
        $items = [];
        $config = [['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertSame($items, $result);
    }

    public function testReturnsOriginalWhenSingleItem(): void
    {
        $items = [['CodigoProducto' => 'P1', 'CantidadEntregada' => '10']];
        $config = [['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertSame($items, $result);
    }

    public function testReturnsOriginalWhenFieldsConfigEmpty(): void
    {
        $items = [
            ['CodigoProducto' => 'P1', 'CantidadEntregada' => '10'],
            ['CodigoProducto' => 'P1', 'CantidadEntregada' => '20'],
        ];
        $config = [];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertSame($items, $result);
    }

    public function testReturnsOriginalWhenNoGroupingKeysExist(): void
    {
        $items = [
            ['CodigoProducto' => 'P1', 'CantidadEntregada' => '10'],
            ['CodigoProducto' => 'P1', 'CantidadEntregada' => '20'],
        ];
        // Solo un campo sumable, sin llaves exactas/semánticas de ítem
        $config = [
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']
        ];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertSame($items, $result);
    }

    public function testAggregatesBySameProductCode(): void
    {
        $items = [
            ['CodigoProducto' => 'P1', 'NombreArticulo' => 'ASPIRINA', 'CantidadEntregada' => '10'],
            ['CodigoProducto' => 'P1', 'NombreArticulo' => 'ASPIRINA', 'CantidadEntregada' => '20'],
        ];
        $config = [
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'E', 'tipoDato' => 'text', 'esMultiItem' => true],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']
        ];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertCount(1, $result);
        $this->assertSame('30', $result[0]['CantidadEntregada']);
        $this->assertSame('P1', $result[0]['CodigoProducto']);
    }

    public function testPreservesSeparationWhenLoteConfiguredAndDifferent(): void
    {
        $items = [
            ['CodigoProducto' => 'P1', 'Lote' => 'L1', 'CantidadEntregada' => '10'],
            ['CodigoProducto' => 'P1', 'Lote' => 'L2', 'CantidadEntregada' => '20'],
        ];
        $config = [
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'E', 'tipoDato' => 'text', 'esMultiItem' => true],
            ['campoNombre' => 'Lote', 'tipoCampo' => 'E', 'tipoDato' => 'trace_token'],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']
        ];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertCount(2, $result);
    }

    public function testAggregatesWhenLoteConfiguredAndSame(): void
    {
        $items = [
            ['CodigoProducto' => 'P1', 'Lote' => 'L1', 'CantidadEntregada' => '10'],
            ['CodigoProducto' => 'P1', 'Lote' => 'L1', 'CantidadEntregada' => '20'],
        ];
        $config = [
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'E', 'tipoDato' => 'text', 'esMultiItem' => true],
            ['campoNombre' => 'Lote', 'tipoCampo' => 'E', 'tipoDato' => 'trace_token'],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']
        ];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertCount(1, $result);
        $this->assertSame('30', $result[0]['CantidadEntregada']);
    }

    public function testSumPreservation(): void
    {
        $items = [
            ['CodigoProducto' => 'P1', 'CantidadEntregada' => '100'],
            ['CodigoProducto' => 'P1', 'CantidadEntregada' => '200'],
            ['CodigoProducto' => 'P1', 'CantidadEntregada' => '50'],
        ];
        $config = [
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'E', 'tipoDato' => 'text', 'esMultiItem' => true],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']
        ];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        $this->assertCount(1, $result);
        $this->assertSame('350', $result[0]['CantidadEntregada']);
    }

    public function testOnlyItemFieldsAreConsidered(): void
    {
        $items = [
            ['CodigoProducto' => 'P1', 'NumeroAutorizacion' => 'A1', 'CantidadEntregada' => '10'],
            ['CodigoProducto' => 'P1', 'NumeroAutorizacion' => 'A2', 'CantidadEntregada' => '20'],
        ];
        $config = [
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'E', 'tipoDato' => 'text', 'esMultiItem' => true],
            ['campoNombre' => 'NumeroAutorizacion', 'tipoCampo' => 'E', 'tipoDato' => 'auth_number'],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']
        ];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        // NumeroAutorizacion es campo de header, no de ítem → no participa en agrupación.
        $this->assertCount(1, $result);
        $this->assertSame('30', $result[0]['CantidadEntregada']);
    }
}
