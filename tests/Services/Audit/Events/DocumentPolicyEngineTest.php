<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\ExtractedEvidence;
use App\Services\Audit\Pipeline\ExtractionState;
use PHPUnit\Framework\TestCase;

final class DocumentPolicyEngineTest extends TestCase
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function field(
        string $name,
        string $tipo = 'E',
        string $severity = 'alta',
        ?string $tipoDato = null
    ): array {
        return [
            'campoNombre' => $name,
            'tipoCampo'   => $tipo,
            'tipoDato'    => $tipoDato ?? self::tipoDatoForTest($name),
            'severity'    => $severity,
            'orden'       => 0,
        ];
    }

    private static function tipoDatoForTest(string $field): string
    {
        return match ($field) {
            'FechaEntrega', 'FechaFormula', 'FechaAutorizacion', 'FechaVencimiento' => 'date',
            'CantidadEntregada', 'CantidadPrescrita' => 'quantity',
            'VlrCobrado', 'VlrTotal' => 'money',
            'TipoDocumentoPaciente', 'TipoDocumentoMedico' => 'identity_doc_type',
            'DocumentoPaciente', 'DocumentoMedico' => 'identity_doc_number',
            'CodigoDiagnostico', 'CodigoArticulo', 'CodigoProducto', 'CUM' => 'code',
            'Lote' => 'trace_token',
            'NombrePaciente', 'Medico' => 'person_name',
            'Cliente', 'IPS' => 'institution_name',
            'NombreArticulo' => 'article_name',
            default => 'text',
        };
    }

    private static function baseState(string $docType, array $fieldsConfig, array $fuenteVerdad, array $visualChecks = []): array
    {
        return [
            'tipo_documento' => $docType,
            'fields_config'  => $fieldsConfig,
            'visual_checks'  => $visualChecks,
            'fuente_verdad'  => $fuenteVerdad,
        ];
    }

    private static function payload(string $docType, array $fields = [], array $items = [], array $visualResults = [], string $quality = 'legible'): array
    {
        $v1Fields = [];
        foreach ($fields as $key => $value) {
            $raw = is_array($value) && array_key_exists('valor', $value) 
                ? $value 
                : ['valor' => $value, 'presente' => true, 'estadoExtraccion' => 'FOUND'];
            $v1Fields[$key] = ExtractedEvidence::fromArray($raw);
        }

        $v1Items = [];
        foreach ($items as $item) {
            $v1Item = [];
            foreach ($item as $key => $value) {
                $raw = is_array($value) && array_key_exists('valor', $value) 
                    ? $value 
                    : ['valor' => $value, 'presente' => true, 'estadoExtraccion' => 'FOUND'];
                $v1Item[$key] = ExtractedEvidence::fromArray($raw);
            }
            $v1Items[] = $v1Item;
        }

        return [
            'tipo_documento'         => $docType,
            'fields_normalized'      => $v1Fields,
            'items_normalized'       => $v1Items,
            'visual_checks_resultado' => $visualResults,
            'document_quality'       => $quality,
        ];
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function testEvaluateBuildsFindingsAndDocumentDecision(): void
    {
        $engine = new DocumentPolicyEngine();

        // fields_normalized usa nombres canónicos (el normalizador aplica FIELD_ALIASES antes de llegar aquí).
        // 'NumeroAutorizacion' → canónico 'Autorizacion'; FDV header usa la columna SQL real.
        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('NumeroAutorizacion'), self::field('CantidadEntregada', 'B')],
                ['header' => ['NumeroAutorizacion' => '46338218'], 'items' => [['CantidadEntregada' => '20'], ['CantidadEntregada' => '30']]],
                [['check' => 'FirmaActaEntrega', 'severity' => 'ALTA']]
            ),
            self::payload(
                'DISPENSA',
                ['NumeroAutorizacion' => '46338218'],
                [['CantidadEntregada' => '20'], ['CantidadEntregada' => '30']],
                [['check' => 'FirmaActaEntrega', 'presente' => true, 'detalle' => 'Firma visible', 'severidad' => 'ALTA']]
            )
        );

        $this->assertSame('DISPENSA', $result['document_name']);
        $this->assertSame(3, $result['hallazgos']['metrics']['total_campos']);
        $this->assertSame(3, $result['hallazgos']['metrics']['coincidencias']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateSkipsCalculatedVisualChecksForAggregatePolicy(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [],
                ['header' => [], 'items' => []],
                [['check' => 'VigenciaEntrega', 'severity' => 'ALTA']]
            ),
            self::payload(
                'AUTORIZACION',
                [],
                [],
                [[
                    'check' => 'VigenciaEntrega',
                    'presente' => true,
                    'valor' => 60,
                    'unidad' => 'dias',
                    'fecha_base' => 'FechaAutorizacion',
                ]]
            )
        );

        $this->assertSame(0, $result['hallazgos']['metrics']['total_campos']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateMarksInconclusiveWhenDocumentQualityIsNotLegible(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA_MEDICA',
                [self::field('CodigoDiagnostico')],
                ['header' => ['CodigoDiagnostico' => 'S127'], 'items' => []]
            ),
            self::payload('FORMULA_MEDICA', [], [], [], 'parcialmente_legible')
        );

        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertFalse($result['document_decision']['approved']);
    }

    public function testEvaluateComparesNumeroAutorizacionInFormulaMedicaWhenInConfig(): void
    {
        // Con fields_config dinámico, el campo se audita si está en config, sin importar el tipo de documento.
        // fields_normalized usa 'Autorizacion' (canónico); FDV header usa la columna SQL 'NumeroAutorizacion'.
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NumeroAutorizacion')],
                ['header' => ['NumeroAutorizacion' => '46338218'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['NumeroAutorizacion' => '45082636'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('NumeroAutorizacion', $result['hallazgos']['items'][0]['campo']);
        $this->assertSame('VALOR_DISTINTO', $result['hallazgos']['items'][0]['resultado']);
        $this->assertFalse($result['document_decision']['approved']);
    }

    public function testEvaluateMatchesPatientDocumentTypeAliasForCedulaCiudadania(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('TipoDocumentoPaciente')],
                ['header' => ['TipoDocumentoPaciente' => 'CC'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['TipoDocumentoPaciente' => 'Cédula ciudadanía'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateKeepsDifferentPatientDocumentTypesAsMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('TipoDocumentoPaciente')],
                ['header' => ['TipoDocumentoPaciente' => 'CC'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['TipoDocumentoPaciente' => 'CE'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('VALOR_DISTINTO', $result['hallazgos']['items'][0]['resultado']);
        $this->assertFalse($result['document_decision']['approved']);
    }

    public function testEvaluateMatchesPatientDocumentNumberWhenGeminiConcatenatesName(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('DocumentoPaciente')],
                ['header' => ['DocumentoPaciente' => '94229637'], 'items' => []]
            ),
            self::payload('DISPENSA', ['DocumentoPaciente' => '94229637-NOREÑA AGUDELO JUAN JOSE'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertSame('identity_doc_number', $result['hallazgos']['items'][0]['valueType']);
    }

    public function testEvaluateKeepsDifferentPatientDocumentNumberAsMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('DocumentoPaciente')],
                ['header' => ['DocumentoPaciente' => '94229637'], 'items' => []]
            ),
            self::payload('DISPENSA', ['DocumentoPaciente' => '12345678-NOREÑA AGUDELO JUAN JOSE'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('VALOR_DISTINTO', $result['hallazgos']['items'][0]['resultado']);
    }

    public function testEvaluateMarksFormulaDiagnosticAsInconclusiveWhenMissing(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CodigoDiagnostico')],
                ['header' => ['CodigoDiagnostico' => 'S127'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['CodigoDiagnostico' => 'null'])
        );

        $this->assertSame('NO_ENCONTRADO', $result['hallazgos']['items'][0]['resultado']);
        $this->assertStringContainsString('No se encontró', (string) $result['hallazgos']['items'][0]['detalle']);
    }

    public function testEvaluateMatchesPatientNameWhenTokensOnlyChangeOrder(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [self::field('NombrePaciente', 'S')],
                ['header' => ['NombrePaciente' => 'GARCIA ABSALON'], 'items' => []]
            ),
            self::payload('AUTORIZACION', ['NombrePaciente' => 'ABSALON GARCIA'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateMatchesPatientNameWhenEncodingReplacesEnyeWithQuestionMark(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [self::field('NombrePaciente', 'S')],
                ['header' => ['NombrePaciente' => 'NOREÑA AGUDELO JUAN JOSE'], 'items' => []]
            ),
            self::payload('AUTORIZACION', ['NombrePaciente' => 'JUAN JOSE NORE?A AGUDELO'])
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('COINCIDE', $hallazgo['resultado']);
        $this->assertSame('exact', $hallazgo['tipo_auditoria']);
        $this->assertSame(0, $result['gemini_semantic_metrics']['semantic_calls']);
    }

    public function testEvaluateMatchesCantidadWhenAggregatedValueIsCorrect(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CantidadPrescrita', 'B'), self::field('DocumentoPaciente')],
                ['header' => ['DocumentoPaciente' => '12132213'], 'items' => [['CantidadPrescrita' => '50']]]
            ),
            self::payload(
                'FORMULA MEDICA',
                ['DocumentoPaciente' => '12132213'],
                [['CantidadPrescrita' => '50']]
            )
        );

        $this->assertCount(2, $result['hallazgos']['items']);
        $campos = array_column($result['hallazgos']['items'], 'campo');
        $this->assertContains('CantidadPrescrita', $campos);
        $this->assertContains('DocumentoPaciente', $campos);

        $cantIdx = array_search('CantidadPrescrita', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$cantIdx]['resultado']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    // ─── AUDIT-016: CAT-1 — TRACE_TOKEN set comparison ───────────────────────

    /**
     * CAT-1 + TRACE_TOKEN: Multi-item con lotes distintos produce COINCIDE cuando FDV = Doc.
     */
    public function testCAT1TraceTokenLotesMatchWhenFdvEqualsDoc(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('Lote')],
                ['header' => [], 'items' => [
                    ['Lote' => '02041804-25'],
                    ['Lote' => '02041806-25'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['Lote' => '02041804-25'],
                    ['Lote' => '02041806-25'],
                ]
            )
        );

        $this->assertNotEmpty($result['hallazgos']['items'], 'El hallazgo no debe desaparecer silenciosamente');
        $loteHallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('Lote', $loteHallazgo['campo']);
        $this->assertSame('COINCIDE', $loteHallazgo['resultado']);
        $this->assertSame('trace_token', $loteHallazgo['valueType']);
        $this->assertArrayHasKey('valoresDocumento', $loteHallazgo);
        $this->assertContains('02041804-25', $loteHallazgo['valoresDocumento']);
        $this->assertContains('02041806-25', $loteHallazgo['valoresDocumento']);
    }

    /**
     * FDV = {A, B}, Doc = {A} → evidencia parcial → NO_CONCLUYENTE.
     */
    public function testTraceTokenPartialEvidenceProducesInconclusive(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('Lote')],
                ['header' => [], 'items' => [
                    ['Lote' => '645B01A'],
                    ['Lote' => 'E245513E'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['Lote' => '645B01A'],
                ]
            )
        );

        $lote = $result['hallazgos']['items'][0];
        $this->assertSame('Lote', $lote['campo']);
        $this->assertSame('NO_CONCLUYENTE', $lote['resultado']);
        $this->assertStringContainsString('E245513E', (string) $lote['detalle']);
        $this->assertSame(['645B01A'], $lote['valoresDocumento']);
    }

    /**
     * FDV = {A, B}, Doc = {A, C} → C no está en FDV → VALOR_DISTINTO.
     */
    public function testTraceTokenUnknownBatchProducesMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('Lote')],
                ['header' => [], 'items' => [
                    ['Lote' => '645B01A'],
                    ['Lote' => 'E245513E'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['Lote' => '645B01A'],
                    ['Lote' => 'FRAUDULENTO99'],
                ]
            )
        );

        $lote = $result['hallazgos']['items'][0];
        $this->assertSame('Lote', $lote['campo']);
        $this->assertSame('VALOR_DISTINTO', $lote['resultado']);
        $this->assertStringContainsString('FRAUDULENTO99', (string) $lote['detalle']);
    }

    /**
     * FDV = {A}, Doc = {A, B} → documento trae lote extra no registrado → VALOR_DISTINTO.
     */
    public function testTraceTokenExtraDocBatchProducesMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('Lote')],
                ['header' => [], 'items' => [
                    ['Lote' => '645B01A'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['Lote' => '645B01A'],
                    ['Lote' => 'EXTRA_LOTE'],
                ]
            )
        );

        $lote = $result['hallazgos']['items'][0];
        $this->assertSame('Lote', $lote['campo']);
        $this->assertSame('VALOR_DISTINTO', $lote['resultado']);
        $this->assertStringContainsString('EXTRA LOTE', (string) $lote['detalle']);
    }

    /**
     * Non-TRACE_TOKEN multi-item (ej: Laboratorio como TEXT) con valores distintos
     * sigue produciendo NO_CONCLUYENTE (guardrail ambiguous).
     */
    public function testNonTraceTokenMultiItemFieldStaysAmbiguous(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('Laboratorio')],
                ['header' => [], 'items' => [
                    ['Laboratorio' => 'BUSSIE'],
                    ['Laboratorio' => 'SEVEN PHARMA'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['Laboratorio' => 'BUSSIE'],
                    ['Laboratorio' => 'SEVEN PHARMA'],
                ]
            )
        );

        $lab = $result['hallazgos']['items'][0];
        $this->assertSame('Laboratorio', $lab['campo']);
        $this->assertSame('NO_CONCLUYENTE', $lab['resultado']);
        $this->assertStringContainsString('múltiples valores distintos', (string) $lab['detalle']);
    }

    // ─── AUDIT-016: CAT-3 — Comparación de subconjunto para CODE ─────────────

    /**
     * CAT-3 Golden Case: FDV contiene "S202", documento contiene "S202, S273, S224, S325, S723, F432".
     * El FDV es subconjunto del set documental → resultado debe ser COINCIDE.
     */
    public function testCAT3DiagnosticCodeFdvSubsetOfDocumentListResultsInCoincide(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CodigoDiagnostico', 'E')],
                ['header' => ['CodigoDiagnostico' => 'S202'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['CodigoDiagnostico' => 'S202, S273, S224, S325, S723, F432'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('CodigoDiagnostico', $hallazgo['campo']);
        $this->assertSame('COINCIDE', $hallazgo['resultado'], 'S202 es subconjunto de la lista documental');
        $this->assertTrue($result['document_decision']['approved']);
    }

    /**
     * CAT-3: Si el código FDV NO está en la lista documental → VALOR_DISTINTO.
     */
    public function testCAT3DiagnosticCodeFdvNotInDocumentListResultsInMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CodigoDiagnostico', 'E')],
                ['header' => ['CodigoDiagnostico' => 'Z999'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['CodigoDiagnostico' => 'S202, S273, S224'])
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('VALOR_DISTINTO', $hallazgo['resultado']);
    }

    /**
     * CAT-3: Cuando el documento tiene un solo código (FOUND, no lista) → comparación exacta normal.
     */
    public function testCAT3SingleCodeInDocumentUsesExactComparison(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [self::field('CodigoDiagnostico', 'E')],
                ['header' => ['CodigoDiagnostico' => 'S202'], 'items' => []]
            ),
            self::payload('AUTORIZACION', ['CodigoDiagnostico' => 'S202'])
        );

        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
    }

    // ─── AUDIT-016: CAT-4 — Token-sort para PERSON_NAME ────────────────────

    /**
     * CAT-4: "GARCIA ABSALON" vs "ABSALON GARCIA" → tokens ordenados son iguales → COINCIDE sin Gemini.
     */
    public function testCAT4PersonNameTokenSortResolvesMismatchBeforeSemanticFallback(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('NombrePaciente', 'S')],
                ['header' => ['NombrePaciente' => 'GARCIA ABSALON'], 'items' => []]
            ),
            self::payload('DISPENSA', ['NombrePaciente' => 'ABSALON GARCIA'])
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('NombrePaciente', $hallazgo['campo']);
        $this->assertSame('COINCIDE', $hallazgo['resultado']);
        $this->assertSame('exact', $hallazgo['tipo_auditoria']);
    }

    /**
     * CAT-4: "GARCIA ABSALON" vs "PEREZ JUAN" → tokens distintos → sigue al fallback local.
     */
    public function testCAT4PersonNameWithDifferentTokensGoesToSemanticJudge(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('NombrePaciente', 'S')],
                ['header' => ['NombrePaciente' => 'GARCIA ABSALON'], 'items' => []]
            ),
            self::payload('DISPENSA', ['NombrePaciente' => 'PEREZ JUAN'])
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('NombrePaciente', $hallazgo['campo']);
        $this->assertNotSame('COINCIDE', $hallazgo['resultado']);
    }

    // ─── AUDIT-016: Contrato v1 — hallazgo canónico ─────────────────────────

    /**
     * El hallazgo de un campo CODE debe incluir valoresDocumento y valueType.
     */
    public function testHallazgoCanonicoIncludesValoresDocumentoAndValueTypeForCodeFields(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CodigoDiagnostico', 'E')],
                ['header' => ['CodigoDiagnostico' => 'S202'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['CodigoDiagnostico' => 'S202, S273, S224'])
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertArrayHasKey('valoresDocumento', $hallazgo);
        $this->assertArrayHasKey('valueType', $hallazgo);
        $this->assertSame('code', $hallazgo['valueType']);
        $this->assertIsArray($hallazgo['valoresDocumento']);
        $this->assertContains('S202', $hallazgo['valoresDocumento']);
        $this->assertContains('S273', $hallazgo['valoresDocumento']);
    }

    // ─── NORM-001: Hardening de normalización ─────────────────────────────────

    /**
     * NORM-001: Alias CE (Cédula de Extranjería) debe resolverse contra FDV "CE".
     */
    public function testNORM001CedulaExtranjeriaAliasResolvesAsMatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('TipoDocumentoPaciente')],
                ['header' => ['TipoDocumentoPaciente' => 'CE'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['TipoDocumentoPaciente' => 'Cédula de Extranjería'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
    }

    /**
     * NORM-001: Alias TI (Tarjeta de Identidad) debe resolverse contra FDV "TI".
     */
    public function testNORM001TarjetaIdentidadAliasResolvesAsMatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('TipoDocumentoPaciente')],
                ['header' => ['TipoDocumentoPaciente' => 'TI'], 'items' => []]
            ),
            self::payload('DISPENSA', ['TipoDocumentoPaciente' => 'Tarjeta de Identidad'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
    }

    /**
     * NORM-001: Fecha narrativa "4 de mayo de 2026" debe coincidir con FDV ISO.
     */
    public function testNORM001NarrativeDateMatchesIsoFdv(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('FechaFormula')],
                ['header' => ['FechaFormula' => '2026-05-04'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['FechaFormula' => '4 de mayo de 2026'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
    }

    public function testEvaluateMultiItemLoteMatchesWhenFdvAndDocSetsAreEqual(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('Lote'), self::field('FechaVencimiento'), self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['Lote' => '02041804-25', 'FechaVencimiento' => '2029-03-30', 'NombreArticulo' => 'GASA ESTERIL'],
                    ['Lote' => '02041806-25', 'FechaVencimiento' => '2030-05-30', 'NombreArticulo' => 'GASA ESTERIL'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['Lote' => '02041804-25', 'FechaVencimiento' => '2029-03-30', 'NombreArticulo' => 'GASA ESTERIL'],
                    ['Lote' => '02041806-25', 'FechaVencimiento' => '2030-05-30', 'NombreArticulo' => 'GASA ESTERIL'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');

        // Lote (TRACE_TOKEN): FDV = Doc = {02041804-25, 02041806-25} → COINCIDE
        $this->assertContains('Lote', $campos);
        $loteIdx = array_search('Lote', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$loteIdx]['resultado']);
        $this->assertSame('trace_token', $result['hallazgos']['items'][$loteIdx]['valueType']);

        // FechaVencimiento (DATE) multi-item con valores distintos → NO_CONCLUYENTE (ambiguous)
        $this->assertContains('FechaVencimiento', $campos);
        $fvIdx = array_search('FechaVencimiento', $campos, true);
        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][$fvIdx]['resultado']);

        // NombreArticulo: idéntico en todas las filas → COINCIDE
        $this->assertContains('NombreArticulo', $campos);
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);
    }

    public function testEvaluateDoesNotEvaluateFieldsAbsentFromConfig(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [],
                ['header' => ['NombreArticulo' => 'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA')
        );

        $this->assertSame([], $result['hallazgos']['items']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateKeepsAuthorizationProductAsInconclusiveWhenSimilarityIsInsufficient(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [self::field('NombreArticulo', 'S')],
                ['header' => ['NombreArticulo' => 'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5'], 'items' => []]
            ),
            self::payload('AUTORIZACION', ['NombreArticulo' => 'Cureband premium gasa antiadherente estéril 7.5cm x 7.5cm'])
        );

        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertStringContainsString('verificación por parte del auditor', (string) $result['hallazgos']['items'][0]['detalle']);
    }

    public function testEvaluateMatchesDispensaArticleWhenFdvNameIsContainedWithinExtendedText(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [['NombreArticulo' => 'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5']]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [['NombreArticulo' => '20012566-23 - GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5 -- INV:2018DM-0018580']]
            )
        );

        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateMatchesDatesAcrossEquivalentDocumentFormats(): void
    {
        $engine = new DocumentPolicyEngine();

        $authorizationResult = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [self::field('FechaAutorizacion')],
                ['header' => ['FechaAutorizacion' => '2025-07-27'], 'items' => []]
            ),
            self::payload('AUTORIZACION', ['FechaAutorizacion' => '27/07/2025'])
        );

        $dispensaResult = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('FechaEntrega')],
                ['header' => ['FechaEntrega' => '2025-07-29'], 'items' => []]
            ),
            self::payload('DISPENSA', ['FechaEntrega' => '29/07/2025'])
        );

        $this->assertSame('COINCIDE', $authorizationResult['hallazgos']['items'][0]['resultado']);
        $this->assertSame('COINCIDE', $dispensaResult['hallazgos']['items'][0]['resultado']);
    }
}
