<?php
declare(strict_types=1);

namespace App\Services\Audit\Telemetry;

use Core\Logger;
use Core\RedisClient;
use DateTimeImmutable;
use DateTimeZone;

final class TelemetryPublisher
{
    private const STREAM = 'audit.telemetry';
    private const MAXLEN = 1000;

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function started(
        string $auditId,
        string $nodeId,
        ?string $documentId = null,
        ?string $disDetNro = null,
        array $meta = [],
        ?string $jobId = null
    ): void {
        $this->emit($auditId, $nodeId, 'started', $documentId, 'running', $disDetNro, $meta, $jobId);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function completed(
        string $auditId,
        string $nodeId,
        int $durationMs,
        ?string $documentId = null,
        ?string $disDetNro = null,
        array $meta = [],
        ?string $jobId = null
    ): void {
        $this->emit(
            $auditId,
            $nodeId,
            'completed',
            $documentId,
            'completed',
            $disDetNro,
            self::withDuration($meta, $durationMs),
            $jobId
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function failed(
        string $auditId,
        string $nodeId,
        int $durationMs,
        ?string $documentId = null,
        ?string $disDetNro = null,
        array $meta = [],
        ?string $jobId = null
    ): void {
        $this->emit(
            $auditId,
            $nodeId,
            'failed',
            $documentId,
            'failed',
            $disDetNro,
            self::withDuration($meta, $durationMs),
            $jobId
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function rejected(
        string $auditId,
        string $nodeId,
        int $durationMs,
        ?string $documentId = null,
        ?string $disDetNro = null,
        array $meta = [],
        ?string $jobId = null
    ): void {
        $this->emit(
            $auditId,
            $nodeId,
            'rejected',
            $documentId,
            'rejected',
            $disDetNro,
            self::withDuration($meta, $durationMs),
            $jobId
        );
    }

    /**
     * Emite un evento de telemetría al stream de visualización.
     * Best-effort: nunca propaga excepciones al caller.
     *
     * @param array<string, mixed> $meta
     */
    public function emit(
        string $auditId,
        string $nodeId,
        string $eventType,
        ?string $documentId = null,
        ?string $status = null,
        ?string $disDetNro = null,
        array $meta = [],
        ?string $jobId = null
    ): void {
        try {
            $payload = json_encode(array_filter([
                'audit_id'    => $auditId,
                'job_id'      => $jobId,
                'dis_det_nro' => $disDetNro,
                'document_id' => $documentId,
                'node_id'     => $nodeId,
                'event_type'  => $eventType,
                'status'      => $status,
                'timestamp'   => self::nowUtc(),
                'meta'        => $meta !== [] ? $meta : null,
            ], fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);

            if ($payload === false) {
                return;
            }

            $this->redis->xAdd(self::STREAM, ['event' => $payload], self::MAXLEN);
        } catch (\Throwable $e) {
            Logger::warning('TelemetryPublisher: no se pudo emitir evento', [
                'audit_id' => $auditId,
                'node_id'  => $nodeId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function withDuration(array $meta, int $durationMs): array
    {
        $meta['duration_ms'] = max(0, $durationMs);

        return $meta;
    }

    private static function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
