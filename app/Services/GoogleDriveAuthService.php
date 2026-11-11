<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * GoogleDriveAuthService 
 */
class GoogleDriveAuthService
{
    private string $clientEmail;
    private string $privateKey;
    private Client $httpClient;
    private ?RedisClient $redis;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    // Constantes para OAuth2
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
    private const DRIVE_API_URL = 'https://www.googleapis.com/drive/v3';

    // Constantes de configuración
    private const TOKEN_EXPIRY_MARGIN_SECONDS = 30;
    private const TOKEN_LIFETIME_SECONDS = 3600;
    private const HTTP_TIMEOUT_SECONDS = 60.0;
    private const DIRECTORY_PERMISSIONS = 0755;
    private const TLS_VERIFY_DEFAULT = true;

    // Constantes de Redis
    public const REDIS_TOKEN_KEY = 'audit:oauth:google_drive_token';
    public const REDIS_LOCK_KEY = 'audit:oauth:google_drive_lock';
    public const REDIS_LOCK_TTL = 10;
    private const LOCK_WAIT_MAX_ATTEMPTS = 10;
    private const LOCK_WAIT_SLEEP_MICROSECONDS = 300000;
    private const TOKEN_EXPIRY_RESERVE_SECONDS = 300;
    private const MIN_REDIS_TTL_SECONDS = 60;
    private const DEFAULT_EXPIRES_IN_SECONDS = 3590;

    public function __construct(?Client $httpClient = null, ?RedisClient $redis = null)
    {
        $this->clientEmail = (string) Env::get('GOOGLE_DRIVE_CLIENT_EMAIL', '');
        $this->privateKey = str_replace('\\n', "\n", (string) Env::get('GOOGLE_DRIVE_PRIVATE_KEY', ''));

        // Validar credenciales
        $this->validateCredentials();

        $tlsVerify = $this->readBoolEnv('GOOGLE_DRIVE_TLS_VERIFY', self::TLS_VERIFY_DEFAULT);

        // Inyección de dependencias con fallback
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'verify' => $tlsVerify
        ]);

        $this->redis = $redis ?? $this->resolveDefaultRedis();

        Logger::info('GoogleDriveAuthService initialized', [
            'clientEmail' => $this->clientEmail,
            'tlsVerify' => $tlsVerify,
            'redisConfigured' => $this->redis !== null,
        ]);

        if (!$tlsVerify) {
            Logger::warning('GoogleDriveAuthService TLS verification is disabled by configuration');
        }
    }

    /**
     * Lee una variable de entorno booleana con soporte para 1/0, true/false, yes/no, on/off.
     *
     * @param string $key Nombre de la variable
     * @param bool $default Valor por defecto
     * @return bool
     */
    private function readBoolEnv(string $key, bool $default): bool
    {
        $raw = Env::get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $raw));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Validate that required credentials are present
     *
     * @throws Exception If credentials are missing
     */
    private function validateCredentials(): void
    {
        if (empty($this->clientEmail)) {
            throw new Exception('GOOGLE_DRIVE_CLIENT_EMAIL environment variable is required');
        }

        if (empty($this->privateKey)) {
            throw new Exception('GOOGLE_DRIVE_PRIVATE_KEY environment variable is required');
        }
    }

    /**
     * Ensure a directory exists, creating it if necessary
     *
     * @param string $directory The directory path to ensure exists
     * @throws Exception If directory cannot be created
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, self::DIRECTORY_PERMISSIONS, true) && !is_dir($directory)) {
                throw new Exception("Error creando directorio: $directory");
            }
        }
    }

    private function resolveDefaultRedis(): ?RedisClient
    {
        try {
            return RedisClient::getInstance();
        } catch (\Throwable $e) {
            Logger::warning('GoogleDriveAuthService: No se pudo instanciar RedisClient', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Invalida y purga el token de acceso de la memoria y de Redis
     */
    public function clearCachedToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = null;

        if ($this->sharedCacheAvailable()) {
            try {
                $this->redis->del(self::REDIS_TOKEN_KEY);
                Logger::info('GoogleDriveAuthService: token de acceso purgado de memoria y Redis');
            } catch (\Throwable $e) {
                Logger::warning('Error purgando token OAuth en Redis', ['error' => $e->getMessage()]);
            }
        }
    }

    private function sharedCacheAvailable(): bool
    {
        return $this->redis !== null && $this->redis->isAvailable();
    }

    private function hasValidMemoryToken(): bool
    {
        return $this->accessToken !== null
            && $this->tokenExpiresAt !== null
            && time() < ($this->tokenExpiresAt - self::TOKEN_EXPIRY_MARGIN_SECONDS);
    }

    /**
     * Consulta el token en Redis validando el margen de seguridad de su TTL.
     * Lanza Throwable si Redis falla.
     */
    private function fetchSharedToken(): ?string
    {
        $cachedToken = $this->redis->get(self::REDIS_TOKEN_KEY);
        if ($cachedToken !== null && $cachedToken !== '') {
            $ttl = (int) $this->redis->ttl(self::REDIS_TOKEN_KEY);
            if ($ttl > self::TOKEN_EXPIRY_MARGIN_SECONDS) {
                $this->accessToken = $cachedToken;
                $this->tokenExpiresAt = time() + $ttl;
                return $this->accessToken;
            }
        }

        return null;
    }

    /**
     * Lectura tolerante a fallos para el paso 2 de getAccessToken().
     * Captura Throwable, registra advertencia y retorna null.
     */
    private function readSharedToken(): ?string
    {
        try {
            return $this->fetchSharedToken();
        } catch (\Throwable $e) {
            Logger::warning('Error consultando token OAuth en Redis', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Bucle de espera pasiva para cuando otro worker adquirió el mutex.
     * Ejecutado dentro del bloque try del lock: si fetchSharedToken() lanza,
     * la excepción interrumpe de inmediato la espera y la maneja el catch exterior.
     */
    private function awaitConcurrentRefresh(): ?string
    {
        for ($i = 0; $i < self::LOCK_WAIT_MAX_ATTEMPTS; $i++) {
            usleep(self::LOCK_WAIT_SLEEP_MICROSECONDS);
            $token = $this->fetchSharedToken();
            if ($token !== null) {
                return $token;
            }
        }

        Logger::warning('OAuth lock wait timeout, proceeding to direct token request', [
            'clientEmail' => $this->clientEmail,
        ]);

        return null;
    }

    /**
     * @return array{access_token:string,expires_in?:int}
     * @throws Exception
     */
    private function requestNewToken(): array
    {
        Logger::info('Generating new Google OAuth Access Token via JWT...', [
            'clientEmail' => $this->clientEmail
        ]);

        $now = time();
        $payload = [
            'iss'   => $this->clientEmail,
            'sub'   => $this->clientEmail,
            'aud'   => self::TOKEN_URI,
            'iat'   => $now,
            'exp'   => $now + self::TOKEN_LIFETIME_SECONDS,
            'scope' => self::SCOPE,
        ];

        $jwt = JWT::encode($payload, $this->privateKey, 'RS256');

        $response = $this->httpClient->post(self::TOKEN_URI, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!isset($data['access_token'])) {
            throw new Exception('Respuesta de token inválida: ' . $body);
        }

        return $data;
    }

    private function storeSharedToken(string $token, int $expiresIn): void
    {
        try {
            $redisTtl = max(self::MIN_REDIS_TTL_SECONDS, $expiresIn - self::TOKEN_EXPIRY_RESERVE_SECONDS);
            $this->redis->set(self::REDIS_TOKEN_KEY, $token, $redisTtl);
        } catch (\Throwable $e) {
            Logger::warning('Error guardando token OAuth en Redis', ['error' => $e->getMessage()]);
        }
    }

    private function releaseRefreshLock(string $lockVal): void
    {
        try {
            $lua = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            $this->redis->eval($lua, [self::REDIS_LOCK_KEY], [$lockVal]);
        } catch (\Throwable $e) {
            Logger::warning('Error liberando lock OAuth en Redis', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get or refresh the OAuth2 access token
     *
     * @return string The access token
     * @throws Exception If authentication fails
     */
    private function getAccessToken(): string
    {
        // 1. Retornar token cacheado en memoria si aún es válido
        if ($this->hasValidMemoryToken()) {
            return $this->accessToken;
        }

        // 2. Consultar token en Redis distribuido si está disponible
        if ($this->sharedCacheAvailable()) {
            $sharedToken = $this->readSharedToken();
            if ($sharedToken !== null) {
                return $sharedToken;
            }
        }

        // 3. Mutex en Redis para evitar tormenta de tokens concurrentes
        $acquiredLock = false;
        $lockVal = bin2hex(random_bytes(16));
        if ($this->sharedCacheAvailable()) {
            try {
                $acquiredLock = (bool) $this->redis->setnx(self::REDIS_LOCK_KEY, $lockVal, self::REDIS_LOCK_TTL);
                if (!$acquiredLock) {
                    $refreshed = $this->awaitConcurrentRefresh();
                    if ($refreshed !== null) {
                        return $refreshed;
                    }
                }
            } catch (\Throwable $e) {
                Logger::warning('Error gestionando lock OAuth en Redis', ['error' => $e->getMessage()]);
            }
        }

        try {
            $tokenData = $this->requestNewToken();
            $this->accessToken = $tokenData['access_token'];
            $expiresIn = (int) ($tokenData['expires_in'] ?? self::DEFAULT_EXPIRES_IN_SECONDS);
            $this->tokenExpiresAt = time() + $expiresIn;

            if ($this->sharedCacheAvailable()) {
                $this->storeSharedToken($this->accessToken, $expiresIn);
            }

            Logger::info('Access Token obtained successfully', [
                'expiresIn' => $expiresIn,
            ]);

            return $this->accessToken;
        } catch (Exception $e) {
            Logger::error('Error obtaining Access Token', ['error' => $e->getMessage()]);
            throw new Exception('Error de autenticación OAuth2: ' . $e->getMessage());
        } finally {
            if ($acquiredLock && $this->sharedCacheAvailable()) {
                $this->releaseRefreshLock($lockVal);
            }
        }
    }

    /**
     * Get file metadata from Google Drive
     *
     * @param string $fileId The Google Drive file ID
     * @return array File metadata or empty array on error
     */
    public function getFileMetadata(string $fileId): array
    {
        try {
            Logger::info("Fetching metadata for File ID: $fileId", ['fileId' => $fileId]);
            $token = $this->getAccessToken();

            $response = $this->httpClient->get(self::DRIVE_API_URL . "/files/$fileId", [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => ['fields' => 'id, name, mimeType, size, createdTime, modifiedTime, owners, webViewLink']
            ]);

            return json_decode((string)$response->getBody(), true);
        } catch (GuzzleException $e) {
            Logger::error("Error fetching file metadata from Google Drive", [
                'fileId' => $fileId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Download file from Google Drive by file ID
     *
     * @param string $fileId The Google Drive file ID
     * @param string $destPath Destination path for the downloaded file
     * @return string Returns mime type
     * @throws Exception If download fails
     */
    public function downloadFile(string $fileId, string $destPath): string
    {
        try {
            $dir = dirname($destPath);
            $this->ensureDirectoryExists($dir);

            Logger::info("Starting download for File ID: $fileId", ['fileId' => $fileId]);
            $token = $this->getAccessToken();

            $response = $this->httpClient->get(self::DRIVE_API_URL . "/files/$fileId", [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => ['alt' => 'media'],
                'sink' => $destPath
            ]);

            if ($response->getStatusCode() === 200) {
                clearstatcache();
                $size = filesize($destPath);
                if ($size === 0) {
                    unlink($destPath);
                    throw new Exception("El archivo descargado está vacío ($destPath)");
                }
                $mimeType = $response->getHeaderLine('Content-Type') ?: 'application/octet-stream';
                Logger::info("Download completed via API v3. Size: $size bytes, Mime: $mimeType", ['path' => $destPath, 'fileId' => $fileId]);
                return $mimeType;
            }

            throw new Exception("Status code inesperado: " . $response->getStatusCode());
        } catch (GuzzleException $e) {
            Logger::error("Guzzle error during file download", ['error' => $e->getMessage(), 'fileId' => $fileId]);
            throw $e;
        } catch (Exception $e) {
            Logger::error("Download failed locally", ['error' => $e->getMessage(), 'fileId' => $fileId]);
            throw $e;
        }
    }

    /**
     * Download file from Google Drive to a temporary location
     *
     * @param string $fileId The Google Drive file ID
     * @param string $prefix Prefix for the temporary file name
     * @return array Array with 'path' and 'size' keys
     * @throws Exception If download fails
     */
    public function downloadFileToTemp(string $fileId, string $prefix = 'gdrive_'): array
    {
        $tempDir = sys_get_temp_dir() . '/audfact';
        $this->ensureDirectoryExists($tempDir);

        $tmpPath = tempnam($tempDir, $prefix);
        if ($tmpPath === false) {
            throw new Exception('Error creando archivo temporal');
        }

        try {
            $this->downloadFile($fileId, $tmpPath);
            clearstatcache();
            $size = filesize($tmpPath) ?: 0;
            return [
                'path' => $tmpPath,
                'size' => $size
            ];
        } catch (Exception $e) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            throw $e;
        }
    }
}
