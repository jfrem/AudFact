<?php

namespace Core;

class RateLimit
{
    private static $storageFile = __DIR__ . '/../logs/ratelimit.json';
    private static $lockFile = __DIR__ . '/../logs/ratelimit.lock';

    public static function check(string $ip, int $limit = 100, int $window = 60): bool
    {
        try {
            if (function_exists('\apcu_inc')) {
                return self::apcuCheck($ip, $limit, $window);
            }
            return self::fileCheck($ip, $limit, $window);
        } catch (\Exception $e) {
            Logger::error('Rate limiting failed/backend unavailable: ' . $e->getMessage());
            
            // En caso de fallo del backend de Rate Limit, aplicamos Fail-Closed para protección
            if (Env::get('APP_ENV') === 'development') {
                throw $e;
            }

            // Evitar bypass silencioso (Fail Open). Bloquear acceso preventivamente.
            Response::error('Service Unavailable - Rate limiter backend is unreachable', 503);
            return false;
        }
    }

    private static function apcuCheck(string $ip, int $limit, int $window): bool
    {
        $key = "rl_{$ip}";
        // apcu_inc fails if key doesn't exist, returning false
        $current = \apcu_inc($key);

        if ($current === false) {
            // Key didn't exist, create it with expiration
            \apcu_store($key, 1, $window);
            return true;
        }

        if ($current > $limit) {
            Logger::warning("Rate limit excedido para IP (APCu): {$ip}");
            Response::error('Demasiadas peticiones. Intenta de nuevo más tarde.', 429);
        }

        return true;
    }

    private static function fileCheck(string $ip, int $limit, int $window): bool
    {
        return self::withLock(function () use ($ip, $limit, $window) {
            $storage = self::getStorage();
            $now = time();
            $key = "ip_{$ip}";

            if (!isset($storage[$key])) {
                $storage[$key] = ['requests' => [], 'blocked_until' => 0];
            }

            $entry = &$storage[$key];

            if (!isset($entry['requests']) || !is_array($entry['requests'])) {
                $entry['requests'] = [];
            }
            if (!isset($entry['blocked_until'])) {
                $entry['blocked_until'] = 0;
            }

            if ($entry['blocked_until'] > $now) {
                Logger::warning("IP bloqueada por rate limit: {$ip}");
                Response::error('Demasiadas peticiones. Intenta de nuevo más tarde.', 429);
            }

            $entry['requests'] = array_filter(
                $entry['requests'],
                fn($time) => $now - $time < $window
            );

            if (count($entry['requests']) >= $limit) {
                $entry['blocked_until'] = $now + $window;
                self::saveStorage($storage);
                Logger::warning("Rate limit excedido para IP: {$ip}");
                Response::error('Demasiadas peticiones. Intenta de nuevo más tarde.', 429);
            }

            $entry['requests'][] = $now;
            self::saveStorage($storage);

            if (rand(1, 100) === 1) {
                self::cleanupOldEntries($window);
            }

            return true;
        });
    }

    private static function withLock(callable $callback)
    {
        $logDir = dirname(self::$lockFile);

        self::ensureStorageDirectoryExists($logDir);

        $lock = @fopen(self::$lockFile, 'c');
        if (!$lock) {
            throw new \RuntimeException('No se pudo crear archivo de lock para rate limiting');
        }

        $startTime = microtime(true);
        $timeout = 2;

        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) - $startTime > $timeout) {
                fclose($lock);
                throw new \RuntimeException('Timeout al obtener lock para rate limiting');
            }
            usleep(100000); // 100ms
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function getStorage(): array
    {
        if (!file_exists(self::$storageFile)) {
            return [];
        }
        $content = file_get_contents(self::$storageFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private static function saveStorage(array $data): void
    {
        $logDir = dirname(self::$storageFile);
        self::ensureStorageDirectoryExists($logDir);

        $tmp = self::$storageFile . '.tmp.' . uniqid();
        $written = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('No se pudo escribir storage temporal de rate limiting');
        }

        if (!@rename($tmp, self::$storageFile)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo persistir storage de rate limiting');
        }
    }

    private static function cleanupOldEntries(int $window): void
    {
        $storage = self::getStorage();
        $now = time();
        $changed = false;

        foreach ($storage as $key => $data) {
            if (!isset($data['requests']) || !is_array($data['requests'])) {
                unset($storage[$key]);
                $changed = true;
                continue;
            }

            $originalCount = count($data['requests']);
            $data['requests'] = array_filter(
                $data['requests'],
                fn($time) => $now - $time < $window
            );

            $allExpired = empty($data['requests']);
            $blockExpired = ($data['blocked_until'] ?? 0) < $now;

            if ($allExpired && $blockExpired) {
                unset($storage[$key]);
                $changed = true;
            } elseif (count($data['requests']) !== $originalCount) {
                $storage[$key] = $data;
                $changed = true;
            }
        }

        if ($changed) {
            self::saveStorage($storage);
        }
    }

    private static function ensureStorageDirectoryExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear directorio de logs para rate limiting');
        }
    }
}
