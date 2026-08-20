<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use Core\Logger;
use Core\SqlServerOperationException;
use Core\SqlServerOperationMode;

/**
 * Modelo de estado de auditoría de dispensaciones.
 *
 * @important Dependencia cross-database: las lecturas de este modelo operan
 *            contra Discolnet.dbo.AudDispEst. Esta tabla
 *            DEBE residir en la misma instancia SQL Server que la BD principal
 *            (DB_NAME). Si la topología cambia, este modelo dejará de funcionar.
 */
class AuditStatusModel extends Model
{
    /**
     * Auditorías prefieren consistencia fuerte de lectura después de escritura.
     * Si `default` no está disponible, degrada a `db2` para no romper el histórico.
     *
     * @var string
     */
    protected string $readConnectionName = 'default';

    /**
     * Resumen agregado de estados de auditoría para el dashboard.
     *
     * Ejecuta un GROUP BY a nivel de BD en lugar de contar sobre items paginados,
     * garantizando conteos reales sobre toda la tabla AudDispEst.
     *
     * @return array{total:int,byState:array<string,int>,documentsAudited:int,lastAuditAt:?string}
     */
    public function getStateSummary(): array
    {
        $stateSql = "SELECT
                    [EstadoDetallado],
                    COUNT(*) AS total
                FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                GROUP BY [EstadoDetallado]";
        $documentsSql = "SELECT
                        COUNT(*) AS total
                    FROM AdjuntosDispensacion WITH (NOLOCK)
                    WHERE AdjDisUsuAudi IS NOT NULL";
        $lastAuditSql = "SELECT TOP 1 [FechaCreacion]
                    FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                    ORDER BY [FechaCreacion] DESC";

        return $this->readWithFallback(function (PDO $connection) use (
            $stateSql,
            $documentsSql,
            $lastAuditSql
        ): array {
            $stateStatement = $connection->prepare($stateSql);
            $stateStatement->execute();
            try {
                $rows = $stateStatement->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stateStatement->closeCursor();
            }

            $byState = [];
            $grandTotal = 0;
            foreach ($rows as $row) {
                $state = strtoupper(trim((string) ($row['EstadoDetallado'] ?? 'UNKNOWN')));
                $count = (int) ($row['total'] ?? 0);
                $byState[$state] = $count;
                $grandTotal += $count;
            }

            $documentsStatement = $connection->prepare($documentsSql);
            $documentsStatement->execute();
            try {
                $documentsTotal = (int) (
                    $documentsStatement->fetch(PDO::FETCH_ASSOC)['total'] ?? 0
                );
            } finally {
                $documentsStatement->closeCursor();
            }

            $lastAuditStatement = $connection->prepare($lastAuditSql);
            $lastAuditStatement->execute();
            try {
                $lastRow = $lastAuditStatement->fetch(PDO::FETCH_ASSOC);
            } finally {
                $lastAuditStatement->closeCursor();
            }
            $lastAuditAt = $lastRow
                ? (string) ($lastRow['FechaCreacion'] ?? '')
                : null;

            return [
                'total' => $grandTotal,
                'byState' => $byState,
                'documentsAudited' => $documentsTotal,
                'lastAuditAt' => $lastAuditAt ?: null,
            ];
        });
    }

    /**
     * Retorna el rendimiento mensual agregado agrupado por mes y cliente/EPS.
     *
     * @param int $year Año a consultar (ej. 2026).
     * @return array<int, array<string, mixed>>
     */
    public function getMonthlyPerformanceStats(int $year): array
    {
        $sql = "SELECT 
                    MONTH(a.FechaCreacion) AS mes,
                    a.FacNitSec AS fac_nit_sec,
                    COALESCE(n.NitCom, CONCAT('Cliente #', a.FacNitSec)) AS tercero,
                    COUNT(CASE WHEN a.EstAud = 1 THEN a.FacNro END) AS aud_conf,
                    COUNT(CASE WHEN a.EstAud = 0 THEN a.FacNro END) AS aud_rech,
                    COUNT(a.FacNro) AS total,
                    SUM(CASE WHEN a.EstAud = 1 THEN a.DocumentosProcesados ELSE 0 END) AS aud_conf_doc,
                    SUM(CASE WHEN a.EstAud = 0 THEN a.DocumentosProcesados ELSE 0 END) AS aud_rech_doc,
                    SUM(a.DocumentosProcesados) AS total_doc
                FROM Discolnet.dbo.AudDispEst a WITH (NOLOCK)
                LEFT JOIN DiscolmetsGx2QA.dbo.nit n WITH (NOLOCK) ON n.NitSec = a.FacNitSec
                WHERE YEAR(a.FechaCreacion) = :year
                GROUP BY MONTH(a.FechaCreacion), a.FacNitSec, n.NitCom
                ORDER BY mes ASC, total DESC";

        return $this->readWithFallback(function (PDO $connection) use ($sql, $year): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':year', $year, PDO::PARAM_INT);
            $stmt->execute();
            try {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }

            return array_map(function (array $row): array {
                $total = (int) ($row['total'] ?? 0);
                $conf = (int) ($row['aud_conf'] ?? 0);
                $rech = (int) ($row['aud_rech'] ?? 0);
                $confDoc = (int) ($row['aud_conf_doc'] ?? 0);
                $rechDoc = (int) ($row['aud_rech_doc'] ?? 0);
                $totalDoc = (int) ($row['total_doc'] ?? 0);

                return [
                    'mes'          => (int) ($row['mes'] ?? 0),
                    'fac_nit_sec'  => (int) ($row['fac_nit_sec'] ?? 0),
                    'tercero'      => trim((string) ($row['tercero'] ?? '')),
                    'aud_conf'     => $conf,
                    'aud_rech'     => $rech,
                    'total'        => $total,
                    'rate_conf'    => $total > 0 ? round(($conf / $total) * 100, 1) : 0.0,
                    'aud_conf_doc' => $confDoc,
                    'aud_rech_doc' => $rechDoc,
                    'total_doc'    => $totalDoc,
                ];
            }, $rows);
        });
    }

    /**
     * Devuelve los tiempos por fase de una auditoría completada, buscando por FacNro (DisDetNro).
     *
     * @return array{fac_nro:string,fac_nit_sec:string,estado:string,phase_timings:array<string,mixed>|null,total_duration_ms:int}|null
     */
    public function getTimingsByFacNro(string $facNro): ?array
    {
        $sql = "SELECT TOP 1
                    [FacSec] AS [DisId], [FacNro], [FacNitSec], [EstadoDetallado],
                    [DuracionProcesamientoMs], [Hallazgos]
                FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                WHERE [FacNro] = :facNro
                ORDER BY [FechaCreacion] DESC";

        $row = $this->readWithFallback(function (PDO $connection) use ($sql, $facNro): array|false {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':facNro', $facNro, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        if ($row === false || !is_array($row)) {
            return null;
        }

        $payload = $this->decodeAuditPayload(
            isset($row['Hallazgos']) && is_string($row['Hallazgos']) ? $row['Hallazgos'] : null
        );

        return [
            'fac_nro'           => (string) ($row['FacNro'] ?? ''),
            'fac_nit_sec'       => (string) ($row['FacNitSec'] ?? ''),
            'estado'            => (string) ($row['EstadoDetallado'] ?? ''),
            'phase_timings'     => $payload['timings'],
            'total_duration_ms' => (int) ($payload['total_duration_ms'] ?? $row['DuracionProcesamientoMs'] ?? 0),
        ];
    }

    /**
     * Obtiene el detalle público de auditoría por FacNro (DisDetNro).
     *
     * @param  string  $facNro  Número único de la dispensación.
     * @return array<string,mixed>|null Detalle con hallazgos, métricas, timings y decisiones documentales.
     */
    public function getAuditDetailByFacNro(string $facNro): ?array
    {
        $row = $this->readWithFallback(
            fn(PDO $connection): ?array => $this->fetchAuditRowByFacNro($connection, $facNro)
        );
        if ($row === null) {
            return null;
        }

        Logger::info("AuditStatus: búsqueda por FacNro", [
            'facNro' => $facNro
        ]);

        return $this->normalizeAuditDetail($row);
    }

    /**
     * Cuenta auditorías que coinciden con los filtros (para paginación).
     * @param array $filters Filtros: facNitSec, facNro, dateFrom, dateTo
     * @return int
     */
    public function countAudits(array $filters): int
    {
        [$where, $params] = $this->buildWhereClause($filters);

        $sql = "SELECT COUNT(*) AS total
                FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                {$where}";
        return $this->readWithFallback(function (PDO $connection) use ($sql, $params): int {
            $stmt = $connection->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->execute();

            try {
                return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            } finally {
                $stmt->closeCursor();
            }
        });
    }

    /**
     * Busca auditorías con filtros opcionales y retorna summaries paginados.
     *
     * El listado no expone el JSON persistido ni hallazgos completos; sólo
     * escalares, conteos y métricas necesarias para tabla/dashboard.
     *
     * @param  array<string,mixed>  $filters  Filtros: facNitSec, facNro, dateFrom, dateTo.
     * @param  int  $page  Página actual (1-indexed).
     * @param  int  $pageSize  Registros por página.
     * @return array<int,array<string,mixed>>
     */
    public function searchAuditSummaries(array $filters, int $page = 1, int $pageSize = 20): array
    {
        [$where, $params] = $this->buildWhereClause($filters);

        $safePage = max(1, $page);
        $safePageSize = max(1, min(100, $pageSize));
        $offset = ($safePage - 1) * $safePageSize;

        $sql = "SELECT
                    a.[FacSec] AS [DisId], a.[FacNro], a.[EstAud], a.[EstadoDetallado],
                    a.[RequiereRevisionHumana], a.[Severidad],
                    a.[DetalleError], a.[DocumentosProcesados], a.[DocumentoFallido],
                    a.[DuracionProcesamientoMs], a.[FacNitSec],
                    a.[FechaCreacion], a.[FechaActualizacion],
                    CAST(JSON_VALUE(a.[Hallazgos], '$.metrics.total_campos') AS INT) AS [TotalCampos],
                    CAST(JSON_VALUE(a.[Hallazgos], '$.metrics.discrepancias') AS INT) AS [Discrepancias],
                    CAST(JSON_VALUE(a.[Hallazgos], '$.metrics.no_concluyentes') AS INT) AS [NoConcluyentes]
                FROM (
                    SELECT [FacNro]
                    FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                    {$where}
                    ORDER BY [FechaCreacion] DESC
                    OFFSET {$offset} ROWS FETCH NEXT {$safePageSize} ROWS ONLY
                ) p
                INNER LOOP JOIN Discolnet.dbo.AudDispEst a WITH (NOLOCK) ON a.FacNro = p.FacNro
                ORDER BY a.[FechaCreacion] DESC";

        $rows = $this->readWithFallback(function (PDO $connection) use (
            $sql,
            $params,
            $filters,
            $safePage,
            $safePageSize
        ): array {
            $stmt = $connection->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            $stmt->execute();

            try {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                Logger::info("AuditStatus: searchAuditSummaries", [
                    'filters' => array_keys($filters),
                    'page' => $safePage,
                    'pageSize' => $safePageSize,
                    'results' => count($rows),
                ]);

                return $rows ?: [];
            } finally {
                $stmt->closeCursor();
            }
        });

        return array_map(fn(array $row): array => $this->normalizeAuditSummary($row), $rows ?: []);
    }

    /**
     * Construye la cláusula WHERE y parámetros a partir de filtros.
     * @param array $filters
     * @return array [string $where, array $params]
     */
    private function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['facNitSec'])) {
            $conditions[] = '[FacNitSec] = :facNitSec';
            $params[':facNitSec'] = $filters['facNitSec'];
        }

        if (!empty($filters['facNro'])) {
            $conditions[] = '[FacNro] LIKE :facNro';
            $params[':facNro'] = '%' . $filters['facNro'] . '%';
        }

        if (!empty($filters['dateFrom'])) {
            $conditions[] = '[FechaActualizacion] >= :dateFrom';
            $params[':dateFrom'] = $filters['dateFrom'];
        }

        if (!empty($filters['dateTo'])) {
            $conditions[] = '[FechaActualizacion] < :dateTo';
            $params[':dateTo'] = date('Y-m-d', strtotime((string) $filters['dateTo'] . ' +1 day'));
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    /**
     * Obtiene una fila cruda de AudDispEst por FacNro.
     *
     * @return array<string,mixed>|null
     */
    private function fetchAuditRowByFacNro(PDO $connection, string $facNro): ?array
    {
        $sql = "SELECT
                    [FacSec] AS [DisId],
                    [FacNro],
                    [EstAud],
                    [EstadoDetallado],
                    [RequiereRevisionHumana],
                    [Severidad],
                    [Hallazgos],
                    [DetalleError],
                    [DocumentosProcesados],
                    [DocumentoFallido],
                    [DuracionProcesamientoMs],
                    [FacNitSec],
                    [FechaCreacion],
                    [FechaActualizacion]
                FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                WHERE [FacNro] = :facNro";

        $stmt = $connection->prepare($sql);
        $stmt->bindParam(':facNro', $facNro, PDO::PARAM_STR);
        $stmt->execute();
        try {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Normaliza una fila de auditoría a summary público para listados (sin decodificar JSON).
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeAuditSummary(array $row): array
    {
        $findingsCount = (int) ($row['TotalCampos'] ?? 0);
        $failedCount = (int) ($row['Discrepancias'] ?? 0);
        $inconclusiveCount = (int) ($row['NoConcluyentes'] ?? 0);

        return [
            'DisId' => (string) ($row['DisId'] ?? ''),
            'FacNro' => (string) ($row['FacNro'] ?? ''),
            'EstAud' => (int) ($row['EstAud'] ?? 0),
            'EstadoDetallado' => (string) ($row['EstadoDetallado'] ?? ''),
            'RequiereRevisionHumana' => (int) ($row['RequiereRevisionHumana'] ?? 0),
            'Severidad' => (string) ($row['Severidad'] ?? ''),
            'DetalleError' => $this->nullableString($row['DetalleError'] ?? null),
            'DocumentosProcesados' => (int) ($row['DocumentosProcesados'] ?? 0),
            'DocumentoFallido' => $this->nullableString($row['DocumentoFallido'] ?? null),
            'DuracionProcesamientoMs' => (int) ($row['DuracionProcesamientoMs'] ?? 0),
            'FacNitSec' => (string) ($row['FacNitSec'] ?? ''),
            'FechaCreacion' => $this->stringifyDate($row['FechaCreacion'] ?? null),
            'FechaActualizacion' => $this->stringifyDate($row['FechaActualizacion'] ?? null),
            'metrics' => [
                'total_campos' => $findingsCount,
                'discrepancias' => $failedCount,
                'no_concluyentes' => $inconclusiveCount,
                'coincidencias' => 0,
                'omitidos' => 0,
                'risk_score' => 0,
            ],
            'findingsCount' => $findingsCount,
            'failedFindingsCount' => $failedCount,
            'inconclusiveFindingsCount' => $inconclusiveCount,
            'auditExecuted' => $this->isSummaryAuditExecuted($row),
        ];
    }

    /**
     * Normaliza una fila de auditoría a detalle público para modal (decodifica JSON completo).
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeAuditDetail(array $row): array
    {
        $payload = $this->decodeAuditPayload(
            isset($row['Hallazgos']) && is_string($row['Hallazgos']) ? $row['Hallazgos'] : null
        );
        $findings = $payload['findings'];

        return [
            'DisId' => (string) ($row['DisId'] ?? ''),
            'FacNro' => (string) ($row['FacNro'] ?? ''),
            'EstAud' => (int) ($row['EstAud'] ?? 0),
            'EstadoDetallado' => (string) ($row['EstadoDetallado'] ?? ''),
            'RequiereRevisionHumana' => (int) ($row['RequiereRevisionHumana'] ?? 0),
            'Severidad' => (string) ($row['Severidad'] ?? ''),
            'DetalleError' => $this->nullableString($row['DetalleError'] ?? null),
            'DocumentosProcesados' => (int) ($row['DocumentosProcesados'] ?? 0),
            'DocumentoFallido' => $this->nullableString($row['DocumentoFallido'] ?? null),
            'DuracionProcesamientoMs' => (int) ($row['DuracionProcesamientoMs'] ?? 0),
            'FacNitSec' => (string) ($row['FacNitSec'] ?? ''),
            'FechaCreacion' => $this->stringifyDate($row['FechaCreacion'] ?? null),
            'FechaActualizacion' => $this->stringifyDate($row['FechaActualizacion'] ?? null),
            'metrics' => $payload['metrics'],
            'findingsCount' => count($findings),
            'failedFindingsCount' => $this->countFindingsByOutcome($findings, ['VALOR_DISTINTO', 'NO_ENCONTRADO']),
            'inconclusiveFindingsCount' => $this->countFindingsByOutcome($findings, ['NO_CONCLUYENTE']),
            'auditExecuted' => $this->isDetailAuditExecuted($row, $payload),
            'findings' => $findings,
            'fieldDecisions' => $payload['field_decisions'],
            'documentDecisions' => $payload['document_decisions'],
            'timings' => $payload['timings'],
        ];
    }

    private function isSummaryAuditExecuted(array $row): bool
    {
        if (((int) ($row['EstAud'] ?? 0)) === 1) {
            return true;
        }

        $status = strtolower(trim((string) ($row['EstadoDetallado'] ?? '')));
        if (!in_array($status, ['completed', 'manual_review', 'failed', 'error'], true)) {
            return false;
        }

        return ((int) ($row['DocumentosProcesados'] ?? 0)) > 0
            || ((int) ($row['TotalCampos'] ?? 0)) > 0;
    }

    private function isDetailAuditExecuted(array $row, array $payload): bool
    {
        if (((int) ($row['EstAud'] ?? 0)) === 1) {
            return true;
        }

        $status = strtolower(trim((string) ($row['EstadoDetallado'] ?? '')));
        if (!in_array($status, ['completed', 'manual_review', 'failed', 'error'], true)) {
            return false;
        }

        return ((int) ($row['DocumentosProcesados'] ?? 0)) > 0
            || count($payload['findings'] ?? []) > 0
            || is_array($payload['timings'] ?? null);
    }

    /**
     * Decodifica el contrato persistido actual de Hallazgos.
     *
     * @return array{
     *   findings:array<int,array<string,mixed>>,
     *   field_decisions:array<int,array<string,mixed>>,
     *   document_decisions:array<int,array<string,mixed>>,
     *   metrics:array<string,int>,
     *   timings:?array<string,mixed>,
     *   total_duration_ms:?int,
     * }
     */
    private function decodeAuditPayload(?string $raw): array
    {
        $empty = [
            'findings' => [],
            'field_decisions' => [],
            'document_decisions' => [],
            'metrics' => $this->normalizeMetrics([]),
            'timings' => null,
            'total_duration_ms' => null,
        ];

        if ($raw === null || trim($raw) === '') {
            return $empty;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return $empty;
        }

        $findings = $this->normalizeArrayList($decoded['items'] ?? []);

        return [
            'findings' => $findings,
            'field_decisions' => $this->normalizeArrayList($decoded['field_decisions'] ?? []),
            'document_decisions' => $this->normalizeArrayList($decoded['document_decisions'] ?? []),
            'metrics' => $this->normalizeMetrics($decoded['metrics'] ?? []),
            'timings' => is_array($decoded['timings'] ?? null) ? $decoded['timings'] : null,
            'total_duration_ms' => isset($decoded['total_duration_ms']) ? (int) $decoded['total_duration_ms'] : null,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function normalizeMetrics(mixed $metrics): array
    {
        $base = [
            'total_campos' => 0,
            'coincidencias' => 0,
            'discrepancias' => 0,
            'omitidos' => 0,
            'no_concluyentes' => 0,
            'risk_score' => 0,
        ];

        if (!is_array($metrics)) {
            return $base;
        }

        foreach (array_keys($base) as $key) {
            $base[$key] = (int) ($metrics[$key] ?? 0);
        }

        return $base;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeArrayList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @param  array<int,string>  $outcomes
     */
    private function countFindingsByOutcome(array $findings, array $outcomes): int
    {
        $count = 0;
        foreach ($findings as $finding) {
            if (in_array((string) ($finding['resultado'] ?? ''), $outcomes, true)) {
                $count++;
            }
        }

        return $count;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function stringifyDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $this->nullableString($value);
    }

    private function readWithFallback(callable $operation): mixed
    {
        try {
            return $this->read($operation);
        } catch (SqlServerOperationException $error) {
            if ($error->phase() !== 'connect' && !$error->retryExhausted()) {
                throw $error;
            }

            Logger::warning('AuditStatusModel: fallback de lectura hacia db2', [
                'preferredConnection' => $this->readConnectionName,
                'fallbackConnection' => 'db2',
                'phase' => $error->phase(),
                'sql_state' => $error->sqlState(),
            ]);

            return $this->executor->execute(
                'db2',
                SqlServerOperationMode::READ,
                $operation
            );
        }
    }

}
