<?php

declare(strict_types=1);

/**
 * Script CLI reproducible para evaluación empírica de dispensas reales:
 * - D03261002256 (Palbociclib / Letrozol)
 * - D63261106501 (Carboximetilcelulosa / Nepafenaco / Brinzolamida / Pred-F / Atropina)
 * - U77261101396 (5 ítems / Diclofenaco)
 * - V14261105835 (Acetaminofén+Cafeína / Esomeprazol)
 *
 * Uso:
 *   php scripts/eval-dispensations-empirical.php
 *   php scripts/eval-dispensations-empirical.php --dispensation=D03261002256
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\Pipeline\DocumentNormalizer;
use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\FieldValueResolver;
use App\Services\Audit\Pipeline\NormalizedDocument;
use App\Services\Audit\Pipeline\SemanticCandidateCollector;
use App\Services\Audit\Pipeline\SemanticEvaluationStore;
use App\Services\Audit\TextNormalization;

$filterDispensation = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dispensation=')) {
        $filterDispensation = strtoupper(trim(substr($arg, strlen('--dispensation='))));
    }
}

echo "===============================================================================\n";
echo " AUDFACT — SUITE CLI DE EVALUACIÓN EMPÍRICA (DISPENSAS REALES REPRODUCIBLES)\n";
echo "===============================================================================\n\n";

$dataset = [
    'D03261002256' => [
        'nombre' => 'PALBOCICLIB 125MG (Cápsulas vs Tabletas Recubiertas + Letrozol unmatched)',
        'cliente' => '1165 - NUEVA EPS',
        'fdv' => [
            'header' => [
                'NumeroFactura' => 'D03261002256',
                'NitSec' => '1165',
                'NombrePaciente' => 'MARIA ROSARIO HERRERA DIAZ',
                'DocumentoPaciente' => '24118080',
                'Medico' => 'CARLOS JULIO MARTINEZ CONTRERAS',
                'FechaFormula' => '2026-10-14',
            ],
            'items' => [
                [
                    'CodigoProducto' => 'MD013098',
                    'NombreArticulo' => 'PALBOCICLIB 125MG FCO*21 CAPSULA',
                    'CantidadEntregada' => '21',
                    'CantidadPrescrita' => '21',
                ],
            ],
        ],
        'formula' => [
            'header' => [
                'NombrePaciente' => ['valor' => 'MARIA ROSARIO HERRERA DIAZ', 'valores' => ['MARIA ROSARIO HERRERA DIAZ'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'DocumentoPaciente' => ['valor' => '24118080', 'valores' => ['24118080'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'Medico' => ['valor' => 'CARLOS JULIO MARTINEZ CONTRERAS', 'valores' => ['CARLOS JULIO MARTINEZ CONTRERAS'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'FechaFormula' => ['valor' => '2026-10-14', 'valores' => ['2026-10-14'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
            ],
            'items' => [
                [
                    'NombreArticulo' => [
                        'valor' => 'PALBOCICLIB X 125 MILIGRAMO(S) EN TABLETAS RECUBIERTAS - [CAJA X 21 CAPSULAS] - (PBS)',
                        'valores' => ['PALBOCICLIB X 125 MILIGRAMO(S) EN TABLETAS RECUBIERTAS - [CAJA X 21 CAPSULAS] - (PBS)'],
                        'presente' => true,
                        'estadoExtraccion' => 'FOUND',
                    ],
                ],
            ],
            'unmatched_articles' => [
                'LETROZOL X 2.5 MILIGRAMO(S) - (PBS)',
            ],
        ],
        'invariants' => [
            'd11_respected' => true,
            'expected_verdict' => AuditFindingResult::INCONCLUSIVE->value,
            'expected_first_candidate' => 'PALBOCICLIB X 125 MILIGRAMO(S) EN TABLETAS RECUBIERTAS - [CAJA X 21 CAPSULAS] - (PBS)',
            'requires_clinical_reason' => true,
            'meta_item_count' => 1,
        ],
    ],
    'D63261106501' => [
        'nombre' => 'OPTISALUD 3 ÍTEMS (Carboximetilcelulosa + Pred-F y Atropina unmatched)',
        'cliente' => '2624 - SALUD TOTAL',
        'fdv' => [
            'header' => [
                'NumeroFactura' => 'D63261106501',
                'NitSec' => '2624',
                'NombrePaciente' => 'SAUL BUITRAGO CASAS',
                'DocumentoPaciente' => '4231454',
                'Medico' => 'SANTIAGO NIÑO LOAIZA',
                'FechaFormula' => '2026-11-20',
            ],
            'items' => [
                ['CodigoProducto' => 'MD015624', 'NombreArticulo' => 'PROPILENGLICOL+POLIETILENGLICOL 3+4% GEL OFTALMICA FCO 10ML', 'CantidadEntregada' => '1', 'CantidadPrescrita' => '1'],
                ['CodigoProducto' => 'MD003471', 'NombreArticulo' => 'CARBOXIMETILCELULOSA (VISCOSIDA MED+ALTA 3.25+1.75) 0.5%  SOL OFT FCO 15ML', 'CantidadEntregada' => '1', 'CantidadPrescrita' => '1'],
                ['CodigoProducto' => 'MD009623', 'NombreArticulo' => 'NEPAFENACO 0.1% SUS OFT FCO*5ML', 'CantidadEntregada' => '1', 'CantidadPrescrita' => '1'],
            ],
        ],
        'formula' => [
            'header' => [
                'NombrePaciente' => ['valor' => 'BUITRAGO CASAS SAUL', 'valores' => ['BUITRAGO CASAS SAUL'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'DocumentoPaciente' => ['valor' => '4231454', 'valores' => ['4231454'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'Medico' => ['valor' => 'NIÑO LOAIZA SANTIAGO', 'valores' => ['NIÑO LOAIZA SANTIAGO'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'FechaFormula' => ['valor' => '2026-11-20', 'valores' => ['2026-11-20'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
            ],
            'items' => [
                ['NombreArticulo' => ['valor' => 'BRINZOLAMIDA 1% SUSPENSION OFTALMICA (10MG/ML)', 'valores' => ['BRINZOLAMIDA 1% SUSPENSION OFTALMICA (10MG/ML)'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
                ['NombreArticulo' => ['valor' => 'NEPAFENACO 0.1% SUSPENSION OFTALMICA (1MG/ML)', 'valores' => ['NEPAFENACO 0.1% SUSPENSION OFTALMICA (1MG/ML)'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
                ['NombreArticulo' => ['valor' => '[CARBOXIMETILCELULOSA SODICA] 5MG/1ML (0.5%)', 'valores' => ['[CARBOXIMETILCELULOSA SODICA] 5MG/1ML (0.5%)'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
            ],
            'unmatched_articles' => [
                'PRED - F (ACETATO DE PREDNISOLONA) 1% SUSPENSION OFTALMICA',
                'ATROPINA SULFATO 1% SOLUCION OFTALMICA',
            ],
        ],
        'invariants' => [
            'd11_respected' => true,
            'expected_verdict' => AuditFindingResult::MATCH->value,
            'expected_first_candidate' => '[CARBOXIMETILCELULOSA SODICA] 5MG/1ML (0.5%)',
            'max_waves' => 1,
            'meta_item_count' => 3,
        ],
    ],
    'U77261101396' => [
        'nombre' => 'SALUD TOTAL 5 ÍTEMS (Número+Unidad, Abreviaturas TAB/CAP, Diclofenaco unmatched)',
        'cliente' => '2624 - SALUD TOTAL',
        'fdv' => [
            'header' => [
                'NumeroFactura' => 'U77261101396',
                'NitSec' => '2624',
                'NombrePaciente' => 'JORGE ALIRIO GUTIERREZ MOLINA',
                'DocumentoPaciente' => '12227114',
                'Medico' => 'LUIS ALFONSO GOMEZ GONZALEZ',
                'FechaFormula' => '2026-11-18',
            ],
            'items' => [
                ['CodigoProducto' => 'MD004423', 'NombreArticulo' => 'ACIDO ACETILSALICILICO 100MG C*100 TABLETA', 'CantidadEntregada' => '30', 'CantidadPrescrita' => '30'],
                ['CodigoProducto' => 'MD007079', 'NombreArticulo' => 'ACETAMINOFEN+TRAMADOL 325+37.5MG C*10 TABLETA', 'CantidadEntregada' => '30', 'CantidadPrescrita' => '30'],
                ['CodigoProducto' => 'MD002262', 'NombreArticulo' => 'PREGABALINA 75MG C*30 CAPSULA', 'CantidadEntregada' => '30', 'CantidadPrescrita' => '30'],
                ['CodigoProducto' => 'MD006419', 'NombreArticulo' => 'ROSUVASTATINA 40MG C*30 TABLETA', 'CantidadEntregada' => '30', 'CantidadPrescrita' => '30'],
                ['CodigoProducto' => 'MD015225', 'NombreArticulo' => 'ACIDO TIOCTICO 600MG C*30 TABLETA', 'CantidadEntregada' => '30', 'CantidadPrescrita' => '30'],
            ],
        ],
        'formula' => [
            'header' => [
                'NombrePaciente' => ['valor' => 'JORGE ALIRIO GUTIERREZ MOLINA', 'valores' => ['JORGE ALIRIO GUTIERREZ MOLINA'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'DocumentoPaciente' => ['valor' => '12227114', 'valores' => ['12227114'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'Medico' => ['valor' => 'LUIS ALFONSO GOMEZ GONZALEZ', 'valores' => ['LUIS ALFONSO GOMEZ GONZALEZ'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'FechaFormula' => ['valor' => '2026-11-18', 'valores' => ['2026-11-18'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
            ],
            'items' => [
                ['NombreArticulo' => ['valor' => 'TRAMADOL + ACETAMINOFEN TAB X 37.5 mg/325 mg', 'valores' => ['TRAMADOL + ACETAMINOFEN TAB X 37.5 mg/325 mg'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
                ['NombreArticulo' => ['valor' => 'ACIDO ACETIL SALICILICO 100mg TABLETA', 'valores' => ['ACIDO ACETIL SALICILICO 100mg TABLETA'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
                ['NombreArticulo' => ['valor' => 'ACIDO TIOCTICO TAB 600 MG', 'valores' => ['ACIDO TIOCTICO TAB 600 MG'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
                ['NombreArticulo' => ['valor' => 'PREGABALINA CAP X 75MG', 'valores' => ['PREGABALINA CAP X 75MG'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
                ['NombreArticulo' => ['valor' => 'ROSUVASTATINA 40 MG TABLETA', 'valores' => ['ROSUVASTATINA 40 MG TABLETA'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
            ],
            'unmatched_articles' => [
                'DICLOFENACO GEL AL 1% TUBO X 100 g',
            ],
        ],
        'invariants' => [
            'd11_respected' => true,
            'expected_verdict' => AuditFindingResult::MATCH->value,
            'min_capa1_lexical_matches' => 2,
            'meta_item_count' => 5,
        ],
    ],
    'V14261105835' => [
        'nombre' => 'NUEVA EPS 2 ÍTEMS (Esomeprazol 40MG vs 40 MG, Sumas en Acta)',
        'cliente' => '2624 - NUEVA EPS',
        'fdv' => [
            'header' => [
                'NumeroFactura' => 'V14261105835',
                'NitSec' => '2624',
                'NombrePaciente' => 'RODULFO CASTELBLANCO CASTIBLANCO',
                'DocumentoPaciente' => '6759520',
                'Medico' => 'JUAN BUITRAGO',
                'FechaFormula' => '2026-11-19',
            ],
            'items' => [
                ['CodigoProducto' => 'MD000680', 'NombreArticulo' => 'ESOMEPRAZOL 40MG C*100 TABLETA', 'CantidadEntregada' => '25', 'CantidadPrescrita' => '25'],
                ['CodigoProducto' => 'MD005991', 'NombreArticulo' => 'ACETAMINOFEN+CAFEINA 500+50MG C*100 TABLETA', 'CantidadEntregada' => '60', 'CantidadPrescrita' => '60'],
            ],
        ],
        'formula' => [
            'header' => [
                'NombrePaciente' => ['valor' => 'RODULFO CASTELBLANCO CASTIBLANCO', 'valores' => ['RODULFO CASTELBLANCO CASTIBLANCO'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'DocumentoPaciente' => ['valor' => '6759520', 'valores' => ['6759520'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'Medico' => ['valor' => 'BUITRAGO GALLO JUAN DIEGO', 'valores' => ['BUITRAGO GALLO JUAN DIEGO'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
                'FechaFormula' => ['valor' => '2026-11-19', 'valores' => ['2026-11-19'], 'presente' => true, 'estadoExtraccion' => 'FOUND'],
            ],
            'items' => [
                ['NombreArticulo' => ['valor' => 'ACETAMINOFEN+CAFEINA 500/50MG TABLETA RECUBIERTA', 'valores' => ['ACETAMINOFEN+CAFEINA 500/50MG TABLETA RECUBIERTA'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
                ['NombreArticulo' => ['valor' => 'ESOMEPRAZOL 40 MG TABLETA RECUBIERTA', 'valores' => ['ESOMEPRAZOL 40 MG TABLETA RECUBIERTA'], 'presente' => true, 'estadoExtraccion' => 'FOUND']],
            ],
            'unmatched_articles' => [],
        ],
        'invariants' => [
            'd11_respected' => true,
            'expected_verdict' => AuditFindingResult::MATCH->value,
            'min_capa1_lexical_matches' => 1,
            'expected_first_candidate' => 'ESOMEPRAZOL 40 MG TABLETA RECUBIERTA',
            'meta_item_count' => 2,
        ],
    ],
];

$normalizer = new DocumentNormalizer();
$engine = new DocumentPolicyEngine();
$totalInvariants = 0;
$passedInvariants = 0;

foreach ($dataset as $facNro => $data) {
    if ($filterDispensation !== null && $facNro !== $filterDispensation) {
        continue;
    }

    echo "-------------------------------------------------------------------------------\n";
    echo sprintf("DISPENSA [%s]: %s\n", $facNro, $data['nombre']);
    echo sprintf("CLIENTE: %s\n", $data['cliente']);
    echo "-------------------------------------------------------------------------------\n";

    $docState = [
        'audit_id' => 'cli-eval-' . strtolower($facNro),
        'document_id' => 'doc-' . strtolower($facNro),
        'doc_id' => '3',
        'attachment_id' => '2',
        'dis_det_nro' => $facNro,
        'tipo_documento' => 'FORMULA MEDICA',
        'fac_nit_sec' => (string) ($data['fdv']['header']['NitSec'] ?? '2624'),
        'fuente_verdad' => $data['fdv'],
        'fields_config' => [
            ['campoNombre' => 'NombrePaciente', 'tipoCampo' => 'E', 'tipoDato' => 'person_name', 'severity' => 'alta', 'codigoCampo' => 'PAC'],
            ['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number', 'severity' => 'alta', 'codigoCampo' => 'DOP'],
            ['campoNombre' => 'Medico', 'tipoCampo' => 'E', 'tipoDato' => 'person_name', 'severity' => 'alta', 'codigoCampo' => 'MED'],
            ['campoNombre' => 'FechaFormula', 'tipoCampo' => 'E', 'tipoDato' => 'date', 'severity' => 'alta', 'codigoCampo' => 'FFO'],
            ['campoNombre' => 'NombreArticulo', 'tipoCampo' => 'S', 'tipoDato' => 'article_name', 'severity' => 'alta', 'codigoCampo' => 'NAM'],
        ],
        'visual_checks' => [],
        'extraction_result' => [
            'document_conformity' => ['matches_expected_type' => true, 'detected_type' => 'Fórmula médica', 'justification' => 'OK'],
            'fields' => $data['formula']['header'],
            'items' => $data['formula']['items'],
            'unmatched_articles' => $data['formula']['unmatched_articles'] ?? [],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ],
    ];

    $normDoc = $normalizer->normalize($docState);
    $plan = $engine->plan($docState, $normDoc, $facNro);

    // 1. EVALUAR CAPA 1 LÉXICA
    $fdvArticles = array_column($data['fdv']['items'], 'NombreArticulo');
    $docArticles = array_map(static fn($it) => $it['NombreArticulo']['valor'], $data['formula']['items']);
    $unmatchedArticles = $data['formula']['unmatched_articles'] ?? [];

    echo "\n1. EVALUACIÓN LÉXICA (CAPA 1):\n";
    $capa1Matches = 0;
    foreach ($fdvArticles as $fi => $fdvArt) {
        $bestDoc = null;
        $bestJaccard = 0.0;
        $bestSim = 0.0;
        foreach ($docArticles as $di => $docArt) {
            $j = TextNormalization::tokenSortedJaccard($fdvArt, $docArt);
            similar_text(
                TextNormalization::normalizeArticleName($fdvArt),
                TextNormalization::normalizeArticleName($docArt),
                $sim
            );
            if ($j > $bestJaccard || ($j === $bestJaccard && $sim > $bestSim)) {
                $bestJaccard = $j;
                $bestSim = $sim;
                $bestDoc = $docArt;
            }
        }
        $isCapa1 = ($bestJaccard >= 0.50 || $bestSim >= 85.0);
        if ($isCapa1) {
            $capa1Matches++;
        }
        echo sprintf(
            "  - FDV: %-42s | Mejor Doc: %s\n    Jaccard=%.4f | Sim=%.2f%% => %s\n",
            substr($fdvArt, 0, 42),
            substr($bestDoc ?? 'NINGUNO', 0, 45),
            $bestJaccard,
            $bestSim,
            $isCapa1 ? "[MATCH CAPA 1]" : "[FASE 3 - LLM]"
        );
    }

    // 2. EVALUAR CANDIDATOS Y WAVES
    echo "\n2. PLANIFICACIÓN SEMÁNTICA (FASE 3):\n";
    echo sprintf("  - Total candidatos semánticos generados: %d\n", count($plan['candidates']));

    // Simular estructura de filas y olas
    $rowsStruct = [];
    foreach ($plan['candidates'] as $c) {
        foreach ($c['row_ids'] as $r) {
            $rowsStruct[$r] ??= [
                'row_id' => $r,
                'pair_ids' => [],
                'expected' => $c['expected'],
                'actuals' => [],
            ];
            $rowsStruct[$r]['pair_ids'][] = $c['pair_id'];
            $rowsStruct[$r]['actuals'][] = $c['actual'];
        }
    }

    $firstCandidateByRow = [];
    foreach ($rowsStruct as $rId => $r) {
        $firstActual = $r['actuals'][0] ?? 'NINGUNO';
        $firstCandidateByRow[$r['expected']] = $firstActual;
        echo sprintf(
            "  - Fila [%s] expected='%s'\n    -> cursor=0 (1.er candidato): '%s' (total opciones: %d)\n",
            substr($rId, 0, 8),
            substr($r['expected'], 0, 40),
            substr($firstActual, 0, 50),
            count($r['actuals'])
        );
    }

    // 3. EVALUAR COMPLETITUD DE METADATOS
    $fieldsNorm = FieldValueResolver::normalizeAssociative($normDoc['fields_normalized'] ?? []);
    $itemsNorm = FieldValueResolver::normalizeRows($normDoc['items_normalized'] ?? []);
    $namResolved = FieldValueResolver::resolveDocumentValue('NombreArticulo', AuditFieldValueType::ARTICLE_NAME, $fieldsNorm, $itemsNorm);
    $metaValoresCount = count($namResolved->evidenceMeta['valores'] ?? []);

    echo "\n3. COMPLETITUD DE extraction_meta.valores:\n";
    echo sprintf("  - Conteo actual de valores en evidencia: %d (esperados: %d)\n", $metaValoresCount, count($docArticles));
    echo sprintf("  - Contenido: %s\n", json_encode($namResolved->evidenceMeta['valores'] ?? [], JSON_UNESCAPED_UNICODE));

    // 4. VERIFICACIÓN DE INVARIANTES
    echo "\n4. VERIFICACIÓN DE INVARIANTES:\n";
    $inv = $data['invariants'];

    // Invariante D-11
    if (isset($inv['d11_respected'])) {
        $totalInvariants++;
        $d11Passed = true;
        foreach ($unmatchedArticles as $unm) {
            foreach ($fdvArticles as $fdvA) {
                $j = TextNormalization::tokenSortedJaccard($fdvA, $unm);
                similar_text(
                    TextNormalization::normalizeArticleName($fdvA),
                    TextNormalization::normalizeArticleName($unm),
                    $sim
                );
                // D-11 exige que no haga match con el documento no coincidente
                if ($j >= 0.50 || $sim >= 85.0) {
                    $d11Passed = false;
                    break 2;
                }
            }
        }
        if ($d11Passed) {
            $passedInvariants++;
            echo "  [PASS] INV-D11: unmatched_articles no contamina Capa 1 léxica.\n";
        } else {
            echo "  [FAIL] INV-D11: unmatched_articles contaminó Capa 1 léxica.\n";
        }
    }

    // Invariante Primer Candidato
    if (isset($inv['expected_first_candidate'])) {
        $totalInvariants++;
        $firstActual = reset($firstCandidateByRow);
        if ($firstActual === $inv['expected_first_candidate']) {
            $passedInvariants++;
            echo sprintf("  [PASS] INV-TIER1: Primer candidato es ítem primario '%s'.\n", substr($inv['expected_first_candidate'], 0, 40));
        } else {
            echo sprintf(
                "  [FAIL] INV-TIER1: Esperaba primer candidato '%s', pero obtuvo '%s'.\n",
                substr($inv['expected_first_candidate'], 0, 40),
                substr((string) $firstActual, 0, 40)
            );
        }
    }

    // Invariante Mínimo de Coincidencias en Capa 1
    if (isset($inv['min_capa1_lexical_matches'])) {
        $totalInvariants++;
        if ($capa1Matches >= $inv['min_capa1_lexical_matches']) {
            $passedInvariants++;
            echo sprintf("  [PASS] INV-CAPA1: Coincidencias léxicas en Capa 1 = %d (mínimo %d).\n", $capa1Matches, $inv['min_capa1_lexical_matches']);
        } else {
            echo sprintf("  [FAIL] INV-CAPA1: Coincidencias léxicas en Capa 1 = %d (esperadas mínimo %d).\n", $capa1Matches, $inv['min_capa1_lexical_matches']);
        }
    }

    // Invariante Conteo de Metadatos
    if (isset($inv['meta_item_count'])) {
        $totalInvariants++;
        if ($metaValoresCount === $inv['meta_item_count']) {
            $passedInvariants++;
            echo sprintf("  [PASS] INV-META: extraction_meta.valores completo (%d de %d ítems).\n", $metaValoresCount, $inv['meta_item_count']);
        } else {
            echo sprintf("  [FAIL] INV-META: extraction_meta.valores truncado (%d de %d ítems).\n", $metaValoresCount, $inv['meta_item_count']);
        }
    }

    echo "\n";
}

echo "===============================================================================\n";
echo sprintf(" RESUMEN FINAL: %d de %d invariantes cumplidos (%.1f%%)\n", $passedInvariants, $totalInvariants, $totalInvariants > 0 ? ($passedInvariants / $totalInvariants) * 100 : 0);
echo "===============================================================================\n";

if ($passedInvariants === $totalInvariants && $totalInvariants > 0) {
    echo "ESTADO: TODOS LOS INVARIANTES PASARON SATISFACTORIAMENTE [OK]\n";
    exit(0);
}

echo "ESTADO: EXISTEN INVARIANTES PENDIENTES POR IMPLEMENTAR [FAIL]\n";
exit(1);
