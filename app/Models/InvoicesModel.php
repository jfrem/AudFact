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
                left join (select f.DisId,f.DisdetId,f.artsec,f.Documento,sum(f.KarUni)KarUni from vw_discolnet_facturas f with(nolock) where f.Fecha>= :date
                    group by f.DisId,f.DisdetId,f.artsec,f.Documento
                )f on f.DisId=d.facsec and f.DisdetId=d.DisDetId and f.artsec=d.artsec
                left join(
                    select DisId,DisDetId,count(DisId)ca,sum(case when AdjDisEstSop='C' then 1 else 0 end)c from AdjuntosDispensacion with(nolock)
                    where AdjDisOpc='N'
                    group by DisId,DisDetId
                )aud on aud.DisId=d.facsec and aud.DisDetId=d.DisDetId
                WHERE d.Fecha_solicitud = :date2
                    AND d.NitSec = :facNitSec
                    AND d.Tipo_servicio in ('POS','MIPRES')
                    AND d.pendientes = 0
                    AND d.estadodisp = 'A'
                    AND (a.EstAud IS NULL)
                    AND aud.c<aud.ca
                GROUP BY d.NitSec, d.FacSec, d.Dispensa,aud.c,aud.ca
                having sum(isnull(f.KarUni,0))=0";
        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':facNitSec', $facNitSec, PDO::PARAM_INT);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':date2', $date);
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
