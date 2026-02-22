<?php

namespace App\wrap\core;

class ApiClient
{
    private string $baseUrl;

    public function __construct()
    {
        if (class_exists('\\Core\\Env')) {
            \Core\Env::load();
        }
        $this->baseUrl = rtrim(getenv('WRAP_API_BASE') ?: 'http://localhost:8001', '/');
    }

    public function get(string $path, array $query = [], array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request('GET', $url, [], $headers);
    }

    public function post(string $path, array $payload = []): array
    {
        $url = $this->baseUrl . $path;
        return $this->request('POST', $url, $payload);
    }

    private function request(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $baseHeaders = ['Content-Type: application/json'];
        if (!empty($headers)) {
            foreach ($headers as $header) {
                $baseHeaders[] = $header;
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $baseHeaders);

        // Timeout configurable (ms) con fallback seguro
        $timeoutMs = (int)(getenv('REQUEST_TIMEOUT_MS') ?: 0);
        if ($timeoutMs > 0) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'status' => 500, 'error' => $err];
        }

        $decoded = json_decode($body, true);
        return [
            'success' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $decoded !== null ? $decoded : $body
        ];
    }
}
