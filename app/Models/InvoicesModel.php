<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use PDO;
use PDOStatement;

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
    private const SEARCH_MAX_PAGE_SIZE = 100;

    /**
     * Obtiene una página de facturas pendientes de auditoría por NIT y rango de fechas.
     *
     * El SQL siempre filtra por rango cerrado: DisFecSol >= :dateFromD AND DisFecSol <= :dateToD.
     * Para consultar un solo día, pasar dateFrom == dateTo.
     *
     * @param  array{facNitSec:int,dateFrom:string,dateTo:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function searchInvoices(array $filters, int $page = 1, int $pageSize = 20): array
    {
        [$page, $pageSize] = $this->normalizeSearchPagination($page, $pageSize);
        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT NitSec, FacSec, Dispensa
                FROM (
                    {$this->invoiceCandidatesSql()}
                ) candidates
                ORDER BY DisFecSol ASC, FacSec ASC, Dispensa ASC
                OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

        $stmt = $this->readDb->prepare($sql);
        $this->bindInvoiceFilters($stmt, $filters);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);

        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $maskedNitSec = '***' . substr((string) $filters['facNitSec'], -3);
        Logger::info('Executed SQL invoice search', [
            'facNitSec' => $maskedNitSec,
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'page' => $page,
            'pageSize' => $pageSize,
            'result' => count($result ?? [])
        ]);

        return $result ?? [];
    }

    /**
     * Cuenta facturas pendientes de auditoría usando los mismos filtros de searchInvoices.
     *
     * @param  array{facNitSec:int,dateFrom:string,dateTo:string} $filters
     */
    public function countInvoices(array $filters): int
    {
        $sql = "SELECT COUNT(1) AS total
                FROM (
                    {$this->invoiceCandidatesSql()}
                ) candidates";

        $stmt = $this->readDb->prepare($sql);
        $this->bindInvoiceFilters($stmt, $filters);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Subquery estable de candidatas pendientes; no usar CTE por compatibilidad con pdo_sqlsrv.
     */
    private function invoiceCandidatesSql(): string
    {
        return "SELECT tb3.FacNitSec NitSec,tb3.FacSec FacSec,tb2.DisDetNro Dispensa,MIN(tb1.DisFecSol) DisFecSol
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
                having sum(tb4.KarUniCP-tb4.KarUni) = 0";
    }

    /**
     * @param array{facNitSec:int,dateFrom:string,dateTo:string} $filters
     */
    private function bindInvoiceFilters(PDOStatement $stmt, array $filters): void
    {
        $stmt->bindValue(':facNitSec', (int) $filters['facNitSec'], PDO::PARAM_INT);
        $stmt->bindValue(':dateFromD', (string) $filters['dateFrom']);
        $stmt->bindValue(':dateToD', (string) $filters['dateTo']);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function normalizeSearchPagination(int $page, int $pageSize): array
    {
        $page = max($page, 1);
        $pageSize = min(max($pageSize, 1), self::SEARCH_MAX_PAGE_SIZE);

        return [$page, $pageSize];
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
                    DisFecSol > :cursorDate1
                    OR (DisFecSol = :cursorDate2 AND FacSec > :cursorFacSec1)
                    OR (DisFecSol = :cursorDate3 AND FacSec = :cursorFacSec2 AND Dispensa > :cursorDispensa1)
                )";
        }

        $sql = "SELECT TOP({$safeLimit})
                    NitSec,
                    FacSec,
                    Dispensa,
                    CONVERT(varchar(33), DisFecSol, 126) AS DisFecSol
                FROM (
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
                ) candidates
                {$cursorWhere}
                ORDER BY DisFecSol ASC, FacSec ASC, Dispensa ASC";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindValue(':facNitSec', $facNitSec, PDO::PARAM_INT);
        $stmt->bindValue(':dateFromD', $dateFrom);
        $stmt->bindValue(':dateToD', $dateTo);

        if ($cursorWhere !== '') {
            $stmt->bindValue(':cursorDate1', (string) $cursor['date']);
            $stmt->bindValue(':cursorDate2', (string) $cursor['date']);
            $stmt->bindValue(':cursorDate3', (string) $cursor['date']);

            $stmt->bindValue(':cursorFacSec1', (string) $cursor['facSec']);
            $stmt->bindValue(':cursorFacSec2', (string) $cursor['facSec']);

            $stmt->bindValue(':cursorDispensa1', (string) $cursor['dispensa']);
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
