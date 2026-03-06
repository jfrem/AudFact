<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use Core\Logger;

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
    /**
     * Busca un registro de auditoría por FacSec (PK).
     * @param string $facSec Secuencia única de la factura
     * @return array|false
     */
    public function getByFacSec(string $facSec): array|false
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

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':facSec', $facSec, PDO::PARAM_STR);
        $stmt->execute();

        Logger::info("AuditStatus: búsqueda por FacSec", [
            'facSec' => $facSec
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
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
     * Busca auditorías con filtros opcionales y paginación server-side.
     * @param array $filters  Filtros: facNitSec, facNro, dateFrom, dateTo
     * @param int   $page     Página actual (1-indexed)
     * @param int   $pageSize Registros por página (default 20)
     * @return array
     */
    public function searchAudits(array $filters, int $page = 1, int $pageSize = 20): array
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

        Logger::info("AuditStatus: searchAudits", [
            'filters' => array_keys($filters),
            'page'    => $page,
            'pageSize' => $pageSize,
            'results' => $stmt->rowCount()
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
     * Actualiza el resultado de auditoría IA en AdjuntosDispensacion.
     *
     * Resuelve la cadena de PKs: FacNro → (DisId, DisDetId) → AdjDisId → UPDATE.
     * Dos escenarios:
     *   - Aprobada: EstSop='C', Rec='N', campos de recobro NULL.
     *   - Rechazada: EstSop='R', Rec='S', observación textual, RecConSopCod=30.
     *
     * @param string $facNro Número de factura (ej: 'D03251203452')
     * @param bool $approved true si la auditoría aprobó el documento
     * @param string|null $observation Observación textual (solo para rechazadas)
     * @param string|null $documentoFallido Nombre del documento fallido (ej: 'FORMULA MEDICA')
     * @return bool true si el UPDATE fue exitoso
     */
    public function updateAuditResult(string $facNro, bool $approved, ?string $observation, ?string $documentoFallido): bool
    {
        $writeDb = $this->getWriteDb();

        try {
            // 1. Resolver DisId y DisDetId
            $sqlResolve = "SELECT TOP 1 d.DisId, d.DisDetId
                FROM DispensacionDetalleServicio d WITH (NOLOCK)
                WHERE d.DisDetNro = :facNro
                ORDER BY d.DisDetId ASC";

            $stmtResolve = $writeDb->prepare($sqlResolve);
            $stmtResolve->bindParam(':facNro', $facNro, PDO::PARAM_STR);
            $stmtResolve->execute();
            $dispensacion = $stmtResolve->fetch(PDO::FETCH_ASSOC);

            if (!$dispensacion) {
                Logger::warning('updateAuditResult: no se encontró DispensacionDetalleServicio', [
                    'FacNro' => $facNro,
                ]);
                return false;
            }

            $disId = $dispensacion['DisId'];
            $disDetId = $dispensacion['DisDetId'];

            if ($approved) {
                // 2a. APROBADA: actualizar TODOS los adjuntos de la dispensación
                $sql = "UPDATE AdjuntosDispensacion SET
                            AdjDisObsRec  = NULL,
                            RecConSopCod  = NULL,
                            AdjDisEstSop  = 'C',
                            AdjDisUsuAudi = 'Z-IA',
                            AdJDisFecAudi = GETDATE(),
                            AdjDisRec     = 'N',
                            AdjDisUsuRec  = NULL,
                            AdjDisFecRec  = NULL
                        WHERE DisId = :disId AND DisDetId = :disDetId";

                $stmt = $writeDb->prepare($sql);
                $stmt->bindParam(':disId', $disId, PDO::PARAM_STR);
                $stmt->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                $stmt->execute();

                Logger::info('updateAuditResult: todos los adjuntos aprobados', [
                    'DisId' => $disId,
                    'DisDetId' => $disDetId,
                    'FacNro' => $facNro,
                    'rowsAffected' => $stmt->rowCount(),
                ]);
            } else {
                // 2b. RECHAZADA: resolver AdjDisId por nombre (case-insensitive)
                if ($documentoFallido !== null) {
                    $sqlAdj = "SELECT TOP 1 a.AdjDisId
                        FROM AdjuntosDispensacion a WITH (NOLOCK)
                        WHERE a.DisId = :disId AND a.DisDetId = :disDetId
                          AND UPPER(a.AdjDisNom) = UPPER(:documentoFallido)
                        ORDER BY a.AdjDisId ASC";

                    $stmtAdj = $writeDb->prepare($sqlAdj);
                    $stmtAdj->bindParam(':disId', $disId, PDO::PARAM_STR);
                    $stmtAdj->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                    $stmtAdj->bindParam(':documentoFallido', $documentoFallido, PDO::PARAM_STR);
                } else {
                    // Sin documentoFallido → primer adjunto de la dispensación
                    $sqlAdj = "SELECT TOP 1 a.AdjDisId
                        FROM AdjuntosDispensacion a WITH (NOLOCK)
                        WHERE a.DisId = :disId AND a.DisDetId = :disDetId
                        ORDER BY a.AdjDisId ASC";

                    $stmtAdj = $writeDb->prepare($sqlAdj);
                    $stmtAdj->bindParam(':disId', $disId, PDO::PARAM_STR);
                    $stmtAdj->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                }
                $stmtAdj->execute();
                $adjunto = $stmtAdj->fetch(PDO::FETCH_ASSOC);

                if (!$adjunto) {
                    Logger::warning('updateAuditResult: no se encontró AdjuntosDispensacion para rechazo', [
                        'DisId' => $disId,
                        'DisDetId' => $disDetId,
                        'DocumentoFallido' => $documentoFallido,
                    ]);
                    return false;
                }

                $adjDisId = $adjunto['AdjDisId'];

                $sql = "UPDATE AdjuntosDispensacion SET
                            AdjDisObsRec  = :observation,
                            RecConSopCod  = 30,
                            AdjDisEstSop  = 'R',
                            AdjDisRec     = 'S',
                            AdjDisUsuRec  = 'Z-IA',
                            AdjDisFecRec  = GETDATE(),
                            AdjDisUsuAudi = 'Z-IA',
                            AdJDisFecAudi = GETDATE()
                        WHERE DisId = :disId AND DisDetId = :disDetId AND AdjDisId = :adjDisId";

                $stmt = $writeDb->prepare($sql);
                $stmt->bindParam(':disId', $disId, PDO::PARAM_STR);
                $stmt->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                $stmt->bindParam(':adjDisId', $adjDisId, PDO::PARAM_INT);
                $stmt->bindParam(':observation', $observation, PDO::PARAM_STR);
                $stmt->execute();

                Logger::info('updateAuditResult: adjunto rechazado', [
                    'DisId' => $disId,
                    'DisDetId' => $disDetId,
                    'AdjDisId' => $adjDisId,
                    'FacNro' => $facNro,
                ]);
            }

            return true;
        } catch (\PDOException $e) {
            Logger::error('updateAuditResult: error en UPDATE', [
                'FacNro' => $facNro,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getByFacSecFromConnection(PDO $connection, string $facSec): array|false
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
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
