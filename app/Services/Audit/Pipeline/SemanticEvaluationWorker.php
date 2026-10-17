<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\GeminiGateway;
use App\Services\Audit\SemanticMatchJudge;
use Core\RedisClient;

final class SemanticEvaluationWorker extends AuditEventConsumer
{
    private GeminiGateway $gateway;
    private SemanticEvaluationStore $plans;
    private SemanticFlightStore $flights;
    private array $owned = [];

    public function __construct(?RedisClient $redis = null, ?GeminiGateway $gateway = null, string|AuditLane|null $lane = null,
        private readonly ?GeminiQuotaBudget $quota = null)
    {
        parent::__construct(redis: $redis, lane: $lane);
        $this->gateway = $gateway ?? GeminiGateway::create(lane: $this->laneEnum);
        $this->plans = new SemanticEvaluationStore($this->redis);
        $this->flights = new SemanticFlightStore($this->redis);
    }

    protected function streams(): array
    {
        return [AuditEventPublisher::STREAM_SEMANTIC_PRIORITY, AuditEventPublisher::STREAM_SEMANTIC_BATCH];
    }

    protected function group(): string
    {
        return AuditEventPublisher::GROUP_SEMANTIC;
    }

    protected function consumer(): string
    {
        return self::defaultConsumerName($this->group(), $this->laneEnum);
    }

    protected function eventTypes(): array
    {
        return [AuditEvent::TYPE_SEMANTIC_REQUESTED];
    }

    protected function handle(AuditEvent $event): void
    {
        throw new \LogicException('semantic_worker_requires_execution_v2');
    }

    public function taskStage(): ?AuditTaskStage
    {
        return AuditTaskStage::SEMANTIC;
    }

    public function prepareTask(AuditTask $task): array
    {
        $event = $task->event();
        $plan = $this->plans->get((string) $event->auditId, (string) $event->documentId, (string) $task->record['plan_id']);
        $effects = AuditTaskEffects::empty($task);
        if (in_array($plan['state'], [SemanticPlanState::CONSUMED->value, SemanticPlanState::FAILED->value], true)) {
            return $effects;
        }
        if ($plan['generation'] !== $task->record['generation'] || $plan['source_fingerprint'] !== ($event->payload['source_fingerprint'] ?? null)
            || $plan['versions']['model'] !== $this->gateway->model()) {
            throw new \DomainException('semantic_snapshot_version_mismatch');
        }
        $revision = $plan['revision'];
        $pairs = [];
        $unresolved = 0;
        foreach ($task->record['pair_ids'] as $id) {
            if (!isset($plan['pairs'][$id])) {
                throw new \DomainException('semantic_pair_unknown');
            }
            unset($plan['scheduled_pairs'][$id]);
            if ($plan['pairs'][$id]['state'] === 'resolved') {
                continue;
            }
            $unresolved++;
            $pair = $plan['pairs'][$id];
            $cacheKey = SemanticEvaluationStore::cacheKey($plan, $pair);
            $cached = $this->flights->read($cacheKey);
            if ($cached !== null) {
                $plan['pairs'][$id]['state'] = 'resolved';
                $plan['pairs'][$id]['result'] = $cached;
            } elseif ($this->flights->claim($task, $cacheKey)) {
                $this->owned[$cacheKey] = true;
                $pairs[] = $pair;
            }
        }
        if ($pairs !== []) {
            $gateway = $this->gateway->withTask($task, $this->quota ?? new GeminiQuotaBudget($this->redis), new FairAuditTaskQueue($this->redis, AuditExecutionConfig::fromEnv()));
            $judge = new SemanticMatchJudge($gateway, $this->redis);
            $pairs = array_slice($pairs, 0, AuditExecutionConfig::MAX_PAIRS);
            while (true) {
                try {
                    $response = $judge->evaluateBatch($pairs, ['audit_id' => $event->auditId, 'document_id' => $event->documentId,
                        'plan_id' => $plan['plan_id'], 'generation' => $plan['generation']]);
                    break;
                } catch (\DomainException $error) {
                    if ($error->getMessage() !== 'semantic_input_too_large' || count($pairs) === 1) {
                        throw $error;
                    }
                    $pairs = array_slice($pairs, 0, max(1, intdiv(count($pairs), 2)));
                }
            }
            foreach ($pairs as $pair) {
                $result = $response['results'][$pair['pair_id']];
                $this->flights->write($task, SemanticEvaluationStore::cacheKey($plan, $pair), $result);
                $plan['pairs'][$pair['pair_id']]['state'] = 'resolved';
                $plan['pairs'][$pair['pair_id']]['result'] = $result;
            }
            $metric = $response['request_metric'];
            if (is_string($metric['request_id'] ?? null)) {
                $plan['requests'][$metric['request_id']] = $metric;
            }
        } elseif ($unresolved === 0) {
            return $effects;
        } elseif ($plan['state'] === SemanticPlanState::WAITING->value && SemanticEvaluationStore::results($plan) === SemanticEvaluationStore::results($this->plans->get(
            (string) $event->auditId, (string) $event->documentId, (string) $task->record['plan_id']))) {
            throw new QuotaDeferredException((new FairAuditTaskQueue($this->redis, AuditExecutionConfig::fromEnv()))->nowMs() + 250, 'semantic_singleflight');
        }
        // Una re-preparación por CAS conserva los requests remotos ya validados.
        $artifact = (new FairAuditTaskQueue($this->redis, AuditExecutionConfig::fromEnv()))->get($task->id())?->record['stage_artifact'] ?? [];
        foreach (($artifact['responses'] ?? []) as $response) {
            $metric = $response['X-Audit-Metrics'] ?? [];
            if (is_string($metric['request_id'] ?? null)) {
                $plan['requests'][$metric['request_id']] = $metric;
            }
        }
        $plan = SemanticEvaluationStore::advance($plan);
        foreach (SemanticEvaluationStore::events($plan) as $next) {
            $effects['outbox'][] = AuditTaskEffects::event($task, $next, $next->eventId);
        }
        $effects['semantic_changes'][] = ['op' => 'resolve_pairs', 'plan_id' => $plan['plan_id'],
            'generation' => $plan['generation'], 'expected_revision' => $revision, 'data' => $plan];
        $effects['source_fingerprint'] = $plan['source_fingerprint'];
        return $effects;
    }

    public function afterTaskCommitted(AuditTask $task): void
    {
        $this->releaseOwned($task);
    }

    public function afterTaskDeferred(AuditTask $task): void
    {
        $this->releaseOwned($task);
    }

    private function releaseOwned(AuditTask $task): void
    {
        foreach (array_keys($this->owned) as $key) {
            $this->flights->release($task, $key);
        }
        $this->owned = [];
    }
}
