#!/usr/bin/env php
<?php

/**
 * Worker CLI de auditorías asíncronas.
 *
 * Consume jobs de la cola Redis (BRPOP audit:queue) y los procesa.
 * Diseñado para ejecutarse como proceso long-running en Docker.
 *
 * Uso:
 *   php bin/audit-worker.php [--max-jobs=N] [--timeout=S]
 *
 * Ejemplo Docker:
 *   docker compose exec php php bin/audit-worker.php --max-jobs=100
 *
 * @since 3.0
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use App\Models\InvoicesModel;
use App\Models\AuditStatusModel;
use App\Services\Audit\AuditQueueService;
use App\Services\Audit\AuditOrchestratorFactory;
use App\Services\Audit\AuditOrchestrator;

// --- Bootstrap ---
Env::load();
set_time_limit(0);
ini_set('memory_limit', '1024M');

// Registrar señales de shutdown graceful
$shutdown = false;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shutdown) {
        Logger::info('Worker: Señal SIGTERM recibida, cerrando gracefully...');
        $shutdown = true;
    });
    pcntl_signal(SIGINT, function () use (&$shutdown) {
        Logger::info('Worker: Señal SIGINT recibida, cerrando gracefully...');
        $shutdown = true;
    });
}

// --- Parsear argumentos CLI ---
$maxJobs = 0; // 0 = infinito
$pollTimeout = 5; // segundos de BRPOP timeout

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-jobs=')) {
        $maxJobs = (int) substr($arg, strlen('--max-jobs='));
    }
    if (str_starts_with($arg, '--timeout=')) {
        $pollTimeout = (int) substr($arg, strlen('--timeout='));
    }
}

echo "[" . date('c') . "] Audit Worker iniciado (max-jobs={$maxJobs}, poll-timeout={$pollTimeout}s)\n";
Logger::info('Audit Worker iniciado', [
    'max_jobs'     => $maxJobs,
    'poll_timeout' => $pollTimeout,
    'pid'          => getmypid(),
]);

/**
 * Construye el orquestador usando la factory compartida (A03).
 */
function buildOrchestrator(): AuditOrchestrator
{
    return AuditOrchestratorFactory::create();
}

/**
 * Verifica idempotencia: ¿esta factura ya fue auditada exitosamente?
 * Capa 1: Redis cache. Capa 2: SQL fallback.
 *
 * @param string $facSec PK de la factura
 * @param AuditStatusModel $model Modelo SQL reutilizable
 * @param RedisClient $redis Cliente Redis reutilizable
 * @return array|null Resultado anterior o null si no existe
 */
function getIdempotentResult(string $facSec, AuditStatusModel $model, RedisClient $redis): ?array
{
    $cacheTTL = (int) Env::get('AUDIT_CACHE_TTL', 86400);
    $cacheKey = 'audit:result:' . $facSec;

    // Capa 1: Redis
    if ($redis->isAvailable()) {
        $cached = $redis->get($cacheKey);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    // Capa 2: SQL fallback
    $existing = $model->getByFacSec($facSec);
    if ($existing !== false && isset($existing['EstAud']) && (int) $existing['EstAud'] === 1) {
        $result = [
            'response' => 'success',
            'message'  => 'Resultado reutilizado (idempotencia)',
            'data'     => $existing,
        ];

        if ($redis->isAvailable()) {
            $redis->set($cacheKey, json_encode($result), $cacheTTL);
        }

        return $result;
    }

    return null;
}

// --- Loop principal ---
$queueService = new AuditQueueService();
$auditStatusModel = new AuditStatusModel();
$redis = RedisClient::getInstance();
$jobsProcessed = 0;
$auditor = null; // Lazy-init, reusar entre jobs (M04)

while (!$shutdown) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Límite de jobs
    if ($maxJobs > 0 && $jobsProcessed >= $maxJobs) {
        echo "[" . date('c') . "] Límite de jobs alcanzado ({$maxJobs})\n";
        break;
    }

    // Esperar job en la cola (blocking)
    $jobData = $queueService->dequeue($pollTimeout);
    if ($jobData === null) {
        continue; // Timeout, volver a intentar
    }

    $jobId = $jobData['jobId'];
    $params = $jobData['params'];
    $jobsProcessed++;

    echo "[" . date('c') . "] Procesando job {$jobId}...\n";
    Logger::info("Worker: Procesando job", [
        'jobId'     => $jobId,
        'facNitSec' => !empty($params['facNitSec']) ? '***' . substr((string)$params['facNitSec'], -3) : null,
        'dateRange' => ($params['date'] ?? '?') . ' → ' . ($params['dateTo'] ?? '?'),
    ]);

    try {
        // Marcar job como processing
        $queueService->updateJob($jobId, AuditQueueService::STATUS_PROCESSING);

        // Obtener facturas
        $facNitSec = (int) ($params['facNitSec'] ?? 0);
        $date = (string) ($params['date'] ?? '');
        $dateTo = isset($params['dateTo']) && $params['dateTo'] !== '' ? (string) $params['dateTo'] : null;
        $limit = (int) ($params['limit'] ?? 10);

        $invoices = (new InvoicesModel())->getInvoices($facNitSec, $date, $dateTo, $limit);

        $queueService->updateJob($jobId, AuditQueueService::STATUS_PROCESSING, [
            'total' => count($invoices),
        ]);

        if (empty($invoices)) {
            $queueService->updateJob(
                $jobId,
                AuditQueueService::STATUS_COMPLETED,
                ['total' => 0, 'processed' => 0],
                ['items' => [], 'message' => 'No se encontraron facturas']
            );
            continue;
        }

        // M04: Lazy-init del orquestador, reusar entre jobs
        if ($auditor === null) {
            $auditor = buildOrchestrator();
        }

        $results = [];
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $interruptedAt = null;

        foreach ($invoices as $i => $invoice) {
            if ($shutdown) {
                Logger::info("Worker: Shutdown durante job {$jobId}", ['processed' => $i]);
                $interruptedAt = $i;
                break;
            }

            $dispensa = (string) ($invoice['Dispensa'] ?? '');
            $facSec = (string) ($invoice['FacSec'] ?? '');

            if ($dispensa === '' || $facSec === '') {
                $results[] = [
                    'invoice' => $invoice,
                    'result'  => [
                        'response' => 'error',
                        'message'  => 'Factura inválida: Dispensa/FacSec faltante',
                    ],
                ];
                $failed++;
            } else {
                // A01: Verificar idempotencia antes de enviar a Gemini
                $idempotentResult = getIdempotentResult($facSec, $auditStatusModel, $redis);
                if ($idempotentResult !== null) {
                    $results[] = ['invoice' => $invoice, 'result' => $idempotentResult];
                    $succeeded++;
                    $skipped++;
                    continue;
                }

                try {
                    $result = $auditor->auditInvoice($facSec, $dispensa, null);
                    $results[] = ['invoice' => $invoice, 'result' => $result];

                    if (($result['response'] ?? '') === 'success') {
                        $succeeded++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    Logger::error("Worker: Error auditando factura {$facSec}", [
                        'jobId' => $jobId,
                        'error' => $e->getMessage(),
                    ]);
                    $results[] = [
                        'invoice' => $invoice,
                        'result'  => [
                            'response' => 'error',
                            'message'  => $e->getMessage(),
                        ],
                    ];
                    $failed++;
                }
            }

            // Actualizar progreso periódicamente (cada 5 facturas)
            if (($i + 1) % 5 === 0 || ($i + 1) === count($invoices)) {
                $queueService->updateJob($jobId, AuditQueueService::STATUS_PROCESSING, [
                    'processed' => $i + 1,
                    'succeeded' => $succeeded,
                    'failed'    => $failed,
                    'skipped'   => $skipped,
                ]);
            }
        }

        // A02: Diferenciar entre completado e interrumpido
        if ($interruptedAt !== null) {
            $queueService->updateJob(
                $jobId,
                AuditQueueService::STATUS_INTERRUPTED,
                [
                    'processed' => count($results),
                    'succeeded' => $succeeded,
                    'failed'    => $failed,
                    'skipped'   => $skipped,
                    'total'     => count($invoices),
                ],
                [
                    'items'            => $results,
                    'totalProcessed'   => count($results),
                    'interruptedAt'    => $interruptedAt,
                    'remainingInvoices' => count($invoices) - count($results),
                ]
            );
            echo "[" . date('c') . "] Job {$jobId} INTERRUMPIDO en factura {$interruptedAt}/" . count($invoices) . "\n";
            Logger::warning("Worker: Job interrumpido por shutdown", [
                'jobId'     => $jobId,
                'processed' => count($results),
                'total'     => count($invoices),
            ]);
        } else {
            // Job completado normalmente
            $queueService->updateJob(
                $jobId,
                AuditQueueService::STATUS_COMPLETED,
                [
                    'processed' => count($results),
                    'succeeded' => $succeeded,
                    'failed'    => $failed,
                    'skipped'   => $skipped,
                ],
                [
                    'items'          => $results,
                    'totalProcessed' => count($results),
                ]
            );

            echo "[" . date('c') . "] Job {$jobId} completado: {$succeeded} ok, {$failed} errores, {$skipped} idempotentes\n";
            Logger::info("Worker: Job completado", [
                'jobId'     => $jobId,
                'succeeded' => $succeeded,
                'failed'    => $failed,
                'skipped'   => $skipped,
            ]);
        }
    } catch (\Throwable $e) {
        // B-NEW-02: Resetear auditor para forzar re-creación limpia en el siguiente job
        $auditor = null;

        Logger::error("Worker: Job falló", [
            'jobId' => $jobId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $queueService->updateJob(
            $jobId,
            AuditQueueService::STATUS_FAILED,
            [],
            null,
            $e->getMessage()
        );

        echo "[" . date('c') . "] Job {$jobId} FALLÓ: {$e->getMessage()}\n";
    }
}

echo "[" . date('c') . "] Worker finalizado ({$jobsProcessed} jobs procesados)\n";
Logger::info('Audit Worker finalizado', ['jobs_processed' => $jobsProcessed]);
