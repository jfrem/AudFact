<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * GoogleDriveAuthService 
 */
class GoogleDriveAuthService implements GoogleDriveServiceInterface
{
    private string $clientEmail;
    private string $privateKey;
    private Client $httpClient;
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

    public function __construct(?Client $httpClient = null)
    {
        $this->clientEmail = getenv('GOOGLE_DRIVE_CLIENT_EMAIL') ?: '';
        $this->privateKey = str_replace('\\n', "\n", getenv('GOOGLE_DRIVE_PRIVATE_KEY') ?: '');

        // Validar credenciales
        $this->validateCredentials();

        // Inyección de dependencias con fallback
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'verify' => false
        ]);

        Logger::info('GoogleDriveAuthService initialized', [
            'clientEmail' => $this->clientEmail
        ]);
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

    /**
     * Get or refresh the OAuth2 access token
     *
     * @return string The access token
     * @throws Exception If authentication fails
     */
    private function getAccessToken(): string
    {
        // Retornar token cacheado si aún es válido (con margen de seguridad)
        if ($this->accessToken && $this->tokenExpiresAt && time() < ($this->tokenExpiresAt - self::TOKEN_EXPIRY_MARGIN_SECONDS)) {
            return $this->accessToken;
        }

        Logger::info('Generating new Google OAuth Access Token via JWT...', [
            'clientEmail' => $this->clientEmail
        ]);

        $now = time();
        $payload = [
            'iss' => $this->clientEmail,
            'sub' => $this->clientEmail,
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + self::TOKEN_LIFETIME_SECONDS,
            'scope' => self::SCOPE
        ];

        try {
            // Generar JWT firmado
            $jwt = JWT::encode($payload, $this->privateKey, 'RS256');

            // Intercambiar por Access Token
            $response = $this->httpClient->post(self::TOKEN_URI, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt
                ]
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (!isset($data['access_token'])) {
                throw new Exception("Respuesta de token inválida: " . $body);
            }

            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = $now + ($data['expires_in'] ?? 3590);

            Logger::info('Access Token obtained successfully', [
                'expiresIn' => $data['expires_in'] ?? 3590
            ]);

            return $this->accessToken;
        } catch (Exception $e) {
            Logger::error('Error obtaining Access Token', ['error' => $e->getMessage()]);
            throw new Exception("Error de autenticación OAuth2: " . $e->getMessage());
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
