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
     * Inserta una observación de auditoría IA en AdjuntosDispensacionDetalle.
     *
     * Resuelve la cadena de PKs: FacNro → (DisId, DisDetId) → AdjDisId → INSERT.
     * Retry una vez en caso de duplicate key (SQLSTATE 23000).
     *
     * @param string $facNro Número de factura (ej: 'D03251203452')
     * @param string $observation Observación textual de la IA
     * @param string|null $documentoFallido Nombre del documento fallido (ej: 'FORMULA MEDICA')
     * @return bool true si la inserción fue exitosa
     */
    public function insertAuditObservation(string $facNro, string $observation, ?string $documentoFallido): bool
    {
        $maxRetries = 2;
        $writeDb = $this->getWriteDb();

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // 1. Resolver DisId y DisDetId
                $sqlResolve = "SELECT TOP 1 d.DisId, d.DisDetId
                    FROM DispensacionDetalleServicio d WITH (NOLOCK)
                    WHERE d.DisDetNro = :facNro";

                $stmtResolve = $writeDb->prepare($sqlResolve);
                $stmtResolve->bindParam(':facNro', $facNro, PDO::PARAM_STR);
                $stmtResolve->execute();
                $dispensacion = $stmtResolve->fetch(PDO::FETCH_ASSOC);

                if (!$dispensacion) {
                    Logger::warning('insertAuditObservation: no se encontró DispensacionDetalleServicio', [
                        'FacNro' => $facNro,
                    ]);
                    return false;
                }

                $disId = $dispensacion['DisId'];
                $disDetId = $dispensacion['DisDetId'];

                // 2. Resolver AdjDisId y AdjDisNom
                $sqlAdj = "SELECT TOP 1 a.AdjDisId, a.AdjDisNom
                    FROM AdjuntosDispensacion a WITH (NOLOCK)
                    WHERE a.DisId = :disId AND a.DisDetId = :disDetId
                      AND a.AdjDisNom = :documentoFallido";

                $stmtAdj = $writeDb->prepare($sqlAdj);
                $stmtAdj->bindParam(':disId', $disId, PDO::PARAM_STR);
                $stmtAdj->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                $stmtAdj->bindParam(':documentoFallido', $documentoFallido, PDO::PARAM_STR);
                $stmtAdj->execute();
                $adjunto = $stmtAdj->fetch(PDO::FETCH_ASSOC);

                // Fallback: primer adjunto si no hay match por nombre
                if (!$adjunto) {
                    $sqlFallback = "SELECT TOP 1 a.AdjDisId, a.AdjDisNom
                        FROM AdjuntosDispensacion a WITH (NOLOCK)
                        WHERE a.DisId = :disId AND a.DisDetId = :disDetId
                        ORDER BY a.AdjDisId ASC";

                    $stmtFallback = $writeDb->prepare($sqlFallback);
                    $stmtFallback->bindParam(':disId', $disId, PDO::PARAM_STR);
                    $stmtFallback->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                    $stmtFallback->execute();
                    $adjunto = $stmtFallback->fetch(PDO::FETCH_ASSOC);
                }

                if (!$adjunto) {
                    Logger::warning('insertAuditObservation: no se encontró AdjuntosDispensacion', [
                        'DisId' => $disId,
                        'DisDetId' => $disDetId,
                        'DocumentoFallido' => $documentoFallido,
                    ]);
                    return false;
                }

                $adjDisId = $adjunto['AdjDisId'];
                $adjDisNom = $adjunto['AdjDisNom'];
                $subRecConSopCod = 30; // RESPUESTA AUDITORIA AUTOMATIZADA

                // 3. Idempotencia: verificar si ya existe observación de Z-IA
                $sqlExists = "SELECT COUNT(1) FROM AdjuntosDispensacionDetalle WITH (NOLOCK)
                    WHERE DisId = :disId AND DisDetId = :disDetId
                      AND AdjDisId = :adjDisId AND DisDetAdjDetUsuCod = 'Z-IA'";

                $stmtExists = $writeDb->prepare($sqlExists);
                $stmtExists->bindParam(':disId', $disId, PDO::PARAM_STR);
                $stmtExists->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                $stmtExists->bindParam(':adjDisId', $adjDisId, PDO::PARAM_INT);
                $stmtExists->execute();

                if ((int) $stmtExists->fetchColumn() > 0) {
                    Logger::info('insertAuditObservation: observación Z-IA ya existe, skip', [
                        'DisId' => $disId,
                        'DisDetId' => $disDetId,
                        'AdjDisId' => $adjDisId,
                        'FacNro' => $facNro,
                    ]);
                    return true; // Idempotente — no es error
                }

                // 4. INSERT con transacción y bloqueo pesimista
                $writeDb->beginTransaction();

                // Obtener siguiente secuencia con UPDLOCK, HOLDLOCK
                $sqlNextSec = "SELECT ISNULL(MAX(det.DisDetAdjDetSec), 0) + 1 AS nextSec
                    FROM AdjuntosDispensacionDetalle det WITH (UPDLOCK, HOLDLOCK)
                    WHERE det.DisId = :disId
                      AND det.DisDetId = :disDetId
                      AND det.AdjDisId = :adjDisId";

                $stmtSec = $writeDb->prepare($sqlNextSec);
                $stmtSec->bindParam(':disId', $disId, PDO::PARAM_STR);
                $stmtSec->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                $stmtSec->bindParam(':adjDisId', $adjDisId, PDO::PARAM_INT);
                $stmtSec->execute();
                $nextSec = (int) $stmtSec->fetchColumn();

                // INSERT
                $sqlInsert = "INSERT INTO AdjuntosDispensacionDetalle (
                        DisId, DisDetId, AdjDisId, DisDetAdjDetSec,
                        DisDetAdjDetFecHor, DisDetAdjDetUsuCod,
                        DisDetAdjDetObsRec, DisDetAdjDetDisNom,
                        SubRecConSopCod
                    ) VALUES (
                        :disId, :disDetId, :adjDisId, :nextSec,
                        GETDATE(), :usuario, :observacion, :adjDisNom, :subRecConSopCod
                    )";

                $stmtInsert = $writeDb->prepare($sqlInsert);
                $stmtInsert->bindParam(':disId', $disId, PDO::PARAM_STR);
                $stmtInsert->bindParam(':disDetId', $disDetId, PDO::PARAM_INT);
                $stmtInsert->bindParam(':adjDisId', $adjDisId, PDO::PARAM_INT);
                $stmtInsert->bindParam(':nextSec', $nextSec, PDO::PARAM_INT);
                $usuario = 'Z-IA';
                $stmtInsert->bindParam(':usuario', $usuario, PDO::PARAM_STR);
                $stmtInsert->bindParam(':observacion', $observation, PDO::PARAM_STR);
                $stmtInsert->bindParam(':adjDisNom', $adjDisNom, PDO::PARAM_STR);
                $stmtInsert->bindParam(':subRecConSopCod', $subRecConSopCod, PDO::PARAM_INT);
                $stmtInsert->execute();

                $writeDb->commit();

                Logger::info('insertAuditObservation: observación insertada', [
                    'DisId' => $disId,
                    'DisDetId' => $disDetId,
                    'AdjDisId' => $adjDisId,
                    'Sec' => $nextSec,
                    'FacNro' => $facNro,
                ]);

                return true;
            } catch (\PDOException $e) {
                // Rollback si la transacción está activa
                if ($writeDb->inTransaction()) {
                    $writeDb->rollBack();
                }

                // Retry en caso de duplicate key (SQLSTATE 23000)
                if ($e->getCode() === '23000' && $attempt < $maxRetries) {
                    Logger::warning('insertAuditObservation: duplicate key, reintentando', [
                        'FacNro' => $facNro,
                        'attempt' => $attempt,
                    ]);
                    continue;
                }

                Logger::error('insertAuditObservation: error en inserción', [
                    'FacNro' => $facNro,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);
                return false;
            }
        }

        return false;
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
