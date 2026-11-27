<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VER-C-01 (SDD-BATCH-001, Sección 9): el conjunto de generaciones pendientes se construye completo, paginando cada
 * origen (PEL, no entregadas, stream sin grupo) con cursor exclusivo. El doble de Redis modela el protocolo de página
 * de `PENDING_BATCH_REQUESTED_LUA` (meta/pel/range); la semántica Lua real la cubre
 * `AuditExecutionMigrationRedisTest::testMigrateDetectsPendingBatchRequestedBeyondTenThousandEntries`.
 */
final class BatchJobStorePendingBatchRequestedTest extends TestCase
{
    private const ENTRIES = 10001;

    /** @var list<array{0:string,1:string}> [id, event JSON] */
    private array $entries = [];
    private bool $hasGroup = false;
    private int $lastDelivered = 0;
    /** @var array<int,true> índices (1..N) en PEL */
    private array $pel = [];
    /** @var list<array<int,string>> */
    private array $pageCalls = [];

    /** @return iterable<string,array{string}> */
    public static function branches(): iterable
    {
        yield 'sin grupo' => ['no_group'];
        yield 'no entregados con grupo' => ['undelivered'];
        yield 'PEL' => ['pel'];
    }

    #[DataProvider('branches')]
    public function testJobInTheLastOfTenThousandAndOneEntriesIsPending(string $branch): void
    {
        $target = AuditEvent::uuidV4();
        $acked = AuditEvent::uuidV4();
        $filler = AuditEvent::uuidV4();
        if ($branch !== 'no_group') {
            // Entregada y confirmada (ACK) antes de las 10.001: no es pendiente.
            $this->append($this->batchRequested($acked));
            $this->hasGroup = true;
            $this->lastDelivered = 1;
        }
        $fillerJson = $this->batchRequested($filler);
        for ($i = 1; $i < self::ENTRIES; $i++) {
            $this->append($fillerJson);
        }
        $this->append($this->batchRequested($target));
        if ($branch === 'pel') {
            $this->lastDelivered = count($this->entries);
            for ($i = 2; $i <= count($this->entries); $i++) {
                $this->pel[$i] = true;
            }
        }

        $ids = $this->pendingIds();

        self::assertArrayHasKey($target, $ids, 'El batch_requested número 10.001 forma parte del conjunto.');
        self::assertArrayHasKey($filler, $ids);
        self::assertArrayNotHasKey($acked, $ids, 'Una entrega confirmada no cuenta como pendiente.');
        $pages = array_filter($this->pageCalls, static fn (array $args): bool => $args[1] !== 'meta');
        self::assertGreaterThanOrEqual(11, count($pages), '10.001 entradas en páginas de <= 1000 exigen >= 11 lecturas.');
        foreach ($pages as $args) {
            self::assertGreaterThan(0, (int) $args[3]);
            self::assertLessThanOrEqual(1000, (int) $args[3], 'Cada página está acotada.');
        }
    }

    /** Separación: las entregadas confirmadas no cuentan; PEL y no entregadas sí, aunque estén tras páginas llenas. */
    public function testSeparatesAckedPelAndUndeliveredAcrossPages(): void
    {
        $acked = AuditEvent::uuidV4();
        $inPel = AuditEvent::uuidV4();
        $undelivered = AuditEvent::uuidV4();
        $filler = AuditEvent::uuidV4();
        $this->hasGroup = true;
        $fillerJson = $this->batchRequested($filler);
        for ($i = 1; $i <= 2500; $i++) {
            $this->append($i === 1200 ? $this->batchRequested($acked) : $fillerJson);
        }
        $this->append($this->batchRequested($inPel));   // 2501: entregada sin ACK
        for ($i = 1; $i <= 2499; $i++) {
            $this->append($fillerJson);
        }
        $this->append($this->batchRequested($undelivered));   // 5001: no entregada
        $this->lastDelivered = 2501;
        for ($i = 1300; $i <= 2501; $i++) {
            $this->pel[$i] = true;
        }
        // Un evento de otro tipo del mismo job no cuenta.
        $other = AuditEvent::uuidV4();
        $this->append(AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4(), $other)->toJson());

        $ids = $this->pendingIds();

        self::assertArrayHasKey($inPel, $ids);
        self::assertArrayHasKey($undelivered, $ids);
        self::assertArrayNotHasKey($acked, $ids);
        self::assertArrayNotHasKey($other, $ids);
        $pelCursors = array_values(array_map(static fn (array $a): string => $a[2],
            array_filter($this->pageCalls, static fn (array $a): bool => $a[1] === 'pel')));
        self::assertSame(['', '2299-0'], $pelCursors, 'PEL de 1.202 entradas: dos páginas, la segunda tras el último id leído.');
        $rangeCursors = array_values(array_map(static fn (array $a): string => $a[2],
            array_filter($this->pageCalls, static fn (array $a): bool => $a[1] === 'range')));
        self::assertSame(['2501-0', '3501-0', '4501-0'], $rangeCursors, 'No entregadas desde last-delivered-id, por páginas.');
    }

    public function testMissingStreamReturnsEmptySetWithoutPaging(): void
    {
        self::assertSame([], $this->pendingIds(false));
        self::assertCount(1, $this->pageCalls, 'Solo la lectura meta.');
    }

    /** @return array<string,true> */
    private function pendingIds(bool $exists = true): array
    {
        $script = (string) (new \ReflectionClassConstant(BatchJobStore::class, 'PENDING_BATCH_REQUESTED_LUA'))->getValue();
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturnCallback(function (string $lua, array $keys = [], array $args = []) use ($script, $exists): mixed {
            self::assertSame($script, $lua);
            self::assertSame([AuditEventPublisher::STREAM_BATCH_INBOX], $keys);
            self::assertSame(AuditEventPublisher::GROUP_BATCH, $args[0]);
            $this->pageCalls[] = $args;

            return $this->page($exists, (string) $args[1], (string) $args[2], (int) $args[3], (string) ($args[4] ?? ''));
        });
        $method = new \ReflectionMethod(BatchJobStore::class, 'pendingBatchRequestedJobIds');

        return $method->invoke(new BatchJobStore($redis));
    }

    /** Modelo del protocolo de página (ids `N-0`, N = posición 1..total). */
    private function page(bool $exists, string $mode, string $cursor, int $count, string $end): array
    {
        if ($mode === 'meta') {
            if (!$exists) {
                return [0, 0, '', ''];
            }
            $last = $this->entries === [] ? '' : $this->entries[count($this->entries) - 1][0];
            return [1, $this->hasGroup ? 1 : 0, $this->hasGroup ? $this->lastDelivered . '-0' : '', $last];
        }
        $from = $cursor === '' ? 0 : (int) explode('-', $cursor)[0];
        $to = $mode === 'pel' ? PHP_INT_MAX : (int) explode('-', $end)[0];
        $out = ['', 0];
        foreach ($this->entries as $index => [$id, $json]) {
            $position = $index + 1;
            if ($position <= $from || $position > $to || ($mode === 'pel' && !isset($this->pel[$position]))) {
                continue;
            }
            if ($out[1] >= $count) {
                break;
            }
            $out[0] = $id;
            $out[1]++;
            $out[] = $json;
        }

        return $out;
    }

    private function append(string $json): void
    {
        $this->entries[] = [(count($this->entries) + 1) . '-0', $json];
    }

    private function batchRequested(string $jobId): string
    {
        return AuditEvent::create(AuditEvent::TYPE_BATCH_REQUESTED, null, $jobId, payload: ['fac_nit_sec' => '1'])->toJson();
    }
}
