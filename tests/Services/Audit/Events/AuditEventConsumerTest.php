<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\RedisClient;
use Core\RedisUnavailableException;
use Core\SqlServerOperationException;
use Core\SqlServerOperationMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditEventConsumerTest extends TestCase
{
    public function testEnsureGroupCreatesFromStreamOrigin(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects($this->once())
            ->method('xGroupCreate')
            ->with('test.stream', 'test-group', '0')
            ->willReturn(true);

        $consumer = new MinimalConsumer(redis: $redis);
        $consumer->requestStop();
        $consumer->run();
    }

    public function testEnsureGroupFailurePropagatesOriginalError(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')
            ->willThrowException(
                new RedisUnavailableException("Redis XGROUP CREATE falló para stream 'test.stream': ACL error")
            );

        $consumer = new MinimalConsumer(redis: $redis);

        $this->expectException(RedisUnavailableException::class);
        $this->expectExceptionMessage('ACL error');
        $consumer->run();
    }

    public function testNogroupInRuntimeThrowsRatherThanSilentLoop(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willThrowException(
            new RuntimeException("NOGROUP No such consumer group 'test-group' for key name 'audfact:test.stream'")
        );

        $consumer = new MinimalConsumer(redis: $redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/desapareció en runtime/');
        $consumer->run();
    }

    public function testRunProcessesMessageAndAcks(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T00000001']
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('eval')->willReturnCallback(fn($script) => str_contains($script, 'acquired') ? 'acquired' : 1);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id'     => '1700000000000-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000000-0');

        $consumer = new MinimalConsumer(redis: $redis);
        $processed = $consumer->run(1);

        $this->assertSame(1, $processed);
        $this->assertCount(1, $consumer->handled);
        $this->assertSame($event->eventId, $consumer->handled[0]->eventId);
    }

    public function testRunProcessesReclaimedPendingBeforeReadingNewMessages(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: AuditEvent::uuidV4(),
            documentId: AuditEvent::uuidV4(),
            payload: ['document_id' => AuditEvent::uuidV4()]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('eval')->willReturnCallback(fn($script) => str_contains($script, 'acquired') ? 'acquired' : 1);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->expects($this->once())
            ->method('xAutoClaim')
            ->with(
                'test.stream',
                'test-group',
                'test-consumer',
                $this->greaterThanOrEqual(600000),
                '0-0',
                1
            )
            ->willReturn([
                'next' => '0-0',
                'messages' => [[
                    'id' => '1700000000001-0',
                    'fields' => ['event' => $event->toJson()],
                ]],
            ]);
        $redis->expects($this->never())->method('xReadGroupMulti');
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000001-0');

        $consumer = new MinimalConsumer(redis: $redis);
        $processed = $consumer->run(1);

        $this->assertSame(1, $processed);
        $this->assertSame($event->eventId, $consumer->handled[0]->eventId);
    }

    public function testRunRecordsSuccessfulEventTelemetry(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T00000002']
        );
        $telemetryStore = new RecordingTelemetryStateStore();

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('eval')->willReturnCallback(fn($script) => str_contains($script, 'acquired') ? 'acquired' : 1);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id'     => '1700000000000-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->expects($this->never())->method('xAdd');
        $redis->expects($this->once())->method('xAck');

        $consumer = new MinimalConsumer(redis: $redis, stateStore: $telemetryStore);
        $consumer->run(1);

        $this->assertCount(1, $telemetryStore->telemetry);
        $this->assertSame($event->eventId, $telemetryStore->telemetry[0]['event_id']);
        $this->assertSame('audit_created', $telemetryStore->telemetry[0]['event_type']);
        $this->assertSame('test.stream', $telemetryStore->telemetry[0]['stream']);
        $this->assertSame('test-consumer', $telemetryStore->telemetry[0]['consumer']);
        $this->assertSame('acked', $telemetryStore->telemetry[0]['status']);
        $this->assertArrayHasKey('queue_wait_ms', $telemetryStore->telemetry[0]);
        $this->assertArrayHasKey('handle_duration_ms', $telemetryStore->telemetry[0]);
        $this->assertArrayHasKey('ack_duration_ms', $telemetryStore->telemetry[0]);
    }

    public function testRedisUnavailableAtStartupThrows(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $consumer = new MinimalConsumer(redis: $redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis no disponible al iniciar consumer');
        $consumer->run();
    }

    public function testTerminalFailureRunsHookBeforeAcknowledgingMessage(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('eval')->willReturnCallback(fn($script) => str_contains($script, 'acquired') ? 'acquired' : 1);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000003-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000004-0');
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000003-0');

        $consumer = new TerminalFailureConsumer(redis: $redis);
        $consumer->run(1);

        $this->assertSame([$event->eventId], $consumer->terminalFailureEventIds);
    }

    public function testSqlRetryExhaustionDeadLettersAndAcksImmediately(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4()
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('eval')->willReturnCallback(fn($script) => str_contains($script, 'acquired') ? 'acquired' : 1);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('incr')->willReturn(1);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000005-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000005-0');

        $publisher = new ConsumerRecordingPublisher();
        $error = new SqlServerOperationException(
            'default',
            'operation',
            SqlServerOperationMode::IDEMPOTENT_WRITE,
            4,
            '08S01',
            true,
            new \PDOException('SQLSTATE[08S01] Communication link failure')
        );
        $consumer = new SqlTerminalFailureConsumer($error, $redis, $publisher);

        $consumer->run(1);

        $this->assertCount(1, $publisher->deadLetters);
        $this->assertSame(AuditEvent::TYPE_DEAD_LETTER, $publisher->deadLetters[0]->eventType);
        $this->assertSame([$event->eventId], $consumer->terminalFailureEventIds);
    }

    public function testEnsureActiveLeaseThrowsWhenLeaseExpiredInRedis(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->with('dedup:test-group:evt-1')->willReturn(null);

        $consumer = new MinimalConsumer(redis: $redis);
        $consumer->setProcessingContext('evt-1', 'tok-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('titularidad de lease perdida');

        $consumer->ensureActiveLease('guardar en base de datos');
    }

    public function testEnsureActiveLeaseThrowsWhenTokenStolenByAnotherReplica(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->with('dedup:test-group:evt-1')->willReturn('processing:tok-stolen');

        $consumer = new MinimalConsumer(redis: $redis);
        $consumer->setProcessingContext('evt-1', 'tok-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('titularidad de lease perdida');

        $consumer->ensureActiveLease('llamar a Gemini');
    }

    public function testEnsureActiveLeaseSucceedsWhenTokenMatches(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->with('dedup:test-group:evt-1')->willReturn('processing:tok-1');

        $consumer = new MinimalConsumer(redis: $redis);
        $consumer->setProcessingContext('evt-1', 'tok-1');

        $consumer->ensureActiveLease('operacion');
        $this->assertTrue($consumer->isCurrentLeaseValid());
        $this->assertTrue($consumer->hasActiveLease());
    }

    public function testRenewEventLeaseFailsWhenKeyExpiredOrStolen(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn(0);
        $redis->method('get')->willReturn(null);

        $consumer = new MinimalConsumer(redis: $redis);
        $this->assertFalse($consumer->renewEventLease('evt-1', 'tok-1'));
    }

    public function testMarkEventCompletedFailsWhenOwnershipLost(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn(0);
        $redis->method('get')->willReturn('processing:other-tok');

        $consumer = new MinimalConsumer(redis: $redis);
        $this->assertFalse($consumer->testMarkCompleted('evt-1', 'tok-1'));
    }

    public function testClaimEventProcessingLeaseFailsClosedOnRedisError(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willThrowException(new \RuntimeException('Redis error'));
        $redis->method('get')->willThrowException(new \RuntimeException('Redis error'));

        $consumer = new MinimalConsumer(redis: $redis);
        $this->assertSame('processing', $consumer->testClaimLease('evt-1', 'tok-1'));
    }

    public function testRenewActiveLeaseReturnsFalseWhenNoActiveLease(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new MinimalConsumer(redis: $redis);

        $this->assertFalse($consumer->renewActiveLease());
    }

    public function testLeaseCompletedByAnotherReplicaSkipsAndAcksMessage(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T00000099']
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id'     => '1700000000010-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('eval')->willReturn('completed');
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000010-0');

        $consumer = new MinimalConsumer(redis: $redis);
        $processed = $consumer->run(1);

        $this->assertSame(1, $processed);
        $this->assertCount(0, $consumer->handled, 'No debe ejecutar handle() si ya estaba completado');
    }

    public function testLeaseCurrentlyProcessingByAnotherReplicaSkipsWithoutAck(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T00000099']
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id'     => '1700000000011-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('eval')->willReturn('processing');
        $redis->expects($this->never())->method('xAck');

        $consumer = new MinimalConsumer(redis: $redis);
        $consumer->run(1);

        $this->assertCount(0, $consumer->handled, 'No debe ejecutar handle() si otra réplica tiene el lease');
    }

    public function testDeadLetterPublishFailurePreventsAckAndMarkCompleted(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4()
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('incr')->willReturn(1);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000005-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        // Si DLQ falla, NUNCA se debe ejecutar xAck ni eliminar intentos (clearAttempts)
        $redis->expects($this->never())->method('xAck');
        $redis->expects($this->never())->method('del');

        $publisher = new FailingDeadLetterPublisher();
        $error = new \DomainException('Error irrecuperable de dominio');
        $consumer = new GenericTerminalFailureConsumer($error, $redis, $publisher);

        $consumer->run(1);

        $this->assertEmpty($consumer->terminalFailureEventIds, 'No debe ejecutar afterTerminalFailure si DLQ falló');
    }

    public function testSetnxRaceLostReturnsProcessingInFallback(): void
    {
        $redis = $this->createMock(RedisClient::class);
        // eval retorna null para forzar fallback
        $redis->method('eval')->willReturn(null);
        // GET retorna null (clave no existía al chequear)
        $redis->method('get')->with('dedup:test-group:evt-race')->willReturn(null);
        // SETNX retorna false (otra réplica ganó la carrera atómica entre GET y SETNX)
        $redis->method('setnx')->with('dedup:test-group:evt-race', $this->anything(), $this->anything())->willReturn(false);

        $consumer = new MinimalConsumer(redis: $redis);
        $status = $consumer->testClaimLease('evt-race', 'tok-lost');

        $this->assertSame('processing', $status, 'Debe retornar processing cuando SETNX pierde la carrera atómica');
    }

    public function testMarkEventCompletedFailsWhenKeyAlreadyCompletedOrTokenChanged(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn(null);

        $consumer = new MinimalConsumer(redis: $redis);
        $result = $consumer->testMarkCompleted('evt-stale', 'tok-stale');

        $this->assertFalse($result, 'Un worker obsoleto no debe poder marcar completed si la clave ya está en completed o pertenece a otro token');
    }

    public function testMarkEventCompletedFailsClosedWhenLuaThrows(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willThrowException(new \RuntimeException('Lua script execution error'));

        $consumer = new MinimalConsumer(redis: $redis);
        $this->assertFalse($consumer->testMarkCompleted('evt-err', 'tok-err'), 'Debe retornar false fail-closed si Lua falla');
    }

    public function testReleaseEventLeaseFailsClosedWhenLuaThrows(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willThrowException(new \RuntimeException('Lua script execution error'));

        $consumer = new MinimalConsumer(redis: $redis);
        $this->assertFalse($consumer->testReleaseLease('evt-err', 'tok-err'), 'Debe retornar false fail-closed si Lua falla');
    }

    public function testReleaseEventLeaseSucceedsWhenTokenMatchesLua(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn(1);

        $consumer = new MinimalConsumer(redis: $redis);
        $this->assertTrue($consumer->testReleaseLease('evt-ok', 'tok-ok'), 'Debe retornar true si el token coincide');
    }

    public function testRenewEventLeaseExtendsTtlWhenTokenMatchesLua(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("if current == ('processing:' .. leaseToken) then", $script);
                    $this->assertStringContainsString("redis.call('EXPIRE', key, newTtl)", $script);
                    return true;
                }),
                ['dedup:test-group:evt-renew'],
                ['tok-renew', '300']
            )
            ->willReturn(1);

        $consumer = new MinimalConsumer(redis: $redis);
        $this->assertTrue($consumer->renewEventLease('evt-renew', 'tok-renew', 300));
    }

    public function testRenewActiveLeaseDuringHandlerExecution(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->willReturn(1);

        $consumer = new MinimalConsumer(redis: $redis);
        $consumer->setProcessingContext('evt-active-1', 'tok-active-1');

        $this->assertTrue($consumer->hasActiveLease());
        $this->assertTrue($consumer->renewActiveLease(180));
    }

    public function testPublishBatchTerminalEventIfNeededReleasesClaimWhenPublishFails(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('Redis connection lost'));

        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->expects($this->once())
            ->method('getJob')
            ->with($jobId)
            ->willReturn([
                'status' => BatchJobStore::JOB_STATUS_COMPLETED,
                'total' => 1,
                'done' => 1,
                'failed' => 0,
            ]);

        $jobStore->expects($this->once())
            ->method('claimBatchTerminalEvent')
            ->with(
                $this->equalTo($jobId),
                $this->equalTo(AuditEvent::TYPE_BATCH_COMPLETED),
                $this->isType('string')
            )
            ->willReturn(true);

        // Crucial (QUAL-010): Debe liberar el claim para que no quede bloqueado
        $jobStore->expects($this->once())
            ->method('releaseBatchTerminalEvent')
            ->with(
                $this->equalTo($jobId),
                $this->isType('string')
            )
            ->willReturn(true);

        $jobStore->expects($this->never())
            ->method('confirmBatchTerminalEvent');

        $consumer = new MinimalConsumer(redis: $redis, publisher: $publisher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis connection lost');

        $consumer->invokePublishBatchTerminalEventIfNeeded(
            $jobStore,
            $jobId,
            $auditId,
            AuditEvent::uuidV4()
        );
    }

    public function testTerminalFailureWhenMarkEventCompletedReturnsZeroNeverCallsAckOrClearAttempts(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000003-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000004-0');

        // Claim succeeds, pero markEventCompleted retorna 0 (lease robado/expirado)
        $redis->method('eval')->willReturnCallback(function ($script) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            if (str_contains($script, "key, 'completed', 'EX'")) {
                return 0;
            }
            return 1;
        });

        // NUNCA debe ejecutar xAck ni del (clearAttempts)
        $redis->expects($this->never())->method('xAck');
        $redis->expects($this->never())->method('del');

        $consumer = new TerminalFailureConsumer(redis: $redis);
        $consumer->run(1);

        $this->assertContains($event->eventId, $consumer->terminalFailureEventIds, 'afterTerminalFailure debe ejecutarse antes de markEventCompleted');
    }

    public function testTerminalFailureWhenMarkEventCompletedThrowsNeverCallsAckOrClearAttempts(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000003-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000004-0');

        // Claim succeeds, pero markEventCompleted lanza excepción
        $redis->method('eval')->willReturnCallback(function ($script) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            if (str_contains($script, "key, 'completed', 'EX'")) {
                throw new \RuntimeException('Redis connection timeout during mark completed');
            }
            return 1;
        });

        $redis->expects($this->never())->method('xAck');
        $redis->expects($this->never())->method('del');

        $consumer = new TerminalFailureConsumer(redis: $redis);
        $consumer->run(1);

        $this->assertContains($event->eventId, $consumer->terminalFailureEventIds, 'afterTerminalFailure debe ejecutarse antes de markEventCompleted');
    }

    public function testTerminalFailureWhenAfterTerminalFailureThrowsReleasesLeaseAndNeverCallsMarkCompletedOrAck(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000003-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000004-0');

        $markCompletedCalled = false;
        $redis->method('eval')->willReturnCallback(function ($script) use (&$markCompletedCalled) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            if (str_contains($script, "key, 'completed', 'EX'")) {
                $markCompletedCalled = true;
                return 1;
            }
            return 1;
        });

        $redis->expects($this->never())->method('xAck');
        $redis->method('del')->willReturnCallback(function (string $key) {
            $this->assertStringNotContainsString('attempts:', $key, 'clearAttempts NO debe ser llamado');
            return true;
        });

        $consumer = new ExceptionInHookConsumer(redis: $redis);
        $consumer->run(1);

        $this->assertFalse($markCompletedCalled, 'markEventCompleted NUNCA debe ser llamado si afterTerminalFailure lanza excepción');
    }

    public function testTerminalFailureWithExistingAuditWhenDlqFailsDoesNotFinalizeAuditOrJobOrAck(): void
    {
        $auditId = AuditEvent::uuidV4();
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            payload: ['dis_det_nro' => 'D123']
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000003-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('eval')->willReturnCallback(fn($script) => str_contains($script, 'acquired') ? 'acquired' : 1);

        // Si get se llamase para leer la auditoría antes de DLQ, devolvería estado existente
        $redis->method('get')->willReturnCallback(function ($key) use ($auditId) {
            if (str_contains($key, $auditId)) {
                return json_encode([
                    'audit_id' => $auditId,
                    'status' => 'processing',
                    'dis_id' => 'DIS-1',
                    'reservation_token' => 'tok-1',
                ]);
            }
            return null;
        });

        // DLQ falla: NUNCA se debe ejecutar xAck
        $redis->expects($this->never())->method('xAck');

        $publisher = new FailingDeadLetterPublisher();
        $error = new \DomainException('Fallo crítico');
        $consumer = new GenericTerminalFailureConsumer($error, $redis, $publisher);
        $consumer->run(1);

        $this->assertEmpty($consumer->terminalFailureEventIds);
    }

    public function testTerminalFailureWithExistingAuditWhenFinalizationFailsRetainsPelWithoutAck(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            jobId: $jobId,
            payload: ['dis_det_nro' => 'D123']
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000003-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);

        // Auditoría existente
        $redis->method('get')->willReturnCallback(function ($key) use ($auditId) {
            if (str_contains($key, $auditId)) {
                return json_encode([
                    'audit_id' => $auditId,
                    'status' => 'processing',
                    'dis_id' => 'DIS-1',
                    'reservation_token' => 'tok-1',
                ]);
            }
            return null;
        });

        // Eval para lease claim funciona, pero completeAudit retorna 0 (falla finalización)
        $redis->method('eval')->willReturnCallback(function ($script) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            // COMPLETE_AUDIT_LUA retorna 0 si falla
            return 0;
        });

        // NUNCA debe ejecutar xAck ni del
        $redis->expects($this->never())->method('xAck');
        $redis->expects($this->never())->method('del');

        $publisher = new ConsumerRecordingPublisher();
        $error = new \DomainException('Fallo irrecuperable');
        $consumer = new GenericTerminalFailureConsumer($error, $redis, $publisher);
        $consumer->run(1);

        $this->assertEmpty($consumer->terminalFailureEventIds, 'No debe ejecutar afterTerminalFailure si la finalización falló');
    }

    public function testDeadLetterEventIdIsDeterministicUuidV4(): void
    {
        $eventId1 = AuditEvent::uuidV4();
        $dlqId1 = AuditEvent::deterministicUuidV4('dlq:' . $eventId1);
        $dlqId2 = AuditEvent::deterministicUuidV4('dlq:' . $eventId1);

        $this->assertSame($dlqId1, $dlqId2, 'El UUID determinístico debe ser idéntico para el mismo seed');
        $this->assertTrue(AuditEvent::isUuidV4($dlqId1), 'Debe ser un UUID v4 válido según RFC 4122');
    }

    public function testCompleteAuditReturnsTrueOnAlreadyTerminalLuaReturnTwo(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('eval')
            ->willReturn(2); // COMPLETE_AUDIT_LUA retorna 2 ante estado ya terminal

        $stateStore = new AuditStateStore($redis);
        $result = $stateStore->completeAudit('00000000-0000-4000-8000-000000000001', ['status' => 'failed']);

        $this->assertTrue($result, 'completeAudit debe considerar 2 (already_terminal) como éxito idempotente');
    }

    public function testReleaseAuditReservationReturnsTrueOnMissingKeyLuaReturnTwo(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('eval')
            ->willReturn(2); // RELEASE_AUDIT_RESERVATION_LUA retorna 2 cuando la llave ya no existe

        $jobStore = new BatchJobStore($redis);
        $result = $jobStore->releaseAuditReservation('DIS-999', 'token-123');

        $this->assertTrue($result, 'releaseAuditReservation debe considerar 2 (already_released) como éxito idempotente');
    }

    public function testRedeliveryAfterPartialTerminalFailureCompletesIdempotently(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            jobId: $jobId,
            payload: ['dis_det_nro' => 'D123']
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('setnx')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000005-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000006-0');

        $redis->method('get')->willReturnCallback(function ($key) use ($auditId, $jobId) {
            if (str_contains($key, "audit:{$auditId}:state")) {
                return json_encode([
                    'audit_id' => $auditId,
                    'status' => 'failed',
                    'dis_id' => 'DIS-123',
                    'reservation_token' => 'TOK-123',
                ]);
            }
            if (str_contains($key, "job:{$jobId}:state")) {
                return json_encode([
                    'job_id' => $jobId,
                    'sealed' => true,
                    'total' => 1,
                    'done' => 0,
                    'failed' => 0,
                    'audits' => [],
                ]);
            }
            return null;
        });

        $redis->method('eval')->willReturnCallback(function ($script) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            return 1;
        });

        $redis->expects($this->once())->method('xAck');
        $redis->expects($this->once())->method('del');

        $consumer = new TerminalFailureConsumer(redis: $redis);
        $consumer->run(1);

        $this->assertContains($event->eventId, $consumer->terminalFailureEventIds);
    }

    public function testConsecutiveTerminalDeliveriesDoNotDuplicateDlqPublication(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000005-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);

        // Simular que el flag dlq:sent ya existe (retorna 2 de Lua)
        $redis->method('eval')->willReturnCallback(function ($script, $keys = []) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            $key = $keys[0] ?? '';
            if (str_starts_with($key, 'dlq:sent:')) {
                return 2; // Ya completado previamente en DLQ (QUAL-003)
            }
            return 1;
        });

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->never())->method('publishDeadLetter');

        $consumer = new TerminalFailureConsumer(redis: $redis, publisher: $publisher);
        $consumer->run(1);
    }

    public function testConsecutiveTerminalDeliveriesDoNotDuplicateAuditFailedEvent(): void
    {
        $auditId = AuditEvent::uuidV4();
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000006-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000006-1');

        // Simular que terminal:finalized ya existe (retorna 2 de Lua)
        $redis->method('eval')->willReturnCallback(function ($script, $keys = []) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            $key = $keys[0] ?? '';
            if (str_starts_with($key, 'terminal:finalized:')) {
                return 2; // Ya completado previamente (QUAL-003)
            }
            return 1;
        });

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publishDeadLetter')->willReturn('1700000000006-2');
        // NUNCA debe publicar audit_failed si la finalización se omitió por idempotencia
        $publisher->expects($this->never())->method('publish');

        $consumer = new TerminalFailureConsumer(redis: $redis, publisher: $publisher);
        $consumer->run(1);
    }

    public function testConsecutiveTerminalDeliveriesDoNotReExecuteHook(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000007-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000007-1');

        // Simular que terminal:hook ya existe (retorna 2 de Lua)
        $redis->method('eval')->willReturnCallback(function ($script, $keys = []) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            $key = $keys[0] ?? '';
            if (str_starts_with($key, 'terminal:hook:')) {
                return 2; // Ya ejecutado previamente (QUAL-003)
            }
            return 1;
        });

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publishDeadLetter')->willReturn('1700000000007-2');

        $consumer = new TerminalFailureConsumer(redis: $redis, publisher: $publisher);
        $consumer->run(1);

        // El hook afterTerminalFailure NO debe haberse ejecutado
        $this->assertEmpty($consumer->terminalFailureEventIds);
    }

    public function testFinalizeDeadLetterFailureCleansUpClaimAndDoesNotAck(): void
    {
        $auditId = AuditEvent::uuidV4();
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
        );

        $deletedKeys = [];
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000008-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('setnx')->willReturn(true);
        $redis->method('del')->willReturnCallback(function (string $key) use (&$deletedKeys) {
            $deletedKeys[] = $key;
            return true;
        });

        $redis->method('eval')->willReturnCallback(function ($script, $keys = []) use (&$deletedKeys) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            // Simular fallo en completeAudit Lua (retorna 0)
            if (str_contains($script, 'completed_at')) {
                return 0;
            }
            // Capturar la liberación del claim vía RELEASE_TERMINAL_ACTION_LUA (QUAL-003)
            if (str_contains($script, 'DEL')) {
                $deletedKeys[] = $keys[0] ?? '';
                return 1;
            }
            return 1;
        });

        // getAudit retorna una auditoría existente
        $redis->method('get')->willReturnCallback(function (string $key) use ($auditId) {
            if (str_starts_with($key, 'audit:') || (str_contains($key, $auditId) && !str_contains($key, 'terminal:'))) {
                return json_encode([
                    'status' => 'processing',
                    'audit_id' => $auditId,
                    'dis_id' => 'DIS-TERM-FAIL',
                    'reservation_token' => 'TOK-TERM-FAIL',
                ]);
            }
            if (str_contains($key, 'terminal:finalized:')) {
                return 'processing:active-lease';
            }
            return null;
        });

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publishDeadLetter')->willReturn('1700000000008-1');
        // NUNCA debe hacer ACK ni publicar audit_failed si la finalización falló
        $publisher->expects($this->never())->method('publish');
        $redis->expects($this->never())->method('xAck');

        $consumer = new TerminalFailureConsumer(redis: $redis, publisher: $publisher);
        $consumer->run(1);

        // Debe haberse limpiado el claim de finalización para permitir reintentos PEL (QUAL-011 / QUAL-003)
        $finalizeKeyPrefix = "terminal:finalized:{$auditId}:{$event->eventId}";
        $this->assertTrue(
            in_array($finalizeKeyPrefix, $deletedKeys, true),
            'El claim de finalización debe ser eliminado cuando completeAudit falla'
        );
    }

    public function testTerminalActionDoesNotInterpretNullAsCompleted(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000009-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000009-1');

        // Simular que el script Lua CLAIM_TERMINAL_ACTION_LUA retorna 0 (ocupado por otra réplica)
        $redis->method('eval')->willReturnCallback(function ($script, $keys = []) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            $key = $keys[0] ?? '';
            if (str_starts_with($key, 'dlq:sent:')) {
                return 0; // Ocupado por otra réplica — fail-closed
            }
            return 1;
        });

        $publisher = $this->createMock(AuditEventPublisher::class);
        // NUNCA debe publicar si el claim falló (ocupado por otra réplica)
        $publisher->expects($this->never())->method('publishDeadLetter');
        // NUNCA debe confirmar con ACK
        $redis->expects($this->never())->method('xAck');

        $consumer = new TerminalFailureConsumer(redis: $redis, publisher: $publisher);
        $consumer->run(1);
    }

    public function testTerminalActionCompleteFailsIfOwnershipLost(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000010-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000010-1');

        // Claim adquirido (1), pero COMPLETE_TERMINAL_ACTION_LUA retorna 0 (ownership perdido por expiración)
        $redis->method('eval')->willReturnCallback(function ($script, $keys = []) {
            if (str_contains($script, 'acquired')) {
                return 'acquired';
            }
            // COMPLETE_TERMINAL_ACTION_LUA tiene "SET key completed" y CAS check
            if (str_contains($script, 'current ~= ARGV[1]') && str_contains($script, 'SET')) {
                return 0; // Ownership perdido
            }
            return 1;
        });

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publishDeadLetter')->willReturn('1700000000010-2');
        // NUNCA debe confirmar con ACK si se perdió el ownership
        $redis->expects($this->never())->method('xAck');

        $consumer = new TerminalFailureConsumer(redis: $redis, publisher: $publisher);
        $consumer->run(1);
    }
}

final class RecordingTelemetryStateStore extends AuditStateStore
{
    /** @var array<int,array<string,mixed>> */
    public array $telemetry = [];

    public function __construct()
    {
    }

    public function recordEventTelemetry(string $auditId, array $telemetry): bool
    {
        $this->telemetry[] = $telemetry;
        return true;
    }
}

final class MinimalConsumer extends AuditEventConsumer
{
    /** @var AuditEvent[] */
    public array $handled = [];

    public function setProcessingContext(?string $eventId, ?string $leaseToken): void
    {
        $this->currentProcessingEventId = $eventId;
        $this->currentLeaseToken = $leaseToken;
    }

    public function testClaimLease(string $eventId, string $leaseToken): string
    {
        return $this->claimEventProcessingLease($eventId, $leaseToken);
    }

    public function testMarkCompleted(string $eventId, string $leaseToken): bool
    {
        return $this->markEventCompleted($eventId, $leaseToken);
    }

    public function testReleaseLease(string $eventId, string $leaseToken): bool
    {
        return $this->releaseEventLease($eventId, $leaseToken);
    }

    public function testRenewLease(string $eventId, string $leaseToken): bool
    {
        return $this->renewEventLease($eventId, $leaseToken);
    }

    public function invokePublishBatchTerminalEventIfNeeded(
        \App\Services\Audit\Pipeline\BatchJobStore $jobStore,
        string $jobId,
        string $auditId,
        string $parentEventId
    ): void {
        $this->publishBatchTerminalEventIfNeeded($jobStore, $jobId, $auditId, $parentEventId);
    }

    protected function streams(): array
    {
        return ['test.stream'];
    }

    protected function group(): string
    {
        return 'test-group';
    }

    protected function consumer(): string
    {
        return 'test-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
        $this->handled[] = $event;
    }
}

final class TerminalFailureConsumer extends AuditEventConsumer
{
    /** @var array<int,string> */
    public array $terminalFailureEventIds = [];

    protected function streams(): array
    {
        return ['test.stream'];
    }

    protected function group(): string
    {
        return 'test-group';
    }

    protected function consumer(): string
    {
        return 'test-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
        throw new InvalidArgumentException('evento no recuperable');
    }

    protected function afterTerminalFailure(AuditEvent $event, \Throwable $error): void
    {
        $this->terminalFailureEventIds[] = $event->eventId;
    }
}

final class SqlTerminalFailureConsumer extends AuditEventConsumer
{
    /** @var array<int,string> */
    public array $terminalFailureEventIds = [];

    public function __construct(
        private SqlServerOperationException $failure,
        RedisClient $redis,
        AuditEventPublisher $publisher
    ) {
        parent::__construct($redis, $publisher);
    }

    protected function streams(): array
    {
        return ['test.stream'];
    }

    protected function group(): string
    {
        return 'test-group';
    }

    protected function consumer(): string
    {
        return 'test-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
        throw $this->failure;
    }

    protected function afterTerminalFailure(AuditEvent $event, \Throwable $error): void
    {
        $this->terminalFailureEventIds[] = $event->eventId;
    }
}

final class ConsumerRecordingPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $deadLetters = [];

    public function __construct()
    {
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        $this->deadLetters[] = $event;

        return 'dead-letter-1';
    }
}

final class GenericTerminalFailureConsumer extends AuditEventConsumer
{
    /** @var array<int,string> */
    public array $terminalFailureEventIds = [];

    public function __construct(
        private \Throwable $failure,
        RedisClient $redis,
        AuditEventPublisher $publisher
    ) {
        parent::__construct($redis, $publisher);
    }

    public function testTriggerHandleFailure(AuditEvent $event, string $streamName, string $streamId, \Throwable $error, string $leaseToken = ''): void
    {
        $this->handleFailure($event, $streamName, $streamId, $error, $leaseToken);
    }

    protected function streams(): array
    {
        return ['test.stream'];
    }

    protected function group(): string
    {
        return 'test-group';
    }

    protected function consumer(): string
    {
        return 'test-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
        throw $this->failure;
    }

    protected function afterTerminalFailure(AuditEvent $event, \Throwable $error): void
    {
        $this->terminalFailureEventIds[] = $event->eventId;
    }
}

final class ExceptionInHookConsumer extends AuditEventConsumer
{
    public function __construct(RedisClient $redis)
    {
        parent::__construct($redis, new AuditEventPublisher($redis));
    }

    protected function streams(): array
    {
        return ['test.stream'];
    }

    protected function group(): string
    {
        return 'test-group';
    }

    protected function consumer(): string
    {
        return 'test-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
        throw new \DomainException('Domain exception terminal');
    }

    protected function afterTerminalFailure(AuditEvent $event, \Throwable $error): void
    {
        throw new \RuntimeException('Error simulado en afterTerminalFailure');
    }
}

final class FailingDeadLetterPublisher extends AuditEventPublisher
{
    public function __construct()
    {
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        throw new \RuntimeException('Simulated Redis stream publication failure for DLQ');
    }
}
