<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit\Pipeline\AuditEvent;
use Core\RedisClient;
use Core\Response;

final class AuditFlowController extends Controller
{
    private const MAX_DURATION_SEC = 30;
    private const KEEPALIVE_SEC = 5;
    private const READ_BLOCK_MS = 1000;
    private const READ_COUNT = 10;
    private const STREAM_NAME = 'audit.telemetry';

    public function stream(string $id): void
    {
        if (!AuditEvent::isUuidV4($id)) {
            Response::error('Identificador invalido', 422);
        }

        $redis = RedisClient::getInstance();
        if (!$redis->isAvailable()) {
            Response::error('Redis no disponible', 503);
        }

        $isAudit = $redis->get("audit:{$id}:state") !== null;
        $isJob = !$isAudit && $redis->get("job:{$id}:state") !== null;

        if (!$isAudit && !$isJob) {
            Response::error('Identificador no encontrado o expirado', 404);
        }

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        set_time_limit(self::MAX_DURATION_SEC + 5);
        $this->sendEvent('connected', [
            'id' => $id,
            'type' => $isJob ? 'job' : 'audit',
        ]);

        $lastEventId = self::lastEventId();

        $startedAt = hrtime(true);
        $lastKeepAlive = hrtime(true);

        while (!connection_aborted()) {
            if (self::elapsedSeconds($startedAt) >= self::MAX_DURATION_SEC) {
                $this->sendEvent('timeout', ['reason' => 'max_duration', 'reconnect_ms' => 1000]);
                break;
            }

            if (self::elapsedSeconds($lastKeepAlive) >= self::KEEPALIVE_SEC) {
                $this->sendKeepAlive();
                $lastKeepAlive = hrtime(true);
            }

            $messages = $redis->xRead([self::STREAM_NAME => $lastEventId], self::READ_COUNT, self::READ_BLOCK_MS);
            foreach ($messages as $message) {
                $lastEventId = (string) $message['id'];
                $eventRaw = $message['fields']['event'] ?? null;
                if (!is_string($eventRaw) || $eventRaw === '') {
                    continue;
                }

                $eventData = json_decode($eventRaw, true);
                if (!is_array($eventData) || !self::eventMatchesTarget($eventData, $id, $isJob)) {
                    continue;
                }

                $this->sendEvent('telemetry', $eventData, $lastEventId);
                $lastKeepAlive = hrtime(true);
            }
        }
    }

    /**
     * @param array<string,mixed> $eventData
     */
    private static function eventMatchesTarget(array $eventData, string $id, bool $isJob): bool
    {
        if ($isJob) {
            return ($eventData['job_id'] ?? '') === $id;
        }
        return ($eventData['audit_id'] ?? '') === $id;
    }

    private static function elapsedSeconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e9;
    }

    private static function lastEventId(): string
    {
        $lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '0-0';
        if (!is_string($lastEventId)) {
            return '0-0';
        }

        $lastEventId = trim($lastEventId);

        return preg_match('/^\d+-\d+$/', $lastEventId) === 1 ? $lastEventId : '0-0';
    }

    private function sendEvent(string $event, array|string $data, ?string $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        echo "event: {$event}\n";
        echo 'data: ' . ($payload === false ? '{}' : $payload) . "\n\n";
        $this->flush();
    }

    private function sendKeepAlive(): void
    {
        echo ": keepalive\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
