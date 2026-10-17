<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use Tests\Support\IsolatedAuditRedis;

if (getenv('RUN_REDIS_INTEGRATION') !== '1' || count($argv) !== 3) {
    exit(2);
}
try {
    $redis = new IsolatedAuditRedis($argv[1]);
    $queue = new FairAuditTaskQueue($redis, new AuditExecutionConfig(2));
    $budget = new GeminiQuotaBudget($redis, ['scope' => 'race', 'model' => 'fixture',
        'rpm' => 3, 'tpm' => 100, 'rpd' => 5, 'inflight' => 2]);
    $grants = 0;
    foreach (json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR) as $id) {
        $task = $queue->get($id);
        for ($i = 0; $i < 5; $i++) {
            $result = $budget->reserve(['task' => $task->record, 'operation' => 'generate_content',
                'tokens' => 50, 'body_hash' => hash('sha256', $id)]);
            $grants += (int) ($result['status'] === 'GRANTED');
        }
    }
    fwrite(STDOUT, (string) $grants);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Quota fixture failed: ' . get_class($error));
    exit(1);
}
