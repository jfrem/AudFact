<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\JobGenerationBusyException;
use App\Services\Audit\Pipeline\JobGenerationFailedException;
use App\Services\Audit\Pipeline\JobLockClaimResult;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobLockClaimResultTest extends TestCase
{
    public function testStatusEnumMapsRedisResponseValues(): void
    {
        self::assertSame(JobLockClaimStatus::ACQUIRED, JobLockClaimStatus::tryFrom('acquired'));
        self::assertSame(JobLockClaimStatus::BUSY, JobLockClaimStatus::tryFrom('busy'));
        self::assertSame(JobLockClaimStatus::TERMINAL, JobLockClaimStatus::tryFrom('terminal'));
        self::assertSame(JobLockClaimStatus::MISSING, JobLockClaimStatus::tryFrom('missing'));
        self::assertNull(JobLockClaimStatus::tryFrom('unknown'));
    }

    public function testAcquiredRequiresEpochAtLeastOne(): void
    {
        $result = new JobLockClaimResult(JobLockClaimStatus::ACQUIRED, 1);

        self::assertSame(JobLockClaimStatus::ACQUIRED, $result->status);
        self::assertSame(1, $result->epoch);
    }

    public function testAcquiredWithoutEpochIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lock ACQUIRED requiere epoch >= 1');

        new JobLockClaimResult(JobLockClaimStatus::ACQUIRED);
    }

    public function testAcquiredWithZeroEpochIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobLockClaimResult(JobLockClaimStatus::ACQUIRED, 0);
    }

    public function testNonAcquiredStatusesAcceptNullEpoch(): void
    {
        foreach ([JobLockClaimStatus::BUSY, JobLockClaimStatus::TERMINAL, JobLockClaimStatus::MISSING] as $status) {
            $result = new JobLockClaimResult($status);
            self::assertSame($status, $result->status);
            self::assertNull($result->epoch);
        }
    }

    public function testBusyExceptionCarriesJobAndChunkWithCode423(): void
    {
        $exception = new JobGenerationBusyException('job-1', 3);

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('job-1', $exception->jobId);
        self::assertSame(3, $exception->chunkIndex);
        self::assertSame(423, $exception->getCode());
        self::assertSame('Lock de generación ocupado para job job-1 en chunk 3', $exception->getMessage());
    }

    public function testFailedExceptionCarriesEpochAndPrevious(): void
    {
        $previous = new RuntimeException('hyt00');
        $exception = new JobGenerationFailedException('fallo', 500, $previous, 7);

        self::assertSame(7, $exception->epoch);
        self::assertSame(500, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testFailedExceptionEpochDefaultsToNull(): void
    {
        $exception = new JobGenerationFailedException('fallo');

        self::assertNull($exception->epoch);
    }
}
