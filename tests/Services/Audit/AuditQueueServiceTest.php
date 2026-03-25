<?php

namespace Tests\Services\Audit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Services\Audit\AuditQueueService;
use Core\RedisClient;
use Core\RedisUnavailableException;

/**
 * Tests unitarios para AuditQueueService — correcciones de auditoría Redis.
 *
 * Valida: REDIS-002 (sin fallback no-atómico, excepción propagada en updateJob).
 *
 * Usa reflexión para inyectar mock de RedisClient en la propiedad privada $redis.
 */
class AuditQueueServiceTest extends TestCase
{
    private MockObject&RedisClient $mockRedis;
    private AuditQueueService $service;

    protected function setUp(): void
    {
        $this->mockRedis = $this->createMock(RedisClient::class);

        // AuditQueueService usa RedisClient::getInstance() en constructor.
        // Usamos reflexión para inyectar el mock.
        $this->service = new AuditQueueService();

        $ref = new \ReflectionClass($this->service);
        $prop = $ref->getProperty('redis');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->mockRedis);
    }

    // ── REDIS-002: updateJob propaga excepción si Lua falla ──

    public function testUpdateJobPropagatesExceptionWhenEvalFails(): void
    {
        $this->mockRedis
            ->method('isAvailable')
            ->willReturn(true);

        $this->mockRedis
            ->expects($this->once())
            ->method('eval')
            ->willThrowException(new \RuntimeException('GENERAL ERROR'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GENERAL ERROR');

        $this->service->updateJob('job-123', 'processing', ['processed' => 1]);
    }

    public function testUpdateJobReturnsTrueOnSuccessfulLuaEval(): void
    {
        $this->mockRedis
            ->method('isAvailable')
            ->willReturn(true);

        $this->mockRedis
            ->expects($this->once())
            ->method('eval')
            ->willReturn(1);

        $result = $this->service->updateJob('job-123', 'completed', ['processed' => 5], ['data' => 'ok']);
        $this->assertTrue($result);
    }

    public function testUpdateJobReturnsFalseWhenJobDoesNotExist(): void
    {
        $this->mockRedis
            ->method('isAvailable')
            ->willReturn(true);

        $this->mockRedis
            ->expects($this->once())
            ->method('eval')
            ->willReturn(0);

        $result = $this->service->updateJob('nonexistent-job', 'completed');
        $this->assertFalse($result, 'Debe retornar false si el job no existe en Redis');
    }

    // ── getJobStatus degrada grácilmente ──

    public function testGetJobStatusReturnsNullWhenRedisUnavailable(): void
    {
        $this->mockRedis
            ->expects($this->once())
            ->method('get')
            ->willThrowException(new RedisUnavailableException('Redis down'));

        $result = $this->service->getJobStatus('job-456');
        $this->assertNull($result, 'Debe retornar null cuando Redis no está disponible');
    }

    public function testGetJobStatusReturnsDecodedJobData(): void
    {
        $jobData = ['jobId' => 'job-789', 'status' => 'processing', 'progress' => ['processed' => 3]];

        $this->mockRedis
            ->expects($this->once())
            ->method('get')
            ->willReturn(json_encode($jobData));

        $result = $this->service->getJobStatus('job-789');
        $this->assertSame($jobData, $result);
    }

    public function testGetJobStatusReturnsNullForMissingJob(): void
    {
        $this->mockRedis
            ->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $result = $this->service->getJobStatus('nonexistent');
        $this->assertNull($result);
    }

    // ── NOSCRIPT resilience: auto-recuperación tras reinicio de Redis ──

    public function testUpdateJobRecoverFromNoscriptOnRetry(): void
    {
        $this->mockRedis
            ->method('isAvailable')
            ->willReturn(true);

        // Primera llamada: falla con NOSCRIPT (Redis reiniciado)
        // Segunda llamada (retry): éxito
        $this->mockRedis
            ->expects($this->exactly(2))
            ->method('eval')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('NOSCRIPT No matching script')),
                1
            );

        $result = $this->service->updateJob('job-789', 'completed', ['processed' => 5]);
        $this->assertTrue($result, 'Debe recuperarse automáticamente de NOSCRIPT en el retry');
    }

    public function testUpdateJobPropagatesNonNoscriptExceptions(): void
    {
        $this->mockRedis
            ->method('isAvailable')
            ->willReturn(true);

        $this->mockRedis
            ->expects($this->once())
            ->method('eval')
            ->willThrowException(new \RuntimeException('ERR unknown command'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ERR unknown command');

        $this->service->updateJob('job-999', 'processing');
    }
}
