<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use PHPUnit\Framework\TestCase;

final class DocumentPolicyEngineTest extends TestCase
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function field(
        string $name,
        string $tipo = 'E',
        string $severity = 'alta',
        string $rol = 'AUTORITATIVO',
        ?string $omitirSi = null
    ): array {
        return [
            'campoNombre' => $name,
            'tipoCampo'   => $tipo,
            'severity'    => $severity,
            'orden'       => 0,
            'rol'         => $rol,
            'omitirSi'    => $omitirSi,
        ];
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
        return [
            'tipo_documento'         => $docType,
            'fields_normalized'      => $fields,
            'items_normalized'       => $items,
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
                [['check' => 'VigenciaEntrega', 'severity' => 'ALTA', 'rol' => 'AUTORITATIVO']]
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
        $this->assertStringContainsString('no se encontró', (string) $result['hallazgos']['items'][0]['detalle']);
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

    public function testEvaluateCantidadPrescritaAsBusinessRuleInFormulaMedica(): void
    {
        // El campo es tipo B (negocio): el documento no puede superar la FDV.
        $engine = new DocumentPolicyEngine();

        // 'DocumentoPaciente' → canónico 'NumeroIdentificacion'; FDV header usa la columna SQL real.
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

    /**
     * AUDIT-016 CAT-1: campos multi-item con valores distintos NO se saltan silenciosamente.
     * Lote y FechaVencimiento tienen lotes distintos → NO_CONCLUYENTE.
     * NombreArticulo es idéntico en todas las filas → COINCIDE (valor único).
     */
    public function testEvaluateMultiItemFieldsWithDistinctValuesProduceAmbiguousNotSilentSkip(): void
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

        // CAT-1: Lote y FechaVencimiento producen NO_CONCLUYENTE, no se saltan
        $this->assertContains('Lote', $campos);
        $this->assertContains('FechaVencimiento', $campos);
        $this->assertContains('NombreArticulo', $campos);

        // NombreArticulo es idéntico en todas las filas → COINCIDE
        $nomIdx = array_search('NombreArticulo', $campos, true);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][$nomIdx]['resultado']);

        // Lote es ambiguous → NO_CONCLUYENTE
        $loteIdx = array_search('Lote', $campos, true);
        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][$loteIdx]['resultado']);
    }

    public function testEvaluateDoesNotEvaluateFieldsAbsentFromConfig(): void
    {
        // Sin fields_config, no se generan hallazgos de datos.
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [], // NombreArticulo no está en config
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
        $this->assertStringContainsString('Similitud', (string) $result['hallazgos']['items'][0]['detalle']);
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

    // ─── Reglas dinámicas (Rol / OmitirSi) ────────────────────────────────────

    public function testFieldWithRolInformativoIsExtractedButNotAudited(): void
    {
        // Reproduce el escenario "NombreArticulo en FORMULA MEDICA": se quiere extraer para
        // dar contexto al modelo, pero no debe generar findings (la receta tiene N artículos).
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [
                    self::field('NombreArticulo', 'S', 'alta', 'INFORMATIVO'),
                    self::field('FechaFormula'),
                ],
                ['header' => ['FechaFormula' => '2025-05-20', 'NombreArticulo' => 'GASA ESTERIL'], 'items' => []]
            ),
            self::payload('FORMULA MEDICA', ['FechaFormula' => '2025-05-20', 'NombreArticulo' => 'OTRO PRODUCTO'])
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $this->assertSame(['FechaFormula'], $campos);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testOmitirSiSkipsFieldWhenFdvHasReferencedKey(): void
    {
        // Reproduce la regla original: skip CantidadPrescrita en FORMULA MEDICA
        // si la FDV ya trae NumeroAutorizacion.
        $engine = new DocumentPolicyEngine();

        $omitirSiJson = json_encode(['fdv_has' => ['NumeroAutorizacion']]);

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [
                    self::field('CantidadPrescrita', 'B', 'alta', 'AUTORITATIVO', $omitirSiJson),
                    self::field('FechaFormula'),
                ],
                [
                    'header' => ['NumeroAutorizacion' => '46338218', 'FechaFormula' => '2025-05-20'],
                    'items'  => [['CantidadPrescrita' => '50']],
                ]
            ),
            self::payload(
                'FORMULA MEDICA',
                ['FechaFormula' => '2025-05-20'],
                [['CantidadPrescrita' => '999']]
            )
        );

        $campos = array_column($result['hallazgos']['items'], 'campo');
        $this->assertSame(['FechaFormula'], $campos);
    }

    public function testOmitirSiSkipsFieldByDocQuality(): void
    {
        $engine = new DocumentPolicyEngine();

        $omitirSi = json_encode(['doc_quality' => ['parcialmente_legible', 'ilegible']]);

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('NumeroAutorizacion', 'E', 'alta', 'AUTORITATIVO', $omitirSi)],
                ['header' => ['NumeroAutorizacion' => '46338218'], 'items' => []]
            ),
            self::payload('DISPENSA', ['NumeroAutorizacion' => '46338218'], [], [], 'parcialmente_legible')
        );

        $this->assertSame([], $result['hallazgos']['items']);
    }

    public function testFieldFindingIncludesRolMetadata(): void
    {
        // El finding debe propagar el rol para que el agregador pueda hacer cross-check.
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'AUTORIZACION',
                [self::field('NombreArticulo', 'S', 'alta', 'ALTERNATIVO')],
                ['header' => [], 'items' => [['NombreArticulo' => 'GASA ESTERIL']]]
            ),
            self::payload('AUTORIZACION', ['NombreArticulo' => 'GASA ESTERIL'])
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('ALTERNATIVO', $result['hallazgos']['items'][0]['rol']);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
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

    public function testSkipByConditionAppliesWithDoubleEncodedJson(): void
    {
        // Simula el valor double-encoded que la DB persiste cuando el frontend
        // envía omitirSi como string JSON serializado dos veces.
        $doubleEncoded = '{\"fdv_has\":[\"NumeroAutorizacion\"]}';

        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'FORMULA MEDICA',
                [self::field('CantidadPrescrita', 'B', 'alta', 'AUTORITATIVO', $doubleEncoded)],
                ['header' => ['NumeroAutorizacion' => '46338218'], 'items' => [['CantidadPrescrita' => '576']]]
            ),
            self::payload('FORMULA MEDICA', [], [['CantidadPrescrita' => '576']])
        );

        // Con NumeroAutorizacion presente en FDV, omitirSi debe activarse → sin findings
        $this->assertSame([], $result['hallazgos']['items']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    // ─── AUDIT-016: CAT-1 — Data loss silencioso en multi-item ────────────────

    /**
     * CAT-1: Multi-item con lotes distintos NO debe producir skip silencioso.
     * Antes del fix: resolveDocumentValue() retornaba [null, false] → OMITIDO.
     * Después del fix: debe retornar NO_CONCLUYENTE con flag ambiguous=true.
     */
    public function testCAT1MultiItemWithDifferentLotesProducesAmbiguousNotSilentSkip(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            self::baseState(
                'DISPENSA',
                [self::field('Lote')],
                ['header' => [], 'items' => [
                    ['Lote' => '02041804-25'],
                    ['Lote' => '02041806-25'], // distinto al anterior
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
        // El resultado debe ser NO_CONCLUYENTE (ambiguous) nunca OMITIDO ni ausente
        $this->assertSame('NO_CONCLUYENTE', $loteHallazgo['resultado']);
        $this->assertStringContainsStringIgnoringCase('ambiguous', (string)($loteHallazgo['detalle'] ?? ''));
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
     * CAT-4: "GARCIA ABSALON" vs "PEREZ JUAN" → tokens distintos → sigue al SemanticMatchJudge.
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
}
