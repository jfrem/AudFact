<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentNormalizationWorker;
use App\Services\Audit\Pipeline\DocumentNormalizer;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentNormalizationWorkerTest extends TestCase
{
    public function testDocumentExtractedPublishesDocumentNormalized(): void
    {
        $publisher = new NormalizationPublisher();
        $store = new NormalizationRecordingStateStore();
        $worker = new DocumentNormalizationWorker(
            stateStore: $store,
            normalizer: new StubDocumentNormalizer([
                'tipo_documento' => 'DISPENSA',
                'fields_normalized' => ['Autorizacion' => '46338218'],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
                'quality_notes' => [],
                'normalization_log' => [['operation' => 'field_alias_normalized']],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'normalizer-test'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: AuditEvent::uuidV4(),
            documentId: AuditEvent::uuidV4(),
            payload: [
                'tipo_documento' => 'DISPENSA',
                'extraction_result' => [
                    'fields' => ['NumeroAutorizacion' => '46338218'],
                    'items' => [],
                    'visual_checks' => [],
                    'document_quality' => 'legible',
                    'quality_notes' => [],
                ],
            ]
        );

        $worker->processEvent($event);

        $this->assertSame('normalized', $store->lastPatch['status'] ?? null);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_NORMALIZED, $publisher->published[0]->eventType);
        $this->assertSame('DISPENSA', $publisher->published[0]->payload['tipo_documento']);
        $this->assertSame('46338218', $publisher->published[0]->payload['fields_normalized']['Autorizacion']);
        $this->assertSame([['operation' => 'field_alias_normalized']], $publisher->published[0]->payload['normalization_log']);
        $this->assertArrayNotHasKey('normalized_result', $publisher->published[0]->payload);
    }

    public function testDoesNotPublishWhenStateStoreReturnsFalse(): void
    {
        $publisher = new NormalizationPublisher();
        $store = new NormalizationRecordingStateStore(false);
        $worker = new DocumentNormalizationWorker(
            stateStore: $store,
            normalizer: new StubDocumentNormalizer([
                'tipo_documento' => 'DISPENSA',
                'fields_normalized' => [],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
                'quality_notes' => [],
                'normalization_log' => [],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'normalizer-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo persistir la normalización');

        try {
            $worker->processEvent(AuditEvent::create(
                eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
                auditId: AuditEvent::uuidV4(),
                documentId: AuditEvent::uuidV4(),
                payload: [
                    'tipo_documento' => 'DISPENSA',
                    'extraction_result' => [
                        'fields' => [],
                        'items' => [],
                        'visual_checks' => [],
                        'document_quality' => 'legible',
                        'quality_notes' => [],
                    ],
                ]
            ));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
    }
}

final class StubDocumentNormalizer extends DocumentNormalizer
{
    /**
     * @param array<string,mixed> $result
     */
    public function __construct(private array $result)
    {
    }

    public function normalize(array $payload): array
    {
        return $this->result;
    }
}

final class NormalizationRecordingStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
    public array $lastPatch = [];

    public function __construct(private bool $result = true)
    {
    }

    public function markDocumentNormalized(string $auditId, string $documentId, array $normalizedState): bool
    {
        $this->lastPatch = $normalizedState;
        return $this->result;
    }
}

final class NormalizationPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

    public function __construct()
    {
    }

    public function publish(AuditEvent $event): string
    {
        $this->published[] = $event;
        return 'stream-' . count($this->published);
    }
}
