<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditSeverity;

/**
 * Evalúa consistencia interna entre columnas de la FdV para campos tipoCampo='I'.
 *
 * Cada regla de integridad mapea un campo primario (campoNombre del catálogo)
 * a una columna de comparación en los items de la FdV. El evaluador itera
 * los items y detecta discrepancias sin que DocumentPolicyEngine conozca
 * nombres de campos.
 *
 * Para agregar una nueva regla de integridad:
 *   1. Registrar el campo en AudDispCampoCatalogo con tipoCampo='I'.
 *   2. Agregar la entrada correspondiente en INTEGRITY_RULES.
 *   3. Asegurar que ambas columnas existen en DispensationModel::DETAIL_COLUMNS.
 */
final class InternalIntegrityEvaluator
{
    /**
     * Mapa de reglas de integridad interna.
     * Key = campoNombre del catálogo (campo primario en FdV items).
     * Value = array con:
     *   - comparisonField: columna de la FdV a comparar
     *   - detailTemplate: plantilla sprintf del mensaje de detalle
     *
     * @var array<string,array{comparisonField:string,detailTemplate:string}>
     */
    private const INTEGRITY_RULES = [
        'NEntrega' => [
            'comparisonField' => 'MipresNoEntrega',
            'detailTemplate'  => 'Inconsistencia interna de base de datos: El ERP registra la entrega %s, pero DatosMipresDetalle reporta la entrega %s.',
        ],
    ];

    /**
     * @param  array<string,mixed>               $sourceTruth    FdV con header/items.
     * @param  string                            $documentType   Tipo documental.
     * @param  array<string,array<string,mixed>> $internalFields Campos con tipoCampo='I',
     *                                                           indexados por campoNombre.
     * @return array<int,array<string,mixed>>                    Hallazgos de integridad.
     */
    public static function evaluate(array $sourceTruth, string $documentType, array $internalFields): array
    {
        $items = is_array($sourceTruth['items'] ?? null) ? $sourceTruth['items'] : [];
        if ($items === [] || $internalFields === []) {
            return [];
        }

        $findings = [];

        foreach ($internalFields as $campoNombre => $fieldConfig) {
            $rule = self::INTEGRITY_RULES[$campoNombre] ?? null;
            if ($rule === null) {
                continue; // Campo 'I' sin regla registrada — se ignora sin error
            }

            $primaryField    = $campoNombre;
            $comparisonField = $rule['comparisonField'];
            $detailTemplate  = $rule['detailTemplate'];

            foreach ($items as $item) {
                if (!isset($item[$primaryField], $item[$comparisonField])) {
                    continue;
                }

                $isMatch = (int) $item[$primaryField] === (int) $item[$comparisonField];

                $findings[] = [
                    'valorFuenteVerdad' => (string) $item[$primaryField],
                    'valorDocumento'    => (string) $item[$comparisonField],
                    'resultado'         => $isMatch
                        ? AuditFindingResult::MATCH->value
                        : AuditFindingResult::MISMATCH->value,
                    'severidad'         => $fieldConfig['severity'] ?? $fieldConfig['severidad'] ?? AuditSeverity::HIGH->value,
                    'codigoCampo'       => $fieldConfig['codigoCampo'] ?? '',
                    'campo'             => $primaryField,
                    'documento'         => $documentType,
                    'valueType'         => $fieldConfig['tipoDato'] ?? AuditFieldValueType::TEXT->value,
                    'detalle'           => $isMatch
                        ? null
                        : sprintf($detailTemplate, $item[$primaryField], $item[$comparisonField]),
                    'tipo_auditoria'    => 'integrity',
                ];
            }
        }

        return $findings;
    }
}
