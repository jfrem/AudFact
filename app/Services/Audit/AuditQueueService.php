<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\RedisClient;
use Core\Logger;

/**
 * AuditQueueService — Cola de auditorías asíncronas con Redis Lists.
 *
 * Flujo:
 *   1. POST /audit/async → enqueue() → LPUSH audit:queue → 202 + jobId
 *   2. Worker CLI: dequeue() → BRPOP audit:queue → procesar → resultado a Redis + SQL
 *   3. GET /audit/jobs/{jobId} → getJobStatus() → estado actual
 *
 * @since 3.0
 */
class AuditQueueService
{
    private const QUEUE_KEY = 'audit:queue';
    private const JOB_PREFIX = 'audit:job:';
    private const JOB_TTL = 86400; // 24 horas

    /** Estados del job */
    public const STATUS_PENDING     = 'pending';
    public const STATUS_PROCESSING  = 'processing';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_INTERRUPTED = 'interrupted';

    private RedisClient $redis;

    public function __construct()
    {
        $this->redis = RedisClient::getInstance();
    }

    /**
     * Encola un batch de auditoría para procesamiento async.
     *
     * @param array $params Parámetros del batch: facNitSec, date, dateTo, limit
     * @return string|null jobId generado o null si Redis no disponible
     */
    public function enqueue(array $params): ?string
    {
        if (!$this->redis->isAvailable()) {
            Logger::error('AuditQueueService: Redis no disponible para encolar');
            return null;
        }

        $jobId = $this->generateJobId();

        $job = [
            'id'        => $jobId,
            'params'    => $params,
            'status'    => self::STATUS_PENDING,
            'createdAt' => date('c'),
            'result'    => null,
            'error'     => null,
            'progress'  => [
                'total'     => 0,
                'processed' => 0,
                'succeeded' => 0,
                'failed'    => 0,
            ],
        ];

        // Guardar metadata del job
        $this->redis->set(
            self::JOB_PREFIX . $jobId,
            json_encode($job, JSON_UNESCAPED_UNICODE),
            self::JOB_TTL
        );

        // Encolar para el worker
        $pushed = $this->redis->lpush(self::QUEUE_KEY, json_encode([
            'jobId'  => $jobId,
            'params' => $params,
        ], JSON_UNESCAPED_UNICODE));

        if ($pushed === null) {
            Logger::error('AuditQueueService: Fallo al encolar job', ['jobId' => $jobId]);
            $this->redis->del(self::JOB_PREFIX . $jobId);
            return null;
        }

        Logger::info('AuditQueueService: Job encolado', [
            'jobId'      => $jobId,
            'queueDepth' => $pushed,
        ]);

        return $jobId;
    }

    /**
     * Desencola el siguiente job (blocking con timeout).
     *
     * @param int $timeout Segundos de espera
     * @return array|null Datos del job o null si timeout
     */
    public function dequeue(int $timeout = 5): ?array
    {
        $raw = $this->redis->brpop(self::QUEUE_KEY, $timeout);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['jobId'])) {
            Logger::error('AuditQueueService: Mensaje de cola malformado', ['raw' => $raw]);
            return null;
        }

        return $data;
    }

    /**
     * Obtiene el estado de un job.
     */
    public function getJobStatus(string $jobId): ?array
    {
        try {
            $raw = $this->redis->get(self::JOB_PREFIX . $jobId);
        } catch (\Core\RedisUnavailableException $e) {
            Logger::warning('AuditQueueService: Redis no disponible para getJobStatus', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($raw === null) {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * Actualiza el estado y progreso de un job de forma atómica.
     *
     * Usa un script Lua Redis para evitar race conditions GET+SET (hallazgo M01).
     * Si el eval falla, recurre al fallback no-atómico con warning.
     */
    public function updateJob(string $jobId, string $status, array $progress = [], ?array $result = null, ?string $error = null): bool
    {
        $key = self::JOB_PREFIX . $jobId;
        $ttl = self::JOB_TTL;

        // Payload parcial de actualización
        $patch = ['status' => $status, 'updatedAt' => date('c')];
        if (!empty($progress)) {
            $patch['progress'] = $progress;
        }
        if ($result !== null) {
            $patch['result'] = $result;
        }
        if ($error !== null) {
            $patch['error'] = $error;
        }

        // Script Lua atómico: lee, mergea, escribe con TTL
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local patch = cjson.decode(ARGV[1])

for k, v in pairs(patch) do
    if k == 'progress' and type(job['progress']) == 'table' and type(v) == 'table' then
        for pk, pv in pairs(v) do
            job['progress'][pk] = pv
        end
    else
        job[k] = v
    end
end

redis.call('SET', KEYS[1], cjson.encode(job), 'EX', tonumber(ARGV[2]))
return 1
LUA;

        try {
            $evalResult = $this->redis->eval($lua, [$key], [$patch, $ttl]);
            if ($evalResult === 1) {
                return true;
            }
            // Job no existe en Redis
            return false;
        } catch (\Exception $e) {
            // REDIS-002 + NOSCRIPT resilience:
            // Si Redis se reinició, los scripts Lua cacheados se borran.
            // Detectar NOSCRIPT y reintentar con EVAL directo (re-carga automática).
            if (stripos($e->getMessage(), 'NOSCRIPT') !== false) {
                Logger::warning('AuditQueueService: NOSCRIPT detectado — reintentando con EVAL directo', [
                    'jobId' => $jobId,
                ]);
                try {
                    $retryResult = $this->redis->eval($lua, [$key], [$patch, $ttl]);
                    return $retryResult === 1;
                } catch (\Exception $retryEx) {
                    Logger::error('AuditQueueService: retry post-NOSCRIPT también falló', [
                        'jobId' => $jobId,
                        'error' => $retryEx->getMessage(),
                    ]);
                    throw $retryEx;
                }
            }

            // Error no-NOSCRIPT: propagar sin fallback no-atómico (REDIS-002).
            Logger::error('AuditQueueService: eval Lua falló (no es NOSCRIPT)', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    /**
     * Profundidad actual de la cola.
     *
     * @return int|null Número de jobs en espera, o null si Redis no disponible
     */
    public function queueDepth(): ?int
    {
        if (!$this->redis->isAvailable()) {
            return null;
        }
        return $this->redis->llen(self::QUEUE_KEY);
    }

    /**
     * Genera un jobId único.
     */
    private function generateJobId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
