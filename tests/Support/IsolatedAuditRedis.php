<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Env;
use Core\RedisClient;
use Predis\Client;

/** Conexión real independiente; solamente opera claves bajo el namespace del test. */
final class IsolatedAuditRedis extends RedisClient
{
    private Client $connection;

    public function __construct(private readonly string $namespace)
    {
        if (preg_match('/^integration:audit:[a-f0-9-]+:$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('Namespace de integración inválido');
        }
        $params = [
            'scheme' => 'tcp', 'host' => Env::get('REDIS_HOST', 'redis'),
            'port' => (int) Env::get('REDIS_PORT', 6379), 'read_write_timeout' => 10, 'timeout' => 5,
        ];
        $password = (string) Env::get('REDIS_PASSWORD', '');
        if ($password !== '') {
            $params['password'] = $password;
        }
        $this->connection = new Client($params);
    }

    public function eval(string $script, array $keys = [], array $argv = []): mixed
    {
        return $this->connection->eval($script, count($keys),
            ...array_merge(array_map(fn(string $key): string => $this->namespace . $key, $keys), $argv));
    }

    public function get(string $key): ?string
    {
        return $this->connection->get($this->namespace . $key);
    }

    public function serverInfo(string $section): array
    {
        $raw = $this->connection->executeRaw(['INFO', $section]);
        $fields = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($line !== '' && $line[0] !== '#' && str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $fields[$key] = $value;
            }
        }
        return $fields;
    }

    public function serverConfiguration(array $names): array
    {
        $raw = $this->connection->executeRaw(array_merge(['CONFIG', 'GET'], $names));
        $fields = [];
        for ($i = 0; $i < count($raw); $i += 2) {
            $fields[(string) $raw[$i]] = (string) $raw[$i + 1];
        }
        return $fields;
    }

    public function cleanup(): void
    {
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->connection->executeRaw(['SCAN', $cursor, 'MATCH', $this->namespace . '*', 'COUNT', 100]);
            if ($keys !== []) {
                $this->connection->executeRaw(array_merge(['DEL'], $keys));
            }
        } while ((string) $cursor !== '0');
    }

    public function scanKeys(string $pattern, string $cursor = '0', int $count = 100): array
    {
        [$next, $keys] = $this->connection->executeRaw(['SCAN', $cursor, 'MATCH', $this->namespace . $pattern, 'COUNT', $count]);
        return [(string) $next, array_map(fn(string $key): string => substr($key, strlen($this->namespace)), $keys)];
    }
}
