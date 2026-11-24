<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;

/**
 * Doble de `AuditEventPublisher` que registra eventos y permite inyectar fallos por evento.
 */
final class RecordingAuditEventPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];
    /** @var array<int,AuditEvent> */
    public array $deadLetters = [];
    /** @var (callable(AuditEvent,self):void)|null Se invoca antes de registrar; puede lanzar. */
    public $beforePublish = null;

    public function __construct()
    {
        // Sin Redis.
    }

    public function publish(AuditEvent $event): string
    {
        if ($this->beforePublish !== null) {
            ($this->beforePublish)($event, $this);
        }
        $this->published[] = $event;

        return $event->eventId;
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        $this->deadLetters[] = $event;

        return '1700000000000-0';
    }

    /**
     * @return array<int,AuditEvent>
     */
    public function ofType(string $eventType): array
    {
        return array_values(array_filter(
            $this->published,
            static fn (AuditEvent $event): bool => $event->eventType === $eventType
        ));
    }
}
