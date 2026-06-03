<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOStatement;
use Core\Logger;
use Core\Cache;

/**
 * Modelo de estado de auditoría de dispensaciones.
 *
 * @important Dependencia cross-database: TODAS las queries de este modelo operan
 *            contra Discolnet.dbo.AudDispEst (SELECT, MERGE, INSERT). Esta tabla
 *            DEBE residir en la misma instancia SQL Server que la BD principal
 *            (DB_NAME). Si la topología cambia, este modelo dejará de funcionar.
 */
class AuditStatusModel extends Model
{
    private const AUDIT_USER = 'Z-IA';
    private const ATTACHMENT_STATUS_APPROVED = 'C';
    private const ATTACHMENT_STATUS_REJECTED = 'R';
    private const ATTACHMENT_REJECTED_FLAG = 'S';
    private const ATTACHMENT_APPROVED_FLAG = 'N';
    private const REJECTION_SUPPORT_CODE = 30;

    /**
     * Auditorías prefieren consistencia fuerte de lectura después de escritura.
     * Si `default` no está disponible, degrada a `db2` para no romper el histórico.
     *
     * @var string
     */
    protected string $readConnectionName = 'default';

    public function __construct()
    {
        try {
            $this->readDb = \Core\Database::getConnection($this->readConnectionName);
        } catch (\RuntimeException $e) {
            Logger::warning('AuditStatusModel: fallback de lectura hacia db2', [
                'preferredConnection' => $this->readConnectionName,
                'fallbackConnection' => 'db2',
                'error' => $e->getMessage(),
            ]);

            $this->readConnectionName = 'db2';
            $this->readDb = \Core\Database::getConnection($this->readConnectionName);
        }
    }

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
        $sql = "SELECT
                    [EstadoDetallado],
                    COUNT(*) AS total
                FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                GROUP BY [EstadoDetallado]";

        $stmt = $this->readDb->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byState = [];
        $grandTotal = 0;
        foreach ($rows as $row) {
            $state = strtoupper(trim((string) ($row['EstadoDetallado'] ?? 'UNKNOWN')));
            $count = (int) ($row['total'] ?? 0);
            $byState[$state] = $count;
            $grandTotal += $count;
        }

        $sqlDocs = "SELECT
                        COUNT(*) AS total
                    FROM AdjuntosDispensacion WITH (NOLOCK)
                    WHERE AdjDisUsuAudi IS NOT NULL";
        $stmtDocs = $this->readDb->prepare($sqlDocs);
        $stmtDocs->execute();
        $docsTotal = (int) ($stmtDocs->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $sqlLast = "SELECT TOP 1 [FechaCreacion]
                    FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                    ORDER BY [FechaCreacion] DESC";
        $stmtLast = $this->readDb->prepare($sqlLast);
        $stmtLast->execute();
        $lastRow = $stmtLast->fetch(PDO::FETCH_ASSOC);
        $lastAuditAt = $lastRow ? (string) ($lastRow['FechaCreacion'] ?? '') : null;

        return [
            'total'            => $grandTotal,
            'byState'          => $byState,
            'documentsAudited' => $docsTotal,
            'lastAuditAt'      => $lastAuditAt ?: null,
        ];
    }

    /**
     * Devuelve los tiempos por fase de una auditoría completada, buscando por FacNro (DisDetNro).
     *
     * @return array{fac_nro:string,fac_nit_sec:string,estado:string,phase_timings:array<string,mixed>|null,total_duration_ms:int}|null
     */
    public function getTimingsByFacNro(string $facNro): ?array
    {
        $sql = "SELECT TOP 1
                    [FacSec], [FacNro], [FacNitSec], [EstadoDetallado],
                    [DuracionProcesamientoMs], [Hallazgos]
                FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                WHERE [FacNro] = :facNro
                ORDER BY [FechaCreacion] DESC";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':facNro', $facNro, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

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
     * Obtiene el detalle público de auditoría por FacSec.
     *
     * @param  string  $facSec  Secuencia única de la factura.
     * @return array<string,mixed>|null Detalle con hallazgos, métricas, timings y decisiones documentales.
     */
    public function getAuditDetailByFacSec(string $facSec): ?array
    {
        $row = $this->fetchAuditRowByFacSec($this->readDb, $facSec);
        if ($row === null) {
            return null;
        }

        Logger::info("AuditStatus: búsqueda por FacSec", [
            'facSec' => $facSec
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
        $stmt = $this->readDb->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
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

        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT
                    [FacSec], [FacNro], [EstAud], [EstadoDetallado],
                    [RequiereRevisionHumana], [Severidad], [Hallazgos],
                    [DetalleError], [DocumentosProcesados], [DocumentoFallido],
                    [DuracionProcesamientoMs], [FacNitSec],
                    [FechaCreacion], [FechaActualizacion]
                FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
                {$where}
                ORDER BY [FechaCreacion] DESC
                OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

        $params[':offset'] = $offset;
        $params[':pageSize'] = $pageSize;

        $stmt = $this->readDb->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        Logger::info("AuditStatus: searchAuditSummaries", [
            'filters' => array_keys($filters),
            'page'    => $page,
            'pageSize' => $pageSize,
            'results' => $stmt->rowCount()
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            $conditions[] = '[FechaCreacion] >= :dateFrom';
            $params[':dateFrom'] = $filters['dateFrom'];
        }

        if (!empty($filters['dateTo'])) {
            $conditions[] = '[FechaCreacion] < DATEADD(day, 1, CAST(:dateTo AS DATE))';
            $params[':dateTo'] = $filters['dateTo'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    /**
     * Inserta o actualiza un resultado de auditoría usando MERGE de SQL Server.
     * Si FacSec existe → UPDATE; si no → INSERT.
     * @param array $data Datos de auditoría mapeados a columnas de AudDispEst
     * @return array|false Registro resultante
     */
    public function upsertAuditResult(array $data): array|false
    {
        if (empty($data['FacSec'])) {
            throw new \InvalidArgumentException('FacSec is required for upsert');
        }
        $writeDb = $this->getWriteDb();

        return $this->upsertAuditResultInConnection($writeDb, $data);
    }

    /**
     * Persiste el resumen global y actualiza el estado documental por adjunto
     * en una única transacción.
     *
     * @param array<string,mixed> $auditResultData
     * @param array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     */
    public function persistAuditResultWithAttachments(
        array $auditResultData,
        array $documentDecisions
    ): array|false {
        if (empty($auditResultData['FacSec']) || empty($auditResultData['FacNro'])) {
            throw new \InvalidArgumentException('FacSec y FacNro son obligatorios para persistir la auditoría.');
        }

        if ($documentDecisions === []) {
            throw new \InvalidArgumentException('La auditoría no produjo decisiones documentales para persistencia.');
        }

        $writeDb = $this->getWriteDb();

        Logger::info('AuditTrace: persist_audit_start', [
            'FacSec' => (string) $auditResultData['FacSec'],
            'FacNro' => (string) $auditResultData['FacNro'],
            'decisions_count' => count($documentDecisions),
        ]);

        try {
            $writeDb->beginTransaction();

            $record = $this->upsertAuditResultInConnection($writeDb, $auditResultData);
            $this->updateAttachmentAuditResultsInConnection(
                $writeDb,
                (string) $auditResultData['FacNro'],
                $documentDecisions,
            );

            $writeDb->commit();
            Cache::invalidateQueryResults((string) ($auditResultData['FacNitSec'] ?? 'all'));

            Logger::info('AuditTrace: persist_audit_committed', [
                'FacSec' => (string) $auditResultData['FacSec'],
                'FacNro' => (string) $auditResultData['FacNro'],
            ]);

            return $record;
        } catch (\Throwable $e) {
            if ($writeDb->inTransaction()) {
                $writeDb->rollBack();
            }

            Logger::error('persistAuditResultWithAttachments: transacción revertida', [
                'FacSec' => $auditResultData['FacSec'],
                'FacNro' => $auditResultData['FacNro'],
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Actualiza exclusivamente los timings finales de una auditoría ya persistida.
     *
     * @param  string  $facSec  Identificador canónico de la factura auditada.
     * @param  array<string,mixed>  $timings  Métricas finales del pipeline.
     * @param  int  $durationMs  Duración final en milisegundos.
     */
    public function updateAuditTimings(string $facSec, array $timings, int $durationMs): bool
    {
        $facSec = trim($facSec);
        if ($facSec === '') {
            throw new \InvalidArgumentException('FacSec es obligatorio para actualizar timings.');
        }

        $writeDb = $this->getWriteDb();
        $row = $this->fetchAuditRowByFacSec($writeDb, $facSec);
        if ($row === null) {
            return false;
        }

        $rawHallazgos = isset($row['Hallazgos']) && is_string($row['Hallazgos'])
            ? $row['Hallazgos']
            : '';
        $payload = json_decode($rawHallazgos, true);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException("Hallazgos inválido para actualizar timings de {$facSec}.");
        }

        $payload['timings'] = $timings;
        $payload['total_duration_ms'] = max(0, $durationMs);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('No se pudieron serializar los timings finales: ' . json_last_error_msg());
        }

        $sql = "UPDATE Discolnet.dbo.AudDispEst
                SET [Hallazgos] = :Hallazgos,
                    [DuracionProcesamientoMs] = :DuracionProcesamientoMs
                WHERE [FacSec] = :FacSec";

        $stmt = $writeDb->prepare($sql);
        $stmt->bindValue(':Hallazgos', $encoded, PDO::PARAM_STR);
        $stmt->bindValue(':DuracionProcesamientoMs', max(0, $durationMs), PDO::PARAM_INT);
        $stmt->bindValue(':FacSec', $facSec, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * @param \PDO $writeDb
     * @param array<string,mixed> $data
     */
    private function upsertAuditResultInConnection(\PDO $writeDb, array $data): array|false
    {
        $sql = "MERGE Discolnet.dbo.AudDispEst AS target
                USING (SELECT
                    :FacSec AS [FacSec],
                    :FacNro AS [FacNro],
                    :EstAud AS [EstAud],
                    :EstadoDetallado AS [EstadoDetallado],
                    :RequiereRevisionHumana AS [RequiereRevisionHumana],
                    :Severidad AS [Severidad],
                    :Hallazgos AS [Hallazgos],
                    :DetalleError AS [DetalleError],
                    :DocumentosProcesados AS [DocumentosProcesados],
                    :DocumentoFallido AS [DocumentoFallido],
                    :DuracionProcesamientoMs AS [DuracionProcesamientoMs],
                    :FacNitSec AS [FacNitSec]
                ) AS source
                ON target.[FacSec] = source.[FacSec]
                WHEN MATCHED THEN
                    UPDATE SET
                        target.[FacNro] = source.[FacNro],
                        target.[EstAud] = source.[EstAud],
                        target.[EstadoDetallado] = source.[EstadoDetallado],
                        target.[RequiereRevisionHumana] = source.[RequiereRevisionHumana],
                        target.[Severidad] = source.[Severidad],
                        target.[Hallazgos] = source.[Hallazgos],
                        target.[DetalleError] = source.[DetalleError],
                        target.[DocumentosProcesados] = source.[DocumentosProcesados],
                        target.[DocumentoFallido] = source.[DocumentoFallido],
                        target.[DuracionProcesamientoMs] = source.[DuracionProcesamientoMs],
                        target.[FacNitSec] = source.[FacNitSec]
                WHEN NOT MATCHED THEN
                    INSERT ([FacSec], [FacNro], [EstAud], [EstadoDetallado],
                            [RequiereRevisionHumana], [Severidad], [Hallazgos],
                            [DetalleError], [DocumentosProcesados], [DocumentoFallido],
                            [DuracionProcesamientoMs], [FacNitSec])
                    VALUES (source.[FacSec], source.[FacNro], source.[EstAud],
                            source.[EstadoDetallado], source.[RequiereRevisionHumana],
                            source.[Severidad], source.[Hallazgos], source.[DetalleError],
                            source.[DocumentosProcesados], source.[DocumentoFallido],
                            source.[DuracionProcesamientoMs], source.[FacNitSec]);";

        $stmt = $writeDb->prepare($sql);

        // Bind con tipos PDO explícitos por columna
        $stmt->bindParam(':FacSec', $data['FacSec'], PDO::PARAM_STR);
        $stmt->bindParam(':FacNro', $data['FacNro'], PDO::PARAM_STR);
        $stmt->bindParam(':EstAud', $data['EstAud'], PDO::PARAM_INT);
        $stmt->bindParam(':EstadoDetallado', $data['EstadoDetallado'], PDO::PARAM_STR);
        $stmt->bindParam(':RequiereRevisionHumana', $data['RequiereRevisionHumana'], PDO::PARAM_INT);
        $stmt->bindParam(':Severidad', $data['Severidad'], PDO::PARAM_STR);
        $stmt->bindParam(':Hallazgos', $data['Hallazgos'], PDO::PARAM_STR);
        $stmt->bindParam(':DetalleError', $data['DetalleError'], PDO::PARAM_STR);
        $stmt->bindParam(':DocumentosProcesados', $data['DocumentosProcesados'], PDO::PARAM_INT);
        $stmt->bindParam(':DocumentoFallido', $data['DocumentoFallido'], PDO::PARAM_STR);
        $stmt->bindParam(':DuracionProcesamientoMs', $data['DuracionProcesamientoMs'], PDO::PARAM_INT);
        $stmt->bindParam(':FacNitSec', $data['FacNitSec'], PDO::PARAM_STR);

        $stmt->execute();

        Logger::info("AuditStatus: upsert ejecutado", [
            'FacSec' => $data['FacSec'],
            'EstAud' => $data['EstAud']
        ]);

        return $this->getByFacSecFromConnection($writeDb, $data['FacSec']);
    }

    /**
     * Actualiza el estado documental por cada adjunto auditado de la dispensación.
     *
     * @param \PDO $connection
     * @param string $facNro
     * @param array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     */
    private function updateAttachmentAuditResultsInConnection(
        PDO $connection,
        string $facNro,
        array $documentDecisions
    ): void {
        $dispensation = $this->resolveDispensationIdentity($connection, $facNro);
        $disId = (string) $dispensation['DisId'];
        $disDetId = (int) $dispensation['DisDetId'];
        $adjuntos = $this->getDispensationAttachments($connection, $disId, $disDetId);

        if ($adjuntos === []) {
            throw new \RuntimeException("No se encontraron adjuntos para la dispensación {$facNro}.");
        }

        ['approve' => $approveStmt, 'reject' => $rejectStmt] = $this->buildAttachmentUpdateStatements($connection);

        foreach ($documentDecisions as $decision) {
            ['documentName' => $documentName, 'approved' => $approved, 'observation' => $observation] =
                $this->normalizeDocumentDecision($decision, $facNro);
            $match = $this->resolveAttachmentByDocumentName($adjuntos, $documentName);
            if ($match === null) {
                throw new \RuntimeException(sprintf(
                    'No se encontró AdjuntosDispensacion para el documento "%s" de la dispensación %s.',
                    $documentName,
                    $facNro
                ));
            }

            $adjDisId = (int) $match['AdjDisId'];

            if ($approved) {
                $this->applyApprovedAttachmentDecision($approveStmt, $disId, $disDetId, $adjDisId);
                continue;
            }

            $this->applyRejectedAttachmentDecision($rejectStmt, $disId, $disDetId, $adjDisId, $observation);
        }
    }

    /**
     * @return array{DisId:string|int,DisDetId:string|int}
     */
    private function resolveDispensationIdentity(PDO $connection, string $facNro): array
    {
        $sqlResolve = "SELECT TOP 1 d.DisId, d.DisDetId
            FROM DispensacionDetalleServicio d WITH (NOLOCK)
            WHERE d.DisDetNro = :facNro
            ORDER BY d.DisDetId ASC";

        $stmtResolve = $connection->prepare($sqlResolve);
        $stmtResolve->bindParam(':facNro', $facNro, PDO::PARAM_STR);
        $stmtResolve->execute();
        $dispensacion = $stmtResolve->fetch(PDO::FETCH_ASSOC);

        if (!$dispensacion) {
            throw new \RuntimeException("No se encontró DispensacionDetalleServicio para {$facNro}.");
        }

        return $dispensacion;
    }

    /**
     * @return array{approve:PDOStatement,reject:PDOStatement}
     */
    private function buildAttachmentUpdateStatements(PDO $connection): array
    {
        $approveSql = "UPDATE AdjuntosDispensacion SET
                            AdjDisObsRec  = NULL,
                            RecConSopCod  = NULL,
                            AdjDisEstSop  = :approvedStatus,
                            AdjDisUsuAudi = :auditUser,
                            AdJDisFecAudi = GETDATE(),
                            AdjDisRec     = :approvedFlag,
                            AdjDisUsuRec  = NULL,
                            AdjDisFecRec  = NULL
                        WHERE DisId = :disId AND DisDetId = :disDetId AND AdjDisId = :adjDisId";

        $rejectSql = "UPDATE AdjuntosDispensacion SET
                            AdjDisObsRec  = :observation,
                            RecConSopCod  = :supportCode,
                            AdjDisEstSop  = :rejectedStatus,
                            AdjDisRec     = :rejectedFlag,
                            AdjDisUsuRec  = :rejectedBy,
                            AdjDisFecRec  = GETDATE(),
                            AdjDisUsuAudi = :auditedBy,
                            AdJDisFecAudi = GETDATE()
                       WHERE DisId = :disId AND DisDetId = :disDetId AND AdjDisId = :adjDisId";

        return [
            'approve' => $connection->prepare($approveSql),
            'reject' => $connection->prepare($rejectSql),
        ];
    }

    /**
     * @param array<string,mixed> $decision
     * @return array{documentName:string,approved:bool,observation:?string}
     */
    private function normalizeDocumentDecision(array $decision, string $facNro): array
    {
        $documentName = trim((string) ($decision['documentName'] ?? ''));
        if ($documentName === '') {
            throw new \InvalidArgumentException("La decisión documental de {$facNro} no tiene documentName.");
        }

        $approved = (bool) ($decision['approved'] ?? false);
        $observation = trim((string) ($decision['observation'] ?? ''));

        if (!$approved && $observation === '') {
            throw new \InvalidArgumentException(sprintf(
                'El documento "%s" de la dispensación %s requiere observación de rechazo.',
                $documentName,
                $facNro
            ));
        }

        return [
            'documentName' => $documentName,
            'approved' => $approved,
            'observation' => $observation === '' ? null : $observation,
        ];
    }

    private function applyApprovedAttachmentDecision(PDOStatement $statement, string $disId, int $disDetId, int $adjDisId): void
    {
        $this->bindAttachmentIdentifiers($statement, $disId, $disDetId, $adjDisId);
        $statement->bindValue(':approvedStatus', self::ATTACHMENT_STATUS_APPROVED, PDO::PARAM_STR);
        $statement->bindValue(':auditUser', self::AUDIT_USER, PDO::PARAM_STR);
        $statement->bindValue(':approvedFlag', self::ATTACHMENT_APPROVED_FLAG, PDO::PARAM_STR);
        $statement->execute();
    }

    private function applyRejectedAttachmentDecision(
        PDOStatement $statement,
        string $disId,
        int $disDetId,
        int $adjDisId,
        ?string $observation
    ): void {
        $this->bindAttachmentIdentifiers($statement, $disId, $disDetId, $adjDisId);
        $statement->bindValue(':observation', $observation, PDO::PARAM_STR);
        $statement->bindValue(':supportCode', self::REJECTION_SUPPORT_CODE, PDO::PARAM_INT);
        $statement->bindValue(':rejectedStatus', self::ATTACHMENT_STATUS_REJECTED, PDO::PARAM_STR);
        $statement->bindValue(':rejectedFlag', self::ATTACHMENT_REJECTED_FLAG, PDO::PARAM_STR);
        $statement->bindValue(':rejectedBy', self::AUDIT_USER, PDO::PARAM_STR);
        $statement->bindValue(':auditedBy', self::AUDIT_USER, PDO::PARAM_STR);
        $statement->execute();
    }

    private function bindAttachmentIdentifiers(PDOStatement $statement, string $disId, int $disDetId, int $adjDisId): void
    {
        $statement->bindValue(':disId', $disId, PDO::PARAM_STR);
        $statement->bindValue(':disDetId', $disDetId, PDO::PARAM_INT);
        $statement->bindValue(':adjDisId', $adjDisId, PDO::PARAM_INT);
    }

    private function getByFacSecFromConnection(PDO $connection, string $facSec): array|false
    {
        $row = $this->fetchAuditRowByFacSec($connection, $facSec);
        if ($row === null) {
            return false;
        }

        return $this->normalizeAuditDetail($row);
    }

    /**
     * Obtiene una fila cruda de AudDispEst por FacSec.
     *
     * @return array<string,mixed>|null
     */
    private function fetchAuditRowByFacSec(PDO $connection, string $facSec): ?array
    {
        $sql = "SELECT
                    [FacSec],
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
                WHERE [FacSec] = :facSec";

        $stmt = $connection->prepare($sql);
        $stmt->bindParam(':facSec', $facSec, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Normaliza una fila de auditoría a summary público.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeAuditSummary(array $row): array
    {
        $payload = $this->decodeAuditPayload(
            isset($row['Hallazgos']) && is_string($row['Hallazgos']) ? $row['Hallazgos'] : null
        );

        return $this->buildAuditSummary($row, $payload);
    }

    /**
     * Normaliza una fila de auditoría a detalle público.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeAuditDetail(array $row): array
    {
        $payload = $this->decodeAuditPayload(
            isset($row['Hallazgos']) && is_string($row['Hallazgos']) ? $row['Hallazgos'] : null
        );

        return array_merge($this->buildAuditSummary($row, $payload), [
            'findings' => $payload['findings'],
            'fieldDecisions' => $payload['field_decisions'],
            'documentDecisions' => $payload['document_decisions'],
            'timings' => $payload['timings'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildAuditSummary(array $row, array $payload): array
    {
        $findings = $payload['findings'];

        return [
            'FacSec' => (string) ($row['FacSec'] ?? ''),
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
            'auditExecuted' => $this->isAuditExecuted($row, $payload),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $payload
     */
    private function isAuditExecuted(array $row, array $payload): bool
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

    /**
     * Obtiene los adjuntos de una dispensación para resolver rechazos por documento.
     *
     * @param PDO $connection Conexión de escritura activa
     * @param string $disId ID de dispensación
     * @param int $disDetId ID de detalle de dispensación
     * @return array<int, array{AdjDisId:string|int,AdjDisNom:string}>
     */
    private function getDispensationAttachments(PDO $connection, string $disId, int $disDetId): array
    {
        $sql = "SELECT a.AdjDisId, a.AdjDisNom
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                WHERE a.DisId = :disId AND a.DisDetId = :disDetId
                ORDER BY a.AdjDisId ASC";

        $stmt = $connection->prepare($sql);
        $stmt->bindParam(':disId', $disId, PDO::PARAM_STR);
        $stmt->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Resuelve un adjunto por nombre con fallback de normalización.
     *
     * Estrategia 1: match exacto case-insensitive (cubre >99% con schema dinámico).
     * Estrategia 2: normalización de texto (acentos, extensiones, caracteres especiales).
     *
     * @param array<int, array{AdjDisId:string|int,AdjDisNom:string}> $adjuntos
     * @param string $documentoFallido Nombre proveniente del pipeline
     * @return array{AdjDisId:string|int,AdjDisNom:string,strategy:string}|null
     */
    private function resolveAttachmentByDocumentName(array $adjuntos, string $documentoFallido): ?array
    {
        $input = trim($documentoFallido);
        if ($input === '') {
            return null;
        }

        $exactMatches = $this->findAttachmentMatches(
            $adjuntos,
            static fn(string $name) => strtoupper($name) === strtoupper($input),
            'exact_ci'
        );
        if (count($exactMatches) > 1) {
            throw new \RuntimeException(sprintf(
                'El documento "%s" coincide con múltiples adjuntos exactos en AdjuntosDispensacion.',
                $documentoFallido
            ));
        }
        if ($exactMatches !== []) {
            return $exactMatches[0];
        }

        $normalizedInput = $this->normalizeDocumentName($input);
        $normalizedMatches = $this->findAttachmentMatches(
            $adjuntos,
            fn(string $name) => $this->normalizeDocumentName($name) === $normalizedInput,
            'normalized'
        );
        if (count($normalizedMatches) > 1) {
            throw new \RuntimeException(sprintf(
                'El documento "%s" coincide con múltiples adjuntos normalizados en AdjuntosDispensacion.',
                $documentoFallido
            ));
        }
        if ($normalizedMatches !== []) {
            return $normalizedMatches[0];
        }

        return null;
    }

    /**
     * @param array<int, array{AdjDisId:string|int,AdjDisNom:string}> $adjuntos
     * @param callable(string):bool $matcher
     * @return array<int,array{AdjDisId:string|int,AdjDisNom:string,strategy:string}>
     */
    private function findAttachmentMatches(array $adjuntos, callable $matcher, string $strategy): array
    {
        $matches = [];

        foreach ($adjuntos as $adjunto) {
            $name = trim((string) ($adjunto['AdjDisNom'] ?? ''));
            if ($name === '' || !$matcher($name)) {
                continue;
            }

            $matches[] = [
                'AdjDisId' => $adjunto['AdjDisId'],
                'AdjDisNom' => $name,
                'strategy' => $strategy,
            ];
        }

        return $matches;
    }

    /**
     * Normaliza texto documental para comparación robusta.
     */
    private function normalizeDocumentName(string $value): string
    {
        $value = trim(strtoupper($value));

        // Quitar extensión común (ejemplo: ".PDF").
        $value = preg_replace('/\.[A-Z0-9]{2,5}$/', '', $value) ?? $value;

        // Eliminar acentos de forma portable.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }

        // Mantener solo alfanumérico y espacios.
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);

        return $value;
    }
}

