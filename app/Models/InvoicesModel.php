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
                where s.SopOk='1' and s.estSop='P'
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
                where s.SopOk='1' and s.estSop='P'
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
                SELECT f.FacNitSec,dd.DisDetNro AS Dispensa,ISNULL(dd.DisAplForRec, 'N') AS Apl,CAST(d.DisFecSol AS DATE) AS Fecha,n.NitCom AS Tercero,pc.PerCliNom AS Perfil,d.DisId,
                dd.DisDetId,f.FacSec,SUM(k.KarUniCp - k.KarUni) AS Pend,SUM(k.KarUni * k.KarPre) AS Costo,SUM(k.KarUniCp * k.KarPrePub) AS Valor,dd.DisDetUsuAud AS Auditoria,
                dd.DisTip,dd.DisDetEst AS Est,dd.DisDetAut Aut
                INTO #DISP
                FROM Dispensacion d WITH(NOLOCK)
                INNER JOIN DispensacionDetalleServicio dd WITH(NOLOCK) ON dd.DisId = d.DisId
                INNER JOIN Factura f WITH(NOLOCK) ON f.DisId = dd.DisId AND f.DisDetId = dd.DisDetId
                INNER JOIN FacturaKardex k WITH(NOLOCK) ON k.FacSec = f.FacSec
                INNER JOIN Tipos t WITH(NOLOCK) ON t.TipCod = d.DisTipCod
                INNER JOIN Nit n WITH(NOLOCK) ON n.NitSec = f.FacNitSec
                LEFT JOIN Clientes c WITH(NOLOCK) ON c.NitSec = f.FacNitSec AND c.CliSec = f.FacCliSec
                LEFT JOIN PerfilDeClientes pc WITH(NOLOCK) ON pc.PerCliCod = c.PerCliCod
                WHERE t.FueCod = 'DISP' AND dd.DisDetEst = 'A'
                AND d.DisFecSol >= :dateFromD AND d.DisFecSol <= :dateToD AND dd.DisTip <> 'A' AND k.KarUni > 0 AND ISNULL(dd.DisAplForRec, 'N') = 'N' AND d.DisNitSec = :facNitSec

                    AND NOT EXISTS ( SELECT 1
                        FROM Factura fx WITH(NOLOCK)
                        INNER JOIN Tipos tx WITH(NOLOCK) ON tx.TipCod = fx.FacTipCod
                        WHERE fx.DisId = dd.DisId AND fx.DisDetId = dd.DisDetId AND fx.FacEst = 'A' AND tx.FueCod = 'FACT'
                        AND EXISTS (
                            SELECT 1
                            FROM DispensacionDetalleServicio dx WITH(NOLOCK)
                            WHERE dx.DisId = fx.DisId AND dx.DisDetId = fx.DisDetId 
                        )
                    )

                    AND NOT EXISTS (
                        SELECT 1
                        FROM FacturaKardex kx WITH(NOLOCK)
                        INNER JOIN Factura fx WITH(NOLOCK) ON fx.FacSec = kx.FacSec
                        INNER JOIN Tipos tx WITH(NOLOCK) ON tx.TipCod = fx.FacTipCod
                        WHERE kx.FacSecRem = f.FacSec AND fx.FacEst = 'A'AND tx.FueCod = 'FACT'
                    )

                GROUP BY f.FacNitSec,dd.DisDetNro,ISNULL(dd.DisAplForRec, 'N'),CAST(d.DisFecSol AS DATE),n.NitCom,pc.PerCliNom,d.DisId,dd.DisDetId,f.FacSec,dd.DisDetUsuAud,dd.DisTip,dd.DisDetEst,dd.DisDetAut
                HAVING SUM(k.KarUniCp - k.KarUni) = 0
                OPTION (RECOMPILE);

                SELECT d.FacNitSec AS Nitsec,d.DisId,d.DisDetId,d.Tercero,d.Fecha,d.Dispensa,d.Auditoria,d.DisTip,d.Pend,d.Aut
                INTO #CRUZE
                FROM #DISP d
                GROUP BY d.FacNitSec,d.DisId,d.DisDetId,d.Tercero,d.Fecha,d.Dispensa,d.Est,d.Auditoria,d.DisTip,d.Pend,d.Aut;
                CREATE CLUSTERED INDEX IX_TMP_CRUZE ON #CRUZE (DisId, DisDetId);

                DROP TABLE IF EXISTS #ADJ;

                SELECT a.DisId,a.DisDetId,c.DisTip,a.AdjDisDocId, a.AdjDisUsuAudi au,
                CASE a.AdjDisEstSop WHEN 'P' THEN 0 WHEN 'C' THEN 10 ELSE 5 END AS EstSop,
                1 AS c,a.AdjDisNom
                INTO #ADJ
                FROM #CRUZE c
                INNER JOIN AdjuntosDispensacion a WITH(NOLOCK) ON a.DisId = c.DisId AND a.DisDetId = c.DisDetId
                WHERE a.AdjDisOpc = 'N'  AND a.AdjDisDocUrlConf = 1;

                CREATE CLUSTERED INDEX IX_TMP_ADJ ON #ADJ (DisId, DisDetId, AdjDisDocId);


                DROP TABLE IF EXISTS #Sopo;

                SELECT t.DisId,t.DisDetId,
                CASE WHEN SUM(case when t.Dispensa<>t.Aut or len(t.aut)>0 and dc.NitMedDocTip='A' THEN 1 ELSE 0 END) = SUM(s.c) THEN 1 ELSE 0 END AS SopOk,
                CASE MIN(case when s.au<>'Z-IA' then s.EstSop end) WHEN 0 THEN 'P' WHEN 5 THEN 'R' ELSE 'C' END AS estSop
                INTO #Sopo
                FROM #CRUZE t
                LEFT JOIN (
                    SELECT n.NitSec,n.NitMedDocId,ISNULL(n.NitMedDocTipSer, '') AS NitMedDocTipSer,n.NitMedDocNom,NitMedDocOpc opc,1 do,n.NitMedDocTip
                    FROM NitDocumentos n WITH(NOLOCK)
                    INNER JOIN (
                        SELECT Nitsec FROM #CRUZE GROUP BY Nitsec
                    ) c ON c.Nitsec = n.NitSec
                ) dc ON dc.NitSec = t.Nitsec and ((dc.NitMedDocTipSer=t.DisTip and dc.NitMedDocTip='A') OR (dc.NitMedDocTipSer = '' and dc.opc='N'))
                LEFT JOIN #ADJ s ON s.DisId = t.DisId AND s.DisDetId = t.DisDetId AND s.AdjDisDocId = dc.NitMedDocId
                GROUP BY t.DisId,t.DisDetId;

                CREATE CLUSTERED INDEX IX_TMP_SOPO ON #Sopo (DisId, DisDetId);

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
                where s.SopOk='1' and s.estSop = 'P'
                group by d.Nitsec, d.DisId, d.DisDetId, d.Tercero, d.fecha, d.Dispensa, isnull(s.estSop,'S'), s.SopOk
            ) s
            WHERE s.Pend='N'
            {$cursorWhere}
            ORDER BY s.fecha ASC, s.DisId ASC, s.Dispensa ASC;
        ";

        $sql = $this->buildOptimizedBatchSql($finalSelect);

        $effectiveDateFrom = ($cursor !== null && !empty($cursor['date']))
            ? (string) substr((string) $cursor['date'], 0, 10)
            : $dateFrom;

        $result = $this->read(function (PDO $connection) use (
            $sql,
            $facNitSec,
            $effectiveDateFrom,
            $dateTo,
            $cursorWhere,
            $cursor
        ): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':facNitSec', $facNitSec, PDO::PARAM_INT);
            $stmt->bindValue(':dateFromD', $effectiveDateFrom);
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
