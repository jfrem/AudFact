<?php

namespace Core;

/**
 * RedisUnavailableException — Indica que Redis no está disponible o una operación falló.
 *
 * Permite a capas superiores diferenciar entre:
 * - "key no existe" (retorno null legítimo)
 * - "Redis está caído/error de conexión" (esta excepción)
 *
 * @since 3.1
 * @see RedisClient::get()
 */
class RedisUnavailableException extends \RuntimeException
{
    public function __construct(string $message = 'Redis no disponible', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
