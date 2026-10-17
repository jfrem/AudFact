<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Core\Logger;
use Core\RedisClient;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Servicio centralizado para la consulta, resolución y almacenamiento en caché de la
 * Tasa Representativa del Mercado (TRM) oficial USD -> COP en Colombia.
 *
 * =========================================================================================
 * GUÍA DE MANTENIMIENTO PARA EL DESARROLLADOR DEL FUTURO:
 * =========================================================================================
 * 1. ¿Cómo funciona la TRM en Colombia?
 *    - La Superintendencia Financiera de Colombia (SFC) calcula y publica la TRM oficial diariamente
 *      a partir de las operaciones del mercado cambiario.
 *    - Horario vespertino crítico: Entre las 16:30 y 17:00 (COT, UTC-5), la SFC calcula y publica
 *      la TRM que entrará en vigencia el SIGUIENTE día hábil.
 *    - Fines de semana y festivos: La TRM del viernes se mantiene vigente durante sábado, domingo
 *      y festivos hasta las 23:59:59 del último día del ciclo no laborable.
 *    - Por esta razón, NUNCA debemos tomar ciegamente el primer registro (offset 0) de Datos Abiertos,
 *      ya que después de las 16:30 COT la API ya publica la tasa de mañana. El método
 *      `findEffectiveRowForDate()` valida el intervalo [vigenciadesde, vigenciahasta] frente
 *      a la fecha actual de Colombia (America/Bogota), garantizando la TRM exacta de hoy.
 *
 * 2. Cascada de Resiliencia Multi-Proveedor:
 *    [Tier 1] Redis Cache:
 *             - Clave: `finance:trm:usd_cop` (TTL: 24h / 86400s).
 *             - Provee resolución en microsegundos (<1ms) y aísla la plataforma de caídas de internet.
 *    [Tier 2] Datos Abiertos Colombia (Socrata API v3 - Dataset 32sa-8pi3):
 *             - Fuente canónica oficial estatal de la Superfinanciera.
 *             - Timeout estricto: 2s conexión, 3s transferencia.
 *    [Tier 3] DólarAPI Colombia (/v1/trm):
 *             - Fallback secundario de alta disponibilidad en tiempo real.
 *             - Consume el endpoint canónico de TRM con soporte de esquemas {'valor', 'venta'}.
 *    [Tier 4] Contingencia Estática Inmutable:
 *             - Constante `DEFAULT_FALLBACK_TRM` ($3.200,00 COP).
 *             - Asegura que el pipeline de auditoría de facturas NUNCA se detenga por fallas de red.
 *
 * 3. Observabilidad y Diagnóstico:
 *    - Toda petición HTTP fallida registra en logs el código HTTP y el código de error cURL exacto.
 *    - Los tokens de API sensibles en URLs son enmascarados automáticamente antes de loguear.
 * =========================================================================================
 */
class CurrencyExchangeService
{
    // =========================================================================
    // CONSTANTES DE CONFIGURACIÓN Y CACHÉ DISTRIBUIDO
    // =========================================================================

    /** Clave canónica de almacenamiento en Redis */
    public const REDIS_KEY_TRM = 'finance:trm:usd_cop';

    /** Tiempo de vida en caché: 24 horas */
    public const DEFAULT_TTL_SECONDS = 86400;

    /** Zona horaria oficial para el cómputo de vigencia de la TRM */
    public const COLOMBIA_TIMEZONE = 'America/Bogota';

    // =========================================================================
    // IDENTIFICADORES CANÓNICOS DE FUENTES (TRAZABILIDAD)
    // =========================================================================

    public const SOURCE_DATOS_GOV = 'datos_gov_co';
    public const SOURCE_DOLAR_API = 'dolarapi_fallback';
    public const SOURCE_STATIC = 'static_fallback';
    public const SOURCE_CACHE = 'redis_cache';

    // =========================================================================
    // ENDPOINTS Y VALORES POR DEFECTO
    // =========================================================================

    /** Endpoint oficial de Datos Abiertos Colombia (Superfinanciera) */
    public const DATOS_GOV_URL = 'https://www.datos.gov.co/api/v3/views/32sa-8pi3/query.json';
    public const DATOS_GOV_TOKEN = 'sVj0LWjA0pYlg1RLG2FOziLxC';

    /**
     * Número de registros a solicitar en Datos Abiertos. 5 registros garantizan
     * cubrir la ventana de vigencia del día actual, día siguiente y fines de semana festivos.
     */
    public const DATOS_GOV_PAGE_SIZE = 5;

    /** Endpoint secundario de alta disponibilidad de DólarAPI */
    public const DOLAR_API_URL = 'https://co.dolarapi.com/v1/trm';

    /** Tasa de cambio de contingencia estática de último recurso */
    public const DEFAULT_FALLBACK_TRM = 3200.00;

    // =========================================================================
    // PARÁMETROS DE TRANSPORTE HTTP
    // =========================================================================

    public const HTTP_CONNECT_TIMEOUT_SECONDS = 2;
    public const HTTP_TIMEOUT_SECONDS = 3;
    public const HTTP_USER_AGENT = 'AudFact-CurrencyExchange/1.0';

    /**
     * Handler alternativo de peticiones HTTP para testing e inyección de dependencias.
     * @var (callable(string, array<int, string>): ?string)|null
     */
    private $httpHandler;

    /**
     * @param RedisClient|null $redis Cliente de caché distribuido. Si es null, intenta obtener la instancia singleton.
     * @param string|null $datosGovUrl URL del dataset de Socrata (override para tests o proxies).
     * @param string|null $datosGovToken App token de Socrata.
     * @param string|null $dolarApiUrl URL de DólarAPI (override para tests o proxies).
     * @param float $defaultTrm Valor estático de contingencia de último nivel.
     * @param (callable(string, array<int, string>): ?string)|null $httpHandler Callback simulador para pruebas unitarias.
     */
    public function __construct(
        private ?RedisClient $redis = null,
        private ?string $datosGovUrl = null,
        private ?string $datosGovToken = null,
        private ?string $dolarApiUrl = null,
        private float $defaultTrm = self::DEFAULT_FALLBACK_TRM,
        ?callable $httpHandler = null
    ) {
        if ($this->redis === null) {
            try {
                $this->redis = RedisClient::getInstance();
            } catch (Throwable $e) {
                Logger::warning('CurrencyExchangeService: RedisClient no disponible en inicialización', [
                    'error' => $e->getMessage(),
                ]);
                $this->redis = null;
            }
        }

        $this->datosGovUrl ??= self::DATOS_GOV_URL;
        $this->datosGovToken ??= self::DATOS_GOV_TOKEN;
        $this->dolarApiUrl ??= self::DOLAR_API_URL;
        $this->httpHandler = $httpHandler;
    }

    // =========================================================================
    // API PÚBLICA PRINCIPAL
    // =========================================================================

    /**
     * Obtiene la tasa de cambio USD -> COP vigente utilizando la cascada de resiliencia
     * con patrón Cache-Aside en Redis.
     *
     * @param bool $forceRefresh Si es true, ignora el valor en caché y consulta directamente los proveedores externos.
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde: ?string,
     *     vigencia_hasta: ?string,
     *     origin?: string
     * }
     */
    public function getUsdCopRate(bool $forceRefresh = false): array
    {
        // Tier 1: Consulta de memoria caché (Redis)
        if (!$forceRefresh) {
            $cached = $this->getFromCache();
            if ($cached !== null) {
                Logger::info('CurrencyExchangeService: trm_cache_hit', [
                    'trm' => $cached['trm'],
                    'source' => $cached['source'],
                    'origin' => $cached['origin'] ?? null,
                ]);
                return $cached;
            }
        }

        // Tier 2, 3 y 4: Resolución mediante proveedores externos
        $resolved = $this->resolveRateFromProviders();

        // Almacenamiento en caché si proviene de una fuente externa válida
        if ($resolved['source'] !== self::SOURCE_STATIC) {
            $this->storeInCache($resolved);
        }

        return $resolved;
    }

    /**
     * Fuerza la actualización de la TRM consultando los proveedores externos y
     * renovando el valor en caché de Redis.
     *
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde: ?string,
     *     vigencia_hasta: ?string,
     *     origin?: string
     * }
     */
    public function refreshUsdCopRate(): array
    {
        return $this->getUsdCopRate(forceRefresh: true);
    }

    // =========================================================================
    // ORQUESTACIÓN DE CASCADA DE PROVEEDORES
    // =========================================================================

    /**
     * Resuelve la TRM a través de la jerarquía de proveedores externos y contingencia.
     *
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde: ?string,
     *     vigencia_hasta: ?string,
     *     origin?: string
     * }
     */
    private function resolveRateFromProviders(): array
    {
        // Tier 2: Datos Abiertos Colombia (Socrata API v3 - Fuente Oficial Superfinanciera)
        $datosGovResult = $this->fetchFromDatosGov();
        if ($datosGovResult !== null) {
            return $datosGovResult;
        }

        // Tier 3: DólarAPI (/v1/trm - Fallback Secundario en Tiempo Real)
        $dolarApiResult = $this->fetchFromDolarApi();
        if ($dolarApiResult !== null) {
            return $dolarApiResult;
        }

        // Tier 4: Contingencia Estática Inmutable de Emergencia
        Logger::warning('CurrencyExchangeService: Activada TRM de contingencia estática', [
            'trm' => $this->defaultTrm,
        ]);

        return self::buildRateResult(
            trm: $this->defaultTrm,
            source: self::SOURCE_STATIC
        );
    }

    // =========================================================================
    // PROVEEDOR 1: DATOS ABIERTOS COLOMBIA (SOCRATA API V3)
    // =========================================================================

    /**
     * Consulta el dataset oficial de la Superfinanciera en Datos Abiertos Colombia.
     *
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde: ?string,
     *     vigencia_hasta: ?string
     * }|null
     */
    private function fetchFromDatosGov(): ?array
    {
        $url = $this->buildDatosGovUrl();

        try {
            $response = $this->httpGet($url, ['Accept: application/json']);
            if ($response === null) {
                Logger::warning('CurrencyExchangeService: datos_gov_co_failure — HTTP request failed', [
                    'url' => self::sanitizeUrlForLogs((string) $this->datosGovUrl),
                ]);
                return null;
            }

            $data = json_decode($response, true);
            if (!is_array($data) || empty($data)) {
                Logger::warning('CurrencyExchangeService: datos_gov_co_failure — Formato de respuesta inesperado', [
                    'response_sample' => substr($response, 0, 150),
                ]);
                return null;
            }

            $today = self::getColombiaCurrentDate();
            $effectiveRow = self::findEffectiveRowForDate($data, $today);

            if ($effectiveRow === null || !isset($effectiveRow['valor']) || !is_numeric($effectiveRow['valor'])) {
                Logger::warning('CurrencyExchangeService: datos_gov_co_failure — Sin registros numéricos válidos', [
                    'target_date' => $today,
                ]);
                return null;
            }

            return self::buildRateResult(
                trm: (float) $effectiveRow['valor'],
                source: self::SOURCE_DATOS_GOV,
                vigenciaDesde: isset($effectiveRow['vigenciadesde']) ? (string) $effectiveRow['vigenciadesde'] : null,
                vigenciaHasta: isset($effectiveRow['vigenciahasta']) ? (string) $effectiveRow['vigenciahasta'] : null
            );
        } catch (Throwable $e) {
            Logger::warning('CurrencyExchangeService: datos_gov_co_failure — Excepción durante consulta', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Construye la URL parametrizada para consultar el dataset oficial de Socrata.
     */
    private function buildDatosGovUrl(): string
    {
        $separator = str_contains((string) $this->datosGovUrl, '?') ? '&' : '?';
        $url = $this->datosGovUrl . $separator . 'pageNumber=1&pageSize=' . self::DATOS_GOV_PAGE_SIZE;

        if ($this->datosGovToken !== null && $this->datosGovToken !== '') {
            $url .= '&app_token=' . urlencode($this->datosGovToken);
        }

        return $url;
    }

    // =========================================================================
    // PROVEEDOR 2: DÓLARAPI COLOMBIA
    // =========================================================================

    /**
     * Consulta el endpoint de respaldo de DólarAPI (/v1/trm).
     *
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde: ?string,
     *     vigencia_hasta: ?string
     * }|null
     */
    private function fetchFromDolarApi(): ?array
    {
        try {
            $response = $this->httpGet((string) $this->dolarApiUrl, ['Accept: application/json']);
            if ($response === null) {
                Logger::warning('CurrencyExchangeService: dolar_api_failure — HTTP request failed', [
                    'url' => self::sanitizeUrlForLogs((string) $this->dolarApiUrl),
                ]);
                return null;
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                Logger::warning('CurrencyExchangeService: dolar_api_failure — Formato de respuesta no es JSON', [
                    'response_sample' => substr($response, 0, 150),
                ]);
                return null;
            }

            $trmValue = self::extractDolarApiValue($data);
            if ($trmValue === null) {
                Logger::warning('CurrencyExchangeService: dolar_api_failure — No se encontró valor numérico de TRM', [
                    'response_sample' => substr($response, 0, 150),
                ]);
                return null;
            }

            $updatedAt = isset($data['fechaActualizacion']) && is_string($data['fechaActualizacion'])
                ? $data['fechaActualizacion']
                : null;

            return self::buildRateResult(
                trm: $trmValue,
                source: self::SOURCE_DOLAR_API,
                updatedAt: $updatedAt
            );
        } catch (Throwable $e) {
            Logger::error('CurrencyExchangeService: dolar_api_failure — Excepción durante consulta', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // CACHÉ DISTRIBUIDO (REDIS CACHE-ASIDE)
    // =========================================================================

    /**
     * Recupera la tasa desde la memoria caché de Redis si existe y es estructuralmente válida.
     *
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde: ?string,
     *     vigencia_hasta: ?string,
     *     origin?: string
     * }|null
     */
    private function getFromCache(): ?array
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            $raw = $this->redis->get(self::REDIS_KEY_TRM);
            if (!is_string($raw) || trim($raw) === '') {
                return null;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['trm']) || !is_numeric($data['trm'])) {
                return null;
            }

            $origin = (string) ($data['source'] ?? $data['origin'] ?? self::SOURCE_DATOS_GOV);

            return self::buildRateResult(
                trm: (float) $data['trm'],
                source: self::SOURCE_CACHE,
                updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
                vigenciaDesde: isset($data['vigencia_desde']) ? (string) $data['vigencia_desde'] : null,
                vigenciaHasta: isset($data['vigencia_hasta']) ? (string) $data['vigencia_hasta'] : null,
                origin: $origin
            );
        } catch (Throwable $e) {
            Logger::warning('CurrencyExchangeService: Error al leer caché Redis', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Almacena el resultado de TRM en Redis con el TTL configurado.
     *
     * @param array<string, mixed> $payload
     */
    private function storeInCache(array $payload): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $this->redis->set(self::REDIS_KEY_TRM, $encoded, self::DEFAULT_TTL_SECONDS);
            }
        } catch (Throwable $e) {
            Logger::warning('CurrencyExchangeService: No se pudo almacenar TRM en Redis', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // FUNCIONES PURAS Y UTILIDADES ESTÁTICAS DE NEGOCIO (TESTEABLES)
    // =========================================================================

    /**
     * Localiza en el conjunto de registros de Socrata la fila cuya vigencia comprende
     * la fecha objetivo en Colombia (formato Y-m-d).
     *
     * Estrategia de búsqueda:
     * 1. Coincidencia estricta en ventana [vigenciadesde, vigenciahasta].
     * 2. Fallback de contingencia: primer registro con valor numérico presente.
     *
     * @param array<int, array<string, mixed>> $rows Lista de filas devueltas por la API.
     * @param string $targetDate Fecha objetivo en formato 'Y-m-d'.
     * @return array<string, mixed>|null Fila seleccionada o null si el dataset está vacío.
     */
    public static function findEffectiveRowForDate(array $rows, string $targetDate): ?array
    {
        // 1. Coincidencia exacta por rango de vigencia oficial
        foreach ($rows as $row) {
            if (!isset($row['valor']) || !is_numeric($row['valor'])) {
                continue;
            }

            if (self::isDateWithinRange($targetDate, $row['vigenciadesde'] ?? null, $row['vigenciahasta'] ?? null)) {
                return $row;
            }
        }

        // 2. Fallback de contingencia: primer registro numérico disponible
        foreach ($rows as $row) {
            if (isset($row['valor']) && is_numeric($row['valor'])) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Determina si una fecha objetivo está comprendida dentro del intervalo [desde, hasta].
     *
     * @param string $targetDate Fecha objetivo en formato 'Y-m-d'.
     * @param string|null $desde Cadena con fecha inicial (ej: '2026-10-10T00:00:00.000').
     * @param string|null $hasta Cadena con fecha final (ej: '2026-10-13T00:00:00.000').
     */
    public static function isDateWithinRange(string $targetDate, ?string $desde, ?string $hasta): bool
    {
        $d = self::parseVigenciaDate($desde);
        $h = self::parseVigenciaDate($hasta);

        return $d !== '' && $h !== '' && $d <= $targetDate && $targetDate <= $h;
    }

    /**
     * Extrae de forma segura los primeros 10 caracteres ('Y-m-d') de una marca de tiempo ISO-8601.
     */
    public static function parseVigenciaDate(?string $dateString): string
    {
        if ($dateString === null || trim($dateString) === '') {
            return '';
        }

        return trim(substr($dateString, 0, 10));
    }

    /**
     * Extrae con tolerancia a esquemas el valor numérico de TRM desde DólarAPI.
     * Soporta tanto el endpoint canónico /v1/trm ('valor') como el endpoint legado /v1/cotizaciones ('venta').
     *
     * @param array<string, mixed> $data
     */
    public static function extractDolarApiValue(array $data): ?float
    {
        if (isset($data['valor']) && is_numeric($data['valor'])) {
            return (float) $data['valor'];
        }

        if (isset($data['venta']) && is_numeric($data['venta'])) {
            return (float) $data['venta'];
        }

        return null;
    }

    /**
     * Retorna la fecha actual oficial en Colombia (America/Bogota) en formato 'Y-m-d'.
     * Protege contra desalineaciones de fecha cuando PHP se ejecuta en contenedores con hora UTC.
     */
    public static function getColombiaCurrentDate(): string
    {
        try {
            $tz = new DateTimeZone(self::COLOMBIA_TIMEZONE);
            return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        } catch (Throwable) {
            return date('Y-m-d');
        }
    }

    /**
     * Ensambla y normaliza el payload estructurado de respuesta de la TRM.
     *
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde: ?string,
     *     vigencia_hasta: ?string,
     *     origin?: string
     * }
     */
    public static function buildRateResult(
        float $trm,
        string $source,
        ?string $updatedAt = null,
        ?string $vigenciaDesde = null,
        ?string $vigenciaHasta = null,
        ?string $origin = null
    ): array {
        $result = [
            'trm' => round($trm, 2),
            'source' => $source,
            'updated_at' => $updatedAt ?? (new DateTimeImmutable('now', new DateTimeZone(self::COLOMBIA_TIMEZONE)))->format('Y-m-d H:i:s'),
            'vigencia_desde' => $vigenciaDesde,
            'vigencia_hasta' => $vigenciaHasta,
        ];

        if ($origin !== null) {
            $result['origin'] = $origin;
        }

        return $result;
    }

    /**
     * Enmascara tokens de autenticación y credenciales presentes en URLs antes de ser registradas en logs.
     */
    public static function sanitizeUrlForLogs(string $url): string
    {
        return (string) preg_replace('/([?&]app_token=)[^&]+/', '$1***', $url);
    }

    // =========================================================================
    // TRANSPORTE HTTP (CURL)
    // =========================================================================

    /**
     * Ejecuta una petición HTTP GET con timeouts estrictos (2s conexión, 3s transferencia)
     * y diagnóstico enriquecido ante fallos.
     *
     * @param array<int, string> $headers Cabeceras HTTP adicionales.
     * @return string|null Cuerpo de la respuesta si el código HTTP es 2xx, o null ante cualquier fallo.
     */
    protected function httpGet(string $url, array $headers = []): ?string
    {
        if ($this->httpHandler !== null) {
            return ($this->httpHandler)($url, $headers);
        }

        if (!function_exists('curl_init')) {
            Logger::error('CurrencyExchangeService: La extensión cURL no está disponible en este entorno PHP');
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => self::HTTP_USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            Logger::warning('CurrencyExchangeService: Fallo en transporte HTTP externo', [
                'url' => self::sanitizeUrlForLogs($url),
                'http_code' => $httpCode,
                'curl_errno' => $curlErrno,
                'curl_error' => $curlError !== '' ? $curlError : null,
            ]);
            return null;
        }

        return (string) $body;
    }
}

