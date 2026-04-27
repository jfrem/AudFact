<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use App\Services\Audit\FieldClassifier;

final class AuditFindingRules
{
    private const RESULT_MATCH        = 'COINCIDE';
    private const RESULT_SKIPPED      = 'OMITIDO';
    private const RESULT_INCONCLUSIVE = 'NO_CONCLUYENTE';

    private const FAILURE_RESULTS     = ['VALOR_DISTINTO', 'NO_ENCONTRADO', 'NO_CONCLUYENTE'];
    private const DISCREPANCY_RESULTS = ['VALOR_DISTINTO', 'NO_ENCONTRADO'];

    private FieldClassifier $classifier;

    public function __construct(?FieldClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new FieldClassifier();
    }

    public function isFailureResult(string $result): bool
    {
        return in_array($result, self::FAILURE_RESULTS, true);
    }

    public function isDiscrepancyResult(string $result): bool
    {
        return in_array($result, self::DISCREPANCY_RESULTS, true);
    }

    public function riskWeight(string $severity): int
    {
        return match ($severity) {
            FieldClassifier::SEVERITY_HIGH => 10,
            FieldClassifier::SEVERITY_LOW => 1,
            default => 5,
        };
    }

    public function findingPriority(string $field, string $severity, string $result): int
    {
        $field = $this->classifier->normalizeField($field);
        $base = match ($field) {
            'FirmaActaEntrega', 'FirmaPrescriptor' => 100,
            'NumeroIdentificacion', 'TipoIdentificacion', 'NombrePaciente' => 90,
            'CodigoDiagnostico' => 85,
            'Autorizacion', 'FechaAutorizacion', 'FechaEntrega', 'FechaFormula' => 80,
            'CantidadEntregada', 'CantidadPrescrita' => 75,
            'NombreArticulo' => 70,
            default => 50,
        };

        $severityWeight = match ($severity) {
            FieldClassifier::SEVERITY_HIGH => 20,
            FieldClassifier::SEVERITY_LOW => 0,
            default => 10,
        };

        $resultWeight = $this->isDiscrepancyResult($result) ? 10 : 0;

        return $base + $severityWeight + $resultWeight;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array<string,int>
     */
    public function summarizeMetrics(array $findings): array
    {
        $metrics = [
            'total_campos' => 0,
            'coincidencias' => 0,
            'discrepancias' => 0,
            'omitidos' => 0,
            'no_concluyentes' => 0,
            'risk_score' => 0,
        ];

        foreach ($findings as $finding) {
            $metrics['total_campos']++;
            $result = (string) ($finding['resultado'] ?? '');
            $severity = (string) ($finding['severidad'] ?? FieldClassifier::SEVERITY_MEDIUM);

            if ($result === self::RESULT_MATCH) {
                $metrics['coincidencias']++;
                continue;
            }

            if ($result === self::RESULT_SKIPPED) {
                $metrics['omitidos']++;
                continue;
            }

            if ($result === self::RESULT_INCONCLUSIVE) {
                $metrics['no_concluyentes']++;
                $metrics['risk_score'] += $this->riskWeight($severity);
                continue;
            }

            if ($this->isDiscrepancyResult($result)) {
                $metrics['discrepancias']++;
                $metrics['risk_score'] += $this->riskWeight($severity);
            }
        }

        return $metrics;
    }

    public function observationRequiresManualReview(?string $observation): bool
    {
        $normalized = strtolower(trim((string) $observation));

        return $normalized !== ''
            && (
                str_contains($normalized, 'confianza')
                || str_contains($normalized, 'no permite concluir')
                || str_contains($normalized, 'incertidumbre')
            );
    }
}
