<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskCommitter;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use App\Services\Audit\Pipeline\AuditTaskOutbox;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use Core\Env;
use Tests\Support\IsolatedAuditRedis;

// Herramienta de integración manual: solo admite un contenedor de prueba dedicado.
$mode = $argv[1] ?? '';
$runId = $argv[2] ?? '';
if (!in_array($mode, ['seed', 'verify'], true) || !AuditEvent::isUuidV4($runId)
    || preg_match('/^audfact-aof-fixture-[a-z0-9-]+$/D', (string) Env::get('REDIS_HOST', '')) !== 1) {
    throw new InvalidArgumentException('Dedicated AOF fixture and UUID run id required');
}
$redis = new IsolatedAuditRedis('integration:audit:' . $runId . ':');
$config = new AuditExecutionConfig(2);
$queue = new FairAuditTaskQueue($redis, $config);
$committer = new AuditTaskCommitter($redis);
$outbox = new AuditTaskOutbox($redis, new AuditPersistenceQueue($redis));
$quota = new GeminiQuotaBudget($redis, ['scope' => 'aof-fixture', 'model' => 'gemini-3.7-flash',
    'rpm' => 100, 'tpm' => 10000, 'rpd' => 1000, 'inflight' => 4]);
$stream = AuditEventPublisher::STREAM_DOCUMENTS_BATCH;
$manifestKey = 'aof-fixture:manifest';
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

if ($mode === 'seed') {
    $check($redis->get($manifestKey) === null, 'Fixture already seeded');
    $redis->eval('redis.call("SET",KEYS[1],"2"); return redis.call("XGROUP","CREATE",KEYS[2],"fixture","0","MKSTREAM")',
        ['audit:execution:version', $stream]);
    $taskIds = [];
    $auditIds = [];
    for ($i = 0; $i < 2; $i++) {
        $event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, AuditEvent::uuidV4(), documentId: 'synthetic');
        $auditIds[] = $event->auditId;
        $root = ['audit_id' => $event->auditId, 'status' => 'processing', 'execution_version' => 2,
            'documents' => [], 'docs_total' => 0, 'created_at' => $event->timestamp];
        $id = $redis->eval('redis.call("SET",KEYS[1],ARGV[1]); local id=redis.call("XADD",KEYS[2],"*","event",ARGV[2]); redis.call("XREADGROUP","GROUP","fixture","intake","COUNT",1,"STREAMS",KEYS[2],">"); return id',
            [AuditStateStore::auditKey($event->auditId), $stream], [json_encode($root, JSON_THROW_ON_ERROR), $event->toJson()]);
        $task = AuditTask::fromEvent(AuditTaskStage::EXTRACTION, $event);
        $check($queue->admit($task, $stream, 'fixture', $id), 'Admission failed');
        $taskIds[] = $task->id();
    }
    $active = $queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'before-crash');
    $check($queue->saveArtifact($active, ['responses' => ['synthetic-body' => ['value' => 'saved-response']]]), 'Artifact failed');
    $grant = $quota->reserve(['task' => $active->record, 'operation' => 'generate_content', 'tokens' => 100,
        'body_hash' => hash('sha256', 'synthetic-body')]);
    $quota->finish($grant['attempt_id'], ['promptTokenCount' => 100]);
    $committed = $queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'before-crash');
    $effects = AuditTaskEffects::empty($committed);
    $event = AuditTaskEffects::followUp($committed, AuditEvent::TYPE_DOCUMENT_EXTRACTED, ['synthetic' => true], 'synthetic', 'extracted');
    $effects['outbox'][] = AuditTaskEffects::event($committed, $event, 'extracted');
    $check($committer->commit($committed->record, $effects) === 'COMMITTED', 'Commit failed');
    $hashes = [];
    foreach ($taskIds as $id) {
        $hashes[$id] = hash('sha256', $redis->eval('return redis.call("HGET",KEYS[1],"record")', [FairAuditTaskQueue::taskKey($id)]));
    }
    $manifest = ['task_ids' => $taskIds, 'audit_ids' => $auditIds, 'hashes' => $hashes, 'active' => $active->record,
        'attempt_id' => $grant['attempt_id'], 'stream_length' => $redis->eval('return redis.call("XLEN",KEYS[1])', [$stream])];
    $redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$manifestKey], [json_encode($manifest, JSON_THROW_ON_ERROR)]);
    echo "Seeded: 2 admitted tasks, saved response, quota charge, commit receipt and pending outbox.\n";
    exit(0);
}

$manifest = json_decode($redis->get($manifestKey) ?? '', true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['hashes'] as $id => $hash) {
    $raw = $redis->eval('return redis.call("HGET",KEYS[1],"record")', [FairAuditTaskQueue::taskKey($id)]);
    $check(is_string($raw) && hash('sha256', $raw) === $hash, 'Task changed across Redis crash');
}
foreach ($manifest['audit_ids'] as $id) {
    $check($redis->eval('return redis.call("TTL",KEYS[1])', [AuditStateStore::auditKey($id)]) === -1, 'Active root lost protection');
}
$pending = $redis->eval('return redis.call("XPENDING",KEYS[1],"fixture")', [$stream]);
$check($pending[0] === 0, 'Admitted message lost ACK');
$check($redis->eval('return redis.call("ZCARD",KEYS[1])', [AuditTaskCommitter::OUTBOX]) === 1, 'Outbox lost');
$check($quota->metrics()['rpd_used'] === 1, 'Quota charge lost');
$check(json_decode($redis->get('gemini:attempt:v2:' . $manifest['attempt_id']), true)['state'] === 'completed', 'Attempt receipt lost');

// Simular vencimiento controlado; no esperar diez minutos para probar reclaim.
$old = new AuditTask($manifest['active']);
$redis->eval('local r=cjson.decode(redis.call("HGET",KEYS[1],"record")); r.lease_until_ms=0; redis.call("HSET",KEYS[1],"record",cjson.encode(r)); return redis.call("ZADD",KEYS[2],0,r.task_id)',
    [FairAuditTaskQueue::taskKey($old->id()), FairAuditTaskQueue::base('extraction', 'batch') . ':leases']);
$recovered = $queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'after-crash');
$check($recovered !== null && $recovered->id() === $old->id() && $recovered->record['fence'] > $old->record['fence'], 'Recovery failed');
$check($recovered->record['stage_artifact']['responses']['synthetic-body']['value'] === 'saved-response', 'Saved response lost');
$check($committer->commit($old->record, AuditTaskEffects::empty($old)) === 'STALE', 'Old fence accepted');
$check($committer->commit($recovered->record, AuditTaskEffects::empty($recovered)) === 'COMMITTED', 'Recovered commit failed');
$check($outbox->flush() === 1 && $outbox->flush() === 0, 'Publication receipt failed');
$check($redis->eval('return redis.call("XLEN",KEYS[1])', [$stream]) === $manifest['stream_length'] + 1, 'Outbox duplicated event');
echo "Verified: AOF survives SIGKILL, identities/ACK/artifact/quota/outbox persist; reclaim rejects old fence and publishes once.\n";
