<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAuditStateStore;
use Tests\Support\InMemoryBatchJobStore;

/**
 * Fidelidad del doble `InMemoryBatchJobStore` frente a los Lua reales (VER-B2-02):
 * `CANCEL_UNDISPATCHED_AUDITS_LUA` y `DISCARD_UNREGISTERED_AUDIT_LUA` de `BatchJobStore`.
 */
final class InMemoryBatchJobStoreTest extends TestCase
{
    private const JOB = '10000000-0000-4000-8000-000000000002';
    private const OTHER_JOB = '10000000-0000-4000-8000-000000000003';

    private InMemoryBatchJobStore $store;
    private InMemoryAuditStateStore $states;

    protected function setUp(): void
    {
        $this->store = new InMemoryBatchJobStore();
        $this->states = new InMemoryAuditStateStore();
        $this->store->auditStateStore = $this->states;
    }

    /**
     * @param array<string,mixed> $jobOverrides
     */
    private function seedAudit(string $ddn, string $auditId, string $entryStatus, bool $dispatched, array $jobOverrides = []): void
    {
        if (!isset($this->store->jobs[self::JOB])) {
            $this->store->seedJob(self::JOB, $jobOverrides);
        }
        $this->store->audits[self::JOB][$auditId] = ['dis_det_nro' => $ddn, 'status' => $entryStatus];
        $this->store->byDispensa[self::JOB][$ddn] = ['audit_id' => $auditId, 'dispatched' => $dispatched];
        $this->store->reservations[$ddn] = ['job_id' => self::JOB, 'audit_id' => $auditId, 'dis_det_nro' => $ddn, 'token' => "tok-{$ddn}"];
        $this->states->audits[$auditId] = ['audit_id' => $auditId, 'dis_det_nro' => $ddn, 'job_id' => self::JOB, 'status' => $entryStatus];
    }

    // ── cancelUndispatchedAudits ─────────────────────────────────────────

    public function testCancelPreservesAlreadyTerminalEntryAndDoesNotCountIt(): void
    {
        $this->seedAudit('D1', 'audit-1', 'completed', false, ['status' => 'failed', 'sealed' => true, 'total' => 1, 'done' => 1]);

        $this->assertSame(0, $this->store->cancelUndispatchedAudits(self::JOB));

        $this->assertSame('completed', $this->store->audits[self::JOB]['audit-1']['status'], 'La entrada terminal no cambia.');
        $this->assertFalse($this->store->byDispensa[self::JOB]['D1']['dispatched'], 'dispatched se conserva en false.');
        $this->assertSame(0, $this->store->jobs[self::JOB]['failed']);
        $this->assertSame('completed', $this->states->audits['audit-1']['status'], 'El estado terminal no cambia.');
        $this->assertArrayNotHasKey('D1', $this->store->reservations, 'La reserva propia sí se libera (como el Lua).');
    }

    public function testCancelTransitionsOnlyNonTerminalUndispatchedEntriesAndIsIdempotent(): void
    {
        $jobState = ['status' => 'failed', 'sealed' => true, 'total' => 3, 'done' => 0];
        $this->seedAudit('D1', 'audit-1', 'pending', false, $jobState);
        $this->seedAudit('D2', 'audit-2', 'pending', true);
        $this->seedAudit('D3', 'audit-3', 'pending', false);
        $this->store->reservations['D3'] = ['job_id' => self::OTHER_JOB, 'audit_id' => 'x', 'dis_det_nro' => 'D3', 'token' => 'ajeno'];

        $this->assertSame(2, $this->store->cancelUndispatchedAudits(self::JOB, 'etapa'));

        foreach (['D1' => 'audit-1', 'D3' => 'audit-3'] as $ddn => $auditId) {
            $this->assertSame(['dis_det_nro' => $ddn, 'status' => 'failed', 'failed_stage' => 'etapa'], $this->store->audits[self::JOB][$auditId]);
            $this->assertFalse($this->store->byDispensa[self::JOB][$ddn]['dispatched'], 'Cancelar no marca como publicado.');
            $this->assertSame('failed', $this->states->audits[$auditId]['status']);
            $this->assertSame('etapa', $this->states->audits[$auditId]['failed_stage']);
        }
        $this->assertSame('pending', $this->store->audits[self::JOB]['audit-2']['status'], 'La despachada sigue viva.');
        $this->assertSame('pending', $this->states->audits['audit-2']['status']);
        $this->assertArrayHasKey('D2', $this->store->reservations, 'La reserva de la despachada se conserva.');
        $this->assertArrayNotHasKey('D1', $this->store->reservations);
        $this->assertSame('ajeno', $this->store->reservations['D3']['token'], 'La reserva ajena se conserva.');
        $this->assertSame(2, $this->store->jobs[self::JOB]['failed']);

        // Segunda cancelación: idempotente.
        $this->assertSame(0, $this->store->cancelUndispatchedAudits(self::JOB, 'etapa'));
        $this->assertSame(2, $this->store->jobs[self::JOB]['failed']);
        $this->assertSame('failed', $this->store->audits[self::JOB]['audit-1']['status']);
    }

    public function testCancelBoundsFailedByTotalMinusDone(): void
    {
        $this->seedAudit('D1', 'audit-1', 'pending', false, ['status' => 'failed', 'total' => 1, 'done' => 1, 'failed' => 0]);

        $this->assertSame(1, $this->store->cancelUndispatchedAudits(self::JOB));
        $this->assertSame(0, $this->store->jobs[self::JOB]['failed'], 'failed = min(failed + n, total - done).');
    }

    public function testCancelIsNoOpWhenJobIsNotFailed(): void
    {
        $this->seedAudit('D1', 'audit-1', 'pending', false, ['status' => 'queued']);

        $this->assertSame(0, $this->store->cancelUndispatchedAudits(self::JOB));
        $this->assertSame('pending', $this->store->audits[self::JOB]['audit-1']['status']);
        $this->assertArrayHasKey('D1', $this->store->reservations);
        $this->assertSame([self::JOB], $this->store->cancelledUndispatchedFor, 'La invocación se registra igualmente.');
    }

    // ── discardUnregisteredAudit ─────────────────────────────────────────

    private function seedUnregistered(int $epoch = 1): void
    {
        $this->store->seedJob(self::JOB, ['generation_epoch' => $epoch]);
        $this->store->reservations['D1'] = ['job_id' => self::JOB, 'audit_id' => 'audit-1', 'dis_det_nro' => 'D1', 'token' => 'tok'];
        $this->states->audits['audit-1'] = ['audit_id' => 'audit-1', 'dis_det_nro' => 'D1', 'job_id' => self::JOB, 'status' => 'pending'];
    }

    public function testDiscardRemovesOwnStateAndReservationWhenEpochMatchesAndDispensaIsUnregistered(): void
    {
        $this->seedUnregistered();

        $this->assertTrue($this->store->discardUnregisteredAudit(self::JOB, 'audit-1', 'D1', 'tok', 1));
        $this->assertArrayNotHasKey('audit-1', $this->states->audits);
        $this->assertArrayNotHasKey('D1', $this->store->reservations);
    }

    public function testDiscardWithStaleEpochWritesNothing(): void
    {
        $this->seedUnregistered(2);

        $this->assertFalse($this->store->discardUnregisteredAudit(self::JOB, 'audit-1', 'D1', 'tok', 1));
        $this->assertArrayHasKey('audit-1', $this->states->audits);
        $this->assertArrayHasKey('D1', $this->store->reservations);
    }

    public function testDiscardWithRegisteredDispensaWritesNothing(): void
    {
        $this->seedUnregistered();
        $this->store->byDispensa[self::JOB]['D1'] = ['audit_id' => 'audit-1', 'dispatched' => false];

        $this->assertFalse($this->store->discardUnregisteredAudit(self::JOB, 'audit-1', 'D1', 'tok', 1));
        $this->assertArrayHasKey('audit-1', $this->states->audits);
        $this->assertArrayHasKey('D1', $this->store->reservations);
    }

    public function testDiscardKeepsForeignReservationAndStateOfAnotherJob(): void
    {
        $this->seedUnregistered();
        $this->store->reservations['D1']['token'] = 'ajeno';
        $this->states->audits['audit-1']['job_id'] = self::OTHER_JOB;

        $this->assertTrue($this->store->discardUnregisteredAudit(self::JOB, 'audit-1', 'D1', 'tok', 1));
        $this->assertSame('ajeno', $this->store->reservations['D1']['token']);
        $this->assertArrayHasKey('audit-1', $this->states->audits);
    }

    public function testDiscardWithEmptyAuditIdOnlyReleasesTheReservation(): void
    {
        $this->seedUnregistered();

        $this->assertTrue($this->store->discardUnregisteredAudit(self::JOB, '', 'D1', 'tok', 1));
        $this->assertArrayHasKey('audit-1', $this->states->audits);
        $this->assertArrayNotHasKey('D1', $this->store->reservations);
    }

    public function testDiscardRequiresLinkedAuditStateStoreWhenAuditIdIsGiven(): void
    {
        $this->seedUnregistered();
        $this->store->auditStateStore = null;

        $this->expectException(\LogicException::class);
        $this->store->discardUnregisteredAudit(self::JOB, 'audit-1', 'D1', 'tok', 1);
    }
}
