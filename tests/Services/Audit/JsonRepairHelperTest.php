<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\JsonRepairHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests para JsonRepairHelper — reparación de JSON truncado de Gemini.
 */
class JsonRepairHelperTest extends TestCase
{
    private JsonRepairHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new JsonRepairHelper();
    }

    // ── Cierre de brackets desbalanceados ──

    public function testRepairClosesUnbalancedBraces(): void
    {
        $truncated = '{"response": "success", "message": "OK", "data": {"items": []';
        $repaired = $this->helper->repair($truncated);

        $decoded = json_decode($repaired, true);
        $this->assertIsArray($decoded, "JSON reparado debe ser decodificable: {$repaired}");
        $this->assertEquals('success', $decoded['response']);
        $this->assertIsArray($decoded['data']['items']);
    }

    public function testRepairClosesUnbalancedArrayBracket(): void
    {
        $truncated = '{"response": "success", "message": "OK", "data": {"items": [{"item": "test"';
        $repaired = $this->helper->repair($truncated);

        $decoded = json_decode($repaired, true);
        $this->assertIsArray($decoded, "JSON con array truncado debe ser reparable: {$repaired}");
        $this->assertEquals('success', $decoded['response']);
    }

    // ── Comas finales ──

    public function testRepairRemovesTrailingCommaBeforeBrace(): void
    {
        $input = '{"response": "success", "message": "OK",}';
        $repaired = $this->helper->repair($input);

        $decoded = json_decode($repaired, true);
        $this->assertIsArray($decoded, "JSON con coma final debe ser reparable: {$repaired}");
        $this->assertEquals('success', $decoded['response']);
    }

    public function testRepairRemovesTrailingCommaBeforeBracket(): void
    {
        $input = '{"data": {"items": ["a", "b",]}}';
        $repaired = $this->helper->repair($input);

        $decoded = json_decode($repaired, true);
        $this->assertIsArray($decoded, "JSON con coma antes de ] debe ser reparable: {$repaired}");
        $this->assertEquals(['a', 'b'], $decoded['data']['items']);
    }

    // ── Strings sin cerrar ──

    public function testRepairClosesOpenString(): void
    {
        $truncated = '{"response": "success", "message": "Procesado correctamente';
        $repaired = $this->helper->repair($truncated);

        $decoded = json_decode($repaired, true);
        $this->assertIsArray($decoded, "JSON con string abierto debe ser reparable: {$repaired}");
        $this->assertEquals('success', $decoded['response']);
    }

    // ── Entries incompletas ──

    public function testRepairCleansIncompleteKeyValueAtEnd(): void
    {
        $truncated = '{"response": "success", "message":';
        $repaired = $this->helper->repair($truncated);

        $decoded = json_decode($repaired, true);
        $this->assertIsArray($decoded, "JSON con key sin valor debe ser reparable: {$repaired}");
        $this->assertEquals('success', $decoded['response']);
    }

    // ── JSON válido no se altera ──

    public function testRepairDoesNotAlterValidJson(): void
    {
        $valid = '{"response": "success", "message": "OK", "data": {"items": []}}';
        $repaired = $this->helper->repair($valid);

        $this->assertEquals($valid, $repaired, 'JSON válido no debe ser alterado');
    }

    // ── Strings vacíos ──

    public function testRepairReturnsEmptyForEmptyInput(): void
    {
        $this->assertEquals('', $this->helper->repair(''));
        $this->assertEquals('', $this->helper->repair('   '));
    }

    // ── Caso real: JSON truncado tipo Gemini ──

    public function testRepairHandlesRealisticGeminiTruncation(): void
    {
        $truncated = '{"response":"success","message":"Auditoría completada","severity":"ninguna","data":{"items":[{"documento":"FORMULA MEDICA","item":"Verificación de formulación","severidad":"ninguna","detalle":"Documento conforme","recomendacion":"Ninguna';

        $repaired = $this->helper->repair($truncated);
        $decoded = json_decode($repaired, true);

        $this->assertIsArray($decoded, "JSON truncado realista de Gemini debe ser reparable: {$repaired}");
        $this->assertEquals('success', $decoded['response']);
        $this->assertNotEmpty($decoded['data']['items']);
    }
}
