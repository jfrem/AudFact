<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use PDO;
use PDOStatement;

/**
 * Modelo de facturas/dispensaciones pendientes de auditoría.
 */
class InvoicesModel extends Model
{
    private const SEARCH_MAX_PAGE_SIZE = 100;

    /**
     * Obtiene una página de facturas pendientes de auditoría por NIT y rango de fechas.
     *
     * @param  array{facNitSec:int,dateFrom:string,dateTo:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function searchInvoices(array $filters, int $page = 1, int $pageSize = 20): array
    {
        [$page, $pageSize] = $this->normalizeSearchPagination($page, $pageSize);
        $offset = ($page - 1) * $pageSize;

        $finalSelect = "
            SELECT s.Nitsec AS NitSec, s.DisId, s.Dispensa
            FROM (
                SELECT d.Nitsec, d.DisId, d.DisDetId, d.Tercero, d.fecha, d.Dispensa, isnull(s.estSop,'S') Auditoria,
                s.SopOk, case when sum(d.Pend)>0 then 'S' else 'N' end Pend
                FROM #CRUZE AS d
                left join #Sopo s on s.DisId=d.DisId and s.DisDetId=d.DisDetId
                where s.SopOk='1'
                group by d.Nitsec, d.DisId, d.DisDetId, d.Tercero, d.fecha, d.Dispensa, isnull(s.estSop,'S'), s.SopOk
            ) s
            WHERE s.Pend='N'
            ORDER BY s.fecha ASC, s.DisId ASC, s.Dispensa ASC
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY;
        ";

        $sql = $this->buildOptimizedBatchSql($finalSelect);

        $result = $this->read(function (PDO $connection) use (
            $sql,
            $filters,
            $offset,
            $pageSize
        ): array {
            $stmt = $connection->prepare($sql);
            $this->bindInvoiceFilters($stmt, $filters);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

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
        $finalSelect = "
            SELECT COUNT(1) AS total
            FROM (
                SELECT d.Nitsec, d.DisId, d.DisDetId, d.Tercero, d.fecha, d.Dispensa, isnull(s.estSop,'S') Auditoria,
                s.SopOk, case when sum(d.Pend)>0 then 'S' else 'N' end Pend
                FROM #CRUZE AS d
                left join #Sopo s on s.DisId=d.DisId and s.DisDetId=d.DisDetId
                where s.SopOk='1'
                group by d.Nitsec, d.DisId, d.DisDetId, d.Tercero, d.fecha, d.Dispensa, isnull(s.estSop,'S'), s.SopOk
            ) s
            WHERE s.Pend='N';
        ";

        $sql = $this->buildOptimizedBatchSql($finalSelect);

        $row = $this->read(function (PDO $connection) use ($sql, $filters): array|false {
            $stmt = $connection->prepare($sql);
            $this->bindInvoiceFilters($stmt, $filters);
            $stmt->execute();

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Genera el script procedimental con tablas temporales optimizadas y anexa el SELECT final deseado.
     */
    private function buildOptimizedBatchSql(string $finalSelect): string
    {
        return "SET NOCOUNT ON;

                IF OBJECT_ID('tempdb..#DISP') IS NOT NULL DROP TABLE #DISP;
                select f.FacNitSec,dd.DisDetNro Dispensa,isnull(dd.DisAplForRec,'N') Apl,cast(d.DisFecSol as date) fecha,n.NitCom Tercero,pc.PerCliNom Perfil,d.DisId,dd.DisDetId,f.FacSec,sum(k.KarUniCp-k.karuni)Pend
                ,sum(k.KarUni*k.KarPre)Costo,sum(k.karuniCP*k.KarPrePub)Valor,dd.DisDetUsuAud Auditoria,dd.DisTip,dd.DisDetEst est
                INTO #Disp
                from Dispensacion d WITH(NOLOCK) 
                inner JOIN DispensacionDetalleServicio dd WITH(NOLOCK) on dd.DisId=d.DisId
                inner JOIN Factura f WITH(NOLOCK) on f.DisId=dd.DisId AND f.DisDetId=dd.DisDetId
                inner JOIN FacturaKardex k WITH(NOLOCK) on k.FacSec=f.FacSec
                inner join tipos t with(nolock) on t.TipCod=f.FacTipCod
                inner join nit n with(nolock) on n.NitSec=f.FacNitSec
                left join Clientes c with(nolock) on c.NitSec=f.FacNitSec and c.CliSec=f.FacCliSec
                left join PerfilDeClientes pc with(nolock) on pc.PerCliCod=c.PerCliCod
                where t.FueCod='DISP' and dd.DisDetEst = 'A'
                and d.DisFecSol>=:dateFromD and d.DisFecSol<=:dateToD
                and dd.DisTip<>'A' and k.KarUniCP>0 and isnull(dd.DisAplForRec,'N')='N'
                and f.FacNitSec=:facNitSec
                group by f.FacNitSec,dd.DisDetNro,cast(d.DisFecSol as date),d.DisId,dd.DisDetId,f.FacSec,n.NitCom,dd.DisDetUsuAud,pc.PerCliNom,dd.DisTip,dd.DisDetEst,isnull(dd.DisAplForRec,'N');
                
                CREATE CLUSTERED INDEX IX_TMP_DISP ON #DISP (Disid,DisDetId);

                IF OBJECT_ID('tempdb..#FACT') IS NOT NULL DROP TABLE #FACT;
                select f.DisId,f.DisDetId,f.FacNro factura
                Into #FACT
                from #DISP d
                inner join Factura f WITH(NOLOCK) on f.DisId=d.DisId and f.DisDetId=d.DisDetId
                inner JOIN DispensacionDetalleServicio dd  WITH(NOLOCK) on dd.DisId=f.DisId and dd.DisDetId=f.DisDetId
                inner join tipos t with(nolock) on t.TipCod=f.FacTipCod
                where t.FueCod='FACT' and f.FacEst='A' 
                group by f.DisId,f.DisdetId,f.FacNro;
                
                CREATE CLUSTERED INDEX IX_TMP_FACT ON #FACT (DisId,DisDetId);

                IF OBJECT_ID('tempdb..#FACT1') IS NOT NULL DROP TABLE #FACT1;
                select k.FacSecRem,f.FacNro factura
                Into #FACT1
                from #DISP d
                inner JOIN FacturaKardex k WITH(NOLOCK) on k.FacSecRem=d.FacSec
                inner join Factura f WITH(NOLOCK) on f.facsec=k.FacSec
                inner join tipos t with(nolock) on t.TipCod=f.FacTipCod
                where t.FueCod='FACT' and f.FacEst='A'
                group by k.FacSecRem,f.FacNro;
                
                CREATE CLUSTERED INDEX IX_TMP_FACT1 ON #FACT1 (FacSecRem);

                IF OBJECT_ID('tempdb..#CRUZE') IS NOT NULL DROP TABLE #CRUZE;
                SELECT d.FacNitSec Nitsec,d.DisId,d.DisDetId,d.Tercero,d.fecha,d.Dispensa,d.Auditoria,d.DisTip,d.Pend
                INTO #CRUZE
                FROM #Disp AS d
                WHERE NOT EXISTS (SELECT 1 FROM #FACT AS f WHERE f.DisId = d.DisId AND f.DisDetId = d.DisDetId)
                and NOT EXISTS (SELECT 1 FROM #FACT1 AS f WHERE f.FacSecRem = d.FacSec)
                group by d.FacNitSec,d.DisId,d.DisDetId,d.Tercero,d.fecha,d.Dispensa,d.est,d.Auditoria,d.DisTip,d.Pend;
                
                CREATE CLUSTERED INDEX IX_TMP_CRUZE ON #CRUZE (DisId,DisDetId);

                IF OBJECT_ID('tempdb..#Sopo') IS NOT NULL DROP TABLE #Sopo;
                SELECT s.DisId,s.DisDetId,case when s.adj>=sum(case when dc.NitMedDocTipSer=s.DisTip or dc.NitMedDocTipSer='' then dc.c else 0 end) and s.adj>=s.AdjTot then 1 else 0 end SopOk,case s.EstSop when 0 then 'P' when 5 then 'R' else 'C' end estSop
                Into #Sopo
                from(
                    select a.DisId,a.DisDetId,f.FacNitSec,c.DisTip,
                    sum(case when a.AdjDisOpc='N' then 1 else 0 end)AdjTot,
                    sum(case when AdjDisDocUrlConf=1 and a.AdjDisOpc='N' then 1 else 0 end)adj,Min(case a.AdjDisEstSop when 'P' then 0 when 'C'  then 10 else 5 end) EstSop
                    from AdjuntosDispensacion a with(nolock)
                    INNER JOIN #CRUZE c on c.DisId=a.DisId and c.DisDetId=a.DisDetId
                    left join (select f.FacNitSec,f.DisId,f.DisDetId from factura f with(nolock) group by f.FacNitSec,f.DisId,f.DisDetId)f on f.DisId=a.DisId and f.DisDetId=a.DisDetId
                    --where a.AdjDisOpc='N'
                    group by a.DisId,a.DisDetId,f.FacNitSec,c.DisTip
                )s 
                left join(
                    select n.NitSec,isnull(NitMedDocTipSer,'')NitMedDocTipSer,count(*)c 
                    from NitDocumentos n with(nolock) 
                    where n.NitMedDocOpc='N' 
                    group by n.NitSec,isnull(NitMedDocTipSer,'')
                )dc on dc.NitSec=s.FacNitSec
                where s.EstSop=0
                GROUP BY s.DisId,s.DisDetId,s.adj,case s.EstSop when 0 then 'P' when 5 then 'R' else 'C' end,s.AdjTot;
                
                CREATE CLUSTERED INDEX IX_TMP_SOPO ON #Sopo (DisId,DisDetId);

                {$finalSelect}

                IF OBJECT_ID('tempdb..#DISP') IS NOT NULL DROP TABLE #DISP;
                IF OBJECT_ID('tempdb..#FACT') IS NOT NULL DROP TABLE #FACT;
                IF OBJECT_ID('tempdb..#FACT1') IS NOT NULL DROP TABLE #FACT1;
                IF OBJECT_ID('tempdb..#CRUZE') IS NOT NULL DROP TABLE #CRUZE;
                IF OBJECT_ID('tempdb..#Sopo') IS NOT NULL DROP TABLE #Sopo;
        ";
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
        if ($cursor !== null && isset($cursor['date'], $cursor['disId'], $cursor['dispensa'])) {
            $cursorWhere = "AND (
                    s.fecha > :cursorDate1
                    OR (s.fecha = :cursorDate2 AND s.DisId > :cursorDisId1)
                    OR (s.fecha = :cursorDate3 AND s.DisId = :cursorDisId2 AND s.Dispensa > :cursorDispensa1)
                )";
        }

        $finalSelect = "
            SELECT TOP ({$safeLimit}) 
                s.Nitsec AS NitSec,
                s.DisId,
                s.Dispensa,
                CONVERT(varchar(33), s.fecha, 126) AS DisFecSol
            FROM (
                SELECT d.Nitsec, d.DisId, d.DisDetId, d.Tercero, d.fecha, d.Dispensa, isnull(s.estSop,'S') Auditoria,
                s.SopOk, case when sum(d.Pend)>0 then 'S' else 'N' end Pend
                FROM #CRUZE AS d
                left join #Sopo s on s.DisId=d.DisId and s.DisDetId=d.DisDetId
                where s.SopOk='1'
                group by d.Nitsec, d.DisId, d.DisDetId, d.Tercero, d.fecha, d.Dispensa, isnull(s.estSop,'S'), s.SopOk
            ) s
            WHERE s.Pend='N'
            {$cursorWhere}
            ORDER BY s.fecha ASC, s.DisId ASC, s.Dispensa ASC;
        ";

        $sql = $this->buildOptimizedBatchSql($finalSelect);

        $result = $this->read(function (PDO $connection) use (
            $sql,
            $facNitSec,
            $dateFrom,
            $dateTo,
            $cursorWhere,
            $cursor
        ): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':facNitSec', $facNitSec, PDO::PARAM_INT);
            $stmt->bindValue(':dateFromD', $dateFrom);
            $stmt->bindValue(':dateToD', $dateTo);

            if ($cursorWhere !== '') {
                $stmt->bindValue(':cursorDate1', (string) $cursor['date']);
                $stmt->bindValue(':cursorDate2', (string) $cursor['date']);
                $stmt->bindValue(':cursorDate3', (string) $cursor['date']);
                $stmt->bindValue(':cursorDisId1', (string) $cursor['disId']);
                $stmt->bindValue(':cursorDisId2', (string) $cursor['disId']);
                $stmt->bindValue(':cursorDispensa1', (string) $cursor['dispensa']);
            }

            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

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
