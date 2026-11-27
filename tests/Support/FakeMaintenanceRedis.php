<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\RedisClient;

/**
 * Doble en memoria de RedisClient para las ramas de `gc()` y del inspector de SDD-BATCH-001 (sin Redis real).
 * Reconoce cada script por un fragmento estable y emula solo su efecto observable; un script desconocido devuelve 0.
 * La semántica fina de los Lua se verifica en las suites de integración (`RUN_REDIS_INTEGRATION=1`).
 */
final class FakeMaintenanceRedis extends RedisClient
{
    /** @var array<string,string> Claves string (job:*:state, reservas, audit:*:state, banderas). */
    public array $strings = [];
    /** @var array<string,int> Tamaño simulado de `job:{id}:audits` (para las rebanadas HSCAN de referencias). */
    public array $hashSizes = [];
    /** @var array<string,array<string,string>> Hashes con campos explícitos (HEXISTS). */
    public array $hashes = [];
    /** @var array<string,true> Claves `job:{id}:audits` cuya rebanada informa una referencia activa. */
    public array $activeRefs = [];
    /** @var array<string,list<list<string>>> Páginas SCAN por patrón (cursor = índice de página). */
    public array $pages = [];
    /** @var array<string,string> Status por auditId (AUDIT_STATUS_LUA). */
    public array $auditStatuses = [];
    /** @var array<string,string> Hash `audit:execution:gc:cursors`. */
    public array $gcCursors = [];
    /** @var array<string,string> Lecturas fallidas: clave → JSON inválido devuelto por get(). */
    public array $corrupt = [];
    /** @var list<string> Traza ordenada de get/scan (y lo que el test añada). */
    public array $log = [];
    /** @var list<string> Cursores recibidos por la rebanada de referencias. */
    public array $refsCursors = [];
    /** @var list<array<int,mixed>> ARGV de cada borrado de job. */
    public array $jobDeletes = [];
    /** @var list<string> Reservas liberadas por GC_RELEASE_ORPHAN_RESERVATION_LUA. */
    public array $orphanReleases = [];
    /** @var list<string> Reservas borradas por la regla heredada (auditoría terminal/ausente). */
    public array $reservationDeletes = [];
    public int $nowMs = 1_800_000_000_000;

    public function __construct()
    {
    }

    public function get(string $key): ?string
    {
        $this->log[] = 'get:' . $key;
        if (isset($this->corrupt[$key])) {
            return $this->corrupt[$key];
        }

        return $this->strings[$key] ?? null;
    }

    public function scanKeys(string $pattern, string $cursor = '0', int $count = 100): array
    {
        $this->log[] = 'scan:' . $pattern . ':' . $cursor;
        $pages = $this->pages[$pattern] ?? [[]];
        $index = (int) $cursor;
        $next = $index + 1 < count($pages) ? (string) ($index + 1) : '0';

        return [$next, $pages[$index] ?? []];
    }

    public function eval(string $script, array $keys = [], array $argv = []): mixed
    {
        if ($keys === []) {
            return $this->nowMs;
        }
        $key = (string) $keys[0];

        if ($key === 'audit:execution:gc:cursors') {
            if (str_contains($script, 'HGETALL')) {
                $flat = [];
                foreach ($this->gcCursors as $field => $value) {
                    $flat[] = $field;
                    $flat[] = $value;
                }
                return $flat;
            }
            if (str_contains($script, 'HSET')) {
                for ($i = 0; $i + 1 < count($argv); $i += 2) {
                    $this->gcCursors[(string) $argv[$i]] = (string) $argv[$i + 1];
                }
                return 1;
            }
            return 0;
        }
        if (str_contains($script, '"STRLEN"')) {
            return strlen($this->strings[$key] ?? '');
        }
        if (str_contains($script, "type(a.status) ~= 'string'")) {
            $auditId = preg_match('/^audit:(.+):state$/D', $key, $m) === 1 ? $m[1] : '';
            return $this->auditStatuses[$auditId] ?? null;
        }
        if (str_contains($script, "'audit:execution:refs:'")) {   // GC_JOB_REFS_SLICE_LUA
            $this->refsCursors[] = (string) $argv[0];
            $next = (int) $argv[0] + 500;
            return [$next >= ($this->hashSizes[$key] ?? 0) ? '0' : (string) $next, isset($this->activeRefs[$key]) ? 1 : 0];
        }
        if (str_contains($script, "'ZREM'")) {                      // GC_DELETE_JOB_STATE_LUA
            $this->jobDeletes[] = $argv;
            $raw = $this->strings[$key] ?? null;
            if ($raw === null || sha1($raw) !== $argv[0]) {
                return 0;
            }
            unset($this->strings[$key], $this->hashSizes[$keys[2]]);
            return 1;
        }
        if (str_contains($script, 'job.status == nil')) {           // GC_JOB_STATUS_LUA
            $raw = $this->strings[$key] ?? null;
            return $raw === null ? null : (string) (json_decode($raw, true)['status'] ?? '');
        }
        if (str_contains($script, '"HEXISTS"')) {
            return isset($this->hashes[$key][(string) $argv[0]]) ? 1 : 0;
        }
        if (str_contains($script, 'orphaned_job_terminal')) {       // GC_RELEASE_ORPHAN_RESERVATION_LUA
            if (($this->strings[$key] ?? null) !== $argv[0]) {
                return 0;
            }
            $auditKey = (string) $keys[1];
            if (isset($this->strings[$auditKey])) {
                $audit = json_decode($this->strings[$auditKey], true);
                $reservation = json_decode((string) $argv[0], true);
                if (!in_array($audit['status'] ?? '', ['completed', 'manual_review', 'error', 'failed'], true)
                    && ($audit['job_id'] ?? '') === ($reservation['job_id'] ?? '')) {
                    $audit['status'] = 'failed';
                    $audit['failed_stage'] = 'orphaned_job_terminal';
                    $audit['completed_at'] = $audit['updated_at'] = (string) $argv[1];
                    $this->strings[$auditKey] = (string) json_encode($audit);
                }
            }
            unset($this->strings[$key]);
            $this->orphanReleases[] = $key;
            return 1;
        }
        if (str_contains($script, 'redis.call("GET",KEYS[1])~=ARGV[1]') && str_contains($script, 'redis.call("DEL",KEYS[1])')) {
            if (($this->strings[$key] ?? null) !== $argv[0]) {
                return 0;
            }
            unset($this->strings[$key]);
            $this->reservationDeletes[] = $key;
            return 1;
        }
        if (str_contains($script, '"EXISTS"')) {
            return isset($this->strings[$key]) ? 1 : 0;
        }
        if (str_contains($script, '"HLEN"')) {
            return $this->hashSizes[$key] ?? 0;
        }

        return 0;
    }
}
