<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use Core\RedisClient;
use Core\Response;

class AuditDlqController extends Controller
{
    /**
     * Lista eventos recientes de la DLQ con metadata resumida.
     *
     * @return void
     */
    public function index(): void
    {
        $query = $this->validateQuery([
            'limit' => 'nullable|integer|min_value:1|max_value:100',
        ]);

        $limit = isset($query['limit']) && $query['limit'] !== '' ? (int) $query['limit'] : 20;
        try {
            $messages = $this->buildRedisClient()->xRange(AuditEventPublisher::dlqStream(), '-', '+', $limit);
        } catch (\Throwable $e) {
            Response::error('No se pudo consultar la DLQ', 503);
        }

        $items = [];
        foreach ($messages as $message) {
            $rawEvent = $message['fields']['event'] ?? null;
            if (!is_string($rawEvent) || $rawEvent === '') {
                continue;
            }

            $decoded = json_decode($rawEvent, true);
            if (!is_array($decoded)) {
                continue;
            }

            $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
            $items[] = [
                'stream_id' => (string) $message['id'],
                'event_id' => (string) ($decoded['event_id'] ?? ''),
                'audit_id' => $decoded['audit_id'] ?? null,
                'job_id' => $decoded['job_id'] ?? null,
                'document_id' => $decoded['document_id'] ?? null,
                'failed_event_type' => (string) ($payload['failed_event_type'] ?? ''),
                'failed_stage' => (string) ($payload['failed_stage'] ?? ''),
                'failed_stream' => (string) ($payload['failed_stream'] ?? ''),
                'attempts' => (int) ($payload['attempts'] ?? 0),
                'last_error_code' => (string) ($payload['last_error_code'] ?? ''),
                'last_error_message' => (string) ($payload['last_error_message'] ?? ''),
                'timestamp' => (string) ($decoded['timestamp'] ?? ''),
            ];
        }

        Response::success([
            'stream' => AuditEventPublisher::dlqStream(),
            'count' => count($items),
            'items' => $items,
        ], 'Eventos DLQ');
    }

    /**
     * Reprocesa un evento de DLQ republicando el evento original a su stream canónico.
     *
     * @return void
     */
    public function reprocess(): void
    {
        $data = $this->validate([
            'streamId' => 'required|string|max:255',
        ]);

        $streamId = trim((string) $data['streamId']);
        if ($streamId === '') {
            Response::error('streamId es requerido', 422);
        }

        $messages = $this->buildRedisClient()->xRange(AuditEventPublisher::dlqStream(), $streamId, $streamId, 1);
        if ($messages === []) {
            Response::error('No se encontró el evento solicitado en DLQ', 404);
        }

        $rawEvent = $messages[0]['fields']['event'] ?? null;
        if (!is_string($rawEvent) || $rawEvent === '') {
            Response::error('Evento DLQ inválido', 422);
        }

        $decoded = json_decode($rawEvent, true);
        if (!is_array($decoded)) {
            Response::error('Payload DLQ malformado', 422);
        }

        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
        $original = $payload['original_event'] ?? null;
        if (!is_array($original)) {
            Response::error('El evento DLQ no contiene original_event', 422);
        }

        try {
            $event = AuditEvent::fromArray($original);
            if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
                $this->buildPersistenceQueue()->reprocess($event);
            } else {
                $this->buildEventPublisher()->publish($event);
            }
        } catch (\Throwable $e) {
            Response::error('No se pudo reprocesar el evento DLQ', 503);
        }

        Response::success([
            'stream_id' => $streamId,
            'reprocessed_event_id' => $event->eventId,
            'event_type' => $event->eventType,
            'audit_id' => $event->auditId,
        ], 'Evento DLQ reprocesado');
    }

    protected function buildRedisClient(): RedisClient
    {
        return RedisClient::getInstance();
    }

    protected function buildEventPublisher(): AuditEventPublisher
    {
        return new AuditEventPublisher($this->buildRedisClient());
    }

    protected function buildPersistenceQueue(): AuditPersistenceQueue
    {
        return new AuditPersistenceQueue($this->buildRedisClient());
    }
}
