<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use RuntimeException;

final class DocumentNormalizationWorker extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private DocumentNormalizer $normalizer;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?DocumentNormalizer $normalizer = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->normalizer = $normalizer ?? new DocumentNormalizer();
        $this->consumerName = $consumerName ?? ('normalizer-' . getmypid());
    }

    public function processEvent(AuditEvent $event): void
    {
        $this->handle($event);
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_DOCUMENTS;
    }

    protected function group(): string
    {
        return 'normalizers';
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function handle(AuditEvent $event): void
    {
        if ($event->eventType !== AuditEvent::TYPE_DOCUMENT_EXTRACTED) {
            return;
        }

        if ($event->auditId === null || $event->documentId === null) {
            throw new RuntimeException('document_extracted sin audit_id o document_id');
        }

        $normalized = $this->normalizer->normalize($event->payload);
        $documentState = [
            'status' => 'normalized',
            'normalized_result' => $normalized,
            'normalized_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (!$this->stateStore->markDocumentNormalized($event->auditId, $event->documentId, $documentState)) {
            throw new RuntimeException('No se pudo persistir la normalización del documento en Redis');
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            documentId: $event->documentId,
            payload: [
                'tipo_documento' => (string) ($normalized['tipo_documento'] ?? ''),
                'fields_normalized' => $normalized['fields_normalized'] ?? [],
                'items_normalized' => $normalized['items_normalized'] ?? [],
                'visual_checks_resultado' => $normalized['visual_checks_resultado'] ?? [],
                'document_quality' => $normalized['document_quality'] ?? null,
                'quality_notes' => $normalized['quality_notes'] ?? [],
                'normalization_log' => $normalized['normalization_log'] ?? [],
            ],
            parentEventId: $event->eventId,
        ));
    }
}
