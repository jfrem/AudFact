<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use Core\Logger;

/**
 * Modelo de facturas/dispensaciones pendientes de auditoría.
 *
 * @important Dependencia cross-database: este modelo ejecuta JOINs contra
 *            Discolnet.dbo.AudDispEst, que DEBE residir en la misma instancia
 *            SQL Server que la BD principal (DB_NAME). Si la topología cambia,
 *            las queries cross-database dejarán de funcionar.
 */
class InvoicesModel extends Model
{
    public function getInvoices(int $facNitSec, string $date, int $limit = 100): array
    {
        $limit = min(max($limit, 1), 1000);
        $sql = "SELECT TOP (:limit)
                d.NitSec,
                d.FacSec,
                d.Dispensa
            FROM vw_discolnet_dispensas d
            LEFT JOIN Discolnet.dbo.AudDispEst a WITH (NOLOCK) ON a.FacSec = d.FacSec
            WHERE
                d.Fecha_solicitud = :date
                AND d.NitSec = :facNitSec
                AND d.Tipo_servicio in ('POS','MIPRES')
                AND d.pendientes = 0
                AND d.estadodisp = 'A'
                AND (a.EstAud IS NULL)
            GROUP BY d.NitSec, d.FacSec, d.Dispensa";
        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':facNitSec', $facNitSec, PDO::PARAM_INT);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Logger::info("Executed SQL: ", [
            'facNitSec' => $facNitSec,
            'date' => $date,
            'limit' => $limit,
            'result' => count($result ?? [])
        ]);
        return $result ?? [];
    }
}
