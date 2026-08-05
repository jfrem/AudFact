<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;

final class DocumentDuplicationEvaluator
{
    /**
     * @param array<string,mixed> $audit
     * @return array<int,array<string,mixed>>
     */
    public static function evaluate(array $audit): array
    {
        $findings = [];
        $documentsByHashAndAttachment = [];

        foreach (($audit['documents'] ?? []) as $documentState) {
            if (!is_array($documentState)) {
                continue;
            }

            $hash = self::documentHash($documentState);
            if ($hash === '') {
                continue;
            }

            $attachmentId = trim((string) ($documentState['attachment_id'] ?? ''));
            if ($attachmentId === '') {
                continue;
            }

            $documentsByHashAndAttachment[$hash][$attachmentId] ??= self::documentName($documentState);
        }

        foreach ($documentsByHashAndAttachment as $hash => $documentsByAttachmentId) {
            if (count($documentsByAttachmentId) <= 1) {
                continue;
            }

            foreach ($documentsByAttachmentId as $documentName) {
                $findings[] = self::finding($hash, $documentName);
            }
        }

        return $findings;
    }

    /**
     * @param array<string,mixed> $documentState
     */
    private static function documentHash(array $documentState): string
    {
        return trim((string) ($documentState['document_hash'] ?? ''));
    }

    /**
     * @param array<string,mixed> $documentState
     */
    private static function documentName(array $documentState): string
    {
        $type = (string) ($documentState['tipo_documento'] ?? $documentState['document_type'] ?? 'DOCUMENTO');
        $normalized = DocumentExtractionContractBuilder::normalizeDocumentName($type);

        return $normalized !== '' ? $normalized : 'DOCUMENTO';
    }

    /**
     * @return array<string,mixed>
     */
    private static function finding(string $hash, string $documentName): array
    {
        return [
            'severidad'         => AuditSeverity::HIGH->value,
            'campo'             => 'INTEGRIDAD_BINARIA',
            'codigoCampo'       => 'DUP',
            'documento'         => $documentName,
            'valorDocumento'    => $hash,
            'valorFuenteVerdad' => null,
            'resultado'         => AuditFindingResult::REJECTED->value,
            'detalle'           => 'Documento idéntico binariamente a otro en la misma dispensación (duplicado).',
            'tipo_auditoria'    => 'integrity',
        ];
    }
}
