<?php
namespace Core;

class RateLimit
{
    private static $storageFile = __DIR__ . '/../logs/ratelimit.json';
    private static $lockFile = __DIR__ . '/../logs/ratelimit.lock';
    
    private static function withLock(callable $callback)
    {
        $logDir = dirname(self::$lockFile);
        
        try {
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                    throw new \RuntimeException('No se pudo crear directorio de logs');
                }
            }
            
            $lock = fopen(self::$lockFile, 'c');
            if (!$lock) {
                throw new \RuntimeException('No se pudo crear archivo de lock');
            }
            
            // Timeout más estricto para evitar bloqueos prolongados
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
        } catch (\Exception $e) {
            Logger::error('Error en rate limiting: ' . $e->getMessage());
            
            // En producción, fallar silenciosamente es más seguro
            if (Env::get('APP_ENV') === 'production') {
                // Permitir la solicitud si el rate limiting falla
                return true;
            }
            
            throw $e; // En desarrollo, mostrar el error
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
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $tmp = self::$storageFile . '.tmp.' . uniqid();
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, self::$storageFile);
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
            
            // Filtrar requests antiguos
            $originalCount = count($data['requests']);
            $data['requests'] = array_filter(
                $data['requests'],
                fn($time) => $now - $time < $window
            );
            
            // Eliminar entrada si no hay requests recientes y no está bloqueada
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
    
    public static function check(string $ip, int $limit = 100, int $window = 60): bool
    {
        try {
            return self::withLock(function() use ($ip, $limit, $window) {
                $storage = self::getStorage();
                $now = time();
                $key = "ip_{$ip}";
                
                if (!isset($storage[$key])) {
                    $storage[$key] = ['requests' => [], 'blocked_until' => 0];
                }
                
                $entry = &$storage[$key];
                
                // Inicializar arrays si no existen
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
                
                // Filtrar requests antiguos
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
                
                // Limpieza probabilística (1% de chance)
                if (rand(1, 100) === 1) {
                    self::cleanupOldEntries($window);
                }
                
                return true;
            });
        } catch (\Exception $e) {
            // En producción, fallar silenciosamente es mejor que exponer errores
            if (Env::get('APP_ENV') === 'development') {
                throw $e;
            }
            
            // En producción, permitir la solicitud si el rate limiting falla
            Logger::error('Rate limiting failed: ' . $e->getMessage());
            return true;
        }
    }
    
    /**
     * Interfaz para futuras implementaciones con Redis/Memcached
     */
    public static function setStorageAdapter($adapter): void
    {
        // Para futura implementación con almacenamiento en memoria
    }
}
