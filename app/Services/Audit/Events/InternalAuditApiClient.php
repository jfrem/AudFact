<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use Core\Env;
use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class InternalAuditApiClient
{
    private string $baseUrl;
    private ClientInterface $httpClient;

    public function __construct(?ClientInterface $httpClient = null, ?string $baseUrl = null)
    {
        Env::load();

        $resolvedBaseUrl = $baseUrl;
        if ($resolvedBaseUrl === null) {
            $envValue = Env::get('AUDIT_INTERNAL_API_BASE', '');
            $resolvedBaseUrl = is_string($envValue) ? trim($envValue) : '';
        }

        if ($resolvedBaseUrl === '') {
            throw new RuntimeException('AUDIT_INTERNAL_API_BASE es requerido para workers');
        }

        $this->baseUrl = rtrim($resolvedBaseUrl, '/');
        $this->httpClient = $httpClient ?? new Client($this->buildClientConfig());
    }

    public function getDispensation(string $disDetNro): array
    {
        $data = $this->get('/dispensation/' . rawurlencode($disDetNro));
        if (!is_array($data) || !isset($data['header']) || !is_array($data['header'])) {
            throw new RuntimeException('FDV inválida desde API interna');
        }

        return $data;
    }

    public function getClientDocuments(string $clientId): array
    {
        $data = $this->get('/clients/' . rawurlencode($clientId) . '/documents');
        if (!is_array($data)) {
            throw new RuntimeException('Catálogo documental inválido desde API interna');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    public function getAuditConfig(string $clientId): array
    {
        $data = $this->get('/clients/' . rawurlencode($clientId) . '/audit-config');
        if (!is_array($data) || !isset($data['documents']) || !is_array($data['documents'])) {
            throw new RuntimeException('audit-config inválido desde API interna');
        }

        return $data;
    }

    public function getAttachments(string $disDetNro, string $nitSec): array
    {
        $data = $this->get(
            '/dispensation/' . rawurlencode($disDetNro) . '/attachments/' . rawurlencode($nitSec)
        );
        if (!is_array($data)) {
            throw new RuntimeException('Adjuntos inválidos desde API interna');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    public function buildDownloadUrl(string $disDetNro, string $attachmentId): string
    {
        return '/dispensation/'
            . rawurlencode($disDetNro)
            . '/attachments/download/'
            . rawurlencode($attachmentId);
    }

    public function downloadAttachment(string $downloadUrl): array
    {
        $path = '/' . ltrim(trim($downloadUrl), '/');
        $url = $this->baseUrl . $path;

        $startTime = microtime(true);
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Error consumiendo API interna para adjunto: ' . $e->getMessage(), 0, $e);
        } finally {
            $durationMs = (microtime(true) - $startTime) * 1000;
            Logger::info('Internal API call: downloadAttachment', [
                'url' => $url,
                'duration_ms' => (int) $durationMs,
            ]);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("API interna respondió {$status} al descargar adjunto");
        }

        $decoded = json_decode($body, true);

        // Si falló el parseo JSON, asumimos que el controlador ignoró el header y devolvió binario crudo
        if (!is_array($decoded)) {
            $mime = $response->getHeaderLine('Content-Type') ?: 'application/octet-stream';
            return [
                'mime' => $mime,
                'data' => base64_encode($body),
                'duration_ms' => (int) $durationMs,
            ];
        }

        // Si devolvió JSON, soportamos tanto Response::success() como Response::json()
        if (isset($decoded['success'])) {
            if (!$decoded['success']) {
                throw new RuntimeException($decoded['message'] ?? 'Error desconocido al descargar adjunto');
            }
            $data = $decoded['data'] ?? [];
            $mime = is_string($data['mime'] ?? null) ? trim($data['mime']) : '';
            $base64 = is_string($data['data'] ?? null) ? trim($data['data']) : '';
        } else {
            $mime = is_string($decoded['mime'] ?? null) ? trim($decoded['mime']) : '';
            $base64 = is_string($decoded['data'] ?? null) ? trim($decoded['data']) : '';
        }

        if ($mime === '' || $base64 === '') {
            throw new RuntimeException("Estructura de adjunto inválida desde API interna ({$path})");
        }

        return [
            'mime' => $mime,
            'data' => $base64,
            'duration_ms' => (int) $durationMs,
        ];
    }

    private function get(string $path): array
    {
        $url = $this->baseUrl . $path;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Error consumiendo API interna: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Respuesta JSON inválida desde API interna ({$path})");
        }

        if ($status < 200 || $status >= 300 || !($decoded['success'] ?? false)) {
            $message = is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : "API interna respondió {$status}";
            throw new RuntimeException($message);
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException("Respuesta sin data válida desde API interna ({$path})");
        }

        return $data;
    }

    private function buildClientConfig(): array
    {
        $timeoutMs = (int) Env::get('REQUEST_TIMEOUT_MS', 60000);
        $timeout = $timeoutMs > 0 ? $timeoutMs / 1000 : 60.0;

        return [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
        ];
    }
}
