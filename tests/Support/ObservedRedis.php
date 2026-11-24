<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\RedisClient;

/**
 * Envoltorio de una conexión de prueba (p. ej. `IsolatedAuditRedis`) que delega todas las operaciones y notifica cada
 * `eval` con su resultado, para medir efectos (borrados, rebanadas) sin alterar la semántica.
 */
final class ObservedRedis extends RedisClient
{
    /**
     * @param (\Closure(string,array,array,mixed):void)|null $afterEval
     */
    public function __construct(private readonly RedisClient $connection, private readonly ?\Closure $afterEval = null)
    {
    }

    public function eval(string $script, array $keys = [], array $argv = []): mixed
    {
        $result = $this->connection->eval($script, $keys, $argv);
        if ($this->afterEval !== null) {
            ($this->afterEval)($script, $keys, $argv, $result);
        }

        return $result;
    }

    public function get(string $key): ?string
    {
        return $this->connection->get($key);
    }

    public function scanKeys(string $pattern, string $cursor = '0', int $count = 100): array
    {
        return $this->connection->scanKeys($pattern, $cursor, $count);
    }

    public function serverInfo(string $section): array
    {
        return $this->connection->serverInfo($section);
    }

    public function serverConfiguration(array $names): array
    {
        return $this->connection->serverConfiguration($names);
    }
}
