#!/usr/bin/env php
<?php

/**
 * schedule-daily-batches.php — Encola auditorías batch para todos los clientes configurados.
 *
 * Uso:
 *   php bin/schedule-daily-batches.php [--date-from=YYYY-MM-DD] [--date-to=YYYY-MM-DD] [--limit=N] [--dry-run]
 *
 * Defaults:
 *   --date-from  Primer día del año en curso
 *   --date-to    Fecha actual
 *   --limit      AUDIT_BATCH_CRON_LIMIT (default: 5000)
 *   --dry-run    Solo muestra qué haría, sin publicar eventos
 *
 * Variables de entorno:
 *   AUDIT_BATCH_CRON_LIMIT  Límite por cliente cuando --limit no se especifica (default: 5000)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\ClientsModel;
use App\Models\AuditConfigModel;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Env;
use Core\Logger;

Env::load();

// ─── Parse CLI arguments ─────────────────────────────────────────────────────

$options = getopt('', ['date-from:', 'date-to:', 'limit:', 'dry-run']);

$dateFrom = isset($options['date-from']) && is_string($options['date-from']) && $options['date-from'] !== ''
    ? $options['date-from']
    : date('Y') . '-01-01';

$dateTo = isset($options['date-to']) && is_string($options['date-to']) && $options['date-to'] !== ''
    ? $options['date-to']
    : date('Y-m-d');

$envLimit = max(1, (int) \Core\Env::get('AUDIT_BATCH_CRON_LIMIT', 5000));
$limit = isset($options['limit']) && is_numeric($options['limit'])
    ? max(1, (int) $options['limit'])
    : $envLimit;

$dryRun = array_key_exists('dry-run', $options);

// ─── Validate dates ──────────────────────────────────────────────────────────

$dtFrom = \DateTime::createFromFormat('Y-m-d', $dateFrom);
$dtTo   = \DateTime::createFromFormat('Y-m-d', $dateTo);

if (!$dtFrom || !$dtTo) {
    fwrite(STDERR, "Error: fechas inválidas (date-from={$dateFrom}, date-to={$dateTo})\n");
    exit(1);
}

if ($dtFrom > $dtTo) {
    fwrite(STDERR, "Error: date-from ({$dateFrom}) no puede ser mayor que date-to ({$dateTo})\n");
    exit(1);
}

// ─── Header ──────────────────────────────────────────────────────────────────

$mode = $dryRun ? '[DRY-RUN] ' : '';

fwrite(STDOUT, "\n{$mode}AudFact — Schedule Daily Batches\n");
fwrite(STDOUT, "Rango: {$dateFrom} → {$dateTo} | Límite por cliente: {$limit}\n\n");

Logger::info('schedule-daily-batches: iniciando', [
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'limit'     => $limit,
    'dry_run'   => $dryRun,
]);

// ─── Load clients ────────────────────────────────────────────────────────────

$clientsModel = new ClientsModel();
$configModel  = new AuditConfigModel();

$clients = $clientsModel->getAllClients();

if (empty($clients)) {
    fwrite(STDOUT, "No se encontraron clientes activos.\n");
    Logger::warning('schedule-daily-batches: no se encontraron clientes activos');
    exit(0);
}

fwrite(STDOUT, "Clientes activos encontrados: " . count($clients) . "\n\n");

// ─── Build dependencies ──────────────────────────────────────────────────────

$jobStore  = new BatchJobStore();
$publisher = new AuditEventPublisher();

$idempotencyTtl = 14400; // 4h — una ejecución por ventana horaria por cliente

// ─── Process each client ─────────────────────────────────────────────────────

$summary = [
    'queued'      => 0,
    'skipped_no_config' => 0,
    'skipped_duplicate' => 0,
    'errors'      => 0,
];

foreach ($clients as $client) {
    $facNitSec = (int) $client['NitSec'];
    $clientName = trim((string) ($client['NitCom'] ?? ''));
    $label = "NitSec={$facNitSec}";

    // ── Check audit configuration ────────────────────────────────────────
    $config = $configModel->getConfig((string) $facNitSec);

    if ($config === null || !$config['activo'] || empty((array) $config['documents'])) {
        fwrite(STDOUT, "  ⏭  {$label} ({$clientName}) — Sin configuración completa, omitido\n");
        Logger::info('schedule-daily-batches: cliente sin configuración completa', [
            'fac_nit_sec' => $facNitSec,
        ]);
        $summary['skipped_no_config']++;
        continue;
    }

    // ── Dry-run shortcut ─────────────────────────────────────────────────
    if ($dryRun) {
        fwrite(STDOUT, "  ✓  {$label} ({$clientName}) — Se encolaría\n");
        $summary['queued']++;
        continue;
    }

    // ── Idempotency check ────────────────────────────────────────────────
    $jobId = AuditEvent::uuidV4();
    $idempotencyKey = 'cron-batch-' . date('Ymd-H') . '-' . $facNitSec;

    try {
        $existingJobId = $jobStore->claimIdempotencyKey($idempotencyKey, $jobId, $idempotencyTtl);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, "  ✗  {$label} ({$clientName}) — Error de idempotencia: {$e->getMessage()}\n");
        Logger::error('schedule-daily-batches: error idempotencia', [
            'fac_nit_sec' => $facNitSec,
            'error'       => $e->getMessage(),
        ]);
        $summary['errors']++;
        continue;
    }

    if ($existingJobId !== null) {
        fwrite(STDOUT, "  ⏭  {$label} ({$clientName}) — Ya encolado hoy (job_id={$existingJobId})\n");
        Logger::info('schedule-daily-batches: cliente ya encolado', [
            'fac_nit_sec'     => $facNitSec,
            'existing_job_id' => $existingJobId,
        ]);
        $summary['skipped_duplicate']++;
        continue;
    }

    // ── Init job and publish event ───────────────────────────────────────
    try {
        $jobStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit);

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_REQUESTED,
            auditId: null,
            jobId: $jobId,
            documentId: null,
            payload: [
                'fac_nit_sec' => (string) $facNitSec,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'limit'       => $limit,
                'source'      => 'cron',
            ],
        );

        $publisher->publish($event);

        fwrite(STDOUT, "  ✓  {$label} ({$clientName}) — Encolado (job_id={$jobId})\n");
        Logger::info('schedule-daily-batches: batch encolado', [
            'fac_nit_sec' => $facNitSec,
            'job_id'      => $jobId,
        ]);
        $summary['queued']++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  ✗  {$label} ({$clientName}) — Error: {$e->getMessage()}\n");
        Logger::error('schedule-daily-batches: error encolando', [
            'fac_nit_sec' => $facNitSec,
            'job_id'      => $jobId,
            'error'       => $e->getMessage(),
        ]);
        $summary['errors']++;
    }
}

// ─── Summary ─────────────────────────────────────────────────────────────────

fwrite(STDOUT, "\n{$mode}Resumen: ");
fwrite(STDOUT, "encolados={$summary['queued']} ");
fwrite(STDOUT, "sin_config={$summary['skipped_no_config']} ");
fwrite(STDOUT, "duplicados={$summary['skipped_duplicate']} ");
fwrite(STDOUT, "errores={$summary['errors']}\n\n");

Logger::info('schedule-daily-batches: finalizado', $summary);

$exitCode = $summary['errors'] > 0 ? 1 : 0;
exit($exitCode);
