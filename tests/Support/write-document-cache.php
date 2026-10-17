<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\Audit\Pipeline\AuditDocumentCache;
use Tests\Support\IsolatedAuditRedis;

if (getenv('RUN_REDIS_INTEGRATION') !== '1' || count($argv) !== 3) {
    exit(2);
}

try {
    $cache = new AuditDocumentCache(new IsolatedAuditRedis($argv[1]), 4096, 32);
    for ($i = 0; $i < 250; $i++) {
        $cache->put("audit:blob:{$argv[2]}:{$i}", ['mime' => 'application/pdf', 'data' => str_repeat('a', 100)]);
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Error de productor: ' . get_class($error) . PHP_EOL);
    exit(1);
}
