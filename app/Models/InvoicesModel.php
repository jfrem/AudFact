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
    /**
     * Obtiene facturas pendientes de auditoría por NIT y fecha o rango de fechas.
     *
     * @param  int         $facNitSec  Identificador del cliente/NIT.
     * @param  string      $dateFrom   Fecha inicial en formato YYYY-MM-DD.
     * @param  string|null $dateTo     Fecha final opcional en formato YYYY-MM-DD.
     * @param  int         $limit      Máximo de resultados (1-1000).
     * @return array                   Facturas encontradas.
     */
    public function getInvoices(int $facNitSec, string $dateFrom, ?string $dateTo = null, int $limit = 100): array
    {
        $limit = min(max($limit, 1), 1000);

        // Determinar condición de fecha principal
        $dateConditionD = $dateTo
            ? "d.Fecha_solicitud >= :dateFromD AND d.Fecha_solicitud <= :dateToD"
            : "d.Fecha_solicitud = :dateFromD";

        $dateConditionF = $dateTo
            ? "f.Fecha >= :dateFromF AND f.Fecha <= :dateToF"
            : "f.Fecha >= :dateFromF";

        $sql = "SELECT TOP ({$limit})
                    d.NitSec,
                    d.FacSec,
                    d.Dispensa
                FROM vw_discolnet_dispensas d
                LEFT JOIN Discolnet.dbo.AudDispEst a WITH (NOLOCK) ON a.FacSec = d.FacSec
                left join (select f.DisId,f.DisdetId,f.artsec,f.Documento,sum(f.KarUni)KarUni from vw_discolnet_facturas f with(nolock) where {$dateConditionF}
                    group by f.DisId,f.DisdetId,f.artsec,f.Documento
                )f on f.DisId=d.facsec and f.DisdetId=d.DisDetId and f.artsec=d.artsec
                left join(
                    select DisId,DisDetId,count(DisId)ca,sum(case when AdjDisEstSop='C' then 1 else 0 end)c from AdjuntosDispensacion with(nolock)
                    where AdjDisOpc='N'
                    group by DisId,DisDetId
                )aud on aud.DisId=d.facsec and aud.DisDetId=d.DisDetId
                WHERE {$dateConditionD}
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
        $stmt->bindParam(':dateFromD', $dateFrom);
        $stmt->bindParam(':dateFromF', $dateFrom);
        if ($dateTo) {
            $stmt->bindParam(':dateToD', $dateTo);
            $stmt->bindParam(':dateToF', $dateTo);
        }
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Logger::info("Executed SQL: ", [
            'facNitSec' => $facNitSec,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'result' => count($result ?? [])
        ]);

        return $result ?? [];
    }
}
