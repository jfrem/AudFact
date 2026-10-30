<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Confirmación con fence y recibo durable reparable; un error no obliga a repetir HTTP. */
final class AuditTaskCommitter
{
    public const PENDING = 'audit:commits:v2:pending';
    public const OUTBOX = 'audit:outbox:v2:pending';

    public function __construct(private readonly RedisClient $redis)
    {
    }

    public function commit(array $lease, array $effects): string
    {
        $task = new AuditTask($lease);
        AuditTaskEffects::validate($effects, $task);
        $raw = $this->redis->get('audit:commit:v2:' . $task->id() . ':' . $task->record['fence']);
        if ($raw !== null) {
            $receipt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $effects = $this->receiptEffects($receipt);
            AuditTaskEffects::validate($effects, $task);
        }
        return $this->apply($task, $effects, false);
    }

    private function apply(AuditTask $task, array $effects, bool $repair): string
    {
        $receiptKey = 'audit:commit:v2:' . $task->id() . ':' . $task->record['fence'];
        $keys = array_merge(FairAuditTaskQueue::keys($task->record), [$receiptKey, self::PENDING, self::OUTBOX, SemanticEvaluationStore::PLANS,
            FairAuditTaskQueue::base($task->record['stage'], $task->record['lane']) . ':waiting',
            'audit:execution:refs:' . $task->record['audit_id']]);
        $outbox = [];
        foreach ($effects['outbox'] as $effect) {
            $keys[] = 'audit:outbox:v2:' . $effect['effect_id'];
            $effect['key_index'] = count($keys);
            $outbox[] = $effect;
        }
        $semantic = [];
        foreach ($effects['semantic_changes'] as $change) {
            $change['logical_key'] = SemanticEvaluationStore::key($task->record['audit_id'], (string) $task->record['document_id'], $change['plan_id']);
            $keys[] = $change['logical_key'];
            $change['key_index'] = count($keys);
            $keys[] = 'audit:semantic:v2:counts';
            $change['counts_key_index'] = count($keys);
            $semantic[] = $change;
        }
        $effects['outbox'] = $outbox;
        $effects['semantic_changes'] = $semantic;
        $result = $this->redis->eval(self::COMMIT, $keys, [json_encode($task->record, JSON_THROW_ON_ERROR),
            json_encode($effects, JSON_THROW_ON_ERROR), $repair ? 1 : 0]);
        if (!is_string($result) || !in_array($result, ['COMMITTED', 'ALREADY_COMMITTED', 'RETRY_PREPARE', 'STALE'], true)) {
            throw new \RuntimeException('task_commit_invalid');
        }
        return $result;
    }

    public function repair(int $limit = 100): int
    {
        $receipts = $this->redis->eval('return redis.call("SRANDMEMBER",KEYS[1],ARGV[1])', [self::PENDING], [$limit]);
        if (!is_array($receipts)) {
            throw new \RuntimeException('commit_index_invalid');
        }
        $count = 0;
        foreach ($receipts as $reference) {
            $raw = $this->redis->get('audit:commit:v2:' . $reference);
            if ($raw === null) {
                throw new \RuntimeException('commit_receipt_missing');
            }
            $receipt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $effects = $this->receiptEffects($receipt);
            $this->apply(new AuditTask($receipt['task']), $effects, true);
            $count++;
        }
        return $count;
    }

    private function receiptEffects(array $receipt): array
    {
        $effects = $receipt['effects'];
        foreach ($effects['outbox'] as &$effect) {
            unset($effect['key_index']);
        }
        unset($effect);
        foreach ($effects['semantic_changes'] as &$change) {
            unset($change['key_index'], $change['logical_key'], $change['counts_key_index']);
        }
        unset($change);
        return $effects;
    }

    private const COMMIT = <<<'LUA'
        local task=cjson.decode(ARGV[1]); local effects=cjson.decode(ARGV[2])
        local reference=task.task_id..':'..tostring(task.fence)
        local receiptRaw=redis.call('GET',KEYS[13]); local receipt=receiptRaw and cjson.decode(receiptRaw) or nil
        if receipt and receipt.state=='completed' then return 'ALREADY_COMMITTED' end
        if not receipt and redis.call('SCARD',KEYS[14])>0 then return 'RETRY_PREPARE' end
        local raw=redis.call('HGET',KEYS[1],'record'); if not raw then return redis.error_reply('task_record_missing') end
        local current=cjson.decode(raw); local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        if not receipt then
            if current.state~='active' or current.fence~=task.fence or current.lease_owner~=task.lease_owner or current.lease_until_ms<=now then return 'STALE' end
        elseif current.fence~=task.fence or (current.state~='active' and current.state~='committing' and current.state~='completed') then return redis.error_reply('commit_receipt_conflict') end
        if receipt and current.state=='completed' then receipt.state='completed'; redis.call('SET',KEYS[13],cjson.encode(receipt)); redis.call('SREM',KEYS[14],reference); return 'ALREADY_COMMITTED' end
        local auditRaw=redis.call('GET',KEYS[11]); if not auditRaw then return redis.error_reply('audit_state_missing') end
        local audit=cjson.decode(auditRaw); local terminal=audit.status=='completed' or audit.status=='manual_review' or audit.status=='error' or audit.status=='failed'
        local types={'hash','zset','list','set','hash','zset','zset','hash','set','string','string','hash','string','set','zset','set','zset','set'}
        for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; local expected=types[i] or 'hash'; if ty~='none' and ty~=expected then return redis.error_reply('commit_key_type_invalid') end end
        local cp=current.stage..':'..current.lane..':'
        for _,state in ipairs({'active','committing','completed'}) do local n=redis.call('HGET',KEYS[8],cp..state); if n and not tonumber(n) then return redis.error_reply('task_counter_invalid') end end
        local scopeCount=redis.call('HGET',KEYS[12],current.scope); if scopeCount and not tonumber(scopeCount) then return redis.error_reply('task_scope_counter_invalid') end
        if not receipt and effects.evaluation_base_revision~=cjson.null and effects.evaluation_base_revision~=tonumber(audit.evaluation_revision or 0) then return 'RETRY_PREPARE' end
        for _,effect in ipairs(effects.outbox) do local ty=redis.call('TYPE',KEYS[effect.key_index]).ok; if ty~='none' and ty~='hash' then return redis.error_reply('outbox_type_invalid') end end
        for _,change in ipairs(effects.semantic_changes) do
            local ty=redis.call('TYPE',KEYS[change.key_index]).ok; if ty~='none' and ty~='hash' then return redis.error_reply('semantic_type_invalid') end
            local existing=redis.call('HGET',KEYS[change.key_index],'record')
            if not receipt and change.op~='create_plan' then
                if not existing then return redis.error_reply('semantic_plan_missing') end
                local plan=cjson.decode(existing); if plan.revision~=change.expected_revision then return 'RETRY_PREPARE' end
            elseif not receipt and existing then
                return 'RETRY_PREPARE'
            end
        end
        if effects.source_fingerprint~=cjson.null and current.document_id~=cjson.null and (current.stage=='semantic' or current.original_event.event_type=='semantic_evaluation_resolved') then
            local document=audit.documents[current.document_id]
            if not document or document.source_fingerprint~=effects.source_fingerprint then return 'STALE' end
        end
        -- Validar y simular todas las transiciones antes de escribir el recibo.
        -- Redis Lua no revierte escrituras anteriores a un error de ejecución.
        local prepared=cjson.decode(auditRaw)
        prepared.documents=prepared.documents or {}; prepared.operation_receipts=prepared.operation_receipts or {}
        local ownedFailure=false
        if receipt and terminal then for _,op in ipairs(effects.state_transitions) do if op.op=='fail_audit' and audit.operation_receipts and audit.operation_receipts[op.operation_id] then ownedFailure=true end end end
        if not terminal then
            for _,op in ipairs(effects.state_transitions) do
                if not prepared.operation_receipts[op.operation_id] then
                    local document=op.target_id~=cjson.null and prepared.documents[op.target_id] or nil
                    if op.expected_status~=cjson.null and op.expected_status~=(document and document.status or prepared.status) then return 'RETRY_PREPARE' end
                    if op.op=='set_documents_total' then
                        if tonumber(prepared.docs_total or 0)>0 and prepared.docs_total~=op.data.total then return redis.error_reply('documents_total_conflict') end
                        prepared.docs_total=op.data.total
                    elseif op.op=='register_document' then prepared.documents[op.target_id]=document or op.data
                    elseif op.op=='mark_extracted' or op.op=='mark_rejected' or op.op=='mark_evaluated' or op.op=='patch_document' then
                        if not document then return redis.error_reply('document_state_missing') end
                    elseif op.op=='patch_audit_context' then for k,v in pairs(op.data) do prepared[k]=v end end
                end
            end
        end
        if not receipt then
            receipt={state='committing',task=task,effects=effects}
            redis.call('SADD',KEYS[14],reference); redis.call('SET',KEYS[13],cjson.encode(receipt))
        end
        if current.state=='active' then
            current.state='committing'; redis.call('HSET',KEYS[1],'record',cjson.encode(current))
            redis.call('HINCRBY',KEYS[8],cp..'active',-1); redis.call('HINCRBY',KEYS[8],cp..'committing',1)
        end
        if not terminal or ownedFailure then
            audit.documents=audit.documents or {}; audit.operation_receipts=audit.operation_receipts or {}
            for _,op in ipairs(effects.state_transitions) do
                if not audit.operation_receipts[op.operation_id] then
                    local data=op.data; local document=op.target_id~=cjson.null and audit.documents[op.target_id] or nil
                    if op.op=='start_audit' then audit.status='processing'; audit.started_at=audit.started_at or data.started_at
                    elseif op.op=='patch_audit_context' then for k,v in pairs(data) do audit[k]=v end
                    elseif op.op=='set_documents_total' then
                        if tonumber(audit.docs_total or 0)>0 and audit.docs_total~=data.total then return redis.error_reply('documents_total_conflict') end
                        audit.docs_total=data.total
                    elseif op.op=='register_document' then
                        if not document then audit.documents[op.target_id]=data end
                    elseif op.op=='patch_document' then for k,v in pairs(data) do document[k]=v end
                    elseif op.op=='mark_extracted' or op.op=='mark_rejected' or op.op=='mark_evaluated' then
                        if not document then return redis.error_reply('document_state_missing') end
                        if document.status~='evaluated' then
                            local status=(op.op=='mark_extracted') and 'extracted' or ((op.op=='mark_rejected') and 'rejected' or 'evaluated')
                            local counter=(op.op=='mark_extracted') and 'docs_extracted' or ((op.op=='mark_rejected') and 'docs_rejected' or 'docs_evaluated')
                            if document.status~=status then audit[counter]=tonumber(audit[counter] or 0)+1 end
                            for k,v in pairs(data) do document[k]=v end; document.status=status
                        end
                    elseif op.op=='store_rules_evaluation' then
                        if not audit.rules_evaluated_result then audit.rules_evaluated_result=data end
                    elseif op.op=='fail_audit' then
                        for k,v in pairs(data) do audit[k]=v end; audit.status='failed'; audit.completed_at=data.completed_at
                    elseif op.op~='protect_active_ttl' then return redis.error_reply('task_transition_unknown') end
                    audit.operation_receipts[op.operation_id]=true; audit.evaluation_revision=tonumber(audit.evaluation_revision or 0)+1
                end
            end
            audit.updated_at=current.original_event.timestamp
            audit.gemini_attempts=audit.gemini_attempts or {}
            for _,metric in ipairs(effects.attempt_metrics) do
                local previous=audit.gemini_attempts[metric.attempt_id]
                if not previous or previous.state~=metric.state then audit.evaluation_revision=tonumber(audit.evaluation_revision or 0)+1 end
                audit.gemini_attempts[metric.attempt_id]=metric
            end
            redis.call('SET',KEYS[11],cjson.encode(audit))
            for _,change in ipairs(effects.semantic_changes) do
                local plan=change.data; plan.updated_at_ms=now; local revision=change.expected_revision; if revision==cjson.null or revision==nil then revision=-1 end; plan.revision=revision+1
                local previousRaw=redis.call('HGET',KEYS[change.key_index],'record'); local previous=previousRaw and cjson.decode(previousRaw) or nil
                if not previous or previous.revision~=plan.revision then
                    if previous then redis.call('HINCRBY',KEYS[change.counts_key_index],previous.state,-1) end
                    redis.call('HINCRBY',KEYS[change.counts_key_index],plan.state,1)
                end
                redis.call('HSET',KEYS[change.key_index],'record',cjson.encode(plan)); redis.call('SADD',KEYS[16],change.logical_key)
                if plan.state=='waiting' or plan.state=='ready' or plan.state=='planned' then redis.call('SADD',KEYS[18],'plan:'..plan.plan_id)
                else redis.call('SREM',KEYS[18],'plan:'..plan.plan_id) end
            end
            for _,effect in ipairs(effects.outbox) do
                if redis.call('HGET',KEYS[effect.key_index],'state')~='sent' then
                    redis.call('HSETNX',KEYS[effect.key_index],'record',cjson.encode(effect)); redis.call('HSETNX',KEYS[effect.key_index],'state','pending')
                    redis.call('ZADD',KEYS[15],now,effect.effect_id)
                    redis.call('SADD',KEYS[18],'outbox:'..effect.effect_id)
                end
            end
        end
        current.state='completed'; current.completed_at_ms=now; current.lease_owner=cjson.null; current.lease_until_ms=cjson.null
        do local held=redis.call('HGET',KEYS[5],current.scope); local n=(held and string.match(held,'^%d+$') and #held<4) and tonumber(held) or 1; if n<=1 then redis.call('HDEL',KEYS[5],current.scope) else redis.call('HSET',KEYS[5],current.scope,n-1) end end; redis.call('ZREM',KEYS[6],current.task_id)
        redis.call('ZREM',KEYS[17],current.task_id)
        redis.call('SREM',KEYS[18],'task:'..current.task_id)
        redis.call('HINCRBY',KEYS[8],cp..'committing',-1); redis.call('HINCRBY',KEYS[8],cp..'completed',1)
        local remaining=redis.call('HINCRBY',KEYS[12],current.scope,-1)
        if remaining==0 then redis.call('HDEL',KEYS[12],current.scope); redis.call('LREM',KEYS[3],0,current.scope); redis.call('SREM',KEYS[4],current.scope) end
        redis.call('HSET',KEYS[1],'record',cjson.encode(current))
        receipt.state='completed'; redis.call('SET',KEYS[13],cjson.encode(receipt)); redis.call('SREM',KEYS[14],reference)
        return 'COMMITTED'
    LUA;
}
