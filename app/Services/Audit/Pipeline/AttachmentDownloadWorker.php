<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\Telemetry\TelemetryPublisher;
use RuntimeException;

final class AttachmentDownloadWorker extends AuditEventConsumer
{
    private const BLOB_KEY_PREFIX = 'audit:blob';

    private AttachmentDownloadService $downloader;
    private TelemetryPublisher $telemetryPublisher;
    private string $consumerName;
    private AuditDocumentCache $documentCache;

    public function __construct(
        ?AuditStateStore           $stateStore         = null,
        ?AttachmentDownloadService $downloader         = null,
        ?\Core\RedisClient         $redis              = null,
        ?AuditEventPublisher       $publisher          = null,
        ?string                    $consumerName       = null,
        ?TelemetryPublisher        $telemetryPublisher = null,
        ?int                       $blobTtl            = null,
        string|AuditLane|null      $lane               = null,
        ?AuditDocumentCache        $documentCache      = null
    ) {
        parent::__construct($redis, $publisher, $stateStore, $lane);

        $this->downloader         = $downloader         ?? new AttachmentDownloadService();
        $this->telemetryPublisher = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->consumerName       = $consumerName       ?? self::defaultConsumerName('downloader', $this->laneEnum);
        $resolvedBlobTtl = $blobTtl ?? AuditDocumentCache::DEFAULT_TTL_SECONDS;
        $this->documentCache = $documentCache ?? new AuditDocumentCache(
            $this->redis,
            ttlSeconds: $resolvedBlobTtl > 0 ? $resolvedBlobTtl : AuditDocumentCache::DEFAULT_TTL_SECONDS
        );
    }

    protected function streams(): array
    {
        return [
            AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY,
            AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
        ];
    }

    protected function group(): string
    {
        return AuditEventPublisher::GROUP_DOWNLOADERS;
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function eventTypes(): array
    {
        return [AuditEvent::TYPE_DOCUMENT_REGISTERED];
    }

    protected function handle(AuditEvent $event): void
    {
        if ($event->auditId === null || $event->documentId === null) {
            throw new RuntimeException('document_registered sin audit_id o document_id');
        }

        $payload      = $event->payload;
        $attachmentId = $this->requiredString($payload, 'attachment_id');
        $disDetNro    = $this->requiredString($payload, 'dis_det_nro');

        $telemetryMeta = [
            'worker' => $this->consumer(),
        ];

        $downloadStartedAt = hrtime(true);
        $this->telemetryPublisher->started(
            $event->auditId,
            'download',
            $event->documentId,
            $disDetNro,
            $telemetryMeta,
            $event->jobId
        );

        try {
            $document = $this->downloader->download($attachmentId, $disDetNro);
        } catch (\Throwable $error) {
            $this->telemetryPublisher->failed(
                $event->auditId,
                'download',
                self::elapsedMs($downloadStartedAt),
                $event->documentId,
                $disDetNro,
                array_merge($telemetryMeta, [
                    'error_class' => get_class($error),
                    'reason_code' => $error instanceof AttachmentDownloadException
                        ? $error->reasonCode()
                        : null,
                ]),
                $event->jobId
            );
            throw $error;
        }

        try {
            $documentHash = $this->documentHash($document);
            $blobKey = $this->blobKey((string) $event->auditId, (string) $event->documentId, $documentHash);
            $cached = $this->documentCache->put($blobKey, $document);

            $newPayload = array_merge($payload, [
                'blob_reference_key' => $blobKey,
                'document_hash'      => $documentHash,
                'blob_cache_stored'  => $cached,
            ]);

            $this->publisher->publish($event->followUp(
                eventType: AuditEvent::TYPE_DOCUMENT_DOWNLOADED,
                documentId: $event->documentId,
                payload: $newPayload,
            ));

            $this->telemetryPublisher->completed(
                $event->auditId,
                'download',
                self::elapsedMs($downloadStartedAt),
                $event->documentId,
                $disDetNro,
                array_merge($telemetryMeta, ['attachment_id' => $attachmentId, 'blob_cache_stored' => $cached]),
                $event->jobId
            );
        } catch (\Throwable $error) {
            $this->telemetryPublisher->failed(
                $event->auditId,
                'download',
                self::elapsedMs($downloadStartedAt),
                $event->documentId,
                $disDetNro,
                array_merge($telemetryMeta, ['error_class' => get_class($error)]),
                $event->jobId
            );
            throw $error;
        }
    }

    /**
     * @param array<string,mixed> $document
     */
    private function documentHash(array $document): string
    {
        $data = $document['data'] ?? null;
        if (!is_string($data) || $data === '') {
            throw new RuntimeException('Documento descargado sin data base64');
        }

        return hash('sha256', $data);
    }

    private function blobKey(string $auditId, string $documentId, string $documentHash): string
    {
        return self::BLOB_KEY_PREFIX . ":{$auditId}:{$documentId}:{$documentHash}";
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("document_registered sin {$key}");
        }

        return $value;
    }

    private static function elapsedMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
