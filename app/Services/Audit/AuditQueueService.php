<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\RedisClient;
use Core\Logger;

class AuditQueueService
{
    private const QUEUE_KEY = 'audit:queue';
    private const JOB_PREFIX = 'audit:job:';
    private const JOB_TTL = 86400;

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

        $this->redis->set(
            self::JOB_PREFIX . $jobId,
            json_encode($job, JSON_UNESCAPED_UNICODE),
            self::JOB_TTL
        );

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

    public function updateJob(string $jobId, string $status, array $progress = [], ?array $result = null, ?string $error = null): bool
    {
        $key = self::JOB_PREFIX . $jobId;
        $ttl = self::JOB_TTL;

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

            Logger::error('AuditQueueService: eval Lua falló (no es NOSCRIPT)', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    public function queueDepth(): ?int
    {
        if (!$this->redis->isAvailable()) {
            return null;
        }
        return $this->redis->llen(self::QUEUE_KEY);
    }

    private function generateJobId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
