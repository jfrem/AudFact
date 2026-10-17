<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** La capacidad se comprueba antes de reservar identidades o crear jobs. */
final class AuditAdmissionGuard
{
    public function __construct(private readonly RedisClient $redis, private readonly AuditExecutionConfig $config)
    {
    }

    public function assertAccepting(): void
    {
        $this->config->assertProtocol($this->redis);
        if ($this->redis->get('audit:admission:paused') === '1' || $this->redis->get('audit:execution:paused') === '1') {
            throw new \RuntimeException('audit_capacity_unavailable', 503);
        }
        if ($this->config->version === 1) {
            return;
        }
        new GeminiQuotaBudget($this->redis);
        $now = (new FairAuditTaskQueue($this->redis, $this->config))->nowMs();
        $heartbeat = $this->redis->get('audit:execution:heartbeat');
        if ($heartbeat === null || !ctype_digit($heartbeat) || (int) $heartbeat > $now || $now - (int) $heartbeat > 10000
            || $this->redis->get('audit:execution:paused') === '1' || $this->atCapacity()) {
            throw new \RuntimeException('audit_capacity_unavailable', 503);
        }
    }

    public function atCapacity(): bool
    {
        return $this->stagesAtCapacity(AuditTaskStage::cases()) || $this->memoryAtCapacity();
    }

    /** El trabajo admitido puede drenar su cola, respetando capacidad aguas abajo. */
    public function orchestrationAtCapacity(): bool
    {
        $downstream = array_values(array_filter(
            AuditTaskStage::cases(),
            static fn(AuditTaskStage $stage): bool => $stage !== AuditTaskStage::ORCHESTRATION
        ));
        return $this->stagesAtCapacity($downstream) || $this->memoryAtCapacity();
    }

    /** @param list<AuditTaskStage> $stages */
    private function stagesAtCapacity(array $stages): bool
    {
        $metrics = (new FairAuditTaskQueue($this->redis, $this->config))->metrics();
        foreach ($stages as $stage) {
            $waiting = 0;
            foreach ($metrics[$stage->value] as $lane) {
                $waiting += $lane['queued'] + $lane['delayed'];
            }
            if ($waiting >= (int) floor($this->config->maxQueued * 0.8)) {
                return true;
            }
        }
        return false;
    }

    private function memoryAtCapacity(): bool
    {
        $memory = $this->redis->serverInfo('memory');
        if (!isset($memory['used_memory'], $memory['maxmemory']) || !ctype_digit((string) $memory['used_memory'])
            || !ctype_digit((string) $memory['maxmemory'])) {
            throw new \RuntimeException('redis_memory_metrics_invalid');
        }
        return (int) $memory['maxmemory'] > 0 && (int) $memory['used_memory'] >= (int) $memory['maxmemory'] * 0.8;
    }
}
