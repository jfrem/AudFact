<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use InvalidArgumentException;

/**
 * Resultado inmutable de la reconciliación global entre documentos lógicos y adjuntos físicos.
 */
final readonly class DocumentAttachmentMatchResult
{
    /**
     * @param array<int,array<string,mixed>> $matches
     * @param array<int,array<string,mixed>> $rejections
     */
    public function __construct(
        public array $matches,
        public array $rejections
    ) {
        $logicalDocumentIds = [];
        $attachmentIds = [];

        foreach ($matches as $match) {
            $logicalDocumentId = self::requiredScalarId($match, 'logical_doc_id');
            $attachmentId = self::requiredScalarId($match, 'attachment_id');

            if (isset($logicalDocumentIds[$logicalDocumentId])) {
                throw new InvalidArgumentException(
                    "DocumentAttachmentMatchResult contiene logical_doc_id repetido: {$logicalDocumentId}"
                );
            }
            if (isset($attachmentIds[$attachmentId])) {
                throw new InvalidArgumentException(
                    "DocumentAttachmentMatchResult contiene attachment_id repetido: {$attachmentId}"
                );
            }

            $logicalDocumentIds[$logicalDocumentId] = true;
            $attachmentIds[$attachmentId] = true;
        }

        foreach ($rejections as $rejection) {
            $logicalDocumentId = self::requiredScalarId($rejection, 'logical_doc_id');
            if (isset($logicalDocumentIds[$logicalDocumentId])) {
                throw new InvalidArgumentException(
                    "DocumentAttachmentMatchResult mezcla match y rejection para logical_doc_id {$logicalDocumentId}"
                );
            }
            $logicalDocumentIds[$logicalDocumentId] = true;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function requiredScalarId(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException("DocumentAttachmentMatchResult requiere {$field}");
        }

        return trim((string) $value);
    }
}
