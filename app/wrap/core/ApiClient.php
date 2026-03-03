<?php

namespace App\wrap\core;

use Core\Env;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ApiClient
{
    private string $baseUrl;
    private Client $httpClient;

    public function __construct()
    {
        Env::load();

        $this->baseUrl = rtrim((string) Env::get('WRAP_API_BASE', 'http://localhost:8001'), '/');
        $timeoutMs = (int) Env::get('REQUEST_TIMEOUT_MS', 0);

        $clientConfig = [];
        if ($timeoutMs > 0) {
            $clientConfig['timeout'] = $timeoutMs / 1000;
            $clientConfig['connect_timeout'] = $timeoutMs / 1000;
        }

        $this->httpClient = new Client($clientConfig);
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
        $requestHeaders = [];
        foreach ($headers as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $requestHeaders[trim($name)] = trim($value);
        }

        if (!isset($requestHeaders['Content-Type'])) {
            $requestHeaders['Content-Type'] = 'application/json';
        }

        $options = [
            'headers' => $requestHeaders,
            'http_errors' => false,
        ];

        if ($method === 'POST') {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            return [
                'success' => $status >= 200 && $status < 300,
                'status' => $status,
                'body' => $decoded !== null ? $decoded : $body
            ];
        } catch (GuzzleException $e) {
            return ['success' => false, 'status' => 500, 'error' => $e->getMessage()];
        }
    }
}
