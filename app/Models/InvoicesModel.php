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
     * Obtiene facturas pendientes de auditoría por NIT y rango de fechas.
     *
     * El SQL siempre filtra por rango cerrado: DisFecSol >= :dateFromD AND DisFecSol <= :dateToD.
     * Para consultar un solo día, pasar dateFrom == dateTo.
     *
     * @param  int    $facNitSec  Identificador del cliente/NIT.
     * @param  string $dateFrom   Fecha inicial en formato YYYY-MM-DD.
     * @param  string $dateTo     Fecha final en formato YYYY-MM-DD.
     * @param  int    $limit      Máximo de resultados (1-1000).
     * @return array              Facturas encontradas.
     */
    public function getInvoices(int $facNitSec, string $dateFrom, string $dateTo, int $limit = 100): array
    {
        $limit = min(max($limit, 1), 1000);

        $safeLimit = (int) $limit;
        $sql = "SELECT TOP({$safeLimit}) tb3.FacNitSec NitSec,tb3.FacSec FacSec,tb2.DisDetNro Dispensa
                FROM Dispensacion tb1 WITH(NOLOCK) 
                left JOIN DispensacionDetalleServicio tb2  WITH(NOLOCK) on tb2.DisId=tb1.DisId
                left JOIN Factura tb3 WITH(NOLOCK) on tb3.DisId=tb2.DisId AND tb3.DisDetId=tb2.DisDetId
                left JOIN FacturaKardex tb4 WITH(NOLOCK) on tb4.FacSec=tb3.FacSec
                left join tipos t with(nolock) on t.TipCod=tb3.FacTipCod
                LEFT JOIN Discolnet.dbo.AudDispEst a WITH (NOLOCK) ON a.FacSec = tb3.FacSec
                LEFT JOIN (
                    select f.facnro Documento,k.artsec,f.DisId,f.DisDetId,sum(k.KarUni)KarUni
                    from Factura f WITH(NOLOCK)
                    inner JOIN FacturaKardex k WITH(NOLOCK) on k.FacSec=f.FacSec
                    inner join tipos t with(nolock) on t.TipCod=f.FacTipCod
                    Where t.FueCod='FACT' and f.FacEst='A'
                    group by f.facnro,k.artsec,f.DisId,f.DisDetId
                )f on f.DisId=tb2.DisId and f.DisdetId=tb2.DisDetId and f.artsec=tb4.artsec
                LEFT JOIN(
                    SELECT DisId,DisDetId,count(DisId)ca,sum(case when AdjDisEstSop='C' then 1 else 0 end)c from AdjuntosDispensacion with(nolock)
                    WHERE AdjDisOpc='N'
                    GROUP BY DisId,DisDetId
                )aud on aud.DisId=tb2.DisId and aud.DisDetId=tb2.DisDetId
                left join (
                    SELECT a.DisId,a.DisDetId,sum(case when DATALENGTH(a.AdjDisDocUrl)>0 OR DATALENGTH(a.AdjDisDoc) > 0 then 1 else 0 end)adj,
                    sum(case when n.NitMedDocId is not null then 1 else 0 end) adjobl
                    from AdjuntosDispensacion a with(nolock)
                    left join factura f with(nolock) on f.DisId=a.DisId and f.DisDetId=a.DisDetId
                    left join NitDocumentos n with(nolock) on n.nitsec=f.FacNitSec and n.NitMedDocCodAlt=a.AdjDisCodDocAlt and n.NitMedDocOpc='N'
                    GROUP BY a.DisId,a.DisDetId
                )docadj on docadj.DisId=tb2.DisId and docadj.DisDetId=tb2.DisDetId
                WHERE t.FueCod='DISP' and tb2.DisDetEst in ('A','P') and tb4.KarUni>0 and f.Documento is null
                    and tb1.DisFecSol >= :dateFromD AND tb1.DisFecSol <= :dateToD
                    AND tb3.FacNitSec = :facNitSec
                    AND tb2.DisTip in ('P','M')
                    AND tb2.DisDetEst = 'A'
                    AND (a.EstAud IS NULL)
                    AND isnull(aud.c,0)<isnull(aud.ca,0)
                    AND isnull(docadj.adj,0)>=isnull(docadj.adjobl,0)
                GROUP BY tb3.FacNitSec, tb3.FacSec, tb2.DisDetNro
                having sum(tb4.KarUniCP-tb4.KarUni) = 0
                ORDER BY MIN(tb1.DisFecSol) ASC, tb3.FacSec ASC, tb2.DisDetNro ASC";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':facNitSec', $facNitSec, PDO::PARAM_INT);
        $stmt->bindParam(':dateFromD', $dateFrom);
        $stmt->bindParam(':dateToD', $dateTo);

        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $maskedNitSec = '***' . substr((string) $facNitSec, -3);
        Logger::info("Executed SQL: ", [
            'facNitSec' => $maskedNitSec,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'result' => count($result ?? [])
        ]);

        return $result ?? [];
    }

    /**
     * Obtiene una página estable de facturas candidatas para auditoría batch.
     *
     * @param  int  $facNitSec  Identificador del cliente/NIT.
     * @param  string  $dateFrom  Fecha inicial en formato YYYY-MM-DD.
     * @param  string  $dateTo  Fecha final en formato YYYY-MM-DD.
     * @param  int  $limit  Máximo de candidatos por página (1-1000).
     * @param  array{date?:string,facSec?:string,dispensa?:string}|null  $cursor  Cursor keyset de la última fila leída.
     * @return array<int,array<string,mixed>>
     */
    public function getInvoicesForAuditBatch(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit = 100,
        ?array $cursor = null
    ): array {
        $limit = min(max($limit, 1), 1000);
        $safeLimit = (int) $limit;

        $cursorWhere = '';
        if ($cursor !== null && isset($cursor['date'], $cursor['facSec'], $cursor['dispensa'])) {
            $cursorWhere = "WHERE (
                    DisFecSol > :cursorDate
                    OR (DisFecSol = :cursorDate AND FacSec > :cursorFacSec)
                    OR (DisFecSol = :cursorDate AND FacSec = :cursorFacSec AND Dispensa > :cursorDispensa)
                )";
        }

        $sql = "WITH candidates AS (
                    SELECT
                        tb3.FacNitSec NitSec,
                        tb3.FacSec FacSec,
                        tb2.DisDetNro Dispensa,
                        MIN(tb1.DisFecSol) DisFecSol
                    FROM Dispensacion tb1 WITH(NOLOCK)
                    left JOIN DispensacionDetalleServicio tb2  WITH(NOLOCK) on tb2.DisId=tb1.DisId
                    left JOIN Factura tb3 WITH(NOLOCK) on tb3.DisId=tb2.DisId AND tb3.DisDetId=tb2.DisDetId
                    left JOIN FacturaKardex tb4 WITH(NOLOCK) on tb4.FacSec=tb3.FacSec
                    left join tipos t with(nolock) on t.TipCod=tb3.FacTipCod
                    LEFT JOIN Discolnet.dbo.AudDispEst a WITH (NOLOCK) ON a.FacSec = tb3.FacSec
                    LEFT JOIN (
                        select f.facnro Documento,k.artsec,f.DisId,f.DisDetId,sum(k.KarUni)KarUni
                        from Factura f WITH(NOLOCK)
                        inner JOIN FacturaKardex k WITH(NOLOCK) on k.FacSec=f.FacSec
                        inner join tipos t with(nolock) on t.TipCod=f.FacTipCod
                        Where t.FueCod='FACT' and f.FacEst='A'
                        group by f.facnro,k.artsec,f.DisId,f.DisDetId
                    )f on f.DisId=tb2.DisId and f.DisdetId=tb2.DisDetId and f.artsec=tb4.artsec
                    LEFT JOIN(
                        SELECT DisId,DisDetId,count(DisId)ca,sum(case when AdjDisEstSop='C' then 1 else 0 end)c from AdjuntosDispensacion with(nolock)
                        WHERE AdjDisOpc='N'
                        GROUP BY DisId,DisDetId
                    )aud on aud.DisId=tb2.DisId and aud.DisDetId=tb2.DisDetId
                    left join (
                        SELECT a.DisId,a.DisDetId,sum(case when DATALENGTH(a.AdjDisDocUrl)>0 OR DATALENGTH(a.AdjDisDoc) > 0 then 1 else 0 end)adj,
                        sum(case when n.NitMedDocId is not null then 1 else 0 end) adjobl
                        from AdjuntosDispensacion a with(nolock)
                        left join factura f with(nolock) on f.DisId=a.DisId and f.DisDetId=a.DisDetId
                        left join NitDocumentos n with(nolock) on n.nitsec=f.FacNitSec and n.NitMedDocCodAlt=a.AdjDisCodDocAlt and n.NitMedDocOpc='N'
                        GROUP BY a.DisId,a.DisDetId
                    )docadj on docadj.DisId=tb2.DisId and docadj.DisDetId=tb2.DisDetId
                    WHERE t.FueCod='DISP' and tb2.DisDetEst in ('A','P') and tb4.KarUni>0 and f.Documento is null
                        and tb1.DisFecSol >= :dateFromD AND tb1.DisFecSol <= :dateToD
                        AND tb3.FacNitSec = :facNitSec
                        AND tb2.DisTip in ('P','M')
                        AND tb2.DisDetEst = 'A'
                        AND (a.EstAud IS NULL)
                        AND isnull(aud.c,0)<isnull(aud.ca,0)
                        AND isnull(docadj.adj,0)>=isnull(docadj.adjobl,0)
                    GROUP BY tb3.FacNitSec, tb3.FacSec, tb2.DisDetNro
                    having sum(tb4.KarUniCP-tb4.KarUni) = 0
                )
                SELECT TOP({$safeLimit})
                    NitSec,
                    FacSec,
                    Dispensa,
                    CONVERT(varchar(33), DisFecSol, 126) AS DisFecSol
                FROM candidates
                {$cursorWhere}
                ORDER BY DisFecSol ASC, FacSec ASC, Dispensa ASC";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindValue(':facNitSec', $facNitSec, PDO::PARAM_INT);
        $stmt->bindValue(':dateFromD', $dateFrom);
        $stmt->bindValue(':dateToD', $dateTo);

        if ($cursorWhere !== '') {
            $stmt->bindValue(':cursorDate', (string) $cursor['date']);
            $stmt->bindValue(':cursorFacSec', (string) $cursor['facSec']);
            $stmt->bindValue(':cursorDispensa', (string) $cursor['dispensa']);
        }

        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $maskedNitSec = '***' . substr((string) $facNitSec, -3);
        Logger::info('Executed SQL audit batch candidates', [
            'facNitSec' => $maskedNitSec,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'cursor' => $cursor,
            'result' => count($result ?? []),
        ]);

        return $result ?? [];
    }
}
