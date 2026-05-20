<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Evaluación de la vigencia de entrega para auditorías documentales.
 *
 * Extraída de AuditFindingRules para cohesión (SRP).
 * Dominio autocontenido: calcula si FechaEntrega está dentro
 * de la vigencia visible del documento.
 */
final class DeliveryValidityEvaluator
{
    /**
     * Evalúa hallazgos calculados de vigencia de entrega para una auditoría completa.
     *
     * @param  array<string,mixed>            $audit    Estado completo del audit en Redis.
     * @param  array<int,array<string,mixed>> $findings Hallazgos recopilados de todos los documentos.
     * @return array<int,array<string,mixed>>
     */
    public static function evaluate(array $audit, array $findings): array
    {
        $candidate = self::resolveCandidate($audit);
        if ($candidate === null) {
            return [];
        }

        $visual = $candidate['visual'];
        if (!is_array($visual) || ($visual['presente'] ?? false) !== true) {
            return [self::buildInconclusiveFinding($candidate, 'No se encontró una vigencia de entrega visible y estructurada.')];
        }

        $days = self::resolvePositiveInteger($visual['valor'] ?? null);
        $unit = (string) ($visual['unidad'] ?? '');
        $baseField = trim((string) ($visual['fecha_base'] ?? ''));
        if ($days === null || $unit !== 'dias' || $baseField === '') {
            return [self::buildInconclusiveFinding($candidate, 'La vigencia visible no contiene valor, unidad o fecha base suficiente para calcular.')];
        }

        $deliveryDate = self::resolveMatchedDate($findings, 'FechaEntrega');
        $baseDate = self::resolveMatchedDate($findings, $baseField);
        if ($deliveryDate === null || $baseDate === null) {
            return [self::buildInconclusiveFinding($candidate, 'FechaEntrega o fecha base no tienen resultado COINCIDE para validar la vigencia.')];
        }

        return [self::buildFinding($candidate, $days, $baseField, $baseDate, $deliveryDate)];
    }

    /**
     * @return array{document_name:string,expected:array<string,mixed>,visual:?array<string,mixed>}|null
     */
    private static function resolveCandidate(array $audit): ?array
    {
        $fallback = null;

        foreach (($audit['documents'] ?? []) as $document) {
            if (!is_array($document)) {
                continue;
            }

            $documentName = (string) ($document['tipo_documento'] ?? '');
            $visualResults = self::indexVisualResults($document['normalized_result']['visual_checks_resultado'] ?? []);

            foreach (($document['visual_checks'] ?? []) as $expected) {
                if (!is_array($expected)) {
                    continue;
                }

                $checkName = trim((string) ($expected['check'] ?? ''));
                if ($checkName !== AuditFindingRules::FIELD_DELIVERY_VALIDITY) {
                    continue;
                }

                $candidate = [
                    'document_name' => $documentName,
                    'expected' => $expected,
                    'visual' => $visualResults[$checkName] ?? null,
                ];

                if (is_array($candidate['visual']) && ($candidate['visual']['presente'] ?? false) === true) {
                    return $candidate;
                }

                $fallback ??= $candidate;
            }
        }

        return $fallback;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function indexVisualResults(mixed $visualResults): array
    {
        if (!is_array($visualResults)) {
            return [];
        }

        $indexed = [];
        foreach ($visualResults as $visual) {
            if (!is_array($visual)) {
                continue;
            }

            $check = trim((string) ($visual['check'] ?? ''));
            if ($check !== '') {
                $indexed[$check] = $visual;
            }
        }

        return $indexed;
    }

    private static function resolvePositiveInteger(mixed $value): ?int
    {
        if (!is_int($value) || $value < 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     */
    private static function resolveMatchedDate(array $findings, string $field): ?\DateTimeImmutable
    {
        foreach ($findings as $finding) {
            if (($finding['campo'] ?? null) !== $field || ($finding['resultado'] ?? null) !== AuditFindingResult::MATCH->value) {
                continue;
            }

            foreach (['valorFuenteVerdad', 'valorDocumento'] as $key) {
                $date = self::parseIsoDate($finding[$key] ?? null);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private static function parseIsoDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $candidate = preg_split('/\s+/', trim($value), 2)[0] ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $candidate) {
            return null;
        }

        return $date;
    }

    private static function buildFinding(
        array $candidate,
        int $days,
        string $baseField,
        \DateTimeImmutable $baseDate,
        \DateTimeImmutable $deliveryDate
    ): array {
        $limitDate = $baseDate->modify("+{$days} days");
        $matches = $deliveryDate <= $limitDate;
        $baseDateText = $baseDate->format('Y-m-d');
        $deliveryDateText = $deliveryDate->format('Y-m-d');
        $limitDateText = $limitDate->format('Y-m-d');
        $severity = AuditSeverity::fromInput((string) ($candidate['expected']['severity'] ?? AuditSeverity::MEDIUM->value))->value;

        return [
            'campo' => AuditFindingRules::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad' => "{$baseField} {$baseDateText} + {$days} dias = {$limitDateText}",
            'valorDocumento' => "{$deliveryDateText} dentro de {$days} dias",
            'resultado' => $matches ? AuditFindingResult::MATCH->value : AuditFindingResult::MISMATCH->value,
            'severidad' => $severity,
            'documento' => $candidate['document_name'],
            'detalle' => $matches
                ? "FechaEntrega {$deliveryDateText} dentro de la vigencia hasta {$limitDateText}."
                : "FechaEntrega {$deliveryDateText} supera la vigencia hasta {$limitDateText}.",
            'tipo_auditoria' => 'visual',
        ];
    }

    private static function buildInconclusiveFinding(array $candidate, string $detail): array
    {
        $severity = AuditSeverity::fromInput((string) ($candidate['expected']['severity'] ?? AuditSeverity::MEDIUM->value))->value;

        return [
            'campo' => AuditFindingRules::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad' => 'Vigencia calculable requerida',
            'valorDocumento' => null,
            'resultado' => AuditFindingResult::INCONCLUSIVE->value,
            'severidad' => $severity,
            'documento' => $candidate['document_name'],
            'detalle' => $detail,
            'tipo_auditoria' => 'visual',
        ];
    }
}
