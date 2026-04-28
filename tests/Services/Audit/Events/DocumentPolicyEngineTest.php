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

    public function testEvaluateSkipsNonDeterministicMultiItemFieldsInDispensa(): void
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

        $this->assertSame(['NombreArticulo'], $campos);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
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
}
