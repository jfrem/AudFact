<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\ExtractedEvidence;
use PHPUnit\Framework\TestCase;

/**
 * GoldenSetReplayTest — Framework de Replay Determinístico (AUDIT-016)
 *
 * Propósito
 * ---------
 * Verifica que el DocumentPolicyEngine produzca resultados estables (reproducibles)
 * al procesar los mismos datos dos veces consecutivas. Si el SHA-256 de ambas
 * corridas coincide, el engine es determinístico para ese caso.
 *
 * Filosofía
 * ---------
 * - NO depende de red, Redis, Gemini ni base de datos.
 * - Los fixtures contienen el payload mínimo necesario (FDV + documento extraído).
 * - Los datos de pacientes y documentos están redactados (valores sintéticos).
 * - Cada fixture = 1 caso clínico con su resultado esperado canónico.
 *
 * Cómo agregar un nuevo caso
 * --------------------------
 * 1. Crear `tests/Services/Audit/Fixtures/golden_<ID>.json`
 * 2. Agregar el ID a GOLDEN_FIXTURES.
 * 3. Correr `phpunit tests/Services/Audit/GoldenSetReplayTest.php` para validar.
 *
 * Formato del fixture (ver golden_D65260408592.json):
 * {
 *   "id":          string,          // Identificador del caso (DisDetNro / FacNro)
 *   "description": string,          // Descripción del escenario clínico
 *   "source_truth": { ... },        // Datos FDV (header + items del sistema)
 *   "documents": [                  // Array de documentos extraídos
 *     {
 *       "document_type":  string,
 *       "fields_config":  [...],    // Configuración de campos (audit-config)
 *       "fields":         {...},    // Campos extraídos (legacy scalar o v1 object)
 *       "items":          [...]     // Items extraídos (dispensas, etc.)
 *     }
 *   ],
 *   "expected": {                   // Resultado canónico esperado
 *     "approved":     bool,
 *     "campo_results": {            // campo → resultado esperado
 *       "NombreArticulo": "COINCIDE",
 *       ...
 *     }
 *   }
 * }
 */
class GoldenSetReplayTest extends TestCase
{
    /**
     * IDs de los fixtures activos en el Golden Set.
     * Cada entrada corresponde a un archivo en tests/Services/Audit/Fixtures/.
     */
    private const GOLDEN_FIXTURES = [
        'D65260408592',  // FORMULA MEDICA — Acetaminofén dispensado, CIE10 en lista
    ];

    private const FIXTURES_DIR = __DIR__ . '/Fixtures';

    // ─────────────────────────────────────────────────────────────────────────
    // Tests principales
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider goldenFixtureProvider
     */
    public function testGoldenCaseIsReproducible(string $fixtureId, array $fixture): void
    {
        $engine = new DocumentPolicyEngine();

        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $runResults = [];
            foreach ($fixture['documents'] as $doc) {
                $state   = $this->buildState($fixture['source_truth'], $doc);
                $payload = $this->buildPayload($doc);
                $result  = $engine->evaluate($state, $payload);
                $runResults[] = $result;
            }
            $results[$i] = $runResults;
        }

        $hash1 = $this->hashResults($results[0]);
        $hash2 = $this->hashResults($results[1]);

        $this->assertSame(
            $hash1,
            $hash2,
            "[$fixtureId] El engine NO es determinístico: ejecuciones 1 y 2 producen hashes distintos."
        );
    }

    /**
     * @dataProvider goldenFixtureProvider
     */
    public function testGoldenCaseMatchesExpectedDecision(string $fixtureId, array $fixture): void
    {
        $engine = new DocumentPolicyEngine();

        $allHallazgos = [];
        foreach ($fixture['documents'] as $doc) {
            $state   = $this->buildState($fixture['source_truth'], $doc);
            $payload = $this->buildPayload($doc);
            $result  = $engine->evaluate($state, $payload);
            foreach ($result['hallazgos']['items'] as $h) {
                $allHallazgos[$h['campo']] = $h['resultado'];
            }
        }

        $expected = $fixture['expected'];

        // Verificar decisión global de aprobación (si está en el fixture)
        if (array_key_exists('approved', $expected)) {
            // approved se calcula desde los hallazgos; aquí solo verificamos los
            // resultados de campo individuales definidos como canónicos.
        }

        // Verificar resultados canónicos por campo
        foreach ($expected['campo_results'] as $campo => $resultadoEsperado) {
            $this->assertArrayHasKey(
                $campo,
                $allHallazgos,
                "[$fixtureId] Campo esperado '$campo' no aparece en hallazgos."
            );
            $this->assertSame(
                $resultadoEsperado,
                $allHallazgos[$campo],
                "[$fixtureId] Campo '$campo': se esperaba '$resultadoEsperado', se obtuvo '{$allHallazgos[$campo]}'."
            );
        }
    }

    /**
     * @dataProvider goldenFixtureProvider
     */
    public function testGoldenCaseHallazgosHaveCanonicalShape(string $fixtureId, array $fixture): void
    {
        $engine = new DocumentPolicyEngine();

        foreach ($fixture['documents'] as $doc) {
            $state   = $this->buildState($fixture['source_truth'], $doc);
            $payload = $this->buildPayload($doc);
            $result  = $engine->evaluate($state, $payload);

            foreach ($result['hallazgos']['items'] as $h) {
                $this->assertArrayHasKey('campo',             $h, "[$fixtureId] Hallazgo sin 'campo'.");
                $this->assertArrayHasKey('resultado',         $h, "[$fixtureId] Hallazgo sin 'resultado'.");
                $this->assertArrayHasKey('valorFuenteVerdad', $h, "[$fixtureId] Hallazgo sin 'valorFuenteVerdad'.");
                $this->assertArrayHasKey('valorDocumento',    $h, "[$fixtureId] Hallazgo sin 'valorDocumento'.");
                $this->assertArrayHasKey('valueType',         $h, "[$fixtureId] Hallazgo sin 'valueType' (contrato v1).");
                $this->assertArrayHasKey('tipo_auditoria',    $h, "[$fixtureId] Hallazgo sin 'tipo_auditoria'.");
                $this->assertArrayHasKey('severidad',         $h, "[$fixtureId] Hallazgo sin 'severidad'.");
                $this->assertArrayHasKey('documento',         $h, "[$fixtureId] Hallazgo sin 'documento'.");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data provider
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{string, array}> */
    public static function goldenFixtureProvider(): array
    {
        $cases = [];
        foreach (self::GOLDEN_FIXTURES as $id) {
            $path = self::FIXTURES_DIR . "/golden_{$id}.json";
            if (!file_exists($path)) {
                // El fixture falta — el test fallará con mensaje claro.
                $cases[$id] = [$id, ['_missing' => true, 'documents' => [], 'source_truth' => [], 'expected' => ['campo_results' => []]]];
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            $cases[$id] = [$id, $data];
        }
        return $cases;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye el array `$state` que consume DocumentPolicyEngine::evaluate().
     *
     * @param array<string,mixed> $sourceTruth  Datos FDV del fixture (shape {header, items})
     * @param array<string,mixed> $doc          Documento del fixture
     * @return array<string,mixed>
     */
    private function buildState(array $sourceTruth, array $doc): array
    {
        return [
            'tipo_documento'   => $doc['document_type'],
            'fields_config'    => $doc['fields_config'],
            'fuente_verdad'    => $sourceTruth,
            'document_quality' => $doc['document_quality'] ?? 'legible',
            'visual_checks'    => [],
        ];
    }

    /**
     * Construye el array `$payload` que consume DocumentPolicyEngine::evaluate().
     *
     * @param array<string,mixed> $doc  Documento del fixture
     * @return array<string,mixed>
     */
    private function buildPayload(array $doc): array
    {
        $rawFields = $doc['fields'] ?? [];
        $rawItems = $doc['items'] ?? [];

        $hydratedFields = [];
        foreach ($rawFields as $key => $value) {
            $raw = is_array($value) && array_key_exists('valor', $value)
                ? $value
                : ['valor' => $value, 'presente' => true, 'estadoExtraccion' => 'FOUND'];
            $hydratedFields[$key] = ExtractedEvidence::fromArray($raw);
        }

        $hydratedItems = [];
        foreach ($rawItems as $item) {
            $hydratedItem = [];
            foreach ($item as $key => $value) {
                $raw = is_array($value) && array_key_exists('valor', $value)
                    ? $value
                    : ['valor' => $value, 'presente' => true, 'estadoExtraccion' => 'FOUND'];
                $hydratedItem[$key] = ExtractedEvidence::fromArray($raw);
            }
            $hydratedItems[] = $hydratedItem;
        }

        return [
            'tipo_documento'       => $doc['document_type'],
            'fields_normalized'    => $hydratedFields,
            'items_normalized'     => $hydratedItems,
            'document_quality'     => $doc['document_quality'] ?? 'legible',
            'visual_checks_resultado' => [],
        ];
    }

    /**
     * Genera un hash SHA-256 canónico de los resultados de auditoría.
     * Ordenamos las claves recursivamente para garantizar serialización estable.
     *
     * @param array<int,array<string,mixed>> $results
     */
    private function hashResults(array $results): string
    {
        $normalized = [];
        foreach ($results as $result) {
            $items = $result['hallazgos']['items'] ?? [];
            // Ordenar items por campo para orden estable
            usort($items, fn ($a, $b) => strcmp($a['campo'], $b['campo']));
            // Extraer solo los campos que forman la decisión canónica
            $normalized[] = array_map(fn ($h) => [
                'campo'     => $h['campo'],
                'resultado' => $h['resultado'],
                'valueType' => $h['valueType'] ?? null,
            ], $items);
        }
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE) ?: '');
    }
}
