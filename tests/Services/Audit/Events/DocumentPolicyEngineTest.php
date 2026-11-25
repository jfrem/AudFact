<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\ExtractedEvidence;
use App\Services\Audit\Pipeline\ExtractionState;
use App\Services\Audit\Pipeline\SemanticEvaluationStore;
use App\Services\Audit\ResolvedSemanticResolver;
use App\Services\Audit\SemanticCandidateCollector;
use App\Services\Audit\SemanticMatchResolver;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class DocumentPolicyEngineTest extends TestCase
{
    public function testPermanentBatchResponseCannotFallBackToMoreIndividualRequests(): void
    {
        // Arrange:
        $gateway = $this->createMock(\App\Services\Audit\GeminiGateway::class);
        $gateway->method('requestBody')->willReturn([]);
        $gateway->expects(self::once())->method('sendPrebuiltBody')->willReturn([
            'candidates' => [['finishReason' => 'MAX_TOKENS']],
        ]);
        $gateway->expects(self::once())->method('invalidateTaskResponses')->with(false);
        $gateway->expects(self::never())->method('sendWithStructuredOutput');
        $redis = $this->createStub(\Core\RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);
        $engine = new DocumentPolicyEngine(new \App\Services\Audit\SemanticMatchJudge($gateway, $redis));
        $state = self::baseState('DISPENSA', [self::field('NombreArticulo', 'S')], [
            'header' => [], 'items' => [['NombreArticulo' => 'AAAAAA'], ['NombreArticulo' => 'BBBBBB']],
        ]);
        $payload = self::payload('DISPENSA', [], [['NombreArticulo' => 'XXXXXX']]);

        // Assert:
        self::expectException(\App\Services\Audit\GeminiResponseException::class);
        self::expectExceptionMessage('GEMINI_RESPONSE_OUTPUT_TRUNCATED');

        // Act:
        $engine->evaluate($state, $payload);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function field(
        string $name,
        string $tipo = 'E',
        string $severity = 'alta',
        ?string $tipoDato = null,
        ?string $codigoCampo = null
    ): array {
        $field = [
            'campoNombre' => $name,
            'tipoCampo'   => $tipo,
            'tipoDato'    => $tipoDato ?? self::tipoDatoForTest($name),
            'severity'    => $severity,
            'orden'       => 0,
        ];

        if ($codigoCampo !== null) {
            $field['codigoCampo'] = $codigoCampo;
        }

        return $field;
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

    public function testFailedDataFindingProducesMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CodigoDiagnostico', 'E', 'alta', 'code', 'DX')],
                ['header' => ['CodigoDiagnostico' => 'S202'], 'items' => []]
            ),
            self::payload(
                'FORMULA MEDICA',
                ['CodigoDiagnostico' => 'A001']
            )
        );

        $finding = $result['hallazgos']['items'][0];
        $this->assertSame('VALOR_DISTINTO', $finding['resultado']);
    }

    public function testMatchingDataFindingProducesCoincide(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CodigoDiagnostico', 'E', 'alta', 'code', 'DX')],
                ['header' => ['CodigoDiagnostico' => 'S202'], 'items' => []]
            ),
            self::payload(
                'FORMULA MEDICA',
                ['CodigoDiagnostico' => 'S202, A001']
            )
        );

        $finding = $result['hallazgos']['items'][0];
        $this->assertSame('COINCIDE', $finding['resultado']);
        $this->assertNull($finding['detalle']);
    }

    public function testFailedVisualFindingProducesMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [],
                ['header' => [], 'items' => []],
                [['check' => 'FirmaActaEntrega', 'severity' => 'ALTA', 'codigoCampo' => 'FIR']]
            ),
            self::payload(
                'DISPENSA',
                [],
                [],
                [['check' => 'FirmaActaEntrega', 'presente' => false, 'detalle' => 'Firma no visible', 'severidad' => 'ALTA']]
            )
        );

        $finding = $result['hallazgos']['items'][0];
        $this->assertSame('VALOR_DISTINTO', $finding['resultado']);
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
        $this->assertArrayHasKey('valoresFuenteVerdad', $loteHallazgo);
        $this->assertArrayHasKey('valoresDocumento', $loteHallazgo);
        $this->assertContains('02041804-25', $loteHallazgo['valoresFuenteVerdad']);
        $this->assertContains('02041806-25', $loteHallazgo['valoresFuenteVerdad']);
        $this->assertContains('02041804-25', $loteHallazgo['valoresDocumento']);
        $this->assertContains('02041806-25', $loteHallazgo['valoresDocumento']);
    }

    /**
     * FDV = {A, B}, Doc = {A} → evidencia parcial → NO_CONCLUYENTE.
     */
    public function testD13260500540MultiItemDispensaUsesResolvedFdvAndDocumentValues(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [
                    self::field('Lote'),
                    self::field('CantidadEntregada', 'B'),
                    self::field('CantidadPrescrita', 'B'),
                ],
                ['header' => [], 'items' => [
                    ['Lote' => '5D03364', 'CantidadEntregada' => '2', 'CantidadPrescrita' => '2'],
                    ['Lote' => '5G00989', 'CantidadEntregada' => '5', 'CantidadPrescrita' => '28'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['Lote' => '5D03364', 'CantidadEntregada' => '2', 'CantidadPrescrita' => '2'],
                    ['Lote' => '5G00989', 'CantidadEntregada' => '5', 'CantidadPrescrita' => '28'],
                ]
            )
        );

        $findingsByField = [];
        foreach ($result['hallazgos']['items'] as $finding) {
            $findingsByField[$finding['campo']] = $finding;
        }

        $this->assertSame('COINCIDE', $findingsByField['Lote']['resultado']);
        $this->assertSame(['5D03364', '5G00989'], $findingsByField['Lote']['valoresFuenteVerdad']);
        $this->assertSame(['5D03364', '5G00989'], $findingsByField['Lote']['valoresDocumento']);

        $this->assertSame('COINCIDE', $findingsByField['CantidadEntregada']['resultado']);
        $this->assertSame('7', $findingsByField['CantidadEntregada']['valorFuenteVerdad']);
        $this->assertSame('7', $findingsByField['CantidadEntregada']['valorDocumento']);

        $this->assertSame('COINCIDE', $findingsByField['CantidadPrescrita']['resultado']);
        $this->assertSame('30', $findingsByField['CantidadPrescrita']['valorFuenteVerdad']);
        $this->assertSame('30', $findingsByField['CantidadPrescrita']['valorDocumento']);

        $this->assertTrue($result['document_decision']['approved']);
    }

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
        $this->assertStringContainsString('multiples valores distintos', (string) $lab['detalle']);
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

    public function testCAT3DiagnosticCodeUsesValoresWhenValorIsNull(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CodigoDiagnostico', 'E')],
                ['header' => ['CodigoDiagnostico' => 'S328'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', [
                'CodigoDiagnostico' => [
                    'valor' => null,
                    'valores' => ['S328', 'S342'],
                    'presente' => true,
                    'estadoExtraccion' => ExtractionState::FOUND_IN_LIST->value,
                ],
            ])
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('COINCIDE', $hallazgo['resultado']);
        $this->assertSame(['S328', 'S342'], $hallazgo['valoresDocumento']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testScalarFieldUsesSingleValueFromValoresWhenValorIsNull(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('FechaFormula')],
                ['header' => ['FechaFormula' => '2026-04-22'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', [
                'FechaFormula' => [
                    'valor' => null,
                    'valores' => ['2026-04-22'],
                    'presente' => true,
                    'estadoExtraccion' => ExtractionState::FOUND_IN_LIST->value,
                ],
            ])
        );

        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testScalarFieldWithMultipleValoresIsInconclusiveNotMissing(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('FechaFormula')],
                ['header' => ['FechaFormula' => '2026-04-22'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', [
                'FechaFormula' => [
                    'valor' => null,
                    'valores' => ['2026-04-22', '2026-04-23'],
                    'presente' => true,
                    'estadoExtraccion' => ExtractionState::FOUND_IN_LIST->value,
                ],
            ])
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('NO_CONCLUYENTE', $hallazgo['resultado']);
        $this->assertSame('2026-04-22, 2026-04-23', $hallazgo['valorDocumento']);
        $this->assertStringContainsString('multiples valores distintos', (string) $hallazgo['detalle']);
    }

    public function testQuantityItemWithMultipleValoresIsInconclusiveInsteadOfSummed(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('CantidadEntregada', 'B')],
                ['header' => ['CantidadEntregada' => '50'], 'items' => []]
            ),
            self::payload(
                'DISPENSA',
                [],
                [[
                    'CantidadEntregada' => [
                        'valor' => null,
                        'valores' => ['40', '50'],
                        'presente' => true,
                        'estadoExtraccion' => ExtractionState::FOUND_IN_LIST->value,
                    ],
                ]]
            )
        );

        $hallazgo = $result['hallazgos']['items'][0];
        $this->assertSame('NO_CONCLUYENTE', $hallazgo['resultado']);
        $this->assertSame('40, 50', $hallazgo['valorDocumento']);
        $this->assertStringContainsString('multiples valores distintos', (string) $hallazgo['detalle']);
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

    public function testIncompleteItemSegmentationMakesQuantityInconclusive(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('CantidadEntregada', 'B')],
                ['header' => [], 'items' => [['CantidadEntregada' => '20'], ['CantidadEntregada' => '30']]]
            ),
            [
                'tipo_documento'         => 'DISPENSA',
                'fields_normalized'      => [],
                'items_normalized'       => [
                    ['CantidadEntregada' => ExtractedEvidence::fromArray(['valor' => '20', 'presente' => true, 'estadoExtraccion' => 'FOUND'])],
                ],
                'visual_checks_resultado' => [],
                'document_quality'       => 'legible',
                'extraction_warnings'    => [
                    [
                        'code' => 'ITEM_SEGMENTATION_INCOMPLETE',
                        'severity' => 'warning',
                        'scope' => 'items',
                        'expected_items_count' => 2,
                        'extracted_items_count' => 1,
                    ],
                ],
            ]
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('CantidadEntregada', $result['hallazgos']['items'][0]['campo']);
        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertStringContainsString('extracción de líneas del documento fue incompleta', (string) $result['hallazgos']['items'][0]['detalle']);
        $this->assertArrayHasKey('item_segmentation', $result['hallazgos']['items'][0]['extraction_meta']);
        $this->assertSame('ITEM_SEGMENTATION_INCOMPLETE', $result['hallazgos']['items'][0]['extraction_meta']['item_segmentation']['code']);
    }

    public function testIncompleteItemSegmentationDoesNotAffectHeaderField(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('NumeroAutorizacion'), self::field('CantidadEntregada', 'B')],
                ['header' => ['NumeroAutorizacion' => '123'], 'items' => [['CantidadEntregada' => '20'], ['CantidadEntregada' => '30']]]
            ),
            [
                'tipo_documento'         => 'DISPENSA',
                'fields_normalized'      => [
                    'NumeroAutorizacion' => ExtractedEvidence::fromArray(['valor' => '123', 'presente' => true, 'estadoExtraccion' => 'FOUND'])
                ],
                'items_normalized'       => [
                    ['CantidadEntregada' => ExtractedEvidence::fromArray(['valor' => '20', 'presente' => true, 'estadoExtraccion' => 'FOUND'])],
                ],
                'visual_checks_resultado' => [],
                'document_quality'       => 'legible',
                'extraction_warnings'    => [
                    [
                        'code' => 'ITEM_SEGMENTATION_INCOMPLETE',
                        'severity' => 'warning',
                        'scope' => 'items',
                        'expected_items_count' => 2,
                        'extracted_items_count' => 1,
                    ],
                ],
            ]
        );

        $this->assertCount(2, $result['hallazgos']['items']);

        $auth = array_values(array_filter($result['hallazgos']['items'], fn($h) => $h['campo'] === 'NumeroAutorizacion'))[0];
        $this->assertSame('COINCIDE', $auth['resultado']);

        $cant = array_values(array_filter($result['hallazgos']['items'], fn($h) => $h['campo'] === 'CantidadEntregada'))[0];
        $this->assertSame('NO_CONCLUYENTE', $cant['resultado']);
    }

    public function testIncompleteItemSegmentationResolvesAsMatchWhenQuantitativeBalanceSatisfied(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [self::field('CodigoProducto', 'B'), self::field('CantidadEntregada', 'B')],
                ['header' => [], 'items' => [['CodigoProducto' => '20175802', 'CantidadEntregada' => '60'], ['CodigoProducto' => '20175802', 'CantidadEntregada' => '60']]]
            ),
            [
                'tipo_documento'         => 'AUTORIZACION',
                'fields_normalized'      => [],
                'items_normalized'       => [
                    [
                        'CodigoProducto'    => ExtractedEvidence::fromArray(['valor' => '20175802-4', 'presente' => true, 'estadoExtraccion' => 'FOUND']),
                        'CantidadEntregada' => ExtractedEvidence::fromArray(['valor' => '120', 'presente' => true, 'estadoExtraccion' => 'FOUND']),
                    ],
                ],
                'visual_checks_resultado' => [],
                'document_quality'       => 'legible',
                'extraction_warnings'    => [
                    [
                        'code' => 'ITEM_SEGMENTATION_INCOMPLETE',
                        'severity' => 'warning',
                        'scope' => 'items',
                        'expected_items_count' => 2,
                        'extracted_items_count' => 1,
                    ],
                ],
            ]
        );

        $this->assertCount(2, $result['hallazgos']['items']);

        $cant = array_values(array_filter($result['hallazgos']['items'], fn($h) => $h['campo'] === 'CantidadEntregada'))[0];
        $this->assertSame('COINCIDE', $cant['resultado']);
        $this->assertArrayHasKey('item_segmentation', $cant['extraction_meta']);
        $this->assertSame('ITEM_SEGMENTATION_INCOMPLETE', $cant['extraction_meta']['item_segmentation']['code']);

        $prd = array_values(array_filter($result['hallazgos']['items'], fn($h) => $h['campo'] === 'CodigoProducto'))[0];
        $this->assertSame('COINCIDE', $prd['resultado']);
        $this->assertArrayHasKey('item_segmentation', $prd['extraction_meta']);
    }

    // ─── Article Set Matching ───────────────────────────────────────────────

    public function testEvaluateArticleSetMatchesTwoDistinctArticlesWhenBothMatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'ACIDO FENOFIBRICO + ROSUVASTATINA 135MG/20MG'],
                    ['NombreArticulo' => 'SALMETEROL + FLUTICASONA 25MCG/125MCG'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'ACIDO FENOFIBRICO + ROSUVASTATINA 135MG/20MG'],
                    ['NombreArticulo' => 'SALMETEROL + FLUTICASONA 25MCG/125MCG'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $this->assertContains('NombreArticulo', $campos);
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);
    }

    public function testEvaluateArticleSetMatchesWhenDocContainsExtraArticles(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'IBUPROFENO 400MG'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'IBUPROFENO 400MG'],
                    ['NombreArticulo' => 'ACETAMINOFEN 500MG'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);
    }

    public function testEvaluateArticleSetReportsInconclusiveWhenFdvArticleMissing(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'IBUPROFENO 400MG'],
                    ['NombreArticulo' => 'ACETAMINOFEN 500MG'],
                    ['NombreArticulo' => 'OMEPRAZOL 20MG'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'IBUPROFENO 400MG'],
                    ['NombreArticulo' => 'ACETAMINOFEN 500MG'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][$nomIdx]['resultado']);
        $this->assertStringContainsString('OMEPRAZOL', (string) $result['hallazgos']['items'][$nomIdx]['detalle']);
        $this->assertStringContainsString('1 de 3', (string) $result['hallazgos']['items'][$nomIdx]['detalle']);
    }

    public function testEvaluateArticleSetMatchesBySubstringContainment(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5'],
                    ['NombreArticulo' => 'ESPARADRAPO MICROPORE 2 PULGADAS'],
                ]]
            ),
            self::payload(
                'DISPENSA',
                [],
                [
                    ['NombreArticulo' => '20012566-23 - GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5 -- INV:2018DM-0018580'],
                    ['NombreArticulo' => 'ESPARADRAPO MICROPORE 2 PULGADAS REF ABC-123'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);
    }

    public function testEvaluateArticleSetReportsInconclusiveWhenDistinctArticleMissing(): void
    {
        $engine = new DocumentPolicyEngine();

        // FDV tiene 2 artículos distintos, Doc tiene 1 artículo que matchea solo con el primero
        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'IBUPROFENO 400MG'],
                    ['NombreArticulo' => 'OMEPRAZOL 20MG'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'IBUPROFENO 400MG'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][$nomIdx]['resultado']);
        $this->assertStringContainsString('OMEPRAZOL', (string) $result['hallazgos']['items'][$nomIdx]['detalle']);
    }

    public function testEvaluateArticleSetSurjectiveMatchingMultipleFdvRowsToOneDocItem(): void
    {
        $engine = new DocumentPolicyEngine();

        // FDV tiene múltiples filas de bodega para el mismo producto clínico (multi-presentación)
        // más otro producto: 3 filas en bodega -> 2 líneas en la fórmula médica
        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'SERTRALINA 100MG C*10 TABLETA'],
                    ['NombreArticulo' => 'TRIMEBUTINA 200MG C*60 TABLETA'],
                    ['NombreArticulo' => 'SERTRALINA 100MG C*28 TABLETA'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'SERTRALINA 100MG TABLETA'],
                    ['NombreArticulo' => 'TRIMEBUTINA 200MG TABLETA'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testResolveDataFindingPreservesSetEvaluatedDetailWithoutSegmentationOverwrite(): void
    {
        $engine = new DocumentPolicyEngine();

        // Cuando hay warning de segmentación y falta un artículo genuinamente,
        // el detalle debe ser el diagnóstico específico del comparador de conjuntos y no el warning genérico.
        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'IBUPROFENO 400MG CAJA X 10'],
                    ['NombreArticulo' => 'LOSARTAN 50MG CAJA X 30'],
                ]]
            ),
            array_merge(
                self::payload(
                    'FORMULA MEDICA',
                    [],
                    [
                        ['NombreArticulo' => 'IBUPROFENO 400MG'],
                    ]
                ),
                [
                    'extraction_warnings' => [
                        [
                            'code'                  => 'ITEM_SEGMENTATION_INCOMPLETE',
                            'severity'              => 'warning',
                            'scope'                 => 'items',
                            'document_type'         => 'FORMULA MEDICA',
                            'expected_items_count'  => 2,
                            'extracted_items_count' => 1,
                        ]
                    ]
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][$nomIdx]['resultado']);
        // El detalle preserva el nombre del artículo faltante ("LOSARTAN") y no el genérico de líneas
        $this->assertStringContainsString('LOSARTAN', (string) $result['hallazgos']['items'][$nomIdx]['detalle']);
    }

    public function testEvaluateArticleSetMatchesArticlesInReversedOrder(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'MEDICAMENTO ALFA'],
                    ['NombreArticulo' => 'MEDICAMENTO BETA'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'MEDICAMENTO BETA'],
                    ['NombreArticulo' => 'MEDICAMENTO ALFA'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);
    }

    public function testEvaluateArticleSetFallsBackToScalarForSingleItem(): void
    {
        $engine = new DocumentPolicyEngine();

        // N=1 debe seguir usando evaluateSemanticField(), no evaluateArticleSetField()
        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'ACETAMINOFEN 500MG C*100 TABLETA'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'ACETAMINOFEN 500MG C*100 TABLETA'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);
    }

    public function testEvaluateShortCircuitsOnDocumentConformityMismatch(): void
    {
        $engine = new DocumentPolicyEngine();

        $payload = self::payload('FORMULA MEDICA', ['NombrePaciente' => 'JUAN PEREZ']);
        $payload['document_conformity'] = [
            'matches_expected_type' => false,
            'detected_type' => 'Cédula de ciudadanía',
            'justification' => 'El archivo adjunto corresponde a un documento de identidad',
        ];

        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombrePaciente', 'S'), self::field('DocumentoPaciente', 'E')],
            ['header' => ['NombrePaciente' => 'JUAN PEREZ', 'DocumentoPaciente' => '123456'], 'items' => []]
        );
        $state['doc_id'] = '3';
        $state['attachment_id'] = '10';

        $result = $engine->evaluate($state, $payload, 'FAC-001');

        $this->assertSame('FORMULA MEDICA', $result['document_name']);
        $this->assertCount(1, $result['hallazgos']['items']);
        $finding = $result['hallazgos']['items'][0];
        $this->assertSame('TipoDocumento', $finding['campo']);
        $this->assertSame('TIP', $finding['codigoCampo']);
        $this->assertSame('NO_CONCLUYENTE', $finding['resultado']);
        $this->assertSame('alta', $finding['severidad']);
        $this->assertStringContainsString('Cédula de ciudadanía', $finding['detalle']);
        $this->assertStringContainsString('Requiere revisión manual', $finding['detalle']);

        $this->assertFalse($result['document_decision']['approved']);
        $this->assertCount(1, $result['document_decision']['payload']['hallazgos']);
        $this->assertSame('TIP', $result['document_decision']['payload']['hallazgos'][0]['Codigo']);
    }

    public function testQualityNotesAsArrayDoesNotTriggerWarning(): void
    {
        $engine = new DocumentPolicyEngine();
        $payload = self::payload('ACTA DE ENTREGA', [
            'NumeroFactura' => ['valor' => 'F123', 'presente' => true, 'estadoExtraccion' => 'FOUND'],
        ]);
        $payload['quality_notes'] = ['Nota de calidad 1', 'Nota de calidad 2'];

        $state = self::baseState(
            'ACTA DE ENTREGA',
            [self::field('NumeroFactura', 'E')],
            ['header' => ['NumeroFactura' => 'F123'], 'items' => []]
        );

        $result = $engine->evaluate($state, $payload, 'FAC-001');
        $this->assertTrue($result['document_decision']['approved']);
    }

    // ─── PRESENCE (Presencia Bilateral) ──────────────────────────────────────

    public function testPresenceComparisonMatchesWhenBothValuesExistEvenIfDifferent(): void
    {
        $engine = new DocumentPolicyEngine();

        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('Medico', 'P', 'alta', 'person_name')],
            ['header' => ['Medico' => 'DR. PEREZ JUAN'], 'items' => []]
        );

        $payload = self::payload(
            'FORMULA MEDICA',
            ['Medico' => 'DRA. GOMEZ MARIA']
        );

        $result = $engine->evaluate($state, $payload, 'FAC-PRES-01');

        $finding = $result['hallazgos']['items'][0] ?? null;
        $this->assertNotNull($finding, 'Debe emitir un hallazgo para Medico');
        $this->assertSame('COINCIDE', $finding['resultado']);
        $this->assertSame('presence', $finding['tipo_auditoria']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testPresenceComparisonReportsNotFoundWhenDocValueIsNull(): void
    {
        $engine = new DocumentPolicyEngine();

        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('Medico', 'P', 'alta', 'person_name')],
            ['header' => ['Medico' => 'DR. PEREZ JUAN'], 'items' => []]
        );

        $payload = self::payload(
            'FORMULA MEDICA',
            ['Medico' => ['valor' => null, 'presente' => false, 'estadoExtraccion' => 'NOT_FOUND']]
        );

        $result = $engine->evaluate($state, $payload, 'FAC-PRES-02');

        $finding = $result['hallazgos']['items'][0] ?? null;
        $this->assertNotNull($finding, 'Debe emitir un hallazgo por ausencia en documento');
        $this->assertSame('NO_ENCONTRADO', $finding['resultado']);
        $this->assertSame('presence', $finding['tipo_auditoria']);
        $this->assertFalse($result['document_decision']['approved']);
    }

    public function testPresenceComparisonReportsMismatchWhenFdvValueIsNull(): void
    {
        $engine = new DocumentPolicyEngine();

        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('Medico', 'P', 'alta', 'person_name')],
            ['header' => ['Medico' => null], 'items' => []]
        );

        $payload = self::payload(
            'FORMULA MEDICA',
            ['Medico' => 'DRA. GOMEZ MARIA']
        );

        $result = $engine->evaluate($state, $payload, 'FAC-PRES-03');

        $finding = $result['hallazgos']['items'][0] ?? null;
        $this->assertNotNull($finding, 'Debe emitir un hallazgo por ausencia en FdV');
        $this->assertSame('VALOR_DISTINTO', $finding['resultado']);
        $this->assertSame('presence', $finding['tipo_auditoria']);
    }

    public function testPresenceComparisonPreservesInconclusiveWhenQualityIsIllegible(): void
    {
        $engine = new DocumentPolicyEngine();

        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('Medico', 'P', 'alta', 'person_name')],
            ['header' => ['Medico' => 'DR. PEREZ JUAN'], 'items' => []]
        );

        $payload = self::payload(
            'FORMULA MEDICA',
            ['Medico' => ['valor' => null, 'presente' => false, 'estadoExtraccion' => 'NOT_FOUND']],
            [],
            [],
            'ilegible'
        );

        $result = $engine->evaluate($state, $payload, 'FAC-PRES-04');

        $finding = $result['hallazgos']['items'][0] ?? null;
        $this->assertNotNull($finding, 'Debe emitir hallazgo inconcluso por calidad');
        $this->assertSame('NO_CONCLUYENTE', $finding['resultado']);
    }

    public function testPresenceComparisonReportsNotFoundWhenBothValuesAreNull(): void
    {
        $engine = new DocumentPolicyEngine();

        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('Medico', 'P', 'alta', 'person_name')],
            ['header' => ['Medico' => null], 'items' => []]
        );

        $payload = self::payload(
            'FORMULA MEDICA',
            ['Medico' => ['valor' => null, 'presente' => false, 'estadoExtraccion' => 'NOT_FOUND']]
        );

        $result = $engine->evaluate($state, $payload, 'FAC-PRES-05');

        $finding = $result['hallazgos']['items'][0] ?? null;
        $this->assertNotNull($finding, 'PRESENCE con ambos nulos debe emitir hallazgo, no omitirse');
        $this->assertSame('NO_ENCONTRADO', $finding['resultado']);
        $this->assertSame('presence', $finding['tipo_auditoria']);
        $this->assertFalse($result['document_decision']['approved']);
    }

    public function testBuildDocumentContextSummaryIsDeterministicRegardlessOfKeyOrder(): void
    {
        $engine = new DocumentPolicyEngine();

        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S', 'alta', 'article_name')],
            ['header' => [], 'items' => [['NombreArticulo' => 'LOSARTAN 50MG TABLETA']]]
        );

        $orderedFields = [
            'TipoDocumentoPaciente' => 'CC',
            'Mipres' => '20260918180002483741',
            'DocumentoPaciente' => '86088752',
            'NITDiscolmets' => '828002423',
            'NumeroAutorizacion' => '360385156',
        ];

        $payload = self::payload(
            'FORMULA MEDICA',
            $orderedFields,
            [['NombreArticulo' => 'LOSARTAN POTASICO 50 MG']]
        );

        // 1. Plan stage
        $plan = $engine->plan($state, $payload, 'FAC-SEM-01');
        $this->assertTrue($plan['requires_semantics']);
        $this->assertCount(1, $plan['candidates']);
        $pairId = $plan['candidates'][0]['pair_id'];

        // 2. Simulate Redis cjson table roundtrip where keys in fields_normalized are shuffled
        $shuffledFields = [
            'NumeroAutorizacion' => '360385156',
            'DocumentoPaciente' => '86088752',
            'TipoDocumentoPaciente' => 'CC',
            'NITDiscolmets' => '828002423',
            'Mipres' => '20260918180002483741',
        ];
        $shuffledPayload = self::payload(
            'FORMULA MEDICA',
            $shuffledFields,
            [['NombreArticulo' => 'LOSARTAN POTASICO 50 MG']]
        );

        $snapshot = [
            'document_state' => $state,
            'normalized_payload' => $shuffledPayload,
            'fac_nro' => 'FAC-SEM-01',
        ];

        $resolvedResults = [
            $pairId => [
                'is_match' => true,
                'reasoning' => 'Principio activo y dosificación idénticos.',
                'source' => 'llm',
            ],
        ];

        // 3. Evaluate resolved stage must succeed without DomainException: semantic_result_missing
        $resolved = $engine->evaluateResolved($snapshot, $resolvedResults);
        $this->assertTrue($resolved['document_decision']['approved']);
        $this->assertSame('COINCIDE', $resolved['hallazgos']['items'][0]['resultado']);
    }

    public function testResolvedSemanticResolverFallbackMatchesByCanonicalSignatureWhenPairIdDiffers(): void
    {
        $resolvedResults = [
            'legacy_hash_abc' => [
                'is_match' => true,
                'reasoning' => 'Coincidencia semántica verificada por fallback.',
                'source' => 'cache',
                'expected' => 'LOSARTAN 50MG',
                'actual' => 'LOSARTAN POTASICO 50MG',
                'purpose' => \App\Services\Audit\SemanticMatchPurpose::PRODUCT->value,
                'field' => 'NombreArticulo',
            ],
        ];

        $resolver = new \App\Services\Audit\ResolvedSemanticResolver($resolvedResults);

        // Context with a completely different document_context string (would compute a different pair hash)
        $evalResult = $resolver->evaluate('LOSARTAN 50MG', 'LOSARTAN POTASICO 50MG', [
            'call_purpose' => \App\Services\Audit\SemanticMatchPurpose::PRODUCT->value,
            'field' => 'NombreArticulo',
            'document_context' => 'DifferentContext: 999',
        ]);

        $this->assertTrue($evalResult['is_match']);
        $this->assertSame('Coincidencia semántica verificada por fallback.', $evalResult['reasoning']);
        $this->assertTrue($evalResult['cache_hit']);
    }

    /**
     * Regresion Bug: semantic_result_missing
     * Escenario N:1 — dos articulos FDV (tab vs comprimido) apuntan a la misma
     * linea documental. La Fase 1 lexica empareja ambos FDV a doc0.
     * Con la restriccion 1:1 anterior esto causaba DomainException al resolver.
     * Con la correccion N:1, ambos deben resolverse como COINCIDE sin excepcion.
     */
    public function testEvaluateArticleSetAllowsMultipleFdvToShareOneDocLine(): void
    {
        $engine = new DocumentPolicyEngine();

        // FDV: 2 articulos donde ambos matchean lexicamente al unico articulo del doc
        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('NombreArticulo', 'S')],
                ['header' => [], 'items' => [
                    ['NombreArticulo' => 'METFORMINA 850 MG TAB'],
                    ['NombreArticulo' => 'METFORMINA 850 MG TABLETA'],
                ]]
            ),
            self::payload(
                'FORMULA MEDICA',
                [],
                [
                    ['NombreArticulo' => 'METFORMINA 850 MG TABLETA'],
                ]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);
        self::assertNotFalse($nomIdx, 'Se espera hallazgo de NombreArticulo');
        self::assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado'],
            'Ambos articulos FDV deben coincidir con la unica linea documental (N:1 surjective)');
    }
    public function testResolvedSemanticResolverThrowsWhenCandidateNotFound(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('semantic_result_missing');

        $resolver = new \App\Services\Audit\ResolvedSemanticResolver([]);
        $resolver->evaluate('AMOXICILINA 500MG', 'AMPICILINA 500MG', [
            'call_purpose' => \App\Services\Audit\SemanticMatchPurpose::PRODUCT->value,
            'field' => 'NombreArticulo',
        ]);
    }

    // ─── Tests T-08 a T-15: Captura y Evaluación de unmatched_articles ─────────

    public function testT08EvaluateWithEmptyUnmatchedArticlesBehavesAsBaseline(): void
    {
        $engine = new DocumentPolicyEngine();
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [['NombreArticulo' => 'ACETAMINOFEN 500MG TABLETA']]]
        );
        $payload = self::payload(
            'FORMULA MEDICA',
            [],
            [['NombreArticulo' => 'ACETAMINOFEN 500MG TABLETA']]
        );
        $payload['unmatched_articles'] = [];

        $result = $engine->evaluate($state, $payload);
        $findings = $result['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);

        self::assertNotFalse($nomIdx);
        self::assertSame('COINCIDE', $findings[$nomIdx]['resultado']);
        self::assertSame('ACETAMINOFEN 500MG TABLETA', $findings[$nomIdx]['valorDocumento']);
    }

    public function testT08bEvaluateWithAmbiguousItemsPreservesAmbiguityRegardlessOfUnmatchedArticles(): void
    {
        $engine = new DocumentPolicyEngine();
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NumeroAutorizacion', 'E')],
            ['header' => ['NumeroAutorizacion' => '123456'], 'items' => []]
        );
        $payload = self::payload(
            'FORMULA MEDICA',
            [],
            [
                ['NumeroAutorizacion' => '123456'],
                ['NumeroAutorizacion' => '999999'],
            ]
        );
        $payload['unmatched_articles'] = ['MEDICAMENTO EXTRA'];

        $result = $engine->evaluate($state, $payload);
        $findings = $result['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $autIdx = array_search('NumeroAutorizacion', $campos, true);

        self::assertNotFalse($autIdx);
        self::assertSame('NO_CONCLUYENTE', $findings[$autIdx]['resultado']);
        self::assertStringContainsString('multiples valores distintos', $findings[$autIdx]['detalle']);
    }

    public function testT09EvaluateRecoversFalseNegativeWithUnmatchedArticlesMatchingViaSemanticJudge(): void
    {
        $judge = new class implements \App\Services\Audit\SemanticMatchResolver {
            public function evaluate(string $expected, string $actual, array $context = []): array
            {
                if (str_contains($actual, 'DOLEX') && str_contains($expected, 'ACETAMINOFEN')) {
                    return ['is_match' => true, 'reasoning' => 'DOLEX es marca comercial de ACETAMINOFEN'];
                }
                return ['is_match' => false, 'reasoning' => 'No match'];
            }
        };

        $engine = new DocumentPolicyEngine($judge);
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [['NombreArticulo' => 'ACETAMINOFEN 500MG TABLETA']]]
        );
        $payload = self::payload('FORMULA MEDICA', [], []);
        $payload['unmatched_articles'] = ['DOLEX 500MG TABLETA'];

        $result = $engine->evaluate($state, $payload);
        $findings = $result['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);

        self::assertNotFalse($nomIdx);
        self::assertSame('COINCIDE', $findings[$nomIdx]['resultado']);
        self::assertSame('DOLEX 500MG TABLETA', $findings[$nomIdx]['valorDocumento']);
        self::assertSame('DOLEX es marca comercial de ACETAMINOFEN', $findings[$nomIdx]['detalle']);
    }

    public function testT10EvaluateTriggersInconclusiveForDiscrepantUnmatchedArticlesD66261005571(): void
    {
        $judge = new class implements \App\Services\Audit\SemanticMatchResolver {
            public function evaluate(string $expected, string $actual, array $context = []): array
            {
                return ['is_match' => false, 'reasoning' => 'FLUOXETINA y QUETIAPINA corresponden a moleculas distintas'];
            }
        };

        $engine = new DocumentPolicyEngine($judge);
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [['NombreArticulo' => 'QUETIAPINA 100MG C 30 TABLETA']]]
        );
        $payload = self::payload('FORMULA MEDICA', [], []);
        $payload['unmatched_articles'] = ['FLUOXETINA 20MG CÁPSULAS'];

        $result = $engine->evaluate($state, $payload);
        $findings = $result['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);

        self::assertNotFalse($nomIdx);
        self::assertSame('NO_CONCLUYENTE', $findings[$nomIdx]['resultado']);
        self::assertNotSame('NO_ENCONTRADO', $findings[$nomIdx]['resultado'], 'No debe emitir NO_ENCONTRADO');
        self::assertSame('FLUOXETINA 20MG CÁPSULAS', $findings[$nomIdx]['valorDocumento']);
        self::assertStringContainsString('FLUOXETINA y QUETIAPINA corresponden a moleculas distintas', $findings[$nomIdx]['detalle']);
    }

    public function testT11EvaluateArticleSetWithPartialMatchAndUnmatchedArticlesDetailsVisibleArticles(): void
    {
        $judge = new class implements \App\Services\Audit\SemanticMatchResolver {
            public function evaluate(string $expected, string $actual, array $context = []): array
            {
                return ['is_match' => false, 'reasoning' => 'Sin equivalencia clínica'];
            }
        };

        $engine = new DocumentPolicyEngine($judge);
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [
                ['NombreArticulo' => 'LOSARTAN 50MG TABLETA'],
                ['NombreArticulo' => 'METFORMINA 850MG TABLETA'],
            ]]
        );
        $payload = self::payload(
            'FORMULA MEDICA',
            [],
            [['NombreArticulo' => 'LOSARTAN 50MG TABLETA']]
        );
        $payload['unmatched_articles'] = ['GLIBENCLAMIDA 5MG TABLETA'];

        $result = $engine->evaluate($state, $payload);
        $findings = $result['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);

        self::assertNotFalse($nomIdx);
        self::assertSame('NO_CONCLUYENTE', $findings[$nomIdx]['resultado']);
        self::assertStringContainsString('no contiene evidencia para 1 de 2 artículos', $findings[$nomIdx]['detalle']);
        self::assertStringContainsString('METFORMINA 850MG TABLETA', $findings[$nomIdx]['detalle']);
        self::assertStringContainsString("Artículos visibles en el documento:", $findings[$nomIdx]['detalle']);
        self::assertStringContainsString('GLIBENCLAMIDA 5MG TABLETA', $findings[$nomIdx]['detalle']);
        self::assertStringContainsString('LOSARTAN 50MG TABLETA', $findings[$nomIdx]['detalle']);
    }

    public function testT12EvaluateIgnoresUnmatchedArticlesWhenDocumentConformityFails(): void
    {
        $engine = new DocumentPolicyEngine();
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [['NombreArticulo' => 'QUETIAPINA 100MG']]]
        );
        $payload = self::payload('FORMULA MEDICA', [], []);
        $payload['unmatched_articles'] = ['FLUOXETINA 20MG'];
        $payload['document_conformity'] = [
            'matches_expected_type' => false,
            'detected_type' => 'Cédula de ciudadanía',
            'justification' => 'El adjunto es un documento de identidad',
        ];

        $result = $engine->evaluate($state, $payload);
        self::assertCount(1, $result['hallazgos']['items']);
        self::assertSame('TIP', $result['hallazgos']['items'][0]['codigoCampo']);
        self::assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][0]['resultado']);
    }

    public function testT13EvaluateLegacyPayloadWithoutUnmatchedArticlesKeyProcessesGracefully(): void
    {
        $engine = new DocumentPolicyEngine();
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [['NombreArticulo' => 'ACETAMINOFEN 500MG TABLETA']]]
        );
        $payload = self::payload(
            'FORMULA MEDICA',
            [],
            [['NombreArticulo' => 'ACETAMINOFEN 500MG TABLETA']]
        );
        unset($payload['unmatched_articles']);

        $result = $engine->evaluate($state, $payload);
        $findings = $result['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);

        self::assertNotFalse($nomIdx);
        self::assertSame('COINCIDE', $findings[$nomIdx]['resultado']);
    }

    public function testT14PlanAndEvaluateResolvedPreserveCandidateParityForUnmatchedArticles(): void
    {
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [['NombreArticulo' => 'QUETIAPINA 100MG C 30 TABLETA']]]
        );
        $payload = self::payload('FORMULA MEDICA', [], []);
        $payload['unmatched_articles'] = ['FLUOXETINA 20MG CÁPSULAS'];

        $engine = new DocumentPolicyEngine();
        $plan = $engine->plan($state, $payload, 'FAC-PARITY');

        self::assertTrue($plan['requires_semantics']);
        self::assertCount(1, $plan['candidates']);
        self::assertSame('QUETIAPINA 100MG C 30 TABLETA', $plan['candidates'][0]['expected']);
        self::assertSame('FLUOXETINA 20MG CÁPSULAS', $plan['candidates'][0]['actual']);

        $pairId = $plan['candidates'][0]['pair_id'];
        $resolvedResults = [
            $pairId => [
                'is_match' => false,
                'reasoning' => 'Mecánica de acción y molécula completamente diferentes',
            ],
        ];

        $snapshot = [
            'document_state' => $state,
            'normalized_payload' => $payload,
            'fac_nro' => 'FAC-PARITY',
        ];

        $resolvedResult = $engine->evaluateResolved($snapshot, $resolvedResults);
        $findings = $resolvedResult['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);

        self::assertNotFalse($nomIdx);
        self::assertSame('NO_CONCLUYENTE', $findings[$nomIdx]['resultado']);
        self::assertSame('FLUOXETINA 20MG CÁPSULAS', $findings[$nomIdx]['valorDocumento']);
        self::assertStringContainsString('Mecánica de acción y molécula completamente diferentes', $findings[$nomIdx]['detalle']);
    }

    public function testT15AntiFalsePositiveBypassesFuzzyMatchForUnmatchedArticles(): void
    {
        $judge = new class implements \App\Services\Audit\SemanticMatchResolver {
            public int $calls = 0;
            public function evaluate(string $expected, string $actual, array $context = []): array
            {
                $this->calls++;
                return [
                    'is_match' => false,
                    'reasoning' => 'Pese a compartir dosis y forma farmacéutica, FLUOXETINA y QUETIAPINA no son equivalentes',
                ];
            }
        };

        $engine = new DocumentPolicyEngine($judge);
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S')],
            ['header' => [], 'items' => [['NombreArticulo' => 'QUETIAPINA 100MG C 30 TABLETA']]]
        );
        $payload = self::payload('FORMULA MEDICA', [], []);
        $payload['unmatched_articles'] = ['FLUOXETINA 100MG C 30 TABLETA'];

        $result = $engine->evaluate($state, $payload);
        $findings = $result['hallazgos']['items'];
        $campos = array_column($findings, 'campo');
        $nomIdx = array_search('NombreArticulo', $campos, true);

        self::assertNotFalse($nomIdx);
        self::assertGreaterThanOrEqual(1, $judge->calls, 'Debe haber invocado al juez semántico obligatoriamente');
        self::assertSame('NO_CONCLUYENTE', $findings[$nomIdx]['resultado'], 'Blindaje D-11 debe evitar el falso COINCIDE');
        self::assertStringContainsString('FLUOXETINA y QUETIAPINA no son equivalentes', $findings[$nomIdx]['detalle']);
    }

    // ── SDD-TOL-001: Tolerancia ortográfica controlada ────────────────────

    private static function spySemanticJudge(
        bool $isMatch = false,
        string $reasoning = 'Escalado a Capa 2'
    ): \App\Services\Audit\SemanticMatchResolver {
        return new class ($isMatch, $reasoning) implements \App\Services\Audit\SemanticMatchResolver {
            public int $calls = 0;

            public function __construct(
                private readonly bool $isMatch,
                private readonly string $reasoning
            ) {
            }

            public function evaluate(string $expected, string $actual, array $context = []): array
            {
                $this->calls++;

                return ['is_match' => $this->isMatch, 'reasoning' => $this->reasoning];
            }
        };
    }

    public function testPatientNameMinorTypoMatchesWhenDocumentIdMatches(): void
    {
        // Arrange: caso real X49260801520 con NombrePaciente ANTES que DocumentoPaciente en fields_config
        $judge = self::spySemanticJudge(false, 'No debería invocarse Gemini');

        $engine = new DocumentPolicyEngine($judge);
        $state = self::baseState(
            'FORMULA MEDICA',
            [
                self::field('NombrePaciente', 'S', 'alta', 'person_name', 'PAC'),
                self::field('DocumentoPaciente', 'E', 'alta', 'identity_doc_number', 'DOP'),
            ],
            [
                'header' => [
                    'NombrePaciente'    => 'NIETO RODRIGUEZ JOSE ERNESTO',
                    'DocumentoPaciente' => '86030619',
                ],
                'items' => [],
            ]
        );
        $payload = self::payload('FORMULA MEDICA', [
            'NombrePaciente'    => 'JOSE ERNESTO NIETO RODRIGREZ',
            'DocumentoPaciente' => '86030619',
        ]);

        // Act:
        $result = $engine->evaluate($state, $payload, 'X49260801520');

        // Assert:
        $findings = $result['hallazgos']['items'];
        $pacIdx = array_search('NombrePaciente', array_column($findings, 'campo'), true);

        self::assertNotFalse($pacIdx);
        self::assertSame('COINCIDE', $findings[$pacIdx]['resultado']);
        self::assertSame('JOSE ERNESTO NIETO RODRIGREZ', $findings[$pacIdx]['valorDocumento']);
        self::assertStringContainsString("'RODRIGREZ' ≈ 'RODRIGUEZ'", (string) $findings[$pacIdx]['detalle']);
        self::assertStringContainsString('DocumentoPaciente', (string) $findings[$pacIdx]['detalle']);
        self::assertTrue($result['document_decision']['approved']);
        self::assertSame(0, $judge->calls);
    }

    public function testPersonNameTypoToleranceIsSymmetricRegardlessOfTokenOrder(): void
    {
        // Arrange:
        $fieldsCfg = [
            self::field('NombrePaciente', 'S', 'alta', 'person_name', 'PAC'),
            self::field('DocumentoPaciente', 'E', 'alta', 'identity_doc_number', 'DOP'),
        ];
        $fdv = [
            'header' => [
                'NombrePaciente'    => 'NIETO RODRIGUEZ JOSE ERNESTO',
                'DocumentoPaciente' => '86030619',
            ],
            'items' => [],
        ];
        $engine = new DocumentPolicyEngine();

        // Act: con cédula coincidente en ambos órdenes
        $sameOrderWithId = $engine->evaluate(
            self::baseState('FORMULA MEDICA', $fieldsCfg, $fdv),
            self::payload('FORMULA MEDICA', [
                'NombrePaciente'    => 'NIETO RODRIGREZ JOSE ERNESTO',
                'DocumentoPaciente' => '86030619',
            ])
        );
        $reversedWithId = $engine->evaluate(
            self::baseState('FORMULA MEDICA', $fieldsCfg, $fdv),
            self::payload('FORMULA MEDICA', [
                'NombrePaciente'    => 'JOSE ERNESTO NIETO RODRIGREZ',
                'DocumentoPaciente' => '86030619',
            ])
        );

        // Act: sin cédula en ambos órdenes (simétrico: ninguno aprueba en Capa 1)
        $sameOrderNoId = $engine->evaluate(
            self::baseState('FORMULA MEDICA', $fieldsCfg, $fdv),
            self::payload('FORMULA MEDICA', ['NombrePaciente' => 'NIETO RODRIGREZ JOSE ERNESTO'])
        );
        $reversedNoId = $engine->evaluate(
            self::baseState('FORMULA MEDICA', $fieldsCfg, $fdv),
            self::payload('FORMULA MEDICA', ['NombrePaciente' => 'JOSE ERNESTO NIETO RODRIGREZ'])
        );

        // Assert:
        self::assertSame('COINCIDE', $sameOrderWithId['hallazgos']['items'][0]['resultado']);
        self::assertSame('COINCIDE', $reversedWithId['hallazgos']['items'][0]['resultado']);
        self::assertSame('NO_CONCLUYENTE', $sameOrderNoId['hallazgos']['items'][0]['resultado']);
        self::assertSame('NO_CONCLUYENTE', $reversedNoId['hallazgos']['items'][0]['resultado']);
    }

    public function testPatientNameTypoWithMismatchingOrMissingDocumentIdDoesNotAutoMatch(): void
    {
        // Arrange:
        $judgeMissing = self::spySemanticJudge(false, 'Escalado a Capa 2 por ausencia de ancla');
        $judgeMismatch = self::spySemanticJudge(false, 'Escalado a Capa 2 por cédula distinta');

        $state = self::baseState(
            'FORMULA MEDICA',
            [
                self::field('NombrePaciente', 'S', 'alta', 'person_name', 'PAC'),
                self::field('DocumentoPaciente', 'E', 'alta', 'identity_doc_number', 'DOP'),
            ],
            [
                'header' => [
                    'NombrePaciente'    => 'NIETO RODRIGUEZ JOSE ERNESTO',
                    'DocumentoPaciente' => '86030619',
                ],
                'items' => [],
            ]
        );

        // Act:
        $resMissing = (new DocumentPolicyEngine($judgeMissing))->evaluate(
            $state,
            self::payload('FORMULA MEDICA', ['NombrePaciente' => 'JOSE ERNESTO NIETO RODRIGREZ'])
        );
        $resMismatch = (new DocumentPolicyEngine($judgeMismatch))->evaluate(
            $state,
            self::payload('FORMULA MEDICA', [
                'NombrePaciente'    => 'JOSE ERNESTO NIETO RODRIGREZ',
                'DocumentoPaciente' => '86030618',
            ])
        );

        // Assert:
        self::assertSame('NO_CONCLUYENTE', $resMissing['hallazgos']['items'][0]['resultado']);
        self::assertSame(1, $judgeMissing->calls);

        self::assertSame('NO_CONCLUYENTE', $resMismatch['hallazgos']['items'][0]['resultado']);
        self::assertSame('VALOR_DISTINTO', $resMismatch['hallazgos']['items'][1]['resultado']);
        self::assertSame(1, $judgeMismatch->calls);
    }

    public function testPersonNameGenderInflectionDoesNotAutoMatchInLayer1(): void
    {
        // Arrange:
        $judge1 = self::spySemanticJudge(false, 'Género distinto');
        $judge2 = self::spySemanticJudge(false, 'Género distinto (-A)');

        // Act: A/O en mismo orden aun con cédula coincidente
        $resMariaMario = (new DocumentPolicyEngine($judge1))->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [
                    self::field('NombrePaciente', 'S', 'alta', 'person_name', 'PAC'),
                    self::field('DocumentoPaciente', 'E', 'alta', 'identity_doc_number', 'DOP'),
                ],
                ['header' => ['NombrePaciente' => 'PEREZ LOPEZ MARIA', 'DocumentoPaciente' => '12345678'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['NombrePaciente' => 'PEREZ LOPEZ MARIO', 'DocumentoPaciente' => '12345678'])
        );

        // Act: sufijo -A en Medico (R1-TOL-B1)
        $resDanielDaniela = (new DocumentPolicyEngine($judge2))->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('Medico', 'S', 'alta', 'person_name', 'MED')],
                ['header' => ['Medico' => 'DR DANIEL PEREZ LOPEZ'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['Medico' => 'DRA DANIELA PEREZ LOPEZ'])
        );

        // Assert:
        self::assertSame('NO_CONCLUYENTE', $resMariaMario['hallazgos']['items'][0]['resultado']);
        self::assertSame(1, $judge1->calls);
        self::assertSame('NO_CONCLUYENTE', $resDanielDaniela['hallazgos']['items'][0]['resultado']);
        self::assertSame(1, $judge2->calls);
    }

    public function testDoctorInstitutionAndLabMinorTypoMatchDeterministically(): void
    {
        // Arrange:
        $judge = self::spySemanticJudge(false, 'Escalado a Capa 2');
        $engine = new DocumentPolicyEngine($judge);

        // Act 1: Medico (S), IPS (S), Cliente (S), Laboratorio (S) y Title Case con ñ (E1-TOL-B1)
        $resValid = $engine->evaluate(
            self::baseState(
                'ACTA DE ENTREGA',
                [
                    self::field('Medico', 'S', 'alta', 'person_name', 'MED'),
                    self::field('IPS', 'S', 'alta', 'institution_name', 'IPS'),
                    self::field('Cliente', 'S', 'alta', 'institution_name', 'CLI'),
                    self::field('Laboratorio', 'S', 'alta', 'text', 'LAB'),
                ],
                [
                    'header' => [
                        'Medico'      => 'SIERRA GOYENECHE JENNY LUCIA',
                        'IPS'         => 'HOSPITAL DEPARTAMENTAL DE VILLAVICENCIO',
                        'Cliente'     => 'NUEVA EMPRESA PROMOTORA DE SALUD',
                        'Laboratorio' => 'TECNOQUIMICAS',
                    ],
                    'items' => [],
                ]
            ),
            self::payload('ACTA DE ENTREGA', [
                'Medico'      => 'JENNY LUCIA SIERA GOYENECHE',
                'IPS'         => 'VILAVICENCIO HOSPITAL DEPARTAMENTAL DE',
                'Cliente'     => 'NUEVA EMPRESA PROMOTORA DE SALUUD',
                'Laboratorio' => 'TECNOQUIMCAS',
            ])
        );

        // Act 2: Title Case con ñ (caso real D75261101999, E1-TOL-B1)
        $resEnye = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('Medico', 'S', 'alta', 'person_name', 'MED')],
                ['header' => ['Medico' => 'JAIME ORLANDO PATIÑO FRANCO'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['Medico' => 'Jaime Orlando Patiño Franco'])
        );

        // Act 3: Cardinalidad > 1 en IPS bloqueada (R1-TOL-I1)
        $resCard = $engine->evaluate(
            self::baseState(
                'ACTA DE ENTREGA',
                [self::field('IPS', 'S', 'alta', 'institution_name', 'IPS')],
                ['header' => ['IPS' => 'CLINICA BOGOTA'], 'items' => []]
            ),
            self::payload('ACTA DE ENTREGA', ['IPS' => 'CLINICA ESPECIALIZADA DE CIRUGIA BOGOTAA'])
        );

        // Act 4: Comodín ? + typo en persona escala a Capa 2 (R1-TOL-I2)
        $resWildcardTypo = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('Medico', 'S', 'alta', 'person_name', 'MED')],
                ['header' => ['Medico' => 'NOREÑA AGUDELO JUAN JOSE'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['Medico' => 'JUAN JOSE NORE?A AGUDELOO'])
        );

        // Assert:
        foreach ($resValid['hallazgos']['items'] as $finding) {
            self::assertSame('COINCIDE', $finding['resultado'], "Campo {$finding['campo']} debía aprobar en Capa 1");
        }
        self::assertSame('COINCIDE', $resEnye['hallazgos']['items'][0]['resultado']);
        self::assertSame('exact', $resEnye['hallazgos']['items'][0]['tipo_auditoria']);
        self::assertSame('NO_CONCLUYENTE', $resCard['hallazgos']['items'][0]['resultado']);
        self::assertSame('NO_CONCLUYENTE', $resWildcardTypo['hallazgos']['items'][0]['resultado']);
        self::assertSame(1, $judge->calls, 'Solo el caso comodín ? + typo debe escalar a Capa 2');
    }

    public function testArticleNameAndExactFieldsNeverUseTypoTolerance(): void
    {
        // Arrange:
        $judge = self::spySemanticJudge(false, 'Principio activo distinto (LASA)');

        $engine = new DocumentPolicyEngine($judge);
        $state = self::baseState(
            'FORMULA MEDICA',
            [
                self::field('NombreArticulo', 'S', 'alta', 'article_name', 'NAM'),
                self::field('DocumentoPaciente', 'E', 'alta', 'identity_doc_number', 'DOP'),
                self::field('CodigoDiagnostico', 'E', 'alta', 'code', 'CIE'),
                self::field('Lote', 'E', 'alta', 'trace_token', 'LOT'),
            ],
            [
                'header' => [
                    'DocumentoPaciente' => '86030619',
                    'CodigoDiagnostico' => 'C439',
                ],
                'items' => [
                    ['NombreArticulo' => 'CLONAZEPAM 2MG TABLETA', 'Lote' => 'LOTEA123'],
                ],
            ]
        );
        $payload = self::payload(
            'FORMULA MEDICA',
            [
                'DocumentoPaciente' => '86030618',
                'CodigoDiagnostico' => 'C438',
            ],
            [
                ['NombreArticulo' => 'TABLETA 2MG CLORAZEPAM', 'Lote' => 'LOTEA124'],
            ]
        );

        // Act:
        $result = $engine->evaluate($state, $payload);

        // Assert:
        $byCampo = [];
        foreach ($result['hallazgos']['items'] as $item) {
            $byCampo[$item['campo']] = $item['resultado'];
        }
        self::assertSame('NO_CONCLUYENTE', $byCampo['NombreArticulo']);
        self::assertSame('VALOR_DISTINTO', $byCampo['DocumentoPaciente']);
        self::assertSame('VALOR_DISTINTO', $byCampo['CodigoDiagnostico']);
        self::assertSame('VALOR_DISTINTO', $byCampo['Lote']);
        self::assertSame(1, $judge->calls);
    }

    // ─── SDD-SEMANTIC-RESOLVE-001: paridad plan-reproducción en la Fase 3 ──

    public function testSemanticResolveT1TwoRowsSharingOnePositiveDocumentResolveAsMatch(): void
    {
        // Arrange:
        $snapshot = self::resolveSnapshot(...self::resolveFixtures()['dos_filas_tres_docs']);
        $engine = new DocumentPolicyEngine();

        // Act:
        $plan = $this->resolveBuildPlan($snapshot);
        [$plan, $waves, $verdicts] = self::resolveRunWaves($plan, self::resolveFirstTermOracle());
        $results = SemanticEvaluationStore::results($plan);
        $resumed = $engine->evaluateResolved($snapshot, $results);

        // Assert:
        self::assertCount(6, $plan['pairs']);
        self::assertCount(2, $results);
        self::assertSame(1, $waves);
        self::assertSame(2, $verdicts);
        self::assertSame(['semantic_match', 'semantic_match'], array_column(array_values($plan['rows']), 'state'));
        self::assertSame('COINCIDE', self::resolveFieldResult($resumed));
    }

    public function testSemanticResolveT2ThreeRowsXYXResolveAsMatch(): void
    {
        // Arrange:
        $snapshot = self::resolveSnapshot(...self::resolveFixtures()['tres_filas_tres_docs']);
        $engine = new DocumentPolicyEngine();

        // Act:
        $plan = $this->resolveBuildPlan($snapshot);
        $planned = self::resolveRowSequences($plan);
        [$plan, $waves, $verdicts] = self::resolveRunWaves($plan, self::resolveT2Oracle());
        $results = SemanticEvaluationStore::results($plan);
        $resumed = $engine->evaluateResolved($snapshot, $results);

        // Assert:
        self::assertSame([
            ['ALFA 10MG TABLETA -> MOLX 10 MG', 'ALFA 10MG TABLETA -> MOLY 30 MG', 'ALFA 10MG TABLETA -> MOLZ 20 MG'],
            ['BETA 20MG TABLETA -> MOLZ 20 MG', 'BETA 20MG TABLETA -> MOLX 10 MG', 'BETA 20MG TABLETA -> MOLY 30 MG'],
            ['GAMMA 30MG TABLETA -> MOLY 30 MG', 'GAMMA 30MG TABLETA -> MOLX 10 MG', 'GAMMA 30MG TABLETA -> MOLZ 20 MG'],
        ], $planned);
        self::assertCount(9, $plan['pairs']);
        self::assertCount(6, $results);
        self::assertSame(3, $waves);
        self::assertSame(6, $verdicts);
        self::assertSame('COINCIDE', self::resolveFieldResult($resumed));
    }

    /**
     * Plan invariante (INV-2) y caché completa/parcial. La caché parcial se comprueba al final:
     * es la única parte que falla sin el cambio.
     */
    public function testSemanticResolveT3PlanIsInvariantAndCachedPlansResolve(): void
    {
        // Arrange: valores fijos tomados antes del cambio de producción.
        $expectedPlans = [
            'dos_filas_tres_docs' => [
                '24bd8cf691c914dd1084767120469a909c32727ba5333a38fe71cacf0f0a1268',
                'a39554ce87fc05365b137670521853a722847b3bef6e8d5c0ca367609d622f3d',
                [
                    ['CABERGOLINA 0.5MG FCO*4 TABLETA -> CABERGOLINA TAB 0,5 MG', 'CABERGOLINA 0.5MG FCO*4 TABLETA -> METFORMINA TAB 850 MG', 'CABERGOLINA 0.5MG FCO*4 TABLETA -> LOSARTAN TAB 50 MG'],
                    ['CABERGOLINA 0.5MG FCO*8 TABLETA -> CABERGOLINA TAB 0,5 MG', 'CABERGOLINA 0.5MG FCO*8 TABLETA -> METFORMINA TAB 850 MG', 'CABERGOLINA 0.5MG FCO*8 TABLETA -> LOSARTAN TAB 50 MG'],
                ],
            ],
            'tres_filas_tres_docs' => [
                'c3c753ef11c3cd8c93878881f997a53df4b97f3a5db74f94fe0ee006bd34a9aa',
                'a70be2afb4c1368e2143e1144018350ded6f845b274281a5fc648264d463d36b',
                [
                    ['ALFA 10MG TABLETA -> MOLX 10 MG', 'ALFA 10MG TABLETA -> MOLY 30 MG', 'ALFA 10MG TABLETA -> MOLZ 20 MG'],
                    ['BETA 20MG TABLETA -> MOLZ 20 MG', 'BETA 20MG TABLETA -> MOLX 10 MG', 'BETA 20MG TABLETA -> MOLY 30 MG'],
                    ['GAMMA 30MG TABLETA -> MOLY 30 MG', 'GAMMA 30MG TABLETA -> MOLX 10 MG', 'GAMMA 30MG TABLETA -> MOLZ 20 MG'],
                ],
            ],
        ];
        $snapshot = self::resolveSnapshot(...self::resolveFixtures()['dos_filas_tres_docs']);
        $base = $this->resolveBuildPlan($snapshot);
        $oracle = self::resolveFirstTermOracle();
        $positive = array_filter($base['pairs'], static fn(array $pair): bool => $pair['actual'] === 'CABERGOLINA TAB 0,5 MG');
        $fullCachePlan = $this->resolveBuildPlan($snapshot, self::resolveCacheEntries($base, $base['pairs'], $oracle));
        $partialCachePlan = $this->resolveBuildPlan($snapshot, self::resolveCacheEntries($base, $positive, $oracle));

        // Act + Assert: plan invariante.
        foreach ($expectedPlans as $fixture => [$planHash, $pairsHash, $expectedRows]) {
            $plan = $this->resolveBuildPlan(self::resolveSnapshot(...self::resolveFixtures()[$fixture]));
            $orderedPairIds = array_merge(...array_column(array_values($plan['rows']), 'pair_ids'));
            self::assertSame($planHash, hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), $fixture);
            self::assertSame($pairsHash, hash('sha256', implode(',', $orderedPairIds)), $fixture);
            self::assertSame($expectedRows, self::resolveRowSequences($plan), $fixture);
        }

        // Act + Assert: caché completa (seis pares).
        $full = (new DocumentPolicyEngine())->evaluateResolved($snapshot, SemanticEvaluationStore::results($fullCachePlan));
        self::assertSame('ready', $fullCachePlan['state']);
        self::assertCount(6, SemanticEvaluationStore::results($fullCachePlan));
        self::assertSame('COINCIDE', self::resolveFieldResult($full));

        // Act + Assert: caché parcial (solo los dos pares positivos CABERGOLINA → CABERGOLINA TAB).
        self::assertCount(2, $positive);
        self::assertSame('ready', $partialCachePlan['state']);
        $partial = (new DocumentPolicyEngine())->evaluateResolved($snapshot, SemanticEvaluationStore::results($partialCachePlan));
        self::assertSame('COINCIDE', self::resolveFieldResult($partial));
    }

    /**
     * Guardia permanente (RQ-8): ejecutar con cada cambio de la Fase 3 o de rankUnmatchedCandidates.
     */
    public function testSemanticResolveT4VerdictMatrixKeepsPlanReplayParity(): void
    {
        $summary = [];
        $riskMasks = 0;
        foreach (self::resolveFixtures() as $name => $fixture) {
            // Arrange:
            $snapshot = self::resolveSnapshot(...$fixture);
            $base = $this->resolveBuildPlan($snapshot);
            $pairIds = array_keys($base['pairs']);
            $counts = ['masks' => 0, 'exceptions' => 0, 'outside_prefix' => 0, 'sequence_mismatch' => 0, 'result_mismatch' => 0];

            for ($mask = 0; $mask < (1 << count($pairIds)); $mask++) {
                $verdicts = [];
                foreach ($pairIds as $bit => $pairId) {
                    $verdicts[$pairId] = (($mask >> $bit) & 1) === 1;
                }
                [$plan] = self::resolveRunWaves($base, static fn(array $pair): array => [
                    'is_match' => $verdicts[$pair['pair_id']],
                    'reasoning' => 'R-' . $pair['pair_id'],
                ]);
                $prefixes = self::resolvePrefixes($plan);
                $expectedResult = self::resolveResultFromRows($plan);
                $riskMasks += self::resolveIsRiskMask($plan, $prefixes) ? 1 : 0;
                $tracer = self::resolveTracer(new ResolvedSemanticResolver(SemanticEvaluationStore::results($plan)));
                $counts['masks']++;

                // Act:
                try {
                    $result = (new DocumentPolicyEngine($tracer))->evaluate(
                        $snapshot['document_state'], $snapshot['normalized_payload'], $snapshot['fac_nro']
                    );
                } catch (\DomainException) {
                    $counts['exceptions']++;
                    continue;
                }

                // Assert (acumulado por fixture):
                $requestedByRow = [];
                foreach ($tracer->calls as $call) {
                    if (!in_array($call['pair'], $prefixes[$call['row']] ?? [], true)) {
                        $counts['outside_prefix']++;
                    }
                    $requestedByRow[$call['row']][] = $call['pair'];
                }
                foreach ($prefixes as $row => $prefix) {
                    if (($requestedByRow[$row] ?? []) !== $prefix) {
                        $counts['sequence_mismatch']++;
                    }
                }
                if (self::resolveFieldResult($result) !== $expectedResult) {
                    $counts['result_mismatch']++;
                }
            }
            $summary[$name] = $counts;
        }

        $expected = [];
        foreach ($summary as $name => $counts) {
            $expected[$name] = ['masks' => $counts['masks']] + array_fill_keys(['exceptions', 'outside_prefix', 'sequence_mismatch', 'result_mismatch'], 0);
        }
        self::assertSame([
            'dos_filas_tres_docs' => 64,
            'tres_filas_tres_docs' => 512,
            'tres_filas_dos_docs_unmatched' => 64,
            'fase1_asigna_una_fila' => 64,
            'empate_puntaje' => 64,
            'dos_filas_dos_docs' => 16,
        ], array_map(static fn(array $counts): int => $counts['masks'], $summary));
        self::assertGreaterThan(0, $riskMasks, 'La matriz debe incluir máscaras con la forma del fallo (0.6).');
        self::assertSame($expected, $summary);
    }

    /**
     * Tres aserciones separadas sobre la fila agotada (BETA): resultado, documento elegido y motivo (D-5).
     * El motivo se comprueba al final: es la única que cambia respecto de la versión anterior.
     */
    public function testSemanticResolveT5ExhaustedRowResultChosenDocumentAndReason(): void
    {
        // Act:
        [$result, $chosen] = $this->resolveT5Evaluation();

        // Assert 1: resultado por campo.
        self::assertSame('NO_CONCLUYENTE', self::resolveFieldResult($result));

        // Assert 2: documento elegido (último par con is_match=true por índice FDV).
        self::assertSame([0 => 'AXIAL 10 MG', 1 => null], $chosen);

        // Assert 3: D-5, primer razonamiento no nulo en el orden planificado [AXIAL, CUARZO].
        self::assertSame(
            "En 'Nombre Articulo', el documento soporte no contiene evidencia para 1 de 2 artículos de la dispensación: "
            . "'BETA 10MG TABLETA'. Artículos visibles en el documento: 'AXIAL 10 MG, CUARZO 30 MG'. "
            . "Motivo: Para 'BETA 10MG TABLETA': R-axial",
            self::resolveFieldDetail($result)
        );
    }

    /**
     * Guardia: pasa con y sin el cambio.
     */
    public function testSemanticResolveT6MissingOrMalformedResultStillThrows(): void
    {
        // Arrange: se corrompe el primer par planificado de la primera fila (siempre en el prefijo resuelto).
        $snapshot = self::resolveSnapshot(...self::resolveT5Fixture());
        [$plan] = self::resolveRunWaves($this->resolveBuildPlan($snapshot), self::resolveFirstTermOracle());
        $results = SemanticEvaluationStore::results($plan);
        $firstPair = array_values($plan['rows'])[0]['pair_ids'][0];
        $removed = $results;
        unset($removed[$firstPair]);
        $malformed = $results;
        $malformed[$firstPair]['is_match'] = 'true';

        foreach (['veredicto eliminado' => $removed, 'is_match no booleano' => $malformed] as $case => $corrupted) {
            // Act:
            $message = null;
            try {
                (new DocumentPolicyEngine())->evaluateResolved($snapshot, $corrupted);
            } catch (\DomainException $error) {
                $message = $error->getMessage();
            }

            // Assert:
            self::assertSame('semantic_result_missing', $message, $case);
        }
    }

    /**
     * @return array<string,array{0:list<string>,1:list<string>,2:list<string>}>
     */
    private static function resolveFixtures(): array
    {
        return [
            'dos_filas_tres_docs' => [
                ['CABERGOLINA 0.5MG FCO*4 TABLETA', 'CABERGOLINA 0.5MG FCO*8 TABLETA'],
                ['CABERGOLINA TAB 0,5 MG', 'LOSARTAN TAB 50 MG', 'METFORMINA TAB 850 MG'],
                [],
            ],
            'tres_filas_tres_docs' => [
                ['ALFA 10MG TABLETA', 'BETA 20MG TABLETA', 'GAMMA 30MG TABLETA'],
                ['MOLX 10 MG', 'MOLZ 20 MG', 'MOLY 30 MG'],
                [],
            ],
            'tres_filas_dos_docs_unmatched' => [
                ['ALFA 10MG TABLETA', 'BETA 20MG TABLETA', 'GAMMA 30MG TABLETA'],
                ['MOLX 10 MG', 'MOLY 30 MG'],
                ['MOLY 30 MG'],
            ],
            'fase1_asigna_una_fila' => [
                ['LOSARTAN 50MG TABLETA', 'ALFA 10MG TABLETA', 'BETA 20MG TABLETA'],
                ['LOSARTAN 50MG TABLETA', 'MOLX 10 MG', 'MOLY 30 MG'],
                [],
            ],
            'empate_puntaje' => [
                ['ALFA TABLETA', 'BETA TABLETA'],
                ['MOLX TABLETA', 'MOLY TABLETA', 'MOLZ TABLETA'],
                [],
            ],
            'dos_filas_dos_docs' => [
                ['ALFA 10MG TABLETA', 'BETA 20MG TABLETA'],
                ['MOLX 10 MG', 'MOLY 30 MG'],
                [],
            ],
        ];
    }

    /**
     * Fixture de T5 y T6: ejemplo sintético de la sección 10 (candidatos planificados [AXIAL, CUARZO]).
     *
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private static function resolveT5Fixture(): array
    {
        return [['AXIAL 10MG TABLETA', 'BETA 10MG TABLETA'], ['AXIAL 10 MG', 'CUARZO 30 MG'], []];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<int,string|null>}
     */
    private function resolveT5Evaluation(): array
    {
        $snapshot = self::resolveSnapshot(...self::resolveT5Fixture());
        [$plan] = self::resolveRunWaves($this->resolveBuildPlan($snapshot), self::resolveFirstTermOracle());
        $tracer = self::resolveTracer(new ResolvedSemanticResolver(SemanticEvaluationStore::results($plan)));
        $result = (new DocumentPolicyEngine($tracer))->evaluate(
            $snapshot['document_state'], $snapshot['normalized_payload'], $snapshot['fac_nro']
        );
        $chosen = [0 => null, 1 => null];
        foreach ($tracer->calls as $call) {
            if ($call['is_match']) {
                $chosen[$call['fdv_index']] = $call['actual'];
            }
        }

        return [$result, $chosen];
    }

    /**
     * @param  list<string> $fdv
     * @param  list<string> $doc
     * @param  list<string> $unmatched
     * @return array<string,mixed>
     */
    private static function resolveSnapshot(array $fdv, array $doc, array $unmatched = []): array
    {
        $state = self::baseState(
            'FORMULA MEDICA',
            [self::field('NombreArticulo', 'S', 'alta', 'article_name')],
            ['header' => [], 'items' => array_map(static fn(string $name): array => ['NombreArticulo' => $name], $fdv)]
        );
        $payload = self::payload(
            'FORMULA MEDICA',
            [],
            array_map(static fn(string $name): array => ['NombreArticulo' => $name], $doc)
        );
        $payload['unmatched_articles'] = $unmatched;

        return [
            'source_event' => [
                'event_id' => 'bbbbbbbb-bbbb-4bbb-abbb-bbbbbbbbbbbb',
                'parent_event_id' => null,
                'event_type' => AuditEvent::TYPE_DOCUMENT_NORMALIZED,
                'audit_id' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
                'job_id' => null,
                'document_id' => 'doc-resolve',
                'timestamp' => '2026-11-25T00:00:00Z',
                'version_extractor' => 'fixture',
                'version_normalizer' => '1.0.0',
                'version_rules' => '1.0.0',
                'payload' => [],
            ],
            'versions' => ['model' => 'fixture', 'rules' => '1', 'normalizer' => '1', 'extractor' => '1'],
            'document_state' => $state,
            'normalized_payload' => $payload,
            'fac_nro' => 'FAC-RESOLVE',
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,string> $cache Clave de caché semántica → resultado JSON.
     * @return array<string,mixed>
     */
    private function resolveBuildPlan(array $snapshot, array $cache = []): array
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturnCallback(static fn(string $key): ?string => $cache[$key] ?? null);
        $planning = (new DocumentPolicyEngine())->plan(
            $snapshot['document_state'], $snapshot['normalized_payload'], $snapshot['fac_nro']
        );

        return (new SemanticEvaluationStore($redis))->buildPlan($snapshot, $planning['candidates']);
    }

    /**
     * @param  array<string,mixed>                $plan
     * @param  array<string,array<string,mixed>>  $pairs
     * @return array<string,string>
     */
    private static function resolveCacheEntries(array $plan, array $pairs, callable $oracle): array
    {
        $cache = [];
        foreach ($pairs as $pair) {
            $cache[SemanticEvaluationStore::cacheKey($plan, $pair)] = json_encode($oracle($pair), JSON_THROW_ON_ERROR);
        }

        return $cache;
    }

    /**
     * Resuelve las olas del plan con el oráculo hasta que queda `ready`.
     *
     * @param  array<string,mixed> $plan
     * @return array{0:array<string,mixed>,1:int,2:int} Plan, olas (una llamada simulada por ola) y veredictos.
     */
    private static function resolveRunWaves(array $plan, callable $oracle): array
    {
        $waves = 0;
        $verdicts = 0;
        while ($plan['state'] !== 'ready') {
            $current = SemanticEvaluationStore::waves($plan);
            self::assertNotSame([], $current, 'Plan sin olas y sin estado ready.');
            foreach ($current as $wave) {
                $waves++;
                foreach ($wave as $pair) {
                    $plan['pairs'][$pair['pair_id']]['state'] = 'resolved';
                    $plan['pairs'][$pair['pair_id']]['result'] = $oracle($pair);
                    $verdicts++;
                }
            }
            $plan = SemanticEvaluationStore::advance($plan);
        }

        return [$plan, $waves, $verdicts];
    }

    /**
     * Oráculo común: casa si el primer término de ambos textos, en mayúsculas, es igual.
     */
    private static function resolveFirstTermOracle(): callable
    {
        return static function (array $pair): array {
            $expected = strtoupper(explode(' ', $pair['expected'])[0]);
            $actual = strtoupper(explode(' ', $pair['actual'])[0]);

            return ['is_match' => $expected === $actual, 'reasoning' => 'R-' . strtolower($actual)];
        };
    }

    /**
     * Oráculo de T2: ALFA y GAMMA casan con MOLX; BETA casa con MOLY.
     */
    private static function resolveT2Oracle(): callable
    {
        $matches = ['ALFA' => 'MOLX', 'BETA' => 'MOLY', 'GAMMA' => 'MOLX'];

        return static function (array $pair) use ($matches): array {
            $expected = explode(' ', $pair['expected'])[0];
            $actual = explode(' ', $pair['actual'])[0];

            return ['is_match' => ($matches[$expected] ?? null) === $actual, 'reasoning' => 'R-' . strtolower($actual)];
        };
    }

    /**
     * @param  array<string,mixed> $plan
     * @return list<list<string>>
     */
    private static function resolveRowSequences(array $plan): array
    {
        return array_map(
            static fn(array $row): array => array_map(
                static fn(string $pairId): string => $plan['pairs'][$pairId]['expected'] . ' -> ' . $plan['pairs'][$pairId]['actual'],
                $row['pair_ids']
            ),
            array_values($plan['rows'])
        );
    }

    /**
     * Prefijo resuelto de cada fila: hasta el primer positivo, o la fila completa si quedó agotada.
     *
     * @param  array<string,mixed> $plan
     * @return array<string,list<string>>
     */
    private static function resolvePrefixes(array $plan): array
    {
        $prefixes = [];
        foreach ($plan['rows'] as $rowId => $row) {
            $length = $row['state'] === 'semantic_match' ? $row['cursor'] + 1 : count($row['pair_ids']);
            $prefixes[$rowId] = array_slice($row['pair_ids'], 0, $length);
        }

        return $prefixes;
    }

    /**
     * Forma necesaria del fallo (0.6): una fila con prefijo truncado por su propio positivo cuyo prefijo
     * contiene un documento asignado por el positivo de una fila anterior.
     *
     * @param array<string,mixed>         $plan
     * @param array<string,list<string>>  $prefixes
     */
    private static function resolveIsRiskMask(array $plan, array $prefixes): bool
    {
        $assignedDocs = [];
        foreach ($plan['rows'] as $rowId => $row) {
            $prefixDocs = array_map(static fn(string $pairId): string => $plan['pairs'][$pairId]['actual'], $prefixes[$rowId]);
            $truncated = $row['state'] === 'semantic_match' && count($prefixes[$rowId]) < count($row['pair_ids']);
            if ($truncated && array_intersect($prefixDocs, $assignedDocs) !== []) {
                return true;
            }
            if ($row['state'] === 'semantic_match') {
                $assignedDocs[] = end($prefixDocs);
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $plan
     */
    private static function resolveResultFromRows(array $plan): string
    {
        foreach ($plan['rows'] as $row) {
            if ($row['state'] !== 'semantic_match') {
                return 'NO_CONCLUYENTE';
            }
        }

        return 'COINCIDE';
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function resolveFieldResult(array $result): string
    {
        return (string) self::resolveArticleFinding($result)['resultado'];
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function resolveFieldDetail(array $result): string
    {
        return (string) (self::resolveArticleFinding($result)['detalle'] ?? '');
    }

    /**
     * @param  array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function resolveArticleFinding(array $result): array
    {
        foreach ($result['hallazgos']['items'] as $item) {
            if ($item['campo'] === 'NombreArticulo') {
                return $item;
            }
        }
        self::fail('Hallazgo NombreArticulo ausente.');
    }

    /**
     * Decorador de ResolvedSemanticResolver que registra los pares pedidos por el motor.
     */
    private static function resolveTracer(ResolvedSemanticResolver $inner): SemanticMatchResolver
    {
        return new class ($inner) implements SemanticMatchResolver {
            /** @var list<array{row:string,pair:string,fdv_index:int,actual:string,is_match:bool|null}> */
            public array $calls = [];

            public function __construct(private readonly ResolvedSemanticResolver $inner)
            {
            }

            public function evaluate(string $expected, string $actual, array $context = []): array
            {
                [$row, $pair] = SemanticCandidateCollector::identity($expected, $actual, $context);
                $index = count($this->calls);
                $this->calls[] = [
                    'row' => $row,
                    'pair' => $pair,
                    'fdv_index' => (int) ($context['semantic_row_index'] ?? -1),
                    'actual' => $actual,
                    'is_match' => null,
                ];
                $result = $this->inner->evaluate($expected, $actual, $context);
                $this->calls[$index]['is_match'] = $result['is_match'];

                return $result;
            }
        };
    }
}

