<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Evaluación de la vigencia de entrega para auditorías documentales.
 *
 * Dominio autocontenido: calcula si FechaEntrega está dentro
 * de la vigencia de la autorización.
 *
 * Estrategia de resolución:
 * 1. Parámetros de vigencia: evidencia visual de Gemini → defaults globales
 * 2. Fechas del cálculo: Fuente de Verdad (FDV) del sistema transaccional
 */
final class DeliveryValidityEvaluator
{
    private const DEFAULT_VALIDITY_DAYS = 60;
    private const DEFAULT_VALIDITY_UNIT = 'dias';
    private const DEFAULT_VALIDITY_BASE_FIELD = 'FechaAutorizacion';

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

        $params = self::resolveValidityParams($candidate['visual']);
        if ($params === null) {
            return [self::buildInconclusiveFinding($candidate, 'No se pudo determinar los parámetros de vigencia de entrega.')];
        }

        $deliveryDate = self::resolveFdvDate($audit, 'FechaEntrega');
        $baseDate     = self::resolveFdvDate($audit, $params['baseField']);
        if ($deliveryDate === null || $baseDate === null) {
            return [self::buildInconclusiveFinding($candidate, 'FechaEntrega o fecha base no disponibles en la fuente de verdad.')];
        }

        return [self::buildFinding($candidate, $params, $baseDate, $deliveryDate)];
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

            $documentName  = (string) ($document['tipo_documento'] ?? '');
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
                    'expected'      => $expected,
                    'visual'        => $visualResults[$checkName] ?? null,
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
     * Resuelve los parámetros de vigencia priorizando evidencia visual sobre defaults globales.
     *
     * @return array{days:int,baseField:string,source:string}|null
     */
    private static function resolveValidityParams(?array $visual): ?array
    {
        if (is_array($visual) && ($visual['presente'] ?? false) === true) {
            $days      = self::resolvePositiveInteger($visual['valor'] ?? null);
            $unit      = (string) ($visual['unidad'] ?? '');
            $baseField = trim((string) ($visual['fecha_base'] ?? ''));

            if ($days !== null && $unit === self::DEFAULT_VALIDITY_UNIT && $baseField !== '') {
                return ['days' => $days, 'baseField' => $baseField, 'source' => 'visual'];
            }
        }

        return [
            'days'      => self::DEFAULT_VALIDITY_DAYS,
            'baseField' => self::DEFAULT_VALIDITY_BASE_FIELD,
            'source'    => 'default',
        ];
    }

    /**
     * Resuelve una fecha directamente desde la Fuente de Verdad del audit state.
     */
    private static function resolveFdvDate(array $audit, string $field): ?\DateTimeImmutable
    {
        foreach ($audit['documents'] ?? [] as $document) {
            if (!is_array($document)) {
                continue;
            }

            $header = $document['fuente_verdad']['header'] ?? [];
            if (!is_array($header)) {
                continue;
            }

            $date = self::parseIsoDate($header[$field] ?? null);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
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

    /**
     * @param array{days:int,baseField:string,source:string} $params
     */
    private static function buildFinding(
        array $candidate,
        array $params,
        \DateTimeImmutable $baseDate,
        \DateTimeImmutable $deliveryDate
    ): array {
        $days             = $params['days'];
        $baseField        = $params['baseField'];
        $limitDate        = $baseDate->modify("+{$days} days");
        $matches          = $deliveryDate <= $limitDate;
        $baseDateText     = $baseDate->format('Y-m-d');
        $deliveryDateText = $deliveryDate->format('Y-m-d');
        $limitDateText    = $limitDate->format('Y-m-d');
        $severity         = AuditSeverity::fromInput((string) ($candidate['expected']['severity'] ?? AuditSeverity::MEDIUM->value))->value;

        $sourceNote = $params['source'] === 'visual'
            ? ''
            : ' (vigencia por defecto del sistema)';

        return [
            'campo'              => AuditFindingRules::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad'  => "{$baseField} {$baseDateText} + {$days} dias = {$limitDateText}",
            'valorDocumento'     => "{$deliveryDateText} dentro de {$days} dias",
            'resultado'          => $matches ? AuditFindingResult::MATCH->value : AuditFindingResult::MISMATCH->value,
            'severidad'          => $severity,
            'documento'          => $candidate['document_name'],
            'detalle'            => $matches
                ? "FechaEntrega {$deliveryDateText} dentro de la vigencia hasta {$limitDateText}{$sourceNote}."
                : "FechaEntrega {$deliveryDateText} supera la vigencia hasta {$limitDateText}{$sourceNote}.",
            'tipo_auditoria'     => 'visual',
        ];
    }

    private static function buildInconclusiveFinding(array $candidate, string $detail): array
    {
        $severity = AuditSeverity::fromInput((string) ($candidate['expected']['severity'] ?? AuditSeverity::MEDIUM->value))->value;

        return [
            'campo'              => AuditFindingRules::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad'  => 'Vigencia calculable requerida',
            'valorDocumento'     => null,
            'resultado'          => AuditFindingResult::INCONCLUSIVE->value,
            'severidad'          => $severity,
            'documento'          => $candidate['document_name'],
            'detalle'            => $detail,
            'tipo_auditoria'     => 'visual',
        ];
    }
}
