#!/usr/bin/env php
<?php

/**
 * schedule-daily-batches.php — Encola auditorías batch para todos los clientes configurados
 * utilizando el despachador equitativo Round-Robin Multi-Job y Multi-Cliente (Fair Queuing).
 *
 * Uso:
 *   php bin/schedule-daily-batches.php [--date-from=YYYY-MM-DD] [--date-to=YYYY-MM-DD] [--limit=N] [--chunk-size=K] [--dry-run]
 *
 * Defaults:
 *   --date-from   Primer día del año en curso
 *   --date-to     Fecha actual
 *   --limit       AUDIT_BATCH_CRON_LIMIT (default: 3000)
 *   --chunk-size  MultiClientBatchDispatcher::DEFAULT_CHUNK_SIZE (default: 20)
 *   --dry-run     Solo muestra qué haría, sin publicar eventos
 *
 * Variables de entorno:
 *   AUDIT_BATCH_CRON_LIMIT  Límite por cliente cuando --limit no se especifica (default: 3000)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\ClientsModel;
use App\Models\AuditConfigModel;
use App\Models\InvoicesModel;
use App\Services\Audit\MultiClientBatchDispatcher;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Env;
use Core\Logger;

Env::load();

// ─── Parse CLI arguments ─────────────────────────────────────────────────────

$options = getopt('', ['date-from:', 'date-to:', 'limit:', 'chunk-size:', 'dry-run']);

$dateFrom = isset($options['date-from']) && is_string($options['date-from']) && $options['date-from'] !== ''
    ? $options['date-from']
    : date('Y') . '-01-01';

$dateTo = isset($options['date-to']) && is_string($options['date-to']) && $options['date-to'] !== ''
    ? $options['date-to']
    : date('Y-m-d');

$envLimit = max(1, (int) Env::get('AUDIT_BATCH_CRON_LIMIT', 3000));
$limit = isset($options['limit']) && is_numeric($options['limit'])
    ? max(1, (int) $options['limit'])
    : $envLimit;

$chunkSize = isset($options['chunk-size']) && is_numeric($options['chunk-size'])
    ? max(1, (int) $options['chunk-size'])
    : MultiClientBatchDispatcher::DEFAULT_CHUNK_SIZE;

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

fwrite(STDOUT, "\n{$mode}AudFact — Despachador Equitativo Multi-Cliente (Fair Queuing)\n");
fwrite(STDOUT, "Rango: {$dateFrom} → {$dateTo} | Límite por cliente: {$limit} | Tamaño ventana Round-Robin: {$chunkSize}\n\n");

Logger::info('schedule-daily-batches: iniciando despacho equitativo', [
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo,
    'limit'      => $limit,
    'chunk_size' => $chunkSize,
    'dry_run'    => $dryRun,
]);

// ─── Build dependencies & Dispatcher ─────────────────────────────────────────

$dispatcher = new MultiClientBatchDispatcher(
    new ClientsModel(),
    new AuditConfigModel(),
    new InvoicesModel(),
    new BatchJobStore(),
    new AuditStateStore(),
    new AuditEventPublisher()
);

$progressCallback = static function (string $phase, array $data): void {
    match ($phase) {
        'recovery_started'    => fwrite(STDOUT, "  [0/3] Verificando jobs sellados previos con auditorías pendientes de publicación...\n"),
        'recovery_found'      => fwrite(STDOUT, sprintf("  ↳ Job recuperado %s (NitSec=%s, pendientes=%d)\n", $data['job_id'] ?? '', $data['fac_nit_sec'] ?? '', $data['pending_count'] ?? 0)),
        'discovery_started'   => fwrite(STDOUT, "  [1/3] Descubriendo clientes activos y validando configuración...\n"),
        'client_discovered'   => fwrite(STDOUT, sprintf("  ↳ Cliente NitSec=%s (%s): %s%s\n", $data['fac_nit_sec'] ?? '', $data['client_name'] ?? '', $data['status'] ?? '', isset($data['job_id']) ? " (job_id={$data['job_id']})" : '')),
        'preparation_started' => fwrite(STDOUT, "  [2/3] Pre-cargando facturas y sellando lotes por cliente...\n"),
        'client_prepared'     => fwrite(STDOUT, sprintf("  ↳ Lote sellado NitSec=%s: %d facturas encoladas (bloqueadas: %d, existentes: %d)\n", $data['fac_nit_sec'] ?? '', $data['enqueued'] ?? 0, $data['skipped_locked'] ?? 0, $data['skipped_existing'] ?? 0)),
        'publishing_started'  => fwrite(STDOUT, "  [3/3] Despachando eventos Round-Robin en ventanas equitativas (Fair Queuing)...\n"),
        'chunk_published'     => fwrite(STDOUT, sprintf("  ↳ Ventana despachada: Job %s (NitSec=%s) -> %d eventos (restantes: %d)\n", $data['job_id'] ?? '', $data['fac_nit_sec'] ?? '', $data['chunk_size'] ?? 0, $data['remaining'] ?? 0)),
        default => null,
    };
};

// ─── Execute Dispatch ────────────────────────────────────────────────────────

$summary = $dispatcher->dispatch(
    $dateFrom,
    $dateTo,
    $limit,
    $chunkSize,
    $dryRun,
    $progressCallback
);

// ─── Summary ─────────────────────────────────────────────────────────────────

fwrite(STDOUT, "\n{$mode}Resumen de Despacho Equitativo:\n");
fwrite(STDOUT, sprintf("  • Clientes encolados:      %d\n", $summary['queued_clients']));
fwrite(STDOUT, sprintf("  • Facturas totales:        %d\n", $summary['total_invoices_queued']));
fwrite(STDOUT, sprintf("  • Clientes sin config:     %d\n", $summary['skipped_no_config']));
fwrite(STDOUT, sprintf("  • Clientes duplicados/hoy: %d\n", $summary['skipped_duplicate']));
fwrite(STDOUT, sprintf("  • Errores:                 %d\n\n", $summary['errors']));

Logger::info('schedule-daily-batches: despacho equitativo finalizado', $summary);

$exitCode = $summary['errors'] > 0 ? 1 : 0;
exit($exitCode);
