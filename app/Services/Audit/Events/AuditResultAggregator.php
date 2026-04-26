<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use App\Services\Audit\FieldClassifier;
use RuntimeException;

class AuditResultAggregator
{
    private FieldClassifier $classifier;
    private AuditFindingRules $findingRules;

    public function __construct(?FieldClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new FieldClassifier();
        $this->findingRules = new AuditFindingRules($this->classifier);
    }

    /**
     * @param  array<string,mixed> $audit
     * @param  array<string,mixed> $rulesPayload
     * @return array{
     *   final_status:string,
     *   requires_manual_review:bool,
     *   severity:string,
     *   detail_message:string,
     *   failed_document:?string,
     *   document_decisions:array<int,array{documentName:string,approved:bool,observation:?string}>,
     *   audit_result_data:array<string,mixed>,
     *   completion_payload:array<string,mixed>
     * }
     */
    public function aggregate(array $audit, array $rulesPayload): array
    {
        $hallazgos = $rulesPayload['hallazgos'] ?? null;
        $documentDecisions = $rulesPayload['document_decisions'] ?? null;

        if (!is_array($hallazgos) || !is_array($documentDecisions)) {
            throw new RuntimeException('rules_evaluated sin hallazgos o document_decisions válidos');
        }

        $findings = $this->normalizeFindings($hallazgos['items'] ?? []);
        $metrics = $this->normalizeMetrics($hallazgos['metrics'] ?? []);
        $normalizedDecisions = $this->normalizeDocumentDecisions($documentDecisions);

        $finalStatus = $this->resolveFinalStatus($findings, $normalizedDecisions);
        $requiresManualReview = in_array($finalStatus, [
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
            AuditStateStore::AUDIT_STATUS_FAILED,
        ], true);
        $severity = $this->resolveOverallSeverity($findings);
        $failedDocument = $this->resolveFailedDocument($findings);
        $detailMessage = $this->buildDetailMessage($finalStatus, $metrics);

        $auditResultData = [
            'FacSec' => (string) ($audit['fac_sec'] ?? ''),
            'FacNro' => (string) ($audit['dis_det_nro'] ?? ''),
            'EstAud' => $finalStatus === AuditStateStore::AUDIT_STATUS_FAILED ? 0 : 1,
            'EstadoDetallado' => $finalStatus,
            'RequiereRevisionHumana' => $requiresManualReview ? 1 : 0,
            'Severidad' => $severity,
            'Hallazgos' => json_encode([
                'items' => $findings,
                'metrics' => $metrics,
                'affected_documents' => $normalizedDecisions,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'DetalleError' => $detailMessage,
            'DocumentosProcesados' => count(is_array($audit['documents'] ?? null) ? $audit['documents'] : []),
            'DocumentoFallido' => $failedDocument,
            'DuracionProcesamientoMs' => $this->resolveDurationMs($audit),
            'FacNitSec' => (string) ($audit['fac_nit_sec'] ?? ''),
        ];

        if ($auditResultData['FacSec'] === '' || $auditResultData['FacNro'] === '') {
            throw new RuntimeException('Estado de auditoría incompleto para persistencia final');
        }

        return [
            'final_status' => $finalStatus,
            'requires_manual_review' => $requiresManualReview,
            'severity' => $severity,
            'detail_message' => $detailMessage,
            'failed_document' => $failedDocument,
            'document_decisions' => $normalizedDecisions,
            'audit_result_data' => $auditResultData,
            'completion_payload' => [
                'status' => $finalStatus,
                'requires_manual_review' => $requiresManualReview,
                'audit_result' => [
                    'hallazgos' => [
                        'items' => $findings,
                        'metrics' => $metrics,
                    ],
                    'document_decisions' => $normalizedDecisions,
                ],
                'persistence_target' => 'AudDispEst+AdjuntosDispensacion',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeFindings(mixed $findings): array
    {
        if (!is_array($findings)) {
            return [];
        }

        $normalized = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $normalized[] = $finding;
        }

        return $normalized;
    }

    /**
     * @return array<string,int>
     */
    private function normalizeMetrics(mixed $metrics): array
    {
        $base = [
            'total_campos' => 0,
            'coincidencias' => 0,
            'discrepancias' => 0,
            'omitidos' => 0,
            'no_concluyentes' => 0,
            'risk_score' => 0,
        ];

        if (!is_array($metrics)) {
            return $base;
        }

        foreach (array_keys($base) as $key) {
            $base[$key] = (int) ($metrics[$key] ?? 0);
        }

        return $base;
    }

    /**
     * @return array<int,array{documentName:string,approved:bool,observation:?string}>
     */
    private function normalizeDocumentDecisions(mixed $decisions): array
    {
        if (!is_array($decisions)) {
            return [];
        }

        $normalized = [];
        foreach ($decisions as $decision) {
            if (!is_array($decision)) {
                continue;
            }

            $name = $this->normalizeDocumentName((string) ($decision['documentName'] ?? ''));
            if ($name === '') {
                continue;
            }

            $observation = trim((string) ($decision['observation'] ?? ''));
            $normalized[] = [
                'documentName' => $name,
                'approved' => (bool) ($decision['approved'] ?? false),
                'observation' => $observation === '' ? null : $observation,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     */
    private function resolveFinalStatus(array $findings, array $documentDecisions): string
    {
        $hasHighSeverityFailure = false;
        $hasNonCriticalFailure = false;

        foreach ($findings as $finding) {
            $result = (string) ($finding['resultado'] ?? '');
            if (!$this->findingRules->isFailureResult($result)) {
                continue;
            }

            $severity = (string) ($finding['severidad'] ?? FieldClassifier::SEVERITY_MEDIUM);
            if ($severity === FieldClassifier::SEVERITY_HIGH) {
                $hasHighSeverityFailure = true;
            } else {
                $hasNonCriticalFailure = true;
            }
        }

        foreach ($documentDecisions as $decision) {
            if ($decision['approved'] === true) {
                continue;
            }

            if ($this->findingRules->observationRequiresManualReview($decision['observation'] ?? null)) {
                $hasHighSeverityFailure = true;
            }
        }

        if ($hasHighSeverityFailure) {
            return AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW;
        }

        if ($hasNonCriticalFailure) {
            return AuditStateStore::AUDIT_STATUS_ERROR;
        }

        return AuditStateStore::AUDIT_STATUS_COMPLETED;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     */
    private function resolveOverallSeverity(array $findings): string
    {
        $current = FieldClassifier::SEVERITY_LOW;
        foreach ($findings as $finding) {
            $severity = (string) ($finding['severidad'] ?? FieldClassifier::SEVERITY_MEDIUM);
            if ($severity === FieldClassifier::SEVERITY_HIGH) {
                return FieldClassifier::SEVERITY_HIGH;
            }
            if ($severity === FieldClassifier::SEVERITY_MEDIUM) {
                $current = FieldClassifier::SEVERITY_MEDIUM;
            }
        }

        return $current;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     */
    private function resolveFailedDocument(array $findings): ?string
    {
        $bestDocument = null;
        $bestPriority = -1;

        foreach ($findings as $finding) {
            $result = (string) ($finding['resultado'] ?? '');
            if (!$this->findingRules->isFailureResult($result)) {
                continue;
            }

            $document = $this->normalizeDocumentName((string) ($finding['documento'] ?? ''));
            if ($document === '') {
                continue;
            }

            $field = (string) ($finding['campo'] ?? '');
            $severity = (string) ($finding['severidad'] ?? FieldClassifier::SEVERITY_MEDIUM);
            $priority = $this->findingRules->findingPriority($field, $severity, $result);
            if ($bestDocument === null || $priority > $bestPriority) {
                $bestDocument = $document;
                $bestPriority = $priority;
            }
        }

        return $bestDocument;
    }

    /**
     * @param  array<string,int> $metrics
     */
    private function buildDetailMessage(string $finalStatus, array $metrics): string
    {
        return match ($finalStatus) {
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW => sprintf(
                'Auditoria completada con incertidumbre documental: %d campos no concluyentes requieren revision humana.',
                $metrics['no_concluyentes']
            ),
            AuditStateStore::AUDIT_STATUS_ERROR => sprintf(
                'Auditoria completada con discrepancias documentales: %d discrepancias requieren analisis posterior.',
                $metrics['discrepancias']
            ),
            default => sprintf(
                'Auditoria completada sin hallazgos criticos: %d campos evaluados.',
                $metrics['total_campos']
            ),
        };
    }

    /**
     * @param  array<string,mixed> $audit
     */
    private function resolveDurationMs(array $audit): int
    {
        $createdAt = $audit['created_at'] ?? null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            return 0;
        }

        try {
            $created = new \DateTimeImmutable($createdAt);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 0;
        }

        return max(0, (int) (($now->getTimestamp() - $created->getTimestamp()) * 1000));
    }

    private function normalizeDocumentName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return str_replace('_', ' ', strtoupper($trimmed));
    }

}
