<?php

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Core\RedisClient;
use Core\RedisUnavailableException;
use Predis\Client as PredisClient;

/**
 * Tests unitarios para RedisClient — correcciones de auditoría Redis.
 *
 * Valida: REDIS-003 (excepción tipada en get), REDIS-004 (setnx),
 * REDIS-005 (incrWithExpire), comportamiento del singleton.
 *
 * NOTA: Estos tests usan mocks de Predis y no requieren Redis real.
 */
class RedisClientTest extends TestCase
{
    private RedisClient $redis;
    private PredisClient $client;
    private string $prefix = 'audfact:';

    protected function setUp(): void
    {
        $this->resetRedisClientSingleton();
        $this->redis = RedisClient::getInstance();
        $this->client = $this->getMockBuilder(PredisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['get', 'setex', 'set', 'eval', 'ttl', 'del'])
            ->getMock();
        $this->setRedisClientState($this->redis, $this->client, $this->prefix);
    }

    private function resetRedisClientSingleton(): void
    {
        $ref = new \ReflectionClass(RedisClient::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    private function setRedisClientState(RedisClient $redis, PredisClient $client, string $prefix): void
    {
        $ref = new \ReflectionClass($redis);

        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($redis, $client);

        $connectedProp = $ref->getProperty('connected');
        $connectedProp->setAccessible(true);
        $connectedProp->setValue($redis, true);

        $prefixProp = $ref->getProperty('prefix');
        $prefixProp->setAccessible(true);
        $prefixProp->setValue($redis, $prefix);
    }

    // ── REDIS-003: get() lanza excepción tipada ──

    public function testGetReturnsNullForNonExistentKey(): void
    {
        $uniqueKey = 'test:nonexistent:' . uniqid();
        $prefixedKey = $this->prefix . $uniqueKey;

        $this->client
            ->expects($this->once())
            ->method('get')
            ->with($prefixedKey)
            ->willReturn(null);

        $result = $this->redis->get($uniqueKey);
        $this->assertNull($result, 'get() debe retornar null para keys que no existen');
    }

    public function testGetReturnsStoredValue(): void
    {
        $key = 'test:stored:' . uniqid();
        $prefixedKey = $this->prefix . $key;

        $this->client
            ->expects($this->once())
            ->method('setex')
            ->with($prefixedKey, 10, 'test_value');

        $this->client
            ->expects($this->once())
            ->method('get')
            ->with($prefixedKey)
            ->willReturn('test_value');

        $this->redis->set($key, 'test_value', 10);

        $result = $this->redis->get($key);
        $this->assertSame('test_value', $result);
    }

    public function testRedisUnavailableExceptionIsThrowable(): void
    {
        $exception = new RedisUnavailableException('test message');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('test message', $exception->getMessage());
    }

    // ── REDIS-004: setnx() ──

    public function testSetnxAcquiresLockOnNewKey(): void
    {
        $key = 'test:lock:' . uniqid();
        $prefixedKey = $this->prefix . $key;

        $this->client
            ->expects($this->once())
            ->method('set')
            ->with($prefixedKey, '1', 'EX', 5, 'NX')
            ->willReturn('OK');

        $acquired = $this->redis->setnx($key, '1', 5);
        $this->assertTrue($acquired, 'setnx debe adquirir lock en key nueva');
    }

    public function testSetnxFailsOnExistingKey(): void
    {
        $key = 'test:lock:' . uniqid();
        $prefixedKey = $this->prefix . $key;

        $this->client
            ->expects($this->exactly(2))
            ->method('set')
            ->with(
                $prefixedKey,
                $this->callback(fn($value) => in_array($value, ['1', '2'], true)),
                'EX',
                5,
                'NX'
            )
            ->willReturnOnConsecutiveCalls('OK', null);

        // Adquirir lock
        $first = $this->redis->setnx($key, '1', 5);
        $this->assertTrue($first);

        // Segundo intento debe fallar
        $second = $this->redis->setnx($key, '2', 5);
        $this->assertFalse($second, 'setnx NO debe adquirir lock si la key ya existe');
    }

    public function testSetnxUsesNxAndExFlags(): void
    {
        $key = 'test:lock:expire:' . uniqid();
        $prefixedKey = $this->prefix . $key;

        $this->client
            ->expects($this->once())
            ->method('set')
            ->with($prefixedKey, '1', 'EX', 1, 'NX')
            ->willReturn('OK');

        $acquired = $this->redis->setnx($key, '1', 1);
        $this->assertTrue($acquired, 'setnx debe devolver true cuando Redis responde OK');
    }

    // ── REDIS-005: incrWithExpire() ──

    public function testIncrWithExpireIncrementsAtomically(): void
    {
        $key = 'test:incr:' . uniqid();
        $prefixedKey = $this->prefix . $key;

        $call = 0;
        $this->client
            ->expects($this->exactly(2))
            ->method('eval')
            ->willReturnCallback(function (...$args) use ($prefixedKey, &$call) {
                $this->assertIsString($args[0]);
                $this->assertSame(1, $args[1]);
                $this->assertSame($prefixedKey, $args[2]);
                $this->assertSame('10', $args[3]);

                $call++;
                return $call === 1 ? 1 : 2;
            });

        $first = $this->redis->incrWithExpire($key, 10);
        $this->assertSame(1, $first, 'Primer INCR debe retornar 1');

        $second = $this->redis->incrWithExpire($key, 10);
        $this->assertSame(2, $second, 'Segundo INCR debe retornar 2');
    }

    public function testIncrWithExpireSetsExpirationOnFirstCall(): void
    {
        $key = 'test:incr:ttl:' . uniqid();
        $prefixedKey = $this->prefix . $key;

        $this->client
            ->expects($this->once())
            ->method('eval')
            ->willReturnCallback(function (...$args) use ($prefixedKey) {
                $this->assertIsString($args[0]);
                $this->assertSame(1, $args[1]);
                $this->assertSame($prefixedKey, $args[2]);
                $this->assertSame('30', $args[3]);

                return 1;
            });

        $this->client
            ->expects($this->once())
            ->method('ttl')
            ->with($prefixedKey)
            ->willReturn(20);

        $this->redis->incrWithExpire($key, 30);

        $ttl = $this->redis->ttl($key);
        $this->assertGreaterThan(0, $ttl, 'Key debe tener TTL > 0 después de incrWithExpire');
        $this->assertLessThanOrEqual(30, $ttl, 'TTL no debe exceder el valor configurado');
    }

    public function testIncrDelegatesToIncrWithExpireWhenTtlProvided(): void
    {
        $key = 'test:incr:delegate:' . uniqid();
        $prefixedKey = $this->prefix . $key;

        $this->client
            ->expects($this->once())
            ->method('eval')
            ->willReturnCallback(function (...$args) use ($prefixedKey) {
                $this->assertIsString($args[0]);
                $this->assertSame(1, $args[1]);
                $this->assertSame($prefixedKey, $args[2]);
                $this->assertSame('15', $args[3]);

                return 1;
            });

        $this->client
            ->expects($this->once())
            ->method('ttl')
            ->with($prefixedKey)
            ->willReturn(10);

        // incr() con TTL debe comportarse igual que incrWithExpire()
        $result = $this->redis->incr($key, 15);
        $this->assertSame(1, $result);

        $ttl = $this->redis->ttl($key);
        $this->assertGreaterThan(0, $ttl, 'incr(key, ttl) debe delegar a incrWithExpire y setear TTL');
    }

    // ── Singleton ──

    public function testGetInstanceReturnsSameObject(): void
    {
        $a = RedisClient::getInstance();
        $b = RedisClient::getInstance();
        $this->assertSame($a, $b, 'getInstance debe retornar la misma instancia (singleton)');
    }
}
