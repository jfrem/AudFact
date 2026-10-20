<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use Core\SqlServerConnectionExecutor;
use Core\SqlServerOperationException;
use Core\SqlServerOperationMode;
use PDO;

class AuditCostModel extends Model
{
    public const DEFAULT_INPUT_RATE_1M = 0.7500;
    public const DEFAULT_OUTPUT_RATE_1M = 3.7500;
    public const DEFAULT_MODEL_IA = 'gemini-3.7-flash';
    public const TOKENS_PER_UNIT = 1000000.0;

    protected string $readConnectionName = 'default';
    protected string $table = 'Discolnet.dbo.AudDispCosto';

    public function __construct(?SqlServerConnectionExecutor $executor = null)
    {
        parent::__construct($executor);
    }

    /**
     * Calcula determinísticamente el costo en USD con base en los tokens y tarifas vigentes.
     * Fórmula: (TokensEntrada * TarifaEntrada + (TokensSalida + TokensPensamiento) * TarifaSalida) / 1,000,000
     */
    public static function calculateCostUsd(
        int $tokensEntrada,
        int $tokensSalida,
        int $tokensPensamiento,
        float $tarifaEntrada1M = self::DEFAULT_INPUT_RATE_1M,
        float $tarifaSalida1M = self::DEFAULT_OUTPUT_RATE_1M
    ): float {
        $cost = ($tokensEntrada * $tarifaEntrada1M + ($tokensSalida + $tokensPensamiento) * $tarifaSalida1M) / self::TOKENS_PER_UNIT;
        return round($cost, 8);
    }

    /**
     * Registra de manera transaccional un intento de auditoría en la tabla AudDispCosto.
     * Utiliza UPDLOCK, HOLDLOCK sobre la factura para asegurar la monotonicidad del consecutivo Intento.
     *
     * @param PDO $connection Conexión activa dentro de una transacción.
     * @param array<string, mixed> $costData Datos del costo a persistir.
     * @return int Consecutivo de intento asignado.
     */
    public function recordCost(PDO $connection, array $costData): int
    {
        $facNro = (string) ($costData['FacNro'] ?? '');
        $facNitSec = (string) ($costData['FacNitSec'] ?? '');
        $facSec = isset($costData['FacSec']) && $costData['FacSec'] !== '' ? (string) $costData['FacSec'] : null;

        // 1. Obtener consecutivo de intento con bloqueo serializable
        $intentoSql = "SELECT COALESCE(MAX([Intento]), 0) + 1 AS NextIntento
                       FROM Discolnet.dbo.AudDispCosto WITH (UPDLOCK, HOLDLOCK)
                       WHERE [FacNro] = :facNro";
        $intentoStmt = $connection->prepare($intentoSql);
        $intentoStmt->bindValue(':facNro', $facNro, PDO::PARAM_STR);
        $intentoStmt->execute();

        try {
            $row = $intentoStmt->fetch(PDO::FETCH_ASSOC);
            $nextIntento = (int) ($row['NextIntento'] ?? 1);
        } finally {
            $intentoStmt->closeCursor();
        }

        // 2. Resolver tokens y tarifas
        $tokensEntrada = (int) ($costData['TokensEntrada'] ?? 0);
        $tokensSalida = (int) ($costData['TokensSalida'] ?? 0);
        $tokensPensamiento = (int) ($costData['TokensPensamiento'] ?? 0);
        $totalTokens = (int) ($costData['TotalTokens'] ?? ($tokensEntrada + $tokensSalida + $tokensPensamiento));

        $tarifaEntrada1M = (float) ($costData['TarifaEntrada1M'] ?? self::DEFAULT_INPUT_RATE_1M);
        $tarifaSalida1M = (float) ($costData['TarifaSalida1M'] ?? self::DEFAULT_OUTPUT_RATE_1M);
        $modeloIa = (string) ($costData['ModeloIA'] ?? self::DEFAULT_MODEL_IA);

        // 3. Liquidar montos USD y COP
        if (isset($costData['CostoUSD']) && is_numeric($costData['CostoUSD'])) {
            $costoUsd = round((float) $costData['CostoUSD'], 8);
        } else {
            $costoUsd = self::calculateCostUsd($tokensEntrada, $tokensSalida, $tokensPensamiento, $tarifaEntrada1M, $tarifaSalida1M);
        }

        $trmAplicada = round((float) ($costData['TrmAplicada'] ?? 0.00), 2);
        if (isset($costData['CostoCOP']) && is_numeric($costData['CostoCOP'])) {
            $costoCop = round((float) $costData['CostoCOP'], 4);
        } else {
            $costoCop = round($costoUsd * $trmAplicada, 4);
        }

        $docsProcesados = (int) ($costData['DocsProcesados'] ?? 0);
        $docsCacheHit = (int) ($costData['DocsCacheHit'] ?? 0);
        $esCacheHitTotal = isset($costData['EsCacheHitTotal'])
            ? ((bool) $costData['EsCacheHitTotal'] ? 1 : 0)
            : (($docsProcesados > 0 && $docsProcesados === $docsCacheHit) ? 1 : 0);

        $fechaAuditoria = isset($costData['FechaAuditoria']) && trim((string) $costData['FechaAuditoria']) !== ''
            ? trim((string) $costData['FechaAuditoria'])
            : null;

        // 4. Inserción atómica
        $insertSql = "INSERT INTO Discolnet.dbo.AudDispCosto (
            [FacNro], [FacNitSec], [FacSec], [Intento],
            [TokensEntrada], [TokensSalida], [TokensPensamiento], [TotalTokens],
            [ModeloIA], [TarifaEntrada1M], [TarifaSalida1M], [CostoUSD],
            [TrmAplicada], [CostoCOP],
            [DocsProcesados], [DocsCacheHit], [EsCacheHitTotal], [FechaAuditoria]
        ) VALUES (
            :facNro, :facNitSec, :facSec, :intento,
            :tokensEntrada, :tokensSalida, :tokensPensamiento, :totalTokens,
            :modeloIa, :tarifaEntrada1M, :tarifaSalida1M, :costoUsd,
            :trmAplicada, :costoCop,
            :docsProcesados, :docsCacheHit, :esCacheHitTotal,
            COALESCE(:fechaAuditoria, GETDATE())
        )";

        $stmt = $connection->prepare($insertSql);
        $stmt->bindValue(':facNro', $facNro, PDO::PARAM_STR);
        $stmt->bindValue(':facNitSec', $facNitSec, PDO::PARAM_STR);
        $stmt->bindValue(':facSec', $facSec, $facSec !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':intento', $nextIntento, PDO::PARAM_INT);
        $stmt->bindValue(':tokensEntrada', $tokensEntrada, PDO::PARAM_INT);
        $stmt->bindValue(':tokensSalida', $tokensSalida, PDO::PARAM_INT);
        $stmt->bindValue(':tokensPensamiento', $tokensPensamiento, PDO::PARAM_INT);
        $stmt->bindValue(':totalTokens', $totalTokens, PDO::PARAM_INT);
        $stmt->bindValue(':modeloIa', $modeloIa, PDO::PARAM_STR);
        $stmt->bindValue(':tarifaEntrada1M', $tarifaEntrada1M);
        $stmt->bindValue(':tarifaSalida1M', $tarifaSalida1M);
        $stmt->bindValue(':costoUsd', $costoUsd);
        $stmt->bindValue(':trmAplicada', $trmAplicada);
        $stmt->bindValue(':costoCop', $costoCop);
        $stmt->bindValue(':docsProcesados', $docsProcesados, PDO::PARAM_INT);
        $stmt->bindValue(':docsCacheHit', $docsCacheHit, PDO::PARAM_INT);
        $stmt->bindValue(':esCacheHitTotal', $esCacheHitTotal, PDO::PARAM_INT);
        $stmt->bindValue(':fechaAuditoria', $fechaAuditoria, $fechaAuditoria !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->execute();
        $stmt->closeCursor();

        Logger::info("AuditCostModel: Costo de auditoría persistido", [
            'facNro' => $facNro,
            'intento' => $nextIntento,
            'costoUSD' => $costoUsd,
            'trm' => $trmAplicada,
            'costoCOP' => $costoCop,
        ]);

        return $nextIntento;
    }

    /**
     * Reporte detallado y paginado de costos con agregaciones del período.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     pagination: array{page: int, pageSize: int, totalRecords: int, totalPages: int},
     *     totales_periodo: array{gran_total_tokens: int, costo_total_usd: float, costo_total_cop: float},
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function getDetailedCostsReport(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $offset = ($page - 1) * $pageSize;

        [$whereClause, $params] = $this->buildFilterConditions($filters);

        return $this->readWithFallback(function (PDO $connection) use ($whereClause, $params, $offset, $pageSize, $page): array {
            // 1. Totales y conteo
            $countSql = "SELECT
                COUNT(*) AS total_records,
                COALESCE(SUM(c.[TotalTokens]), 0) AS gran_total_tokens,
                COALESCE(SUM(c.[CostoUSD]), 0) AS costo_total_usd,
                COALESCE(SUM(c.[CostoCOP]), 0) AS costo_total_cop
            FROM Discolnet.dbo.AudDispCosto c WITH (NOLOCK)
            {$whereClause}";

            $countStmt = $connection->prepare($countSql);
            foreach ($params as $key => $val) {
                $countStmt->bindValue($key, $val);
            }
            $countStmt->execute();

            try {
                $totalsRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } finally {
                $countStmt->closeCursor();
            }

            $totalRecords = (int) ($totalsRow['total_records'] ?? 0);
            $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $pageSize) : 0;
            $granTotalTokens = (int) ($totalsRow['gran_total_tokens'] ?? 0);
            $costoTotalUsd = round((float) ($totalsRow['costo_total_usd'] ?? 0), 6);
            $costoTotalCop = round((float) ($totalsRow['costo_total_cop'] ?? 0), 2);

            if ($totalRecords === 0) {
                return [
                    'pagination' => [
                        'page' => $page,
                        'pageSize' => $pageSize,
                        'totalRecords' => 0,
                        'totalPages' => 0,
                    ],
                    'totales_periodo' => [
                        'gran_total_tokens' => 0,
                        'costo_total_usd' => 0.0,
                        'costo_total_cop' => 0.0,
                    ],
                    'items' => [],
                ];
            }

            // 2. Consulta paginada
            $itemsSql = "SELECT
                c.[AudCostoId] AS aud_costo_id,
                c.[FacNitSec] AS fac_nit_sec,
                COALESCE(n.[NitCom], CONCAT('Cliente #', c.[FacNitSec])) AS cliente,
                c.[FacNro] AS fac_nro,
                c.[FacSec] AS fac_sec,
                c.[Intento] AS intento,
                c.[TokensEntrada] AS tokens_entrada,
                c.[TokensSalida] AS tokens_salida,
                c.[TokensPensamiento] AS tokens_pensamiento,
                c.[TotalTokens] AS total_tokens,
                c.[CostoUSD] AS costo_usd,
                c.[TrmAplicada] AS trm_aplicada,
                c.[CostoCOP] AS costo_cop,
                c.[DocsProcesados] AS docs_procesados,
                c.[DocsCacheHit] AS docs_cache_hit,
                c.[EsCacheHitTotal] AS es_cache_hit_total,
                c.[ModeloIA] AS modelo_ia,
                CONVERT(VARCHAR(19), c.[FechaAuditoria], 120) AS fecha_auditoria
            FROM Discolnet.dbo.AudDispCosto c WITH (NOLOCK)
            LEFT JOIN NIT n WITH (NOLOCK) ON n.NitSec = c.FacNitSec
            {$whereClause}
            ORDER BY c.[FechaAuditoria] DESC, c.[AudCostoId] DESC
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

            $itemsStmt = $connection->prepare($itemsSql);
            foreach ($params as $key => $val) {
                $itemsStmt->bindValue($key, $val);
            }
            $itemsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $itemsStmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
            $itemsStmt->execute();

            try {
                $rawItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } finally {
                $itemsStmt->closeCursor();
            }

            $items = array_map([self::class, 'mapCostRecord'], $rawItems);

            return [
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'totalRecords' => $totalRecords,
                    'totalPages' => $totalPages,
                ],
                'totales_periodo' => [
                    'gran_total_tokens' => $granTotalTokens,
                    'costo_total_usd' => $costoTotalUsd,
                    'costo_total_cop' => $costoTotalCop,
                ],
                'items' => $items,
            ];
        });
    }

    /**
     * Reporte consolidado de costos agrupado por Cliente / EPS.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getCostsByClient(array $filters = []): array
    {
        [$whereClause, $params] = $this->buildFilterConditions($filters);

        return $this->readWithFallback(function (PDO $connection) use ($whereClause, $params): array {
            $sql = "SELECT
                c.[FacNitSec] AS fac_nit_sec,
                COALESCE(n.[NitCom], CONCAT('Cliente #', c.[FacNitSec])) AS cliente,
                COUNT(DISTINCT c.[FacNro]) AS facturas_auditadas,
                COUNT(*) AS total_intentos,
                COALESCE(SUM(c.[TotalTokens]), 0) AS total_tokens,
                ROUND(COALESCE(SUM(c.[CostoUSD]), 0), 6) AS gasto_total_usd,
                ROUND(COALESCE(SUM(c.[CostoCOP]), 0), 2) AS gasto_total_cop
            FROM Discolnet.dbo.AudDispCosto c WITH (NOLOCK)
            LEFT JOIN NIT n WITH (NOLOCK) ON n.NitSec = c.FacNitSec
            {$whereClause}
            GROUP BY c.[FacNitSec], n.[NitCom]
            ORDER BY gasto_total_usd DESC";

            $stmt = $connection->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();

            try {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } finally {
                $stmt->closeCursor();
            }

            return array_map(function (array $row): array {
                return [
                    'fac_nit_sec' => (string) $row['fac_nit_sec'],
                    'cliente' => (string) $row['cliente'],
                    'facturas_auditadas' => (int) $row['facturas_auditadas'],
                    'total_intentos' => (int) $row['total_intentos'],
                    'total_tokens' => (int) $row['total_tokens'],
                    'gasto_total_usd' => round((float) $row['gasto_total_usd'], 6),
                    'gasto_total_cop' => round((float) $row['gasto_total_cop'], 2),
                ];
            }, $rows);
        });
    }

    /**
     * Consulta el historial cronológico de costos e intentos para una factura.
     *
     * @param string $facNro Número de factura o dispensa.
     * @return array<int, array<string, mixed>>
     */
    public function getCostsByInvoice(string $facNro): array
    {
        return $this->readWithFallback(function (PDO $connection) use ($facNro): array {
            $sql = "SELECT
                c.[AudCostoId] AS aud_costo_id,
                c.[FacNro] AS fac_nro,
                c.[FacNitSec] AS fac_nit_sec,
                COALESCE(n.[NitCom], CONCAT('Cliente #', c.[FacNitSec])) AS cliente,
                c.[FacSec] AS fac_sec,
                c.[Intento] AS intento,
                c.[TokensEntrada] AS tokens_entrada,
                c.[TokensSalida] AS tokens_salida,
                c.[TokensPensamiento] AS tokens_pensamiento,
                c.[TotalTokens] AS total_tokens,
                c.[CostoUSD] AS costo_usd,
                c.[TrmAplicada] AS trm_aplicada,
                c.[CostoCOP] AS costo_cop,
                c.[DocsProcesados] AS docs_procesados,
                c.[DocsCacheHit] AS docs_cache_hit,
                c.[EsCacheHitTotal] AS es_cache_hit_total,
                c.[ModeloIA] AS modelo_ia,
                CONVERT(VARCHAR(19), c.[FechaAuditoria], 120) AS fecha_auditoria
            FROM Discolnet.dbo.AudDispCosto c WITH (NOLOCK)
            LEFT JOIN NIT n WITH (NOLOCK) ON n.NitSec = c.FacNitSec
            WHERE c.[FacNro] = :facNro
            ORDER BY c.[Intento] ASC";

            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':facNro', $facNro, PDO::PARAM_STR);
            $stmt->execute();

            try {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } finally {
                $stmt->closeCursor();
            }

            return array_map([self::class, 'mapCostRecord'], $rows);
        });
    }

    /**
     * Construye dinámicamente la cláusula WHERE y los parámetros parametrizados.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilterConditions(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['dateFrom'])) {
            $conditions[] = "c.[FechaAuditoria] >= :dateFrom";
            $params[':dateFrom'] = strlen((string) $filters['dateFrom']) === 10
                ? $filters['dateFrom'] . ' 00:00:00'
                : (string) $filters['dateFrom'];
        }

        if (!empty($filters['dateTo'])) {
            $conditions[] = "c.[FechaAuditoria] <= :dateTo";
            $params[':dateTo'] = strlen((string) $filters['dateTo']) === 10
                ? $filters['dateTo'] . ' 23:59:59'
                : (string) $filters['dateTo'];
        }

        if (!empty($filters['facNitSec'])) {
            $conditions[] = "c.[FacNitSec] = :facNitSec";
            $params[':facNitSec'] = (string) $filters['facNitSec'];
        }

        if (!empty($filters['facNro'])) {
            $conditions[] = "c.[FacNro] = :facNro";
            $params[':facNro'] = (string) $filters['facNro'];
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$whereClause, $params];
    }

    /**
     * Normaliza un registro de base de datos AudDispCosto al contrato tipado de dominio.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapCostRecord(array $row): array
    {
        return [
            'aud_costo_id' => (int) $row['aud_costo_id'],
            'fac_nro' => (string) $row['fac_nro'],
            'fac_nit_sec' => (string) $row['fac_nit_sec'],
            'cliente' => (string) $row['cliente'],
            'fac_sec' => $row['fac_sec'] !== null ? (string) $row['fac_sec'] : null,
            'intento' => (int) $row['intento'],
            'tokens_entrada' => (int) $row['tokens_entrada'],
            'tokens_salida' => (int) $row['tokens_salida'],
            'tokens_pensamiento' => (int) $row['tokens_pensamiento'],
            'total_tokens' => (int) $row['total_tokens'],
            'costo_usd' => round((float) $row['costo_usd'], 8),
            'trm_aplicada' => round((float) $row['trm_aplicada'], 2),
            'costo_cop' => round((float) $row['costo_cop'], 4),
            'docs_procesados' => (int) $row['docs_procesados'],
            'docs_cache_hit' => (int) $row['docs_cache_hit'],
            'es_cache_hit_total' => (bool) $row['es_cache_hit_total'],
            'modelo_ia' => (string) $row['modelo_ia'],
            'fecha_auditoria' => (string) $row['fecha_auditoria'],
        ];
    }

    /**
     * Ejecuta una lectura con fallback automático de conexión hacia db2 si default no está disponible.
     */
    private function readWithFallback(callable $operation): mixed
    {
        try {
            return $this->read($operation);
        } catch (SqlServerOperationException $error) {
            if ($error->phase() !== 'connect' && !$error->retryExhausted()) {
                throw $error;
            }

            Logger::warning('AuditCostModel: Fallback de lectura hacia db2', [
                'preferredConnection' => $this->readConnectionName,
                'fallbackConnection' => 'db2',
                'phase' => $error->phase(),
                'sql_state' => $error->sqlState(),
            ]);

            return $this->executor->execute(
                'db2',
                SqlServerOperationMode::READ,
                $operation
            );
        }
    }
}
