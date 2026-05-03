<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\Env;
use Core\Logger;
use Core\RedisClient;

/**
 * Circuit Breaker para llamadas a la API de Gemini.
 *
 * Protege el sistema abriendo el circuito tras N fallos consecutivos.
 * La transición open→closed ocurre automáticamente vía expiración del TTL
 * de la key de estado en Redis (no se implementa half-open explícito).
 */
final class GeminiCircuitBreaker
{
    private const KEY_STATE = 'cb:gemini:state';
    private const KEY_FAILS = 'cb:gemini:fails';

    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN   = 'open';

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    /**
     * Verifica el estado del circuito antes de realizar una llamada.
     *
     * @throws \RuntimeException Si el circuito está abierto.
     */
    public function check(): void
    {
        if (!$this->redis->isAvailable()) {
            return; // Degradación: sin Redis, CB siempre cerrado
        }

        try {
            $state = $this->redis->get(self::KEY_STATE) ?? self::STATE_CLOSED;
        } catch (\Core\RedisUnavailableException $e) {
            return;
        }

        if ($state === self::STATE_OPEN) {
            $ttl = $this->redis->ttl(self::KEY_STATE);
            Logger::warning('Circuit Breaker ABIERTO — request rechazado sin llamar API', [
                'cooldownRestante' => $ttl,
            ]);
            throw new \RuntimeException(
                'Circuit Breaker abierto: API Gemini temporalmente no disponible. Reintentar en ' . max($ttl, 0) . 's',
                503
            );
        }
    }

    /**
     * Registra una llamada exitosa. Si estaba half-open, cierra el circuito.
     */
    public function recordSuccess(): void
    {
        if (!$this->redis->isAvailable()) {
            return;
        }

        $this->redis->del(self::KEY_STATE);
        $this->redis->del(self::KEY_FAILS);
    }

    /**
     * Registra un fallo. Si se alcanza el threshold, abre el circuito.
     */
    public function recordFailure(int $httpCode): void
    {
        if (!$this->redis->isAvailable()) {
            return;
        }

        $threshold = (int) Env::get('CB_GEMINI_THRESHOLD', 3);
        $cooldown  = (int) Env::get('CB_GEMINI_COOLDOWN', 60);

        $fails = $this->redis->incr(self::KEY_FAILS, $cooldown * 2);

        if ($fails !== null && $fails >= $threshold) {
            $this->redis->set(self::KEY_STATE, self::STATE_OPEN, $cooldown);

            Logger::warning('Circuit Breaker ABIERTO', [
                'fallosConsecutivos' => $fails,
                'threshold'          => $threshold,
                'cooldownSeconds'    => $cooldown,
                'httpCode'           => $httpCode,
            ]);
        } else {
            Logger::info('Circuit Breaker: fallo registrado', [
                'fallosActuales' => $fails,
                'threshold'      => $threshold,
                'httpCode'       => $httpCode,
            ]);
        }
    }
}
