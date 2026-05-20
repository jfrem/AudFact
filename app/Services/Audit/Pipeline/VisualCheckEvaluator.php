<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditFindingRules;
use App\Services\Audit\AuditSeverity;

/**
 * Encapsula la evaluación de checks visuales (Visual Checks).
 *
 * Extraída de DocumentPolicyEngine para cohesión (SRP).
 * Se encarga de cruzar los checks esperados con los resultados del modelo,
 * manejando la calidad del documento y fallbacks.
 */
final class VisualCheckEvaluator
{
    /**
     * @return array<string,array{check:string,presente:bool,detalle:?string,severidad:string}>
     */
    public static function normalizeVisualCheckResults(mixed $value): array
    {
        $indexed = [];
        if (!is_array($value)) {
            return $indexed;
        }

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $check = trim((string) ($row['check'] ?? ''));
            if ($check === '') {
                continue;
            }

            $indexed[$check] = [
                'check'    => $check,
                'presente' => (bool) ($row['presente'] ?? false),
                'detalle'  => AuditFindingRules::normalizeNullableString($row['detalle'] ?? null),
                'severidad' => AuditSeverity::fromInput((string) ($row['severidad'] ?? ''))->value,
                'valor' => $row['valor'] ?? null,
                'unidad' => $row['unidad'] ?? null,
                'fecha_base' => $row['fecha_base'] ?? null,
            ];
        }

        return $indexed;
    }

    /**
     * @param array<int,array<string,mixed>> $visualChecksExpected
     * @param array<string,array{check:string,presente:bool,detalle:?string,severidad:string}> $results
     * @return array<int,array<string,mixed>>
     */
    public static function evaluate(
        string $documentType,
        mixed $visualChecksExpected,
        array $results,
        string $documentQuality
    ): array {
        if (!is_array($visualChecksExpected)) {
            return [];
        }

        $findings = [];

        foreach ($visualChecksExpected as $checkExpected) {
            if (!is_array($checkExpected)) {
                continue;
            }

            $checkName = trim((string) ($checkExpected['check'] ?? ''));
            if ($checkName === '') {
                continue;
            }

            if (AuditFindingRules::isCalculatedVisualCheck($checkName)) {
                continue;
            }

            $severity = AuditSeverity::fromInput((string) ($checkExpected['severity'] ?? ''))->value;

            if ($documentQuality !== 'legible') {
                $findings[] = self::buildVisualFinding(
                    $documentType, $checkName, $severity,
                    'NO_EVALUADO', AuditFindingResult::INCONCLUSIVE->value,
                    'La calidad documental no permite concluir la validación visual.'
                );
                continue;
            }

            $foundResult = $results[$checkName] ?? null;
            if (!is_array($foundResult)) {
                $findings[] = self::buildVisualFinding(
                    $documentType, $checkName, $severity,
                    'NO_EVALUADO', AuditFindingResult::INCONCLUSIVE->value,
                    'Check visual esperado no fue evaluado por el modelo.'
                );
                continue;
            }

            $isPresent  = (bool) ($foundResult['presente'] ?? false);
            $findings[] = self::buildVisualFinding(
                $documentType, $checkName, $severity,
                $isPresent ? 'PRESENTE' : 'AUSENTE',
                $isPresent ? AuditFindingResult::MATCH->value : AuditFindingResult::MISMATCH->value,
                AuditFindingRules::normalizeNullableString($foundResult['detalle'] ?? null)
            );
        }

        return $findings;
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildVisualFinding(
        string $documentType,
        string $displayField,
        string $severity,
        string $valorDocumento,
        string $resultado,
        ?string $detalle
    ): array {
        return [
            'valorFuenteVerdad' => 'OBLIGATORIO',
            'valorDocumento'    => $valorDocumento,
            'resultado'         => $resultado,
            'detalle'           => $detalle,
            'campo'             => $displayField,
            'severidad'         => $severity,
            'documento'         => $documentType,
        ];
    }
}
