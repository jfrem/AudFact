<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Corte con inventario durable y replay de entradas originales. No consulta FDV ni IA. */
final class AuditExecutionMigration
{
    private AuditExecutionInspector $inspector;

    public function __construct(private readonly RedisClient $redis, private readonly AuditExecutionConfig $config)
    {
        $this->inspector = new AuditExecutionInspector($redis);
    }

    private static function key(string $runId): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $runId) !== 1) {
            throw new \InvalidArgumentException('migration_run_id_invalid');
        }
        return 'audit:migration:v2:' . $runId;
    }

    public function inventory(): array
    {
        $snapshot = $this->inspector->snapshot();
        $snapshot['sources'] = [];
        $snapshot['document_ids'] = [];
        foreach ($this->inspector->records('audit:*:state') as $stored) {
            $audit = $stored['value'];
            if (AuditStateStore::isTerminalStatus($audit['status'])) {
                continue;
            }
            $ids = array_keys($audit['documents'] ?? []);
            sort($ids, SORT_STRING);
            $snapshot['document_ids'][$audit['audit_id']] = $ids;
        }
        $streams = [AuditEventPublisher::STREAM_INBOX_PRIORITY, AuditEventPublisher::STREAM_INBOX_BATCH,
            AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, AuditEventPublisher::STREAM_DOCUMENTS_BATCH];
        foreach ($streams as $stream) {
            $cursor = '-';
            do {
                $entries = $this->redis->eval('if redis.call("EXISTS",KEYS[1])==0 then return {} end; return redis.call("XRANGE",KEYS[1],ARGV[1],"+","COUNT",10)', [$stream], [$cursor]);
                foreach ($entries as [$id, $fields]) {
                    $cursor = '(' . $id;
                    $values = [];
                    for ($i = 0; $i < count($fields); $i += 2) {
                        $values[$fields[$i]] = $fields[$i + 1];
                    }
                    $event = AuditEvent::fromArray(json_decode($values['event'] ?? '', true, 512, JSON_THROW_ON_ERROR));
                    if ($event->auditId !== null && isset($snapshot['audits'][$event->auditId])) {
                        $snapshot['sources'][$event->eventId] = $this->sourceReference($event, ['stream' => $stream, 'stream_id' => $id]);
                    }
                }
            } while (count($entries) === 10);
        }
        foreach ($this->inspector->records('audit:task:v2:*', true) as $key => $stored) {
            $task = new AuditTask($stored['value']);
            if (isset($snapshot['audits'][$task->record['audit_id']])) {
                $snapshot['sources'][$task->event()->eventId] = $this->sourceReference($task->event(), ['task_key' => $key]);
            }
        }
        foreach ($this->inspector->records('audit:semantic:v2:*', true) as $key => $stored) {
            $plan = $stored['value'];
            if (isset($snapshot['audits'][$plan['audit_id']])) {
                $event = AuditEvent::fromArray($plan['source_event']);
                $snapshot['sources'][$event->eventId] = $this->sourceReference($event, ['plan_key' => $key]);
            }
        }
        foreach ($this->inspector->records('audit:outbox:v2:*', true) as $key => $stored) {
            $event = AuditEvent::fromArray($stored['value']['event']);
            if ($event->auditId !== null && isset($snapshot['audits'][$event->auditId])) {
                // Conservar el locator de entrega si el evento ya está en un stream.
                $snapshot['sources'][$event->eventId] ??= $this->sourceReference($event, ['outbox_key' => $key]);
            }
        }
        return $snapshot;
    }

    public function migrate(string $runId, bool $apply): array
    {
        $key = self::key($runId);
        $manifest = $this->load($key) ?? ['schema_version' => 2, 'run_id' => $runId, 'state' => 'inventoried',
            'baseline' => $this->inventory()];
        $this->assertSources($manifest['baseline']);
        if (!$apply) {
            return $this->summary($manifest) + ['dry_run' => true];
        }
        $this->assertPaused();
        if ($manifest['state'] === 'rolled_back') {
            throw new \RuntimeException('migration_run_already_rolled_back');
        }
        $manifest['baseline'] = $this->archive($key, $manifest['baseline']);
        $this->save($key, $manifest);
        $this->protect($manifest['baseline']);
        // El import requiere marcador 2; todavía no hay consumidores ejecutando.
        $this->redis->eval('return redis.call("SET",KEYS[1],"2")', ['audit:execution:version']);
        (new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2, $this->config->maxQueued)))->importPending();
        $this->recoverAcknowledgedContinuations($key, $manifest['baseline']);
        $this->inspector->reconcile();
        $manifest['state'] = 'migrated';
        $this->save($key, $manifest);
        return $this->verify($runId);
    }

    public function rollback(string $runId, bool $apply): array
    {
        $key = self::key($runId);
        $manifest = $this->load($key);
        if ($manifest === null) {
            throw new \RuntimeException('migration_manifest_missing');
        }
        if (!$apply) {
            $current = $this->inventory();
            return $this->summary($manifest) + ['dry_run' => true, 'replays' => iterator_count($this->replays($current))];
        }
        $this->assertPaused();
        (new AuditExecutionMaintenance($this->redis, $this->config))->flush();
        // Conservar un inventario estable para repetición tras interrupción.
        if (!isset($manifest['rollback_inventory'])) {
            $current = $this->inventory();
            $this->assertSources($current);
            $manifest['rollback_inventory'] = $this->archive($key . ':rollback', $current);
        }
        $this->save($key, $manifest);
        foreach ($this->replays($manifest['rollback_inventory']) as $identity => $event) {
            if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
                (new AuditPersistenceQueue($this->redis))->enqueue($event);
                $receiptKey = $key . ':replay:' . hash('sha256', $identity);
                $this->redis->eval('redis.call("SET",KEYS[1],"persistence_queue"); return redis.call("SADD",KEYS[2],ARGV[1])',
                    [$receiptKey, $key . ':objects'], [$receiptKey]);
                continue;
            }
            $stream = AuditEventPublisher::streamForEvent($event);
            $this->redis->eval(self::REPLAY, [$key . ':replay:' . hash('sha256', $identity), $stream,
                AuditStateStore::auditKey((string) $event->auditId), 'audit:execution:paused', $key . ':objects'],
                [$event->toJson(), $event->documentId ?? '', $event->eventType === AuditEvent::TYPE_DOCUMENT_NORMALIZED ? 1 : 0,
                    $key . ':replay:' . hash('sha256', $identity)]);
        }
        foreach (['audit:task:v2:*', 'audit:semantic:v2:*'] as $pattern) {
            foreach ($this->inspector->records($pattern, true) as $recordKey => $stored) {
                $value = $stored['value'];
                if (!in_array($value['state'], ['completed', 'failed', 'consumed'], true)) {
                    $value['migration_disposition'] = 'rolled_back';
                    $value['retired_at_ms'] ??= (new FairAuditTaskQueue($this->redis, $this->config))->nowMs();
                    $this->redis->eval('if redis.call("GET",KEYS[2])~="1" then return redis.error_reply("execution_pause_required") end; return redis.call("HSET",KEYS[1],"record",ARGV[1])',
                        [$recordKey, 'audit:execution:paused'], [json_encode($value, JSON_THROW_ON_ERROR)]);
                }
            }
        }
        $this->inspector->reconcile();
        $manifest['state'] = 'rolled_back';
        $this->save($key, $manifest);
        $result = $this->verify($runId);
        $this->redis->eval('return redis.call("SET",KEYS[1],"1")', ['audit:execution:version']);
        return $result;
    }

    public function verify(string $runId): array
    {
        $manifest = $this->load(self::key($runId));
        if ($manifest === null) {
            throw new \RuntimeException('migration_manifest_missing');
        }
        $current = $this->inspector->snapshot();
        foreach (($manifest['baseline']['persistence']['slots'] ?? []) as $key => $slots) {
            if (($current['persistence']['slots'][$key] ?? null) !== $slots) {
                throw new \RuntimeException('migration_persistence_slots_changed');
            }
        }
        foreach ($manifest['baseline']['sources'] as $reference) {
            $this->readSource($reference);
        }
        foreach ($manifest['baseline']['terminal_hashes'] as $key => $hash) {
            if (($current['terminal_hashes'][$key] ?? null) !== $hash) {
                throw new \RuntimeException('migration_terminal_changed');
            }
        }
        foreach ($manifest['baseline']['jobs'] as $jobId => $baseline) {
            $job = (new BatchJobStore($this->redis))->getJob($jobId);
            if ($job === null || $job['total'] < $baseline['total'] || $job['done'] < $baseline['done'] || $job['failed'] < $baseline['failed']) {
                throw new \RuntimeException('migration_job_counter_lost');
            }
        }
        foreach ($manifest['baseline']['document_ids'] as $auditId => $ids) {
            $audit = (new AuditStateStore($this->redis))->getAudit($auditId);
            if ($audit === null || array_diff($ids, array_keys($audit['documents'] ?? [])) !== []) {
                throw new \RuntimeException('migration_document_identity_lost');
            }
        }
        if ($manifest['state'] === 'rolled_back') {
            foreach ($this->replays($manifest['rollback_inventory']) as $identity => $event) {
                if ($this->redis->get(self::key($runId) . ':replay:' . hash('sha256', $identity)) === null) {
                    throw new \RuntimeException('rollback_replay_missing');
                }
            }
        } else {
            $expected = count(array_filter($current['tasks'], static fn(array $task): bool => $task['migration_disposition'] !== 'rolled_back'));
            $actual = $this->redis->eval('return redis.call("SCARD",KEYS[1])', [FairAuditTaskQueue::ALL]);
            if ($actual !== $expected) {
                throw new \RuntimeException('migration_task_index_incomplete');
            }
        }
        return $this->summary($manifest) + ['verified' => true];
    }

    /** Confirmación explícita; no certifica por sí misma cuotas, corpus o backup. */
    public function accept(string $runId): array
    {
        $this->assertPaused();
        $result = $this->verify($runId);
        $key = self::key($runId);
        $manifest = $this->load($key);
        $manifest['accepted_at_ms'] ??= (new FairAuditTaskQueue($this->redis, $this->config))->nowMs();
        $this->save($key, $manifest);
        return $result + ['accepted' => true];
    }

    private function assertSources(array $inventory): void
    {
        iterator_count($this->replays($inventory));
    }

    /** El ACK histórico tampoco demuestra que una auditoría activa ya terminó. */
    private function recoverAcknowledgedContinuations(string $key, array $inventory): void
    {
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2, $this->config->maxQueued));
        foreach ($this->replays($inventory) as $identity => $event) {
            if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
                (new AuditPersistenceQueue($this->redis))->enqueue($event);
                continue;
            }
            $stage = null;
            foreach (AuditTaskStage::cases() as $candidate) {
                if (in_array($event->eventType, $candidate->eventTypes(), true)) {
                    $stage = $candidate;
                    break;
                }
            }
            if ($stage !== null) {
                $task = AuditTask::fromEvent($stage, $event);
                $existing = $queue->get($task->id());
                if ($existing !== null) {
                    if (($existing->record['migration_disposition'] ?? null) === 'rolled_back') {
                        throw new \RuntimeException('migration_retired_task_requires_new_generation');
                    }
                    continue;
                }
            } else {
                $group = match ($event->eventType) {
                    AuditEvent::TYPE_DOCUMENT_REGISTERED => AuditEventPublisher::GROUP_DOWNLOADERS,
                    AuditEvent::TYPE_DOCUMENT_EXTRACTED => AuditEventPublisher::GROUP_NORMALIZERS,
                    default => throw new \RuntimeException('migration_continuation_stage_unknown'),
                };
                $reference = $inventory['sources'][$event->eventId] ?? null;
                if (!is_array($reference) || !isset($reference['stream'], $reference['stream_id'])) {
                    throw new \RuntimeException('migration_continuation_delivery_missing');
                }
                $acked = $this->redis->eval('local groups=redis.call("XINFO","GROUPS",KEYS[1]); for _,g in ipairs(groups) do local name=nil; local last=nil; for i=1,#g,2 do if g[i]=="name" then name=g[i+1] elseif g[i]=="last-delivered-id" then last=g[i+1] end end; if name==ARGV[1] then local function parts(id) local a,b=string.match(id,"^(%d+)%-(%d+)$"); return tonumber(a),tonumber(b) end; local a,b=parts(ARGV[2]); local x,y=parts(last); if a>x or (a==x and b>y) then return 0 end; return #redis.call("XPENDING",KEYS[1],ARGV[1],ARGV[2],ARGV[2],1)==0 and 1 or 0 end end; return 0',
                    [$reference['stream']], [$group, $reference['stream_id']]);
                if ($acked !== 1) {
                    continue;
                }
            }
            $receipt = $key . ':cutover-replay:' . hash('sha256', $identity);
            $stream = AuditEventPublisher::streamForEvent($event);
            $id = $this->redis->eval(self::REPLAY, [$receipt, $stream, AuditStateStore::auditKey((string) $event->auditId),
                'audit:execution:paused', $key . ':objects'], [$event->toJson(), $event->documentId ?? '', 0, $receipt]);
            if ($stage !== null && !$queue->admit($task, $stream, AuditExecutionMaintenance::stages()[$stage->value]['group'], (string) $id)) {
                throw new \RuntimeException('migration_stage_capacity_exceeded');
            }
        }
    }

    /** Una entrada por etapa actual; never fabricar contexto a partir de una consulta nueva. */
    private function replays(array $inventory): \Generator
    {
        $sources = [];
        foreach ($inventory['sources'] as $reference) {
            $sources[$reference['audit_id']][$reference['document_id'] ?? 'root'][$reference['event_type']] = $reference;
        }
        foreach ($inventory['audits'] as $auditId => $metadata) {
            $audit = (new AuditStateStore($this->redis))->getAudit($auditId);
            if ($audit === null) {
                throw new \RuntimeException('migration_audit_missing');
            }
            if (AuditStateStore::isTerminalStatus($audit['status'])) {
                continue;
            }
            if (($audit['documents'] ?? []) === []) {
                $type = AuditEvent::TYPE_AUDIT_CREATED;
                $event = $sources[$auditId]['root'][$type] ?? null;
                if ($event === null) {
                    throw new \RuntimeException('migration_original_event_missing');
                }
                yield $auditId . ':root:' . $type => $this->readSource($event);
                continue;
            }
            foreach ($audit['documents'] as $documentId => $document) {
                if (($document['status'] ?? '') === 'evaluated') {
                    continue;
                }
                $types = match ($document['status'] ?? 'registered') {
                    'normalized' => [AuditEvent::TYPE_DOCUMENT_NORMALIZED],
                    'extracted' => [AuditEvent::TYPE_DOCUMENT_EXTRACTED],
                    'rejected' => [AuditEvent::TYPE_DOCUMENT_REJECTED],
                    default => [AuditEvent::TYPE_DOCUMENT_DOWNLOADED, AuditEvent::TYPE_DOCUMENT_REGISTERED],
                };
                $event = null;
                foreach ($types as $type) {
                    $event = $sources[$auditId][$documentId][$type] ?? null;
                    if ($event !== null) {
                        break;
                    }
                }
                if ($event === null) {
                    throw new \RuntimeException('migration_original_event_missing');
                }
                $event = $this->readSource($event);
                yield $auditId . ':' . $documentId . ':' . $event->eventType => $event;
            }
            if ((int) ($audit['docs_total'] ?? 0) > 0 && (int) ($audit['docs_evaluated'] ?? 0) === (int) $audit['docs_total']) {
                if (!is_array($audit['rules_evaluated_result'] ?? null)) {
                    throw new \RuntimeException('migration_consolidation_missing');
                }
                // La entrega SQL permanece en su scheduler original, cuyos slots no se tocan.
                $parent = reset($inventory['sources']);
                foreach ($inventory['sources'] as $source) {
                    if ($source['audit_id'] === $auditId) {
                        $parent = $source;
                        break;
                    }
                }
                if (!is_array($parent) || $parent['audit_id'] !== $auditId) {
                    throw new \RuntimeException('migration_consolidation_source_missing');
                }
                $event = $this->readSource($parent)->followUp(AuditEvent::TYPE_RULES_EVALUATED, $audit['rules_evaluated_result'], null);
                yield $auditId . ':root:' . AuditEvent::TYPE_RULES_EVALUATED => $event;
            }
        }
    }

    private function sourceReference(AuditEvent $event, array $pointer): array
    {
        return ['event_id' => $event->eventId, 'audit_id' => $event->auditId, 'document_id' => $event->documentId,
            'event_type' => $event->eventType, 'checksum' => hash('sha256', SemanticEvaluationStore::canonical($event->toArray()))] + $pointer;
    }

    private function readSource(array $reference): AuditEvent
    {
        if (isset($reference['archive_key'])) {
            $raw = $this->redis->get($reference['archive_key']);
            $record = $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } elseif (isset($reference['stream'])) {
            $entries = $this->redis->eval('return redis.call("XRANGE",KEYS[1],ARGV[1],ARGV[1])', [$reference['stream']], [$reference['stream_id']]);
            $fields = $entries[0][1] ?? [];
            $record = null;
            for ($i = 0; $i < count($fields); $i += 2) {
                if ($fields[$i] === 'event') {
                    $record = json_decode($fields[$i + 1], true, 512, JSON_THROW_ON_ERROR);
                }
            }
        } else {
            $raw = $this->redis->eval('return redis.call("HGET",KEYS[1],"record")', [$reference['task_key'] ?? $reference['plan_key'] ?? $reference['outbox_key']]);
            $value = $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $record = $value['original_event'] ?? $value['source_event'] ?? $value['event'] ?? null;
        }
        if (!is_array($record) || hash('sha256', SemanticEvaluationStore::canonical($record)) !== $reference['checksum']) {
            throw new \RuntimeException('migration_source_checksum_mismatch');
        }
        return AuditEvent::fromArray($record);
    }

    private function archive(string $key, array $inventory): array
    {
        foreach ($inventory['sources'] as &$reference) {
            $event = $this->readSource($reference);
            $reference['archive_key'] = $key . ':source:' . $event->eventId;
            $manifestKey = preg_replace('/:rollback$/D', '', $key);
            $this->redis->eval('local old=redis.call("GET",KEYS[1]); if old and old~=ARGV[1] then return redis.error_reply("migration_archive_conflict") end; redis.call("SET",KEYS[1],ARGV[1]); return redis.call("SADD",KEYS[2],ARGV[2])',
                [$reference['archive_key'], $manifestKey . ':objects'], [$event->toJson(), $reference['archive_key']]);
        }
        unset($reference);
        return $inventory;
    }

    private function protect(array $inventory): void
    {
        foreach (array_merge(array_column($inventory['audits'], 'key'), array_column($inventory['jobs'], 'key')) as $key) {
            $this->redis->eval('local raw=redis.call("GET",KEYS[1]); if not raw then return redis.error_reply("migration_state_missing") end; local r=cjson.decode(raw); r.execution_version=2; return redis.call("SET",KEYS[1],cjson.encode(r))', [$key]);
        }
        foreach ($inventory['audits'] as $auditId => $metadata) {
            $audit = (new AuditStateStore($this->redis))->getAudit($auditId);
            if (!empty($audit['dis_id'])) {
                $this->redis->eval('return redis.call("PERSIST",KEYS[1])', [BatchJobStore::auditReservationKey((string) $audit['dis_id'])]);
            }
        }
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->redis->scanKeys('batch:idem:*', $cursor, 100);
            foreach ($keys as $key) {
                $jobId = $this->redis->get($key);
                if ($jobId !== null && isset($inventory['jobs'][$jobId])) {
                    $this->redis->eval('return redis.call("PERSIST",KEYS[1])', [$key]);
                }
            }
        } while ($cursor !== '0');
    }

    private function assertPaused(): void
    {
        if ($this->redis->get('audit:execution:paused') !== '1' || $this->redis->get('audit:admission:paused') !== '1') {
            throw new \RuntimeException('migration_pause_required');
        }
    }

    private function load(string $key): ?array
    {
        $raw = $this->redis->get($key);
        return $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function save(string $key, array $manifest): void
    {
        $this->redis->eval('redis.call("SET",KEYS[1],ARGV[1]); return redis.call("SADD",KEYS[2],ARGV[2])',
            [$key, 'audit:migrations:v2:all'], [json_encode($manifest, JSON_THROW_ON_ERROR), $key]);
    }

    private function summary(array $manifest): array
    {
        return ['run_id' => $manifest['run_id'], 'state' => $manifest['state'], 'audits' => count($manifest['baseline']['audits']),
            'jobs' => count($manifest['baseline']['jobs']), 'sources' => count($manifest['baseline']['sources']),
            'terminal_hashes' => count($manifest['baseline']['terminal_hashes'])];
    }

    private const REPLAY = <<<'LUA'
        if redis.call('GET',KEYS[4])~='1' then return redis.error_reply('execution_pause_required') end
        local types={'string','stream','string','string','set'}; for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('rollback_key_type_invalid') end end
        local old=redis.call('GET',KEYS[1]); if old then redis.call('SADD',KEYS[5],ARGV[4]); return old end
        local raw=redis.call('GET',KEYS[3]); if not raw then return redis.error_reply('audit_state_missing') end
        local a=cjson.decode(raw)
        if a.status=='completed' or a.status=='failed' or a.status=='error' or a.status=='manual_review' then return 'terminal' end
        if ARGV[3]=='1' then
            local d=a.documents[ARGV[2]]; if not d then return redis.error_reply('document_state_missing') end
            d.policy_state=nil; d.semantic_plan_id=nil; d.source_fingerprint=nil; d.policy_generation=nil
            a.evaluation_revision=tonumber(a.evaluation_revision or 0)+1
            redis.call('SET',KEYS[3],cjson.encode(a))
        end
        local event=cjson.decode(ARGV[1]); event.parent_event_id=event.event_id
        local id=redis.call('XADD',KEYS[2],'*','event',cjson.encode(event)); redis.call('SET',KEYS[1],id); redis.call('SADD',KEYS[5],ARGV[4]); return id
    LUA;
}
