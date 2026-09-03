<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Exceptions\HttpResponseException;
use Core\Logger;
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

        $stateStore = null;
        $jobStore = null;
        $event = null;

        try {
            $originalEventId = (string) ($original['event_id'] ?? '');
            $event = AuditEvent::create(
                eventType: (string) ($original['event_type'] ?? ''),
                auditId: isset($original['audit_id']) && is_string($original['audit_id']) ? $original['audit_id'] : null,
                jobId: isset($original['job_id']) && is_string($original['job_id']) ? $original['job_id'] : null,
                documentId: isset($original['document_id']) && is_string($original['document_id']) ? $original['document_id'] : null,
                payload: is_array($original['payload'] ?? null) ? $original['payload'] : [],
                parentEventId: AuditEvent::isUuidV4($originalEventId) ? $originalEventId : null,
            );

            // QUAL-018: Reabrir auditoría en Redis de forma coordinada y fail-closed antes de republicar
            if ($event->auditId !== null) {
                $stateStore = $this->buildStateStore();
                $reopened = $stateStore->reopenAuditForReprocess($event->auditId, $event->eventId);

                if (!$reopened) {
                    $currentAudit = $stateStore->getAudit($event->auditId);
                    $currentStatus = is_array($currentAudit) ? (string) ($currentAudit['status'] ?? 'desconocido') : 'inexistente';
                    Response::error("Auditoría no elegible para reproceso (estado: '{$currentStatus}')", 409);
                }

                if ($event->jobId !== null) {
                    $jobStore = $this->buildJobStore();
                    $jobReopened = $jobStore->reopenAuditInJob($event->jobId, $event->auditId, $event->eventId);
                    if (!$jobReopened) {
                        // QUAL-001: Compensación atómica con reintentos al fallar coordinación con job
                        $stateReverted = false;
                        for ($attempt = 1; $attempt <= 3; $attempt++) {
                            try {
                                if ($stateStore->revertReprocess($event->auditId, 'Reapertura revertida: fallo de coordinación con job batch')) {
                                    $stateReverted = true;
                                    break;
                                }
                            } catch (\Throwable) {
                                usleep(10000);
                            }
                        }
                        if (!$stateReverted) {
                            Logger::critical('AuditDlqController: compensación de reapertura falló — auditoría puede quedar en processing', [
                                'audit_id' => $event->auditId,
                                'job_id' => $event->jobId,
                            ]);
                        }
                        Response::error('No se pudo reabrir la auditoría en el job batch para reproceso', 409);
                    }
                }
            }

            if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
                $this->buildPersistenceQueue()->reprocess($event);
            } else {
                $this->buildEventPublisher()->publish($event);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // QUAL-001: Compensación transaccional robusta con verificación de retornos y reintentos
            $reconciliationFailed = false;
            if ($event !== null && $event->auditId !== null && $stateStore !== null) {
                $stateReverted = false;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        if ($stateStore->revertReprocess($event->auditId, $e->getMessage())) {
                            $stateReverted = true;
                            break;
                        }
                    } catch (\Throwable) {
                        usleep(10000);
                    }
                }

                $jobReverted = true;
                if ($event->jobId !== null && $jobStore !== null) {
                    $jobReverted = false;
                    for ($attempt = 1; $attempt <= 3; $attempt++) {
                        try {
                            if ($jobStore->revertAuditReprocessInJob($event->jobId, $event->auditId)) {
                                $jobReverted = true;
                                break;
                            }
                        } catch (\Throwable) {
                            usleep(10000);
                        }
                    }
                }

                if (!$stateReverted || !$jobReverted) {
                    $reconciliationFailed = true;
                    $recorded = false;
                    try {
                        $recorded = $stateStore->recordFailedReconciliation($event->auditId, [
                            'event_id' => $event->eventId,
                            'job_id' => $event->jobId,
                            'error' => $e->getMessage(),
                            'state_reverted' => $stateReverted,
                            'job_reverted' => $jobReverted,
                            'failed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                        ]);
                    } catch (\Throwable) {
                        // Best-effort — Redis ya está degradado
                    }
                    if (!$recorded) {
                        Logger::critical('AuditDlqController: reconciliación fallida NO registrada — requiere inspección manual', [
                            'audit_id' => $event->auditId,
                            'job_id' => $event->jobId,
                            'state_reverted' => $stateReverted,
                            'job_reverted' => $jobReverted,
                        ]);
                    }
                }
            }

            Logger::error('AuditDlqController: reproceso falló post-reapertura, compensación ejecutada', [
                'event_id' => $event?->eventId,
                'audit_id' => $event?->auditId,
                'job_id' => $event?->jobId,
                'error' => $e->getMessage(),
                'reconciliation_failed' => $reconciliationFailed,
            ]);
            Response::error('No se pudo reprocesar el evento DLQ', 503);
        }

        Response::success([
            'stream_id' => $streamId,
            'reprocessed_event_id' => $event->eventId,
            'original_event_id' => $originalEventId,
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

    protected function buildStateStore(): AuditStateStore
    {
        return new AuditStateStore($this->buildRedisClient());
    }

    protected function buildJobStore(): BatchJobStore
    {
        return new BatchJobStore($this->buildRedisClient());
    }
}
