<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Logger;
use RuntimeException;

/** Recupera bytes verificados durante el turno del extractor, sin depender del TTL. */
final class AuditDocumentInput
{
    public function __construct(
        private readonly AuditDocumentCache $cache,
        private readonly AttachmentDownloadService $downloader,
        private readonly ?AuditDocumentMetrics $metrics = null
    ) {
    }

    public function resolve(AuditEvent $event): array
    {
        $payload = $event->payload;
        $key = self::required($payload, 'blob_reference_key');
        $expectedHash = self::required($payload, 'document_hash');
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
            throw new RuntimeException('document_hash inválido');
        }
        $document = $this->cache->read($key);
        if ($document === null) {
            try {
                $document = $this->downloader->download(
                    self::required($payload, 'attachment_id'),
                    self::required($payload, 'dis_det_nro')
                );
                self::validate($document, $expectedHash);
            } catch (\Throwable $error) {
                $this->metrics?->record('recovery_failed');
                if ($error instanceof DocumentHashMismatchException) {
                    $this->metrics?->record('hash_mismatch');
                }
                throw $error;
            }
            $this->metrics?->record('recovered', (int) ($document['duration_ms'] ?? 0));
            Logger::info('AuditDocumentInput: documento recuperado desde su origen', [
                'audit_id' => $event->auditId,
                'job_id' => $event->jobId,
                'document_id' => $event->documentId,
                'recovery_duration_ms' => (int) ($document['duration_ms'] ?? 0),
            ]);
        } else {
            self::validate($document, $expectedHash);
        }
        return $document;
    }

    private static function validate(array $document, string $expectedHash): void
    {
        if (!is_string($document['mime'] ?? null) || trim($document['mime']) === '') {
            throw new RuntimeException('BLOB documental sin MIME válido');
        }
        if (!is_string($document['data'] ?? null) || $document['data'] === '') {
            throw new RuntimeException('BLOB documental sin data base64');
        }
        // Conserva el algoritmo del contrato vigente: SHA256 de la representación base64.
        if (!hash_equals($expectedHash, hash('sha256', $document['data']))) {
            throw new DocumentHashMismatchException();
        }
    }

    private static function required(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("document_downloaded sin {$key}");
        }
        return $value;
    }
}
