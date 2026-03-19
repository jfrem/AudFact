<?php

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Core\Cache;
use Core\RedisClient;
use Core\RedisUnavailableException;

/**
 * Tests unitarios para Cache — correcciones de auditoría Redis.
 *
 * Valida: REDIS-003 (degradación graciosa en get),
 * REDIS-004 (mutex anti-stampede en remember).
 *
 * Usa reflexión para inyectar un mock de RedisClient al singleton estático.
 */
class CacheTest extends TestCase
{
    private MockObject&RedisClient $mockRedis;

    protected function setUp(): void
    {
        // Inyectar mock de RedisClient en Cache vía reflexión
        $this->mockRedis = $this->createMock(RedisClient::class);

        $ref = new \ReflectionClass(Cache::class);
        $prop = $ref->getProperty('redis');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->mockRedis);
    }

    protected function tearDown(): void
    {
        // Limpiar el mock tras cada test
        $ref = new \ReflectionClass(Cache::class);
        $prop = $ref->getProperty('redis');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ── REDIS-003: Cache::get() degrada grácilmente ──

    public function testGetReturnsNullWhenRedisUnavailable(): void
    {
        $this->mockRedis
            ->expects($this->once())
            ->method('get')
            ->willThrowException(new RedisUnavailableException('Redis down'));

        $result = Cache::get('some:key');
        $this->assertNull($result, 'Cache::get debe retornar null cuando Redis no está disponible');
    }

    public function testGetReturnsDeserializedDataOnSuccess(): void
    {
        $data = ['response' => 'success', 'items' => [1, 2]];

        $this->mockRedis
            ->expects($this->once())
            ->method('get')
            ->with('test:key')
            ->willReturn(json_encode($data));

        $result = Cache::get('test:key');
        $this->assertSame($data, $result);
    }

    public function testGetReturnsNullForCacheMiss(): void
    {
        $this->mockRedis
            ->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $result = Cache::get('missing:key');
        $this->assertNull($result);
    }

    // ── REDIS-004: Cache::remember() con mutex ──

    public function testRememberReturnsCachedValueWithoutCallback(): void
    {
        $cachedData = ['cached' => true];

        $this->mockRedis
            ->expects($this->once())
            ->method('get')
            ->willReturn(json_encode($cachedData));

        $callbackExecuted = false;
        $result = Cache::remember('key', function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return ['fresh' => true];
        }, 60);

        $this->assertSame($cachedData, $result);
        $this->assertFalse($callbackExecuted, 'Callback NO debe ejecutarse si hay datos en caché');
    }

    public function testRememberExecutesCallbackAndCachesOnMissWithLockAcquired(): void
    {
        $freshData = ['fresh' => true];

        // Primer get(): cache miss
        $this->mockRedis
            ->method('get')
            ->willReturn(null);

        // setnx() adquiere el lock
        $this->mockRedis
            ->expects($this->once())
            ->method('setnx')
            ->willReturn(true);

        // set() cachea el resultado
        $this->mockRedis
            ->expects($this->once())
            ->method('set')
            ->willReturn(true);

        // del() libera el lock
        $this->mockRedis
            ->expects($this->once())
            ->method('del')
            ->willReturn(true);

        $result = Cache::remember('key', function () use ($freshData) {
            return $freshData;
        }, 60);

        $this->assertSame($freshData, $result);
    }

    public function testRememberDoesFallbackCallbackWhenLockNotAcquiredAndRetriesFail(): void
    {
        $freshData = ['computed' => true];

        // Todas las llamadas a get() retornan null (cache miss persistente)
        $this->mockRedis
            ->method('get')
            ->willReturn(null);

        // setnx() NO adquiere el lock (otro worker lo tiene)
        $this->mockRedis
            ->expects($this->once())
            ->method('setnx')
            ->willReturn(false);

        // set() se llama para cachear el resultado del último recurso
        $this->mockRedis
            ->expects($this->once())
            ->method('set')
            ->willReturn(true);

        $result = Cache::remember('key', function () use ($freshData) {
            return $freshData;
        }, 60);

        $this->assertSame($freshData, $result, 'Debe ejecutar callback como último recurso si reintentos fallan');
    }

    // ── hasDispensationChanged degrada conservadoramente ──

    public function testHasDispensationChangedReturnsTrueWhenRedisDown(): void
    {
        $this->mockRedis
            ->method('get')
            ->willThrowException(new RedisUnavailableException('down'));

        $result = Cache::hasDispensationChanged('FAC-001', 'somehash');
        $this->assertTrue($result, 'Debe asumir cambio (conservador) cuando Redis está caído');
    }

    public function testHasDispensationChangedReturnsFalseWhenHashMatches(): void
    {
        $hash = 'abc123';

        $this->mockRedis
            ->method('get')
            ->with('audit:hash:FAC-001')
            ->willReturn($hash);

        $result = Cache::hasDispensationChanged('FAC-001', $hash);
        $this->assertFalse($result, 'Debe retornar false si el hash no cambió');
    }
}
