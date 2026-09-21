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

    public function testAggregatesWhenSameProductCodeEvenIfLotesAreDifferent(): void
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
        
        // TRACE_TOKEN no es grouping key: los lotes se evalúan como set en evaluateTraceSetField
        $this->assertCount(1, $result);
        $this->assertSame('30', $result[0]['CantidadEntregada']);
        $this->assertSame('P1', $result[0]['CodigoProducto']);
    }

    public function testAggregatesWhenSameProductCodeEvenIfArticleNamesAreDifferent(): void
    {
        $items = [
            ['CodigoProducto' => 'MD003128', 'NombreArticulo' => 'SERTRALINA 100MG C*10 TABLETA', 'CantidadEntregada' => '20'],
            ['CodigoProducto' => 'MD003128', 'NombreArticulo' => 'SERTRALINA 100MG C*28 TABLETA', 'CantidadEntregada' => '10'],
        ];
        $config = [
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'E', 'tipoDato' => 'code', 'esMultiItem' => true],
            ['campoNombre' => 'NombreArticulo', 'tipoCampo' => 'S', 'tipoDato' => 'article_name'],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity']
        ];
        
        $result = FdvItemAggregator::aggregate($items, $config, $this->documentType);
        
        // ARTICLE_NAME no es grouping key: se evalúa semánticamente / suryectivamente
        $this->assertCount(1, $result);
        $this->assertSame('30', $result[0]['CantidadEntregada']);
        $this->assertSame('MD003128', $result[0]['CodigoProducto']);
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

    public function testAggregatesByProductCodeWhenTipoCampoIsBusiness(): void
    {
        $items = [
            ['CodigoProducto' => '20175802', 'CantidadEntregada' => '60', 'Lote' => 'L1', 'FechaVencimiento' => '2029-02-28'],
            ['CodigoProducto' => '20175802', 'CantidadEntregada' => '60', 'Lote' => 'L2', 'FechaVencimiento' => '2029-08-20'],
        ];
        // En configuración real de AUTORIZACION, CodigoProducto es B y code, CantidadEntregada es B y quantity
        $config = [
            ['campoNombre' => 'NumeroAutorizacion', 'tipoCampo' => 'E', 'tipoDato' => 'auth_number'],
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'B', 'tipoDato' => 'code'],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity'],
        ];

        $result = FdvItemAggregator::aggregate($items, $config, 'AUTORIZACION');

        $this->assertCount(1, $result);
        $this->assertSame('20175802', $result[0]['CodigoProducto']);
        $this->assertSame('120', $result[0]['CantidadEntregada']);
    }

    public function testPreservesMultipleItemsWhenDifferentProductsWithBusinessComparison(): void
    {
        $items = [
            ['CodigoProducto' => '20175802', 'CantidadEntregada' => '60'],
            ['CodigoProducto' => '10045678', 'CantidadEntregada' => '30'],
        ];
        $config = [
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'B', 'tipoDato' => 'code'],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity'],
        ];

        $result = FdvItemAggregator::aggregate($items, $config, 'AUTORIZACION');

        $this->assertCount(2, $result);
        $this->assertSame('20175802', $result[0]['CodigoProducto']);
        $this->assertSame('60', $result[0]['CantidadEntregada']);
        $this->assertSame('10045678', $result[1]['CodigoProducto']);
        $this->assertSame('30', $result[1]['CantidadEntregada']);
    }
}
