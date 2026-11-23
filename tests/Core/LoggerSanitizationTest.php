<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LoggerSanitizationTest extends TestCase
{
    public function testFacNitSecIsRedactedAtTopLevel(): void
    {
        $sanitized = $this->sanitize(['fac_nit_sec' => 2624, 'job_id' => 'abc']);

        self::assertSame('[REDACTED]', $sanitized['fac_nit_sec']);
        self::assertSame('abc', $sanitized['job_id']);
    }

    public function testFacNitSecIsRedactedInNestedContextAndCaseInsensitively(): void
    {
        $sanitized = $this->sanitize(['meta' => ['FAC_NIT_SEC' => '2624', 'count' => 3]]);

        self::assertSame('[REDACTED]', $sanitized['meta']['FAC_NIT_SEC']);
        self::assertSame(3, $sanitized['meta']['count']);
    }

    public function testRawNitIsNotPresentInSerializedContext(): void
    {
        $sanitized = $this->sanitize(['fac_nit_sec' => 2624, 'message_note' => 'ok']);

        self::assertStringNotContainsString('2624', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function sanitize(array $context): array
    {
        $method = new ReflectionMethod(Logger::class, 'sanitizeContext');
        /** @var array<string,mixed> $result */
        $result = $method->invoke(null, $context);

        return $result;
    }
}
