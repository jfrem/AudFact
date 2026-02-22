<?php

namespace App\Models;

use PDO;
use Core\Logger;

class AuditStatusModel extends Model
{
    /**
     * Busca un registro de auditoría por FacSec (PK).
     * @param string $facSec Secuencia única de la factura
     * @return array|false
     */
    public function getByFacSec(string $facSec)
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

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':facSec', $facSec, PDO::PARAM_STR);
        $stmt->execute();

        Logger::info("AuditStatus: búsqueda por FacSec", [
            'facSec' => $facSec
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
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

        $stmt = $this->db->prepare($sql);

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

        return $this->getByFacSec($data['FacSec']);
    }
}
