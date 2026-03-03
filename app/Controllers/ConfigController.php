<?php
declare(strict_types=1);

namespace App\Controllers;

use Core\Env;
use Core\Response;

/**
 * Expone configuración pública (no sensible) al frontend.
 * Esto permite centralizar valores como timeouts y límites en .env
 * sin necesidad de hardcodearlos en JavaScript.
 */
class ConfigController extends Controller
{
    public function publicConfig(): void
    {
        Response::success([
            'auditBatchMaxLimit'  => (int) Env::get('AUDIT_BATCH_MAX_LIMIT', 100),
            'auditBatchTimeoutMs' => (int) Env::get('AUDIT_BATCH_TIMEOUT', 3600) * 1000,
        ]);
    }
}
