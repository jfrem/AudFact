<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Logger;
use RuntimeException;

/**
 * Trait compartido para serialización JSON y ejecución de scripts Lua en Redis.
 */
trait JsonRedisStoreTrait
{
    /**
     * @param array<string,mixed> $data
     */
    protected static function encodeJson(array $data, string $context = 'Store'): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("{$context}: encoding falló — " . json_last_error_msg());
        }
        return $json;
    }

    /**
     * @return array<string,mixed>
     */
    protected static function decodeJson(string $raw, string $context = 'Store'): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("{$context}: decoding falló — payload inválido en Redis");
        }
        return $data;
    }

    /**
     * Ejecuta un script Lua y retorna true si el resultado es 1.
     * La clase que utiliza este trait debe tener una propiedad `$this->redis`.
     *
     * @param  array<int,string> $keys
     * @param  array<int,mixed> $args
     * @param  array<string,mixed> $logContext
     * @param  array<int,int> $acceptValues Valores enteros del retorno Lua que se consideran éxito (default [1]).
     */
    protected function runScript(
        string $lua,
        array $keys,
        array $args,
        string $errorMessage,
        array $logContext,
        array $acceptValues = [1]
    ): bool {
        try {
            $result = $this->redis->eval($lua, $keys, $args);
        } catch (\Exception $e) {
            Logger::error($errorMessage, array_merge($logContext, ['error' => $e->getMessage()]));
            throw new RuntimeException($errorMessage, 0, $e);
        }

        return in_array((int) $result, $acceptValues, true);
    }

    /**
     * Script Lua genérico de merge: lee JSON de una key, aplica un patch, y guarda con TTL.
     * Centralizado aquí para evitar duplicación entre AuditStateStore y BatchJobStore.
     */
    protected static string $MERGE_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end

        local state = cjson.decode(raw)
        local patch = cjson.decode(ARGV[1])

        for k, v in pairs(patch) do
            state[k] = v
        end

        redis.call('SET', KEYS[1], cjson.encode(state), 'EX', tonumber(ARGV[2]))
        return 1
    LUA;
}