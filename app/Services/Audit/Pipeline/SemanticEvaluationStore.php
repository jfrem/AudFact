<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\SemanticBatchContract;
use App\Services\Audit\SemanticMatchPurpose;
use Core\RedisClient;

final class SemanticEvaluationStore
{
    public const PLANS = 'audit:semantic:v2:plans';

    public function __construct(private readonly RedisClient $redis)
    {
    }

    public static function canonical(array $value): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            return array_map($sort, $value);
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function buildPlan(array $snapshot, array $candidates): array
    {
        $source = AuditEvent::fromArray($snapshot['source_event']);
        if ($source->auditId === null || $source->documentId === null || $candidates === []) {
            throw new \InvalidArgumentException('semantic_plan_identity_invalid');
        }
        $fingerprint = hash('sha256', self::canonical($snapshot));
        $id = hash('sha256', self::canonical([$source->auditId, $source->documentId, $fingerprint]));
        $plan = ['schema_version' => 2, 'plan_id' => $id, 'generation' => 1, 'audit_id' => $source->auditId,
            'document_id' => $source->documentId, 'source_event' => $source->toArray(), 'snapshot' => $snapshot,
            'versions' => $snapshot['versions'], 'source_fingerprint' => $fingerprint, 'state' => SemanticPlanState::WAITING->value,
            'pairs' => [], 'rows' => [], 'requests' => [], 'scheduled_pairs' => [], 'revision' => 0, 'wave' => 0];
        foreach ($candidates as $candidate) {
            $purpose = SemanticMatchPurpose::from($candidate['purpose']);
            $schemaHash = hash('sha256', self::canonical(SemanticBatchContract::schema($purpose)));
            $cacheHash = hash('sha256', self::canonical([
                $candidate['expected'], $candidate['actual'], $candidate['context'], $purpose->value,
                $snapshot['versions'], SemanticBatchContract::VERSION, $schemaHash,
            ]));
            $candidate['cache_hash'] = $cacheHash;
            $candidate['state'] = 'unresolved';
            $raw = $this->redis->get(self::cacheKey($plan, $candidate));
            if ($raw !== null) {
                $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!SemanticFlightStore::validResult($result)) {
                    throw new \RuntimeException('semantic_cache_invalid');
                }
                $candidate['state'] = 'resolved';
                $candidate['result'] = $result + ['source' => 'cache'];
            }
            $plan['pairs'][$candidate['pair_id']] = $candidate;
            foreach ($candidate['row_ids'] as $row) {
                $plan['rows'][$row] ??= ['row_id' => $row, 'pair_ids' => [], 'cursor' => 0, 'state' => 'pending'];
                $plan['rows'][$row]['pair_ids'][] = $candidate['pair_id'];
            }
        }
        return self::advance($plan);
    }

    public static function key(string $auditId, string $documentId, string $planId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $planId) !== 1) {
            throw new \InvalidArgumentException('semantic_plan_id_invalid');
        }
        return "audit:semantic:v2:{$auditId}:{$documentId}:{$planId}";
    }

    public function get(string $auditId, string $documentId, string $planId): array
    {
        $raw = $this->redis->eval('return redis.call("HGET",KEYS[1],"record")', [self::key($auditId, $documentId, $planId)]);
        if (!is_string($raw)) {
            throw new \RuntimeException('semantic_plan_missing');
        }
        $plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        SemanticPlanState::from($plan['state']);
        return $plan;
    }

    public static function advance(array $plan): array
    {
        $pending = false;
        foreach ($plan['rows'] as &$row) {
            if ($row['state'] !== 'pending') {
                continue;
            }
            while (isset($row['pair_ids'][$row['cursor']])) {
                $pair = $plan['pairs'][$row['pair_ids'][$row['cursor']]];
                if ($pair['state'] !== 'resolved') {
                    $pending = true;
                    break;
                }
                if ($pair['result']['is_match']) {
                    $row['state'] = 'semantic_match';
                    break;
                }
                $row['cursor']++;
            }
            if ($row['state'] === 'pending' && !isset($row['pair_ids'][$row['cursor']])) {
                $row['state'] = 'exhausted';
            }
        }
        unset($row);
        if (!$pending) {
            $plan['state'] = SemanticPlanState::READY->value;
        }
        return $plan;
    }

    public static function waves(array $plan): array
    {
        $groups = [];
        foreach ($plan['rows'] as $row) {
            if ($row['state'] !== 'pending') {
                continue;
            }
            $pair = $plan['pairs'][$row['pair_ids'][$row['cursor']]];
            $key = $pair['purpose'] . ':' . hash('sha256', (string) ($pair['context']['document_context'] ?? ''));
            $groups[$key][$pair['pair_id']] = $pair;
        }
        $waves = [];
        foreach ($groups as $pairs) {
            foreach (array_chunk(array_values($pairs), AuditExecutionConfig::MAX_PAIRS) as $chunk) {
                $waves[] = $chunk;
            }
        }
        return $waves;
    }

    public static function cacheKey(array $plan, array $pair): string
    {
        return 'semantic:match:v6:' . hash('sha256', $plan['versions']['model']) . ':' . $pair['purpose'] . ':' . $pair['cache_hash'];
    }

    public static function results(array $plan): array
    {
        $out = [];
        foreach ($plan['pairs'] as $pair) {
            if ($pair['state'] === 'resolved') {
                $out[$pair['pair_id']] = $pair['result'];
            }
        }
        return $out;
    }

    public static function events(array &$plan): array
    {
        $parent = AuditEvent::fromArray($plan['source_event']);
        $payload = ['schema_version' => 2, 'plan_id' => $plan['plan_id'], 'generation' => $plan['generation'],
            'source_fingerprint' => $plan['source_fingerprint'], 'snapshot_ref' => self::key($plan['audit_id'], $plan['document_id'], $plan['plan_id'])];
        if ($plan['state'] === SemanticPlanState::READY->value) {
            if (isset($plan['continuation_event'])) {
                return [];
            }
            $event = self::stableEvent($parent, AuditEvent::TYPE_SEMANTIC_RESOLVED, $payload + ['state' => 'ready'], $plan['plan_id'] . ':ready');
            $plan['continuation_event'] = $event->toArray();
            return [$event];
        }
        $events = [];
        foreach (self::waves($plan) as $pairs) {
            $pairs = array_values(array_filter($pairs, static fn(array $pair): bool => !isset($plan['scheduled_pairs'][$pair['pair_id']])));
            if ($pairs === []) {
                continue;
            }
            foreach ($pairs as $pair) {
                $plan['scheduled_pairs'][$pair['pair_id']] = $plan['wave'];
            }
            $events[] = self::stableEvent($parent, AuditEvent::TYPE_SEMANTIC_REQUESTED, $payload + [
                'wave' => $plan['wave'], 'purpose' => $pairs[0]['purpose'], 'pair_ids' => array_column($pairs, 'pair_id'),
            ], $plan['plan_id'] . ':' . $plan['generation'] . ':' . $plan['wave'] . ':' . implode(':', array_column($pairs, 'pair_id')));
        }
        if ($events !== []) {
            $plan['wave']++;
        }
        return $events;
    }

    private static function stableEvent(AuditEvent $parent, string $type, array $payload, string $identity): AuditEvent
    {
        $record = $parent->followUp($type, $payload, $parent->documentId)->toArray();
        $record['event_id'] = AuditTaskEffects::uuid($identity);
        $record['timestamp'] = $parent->toArray()['timestamp'];
        return AuditEvent::fromArray($record);
    }
}
