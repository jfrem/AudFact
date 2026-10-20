<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiResponseException;
use App\Services\Audit\GeminiResponseFailureReason;
use App\Services\Audit\GeminiResponseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeminiResponseGuardTest extends TestCase
{
    #[DataProvider('unusableResponses')]
    public function testUnusableResponseIsTechnicalAndHasExplicitRetryPolicy(
        array $response,
        GeminiResponseFailureReason $reason,
        bool $retryable
    ): void {
        // Arrange:
        $error = null;

        // Act:
        try {
            GeminiResponseGuard::assertUsable($response, 'extraction');
        } catch (GeminiResponseException $caught) {
            $error = $caught;
        }

        // Assert:
        self::assertInstanceOf(GeminiResponseException::class, $error);
        self::assertSame($reason, $error->reason);
        self::assertSame($reason->value, $error->getMessage());
        self::assertSame($retryable, $error->retryable());
        self::assertSame(0, $error->getCode());
    }

    public static function unusableResponses(): iterable
    {
        yield 'provider block precedes absent candidate' => [
            ['promptFeedback' => ['blockReason' => 'OTHER']], GeminiResponseFailureReason::PROVIDER_BLOCKED, false];
        yield 'provider block precedes candidate' => [
            ['promptFeedback' => ['blockReason' => 'OTHER'], 'candidates' => [['finishReason' => 'STOP']]],
            GeminiResponseFailureReason::PROVIDER_BLOCKED, false];
        yield 'missing candidate' => [[], GeminiResponseFailureReason::MISSING_CANDIDATE, true];
        yield 'malformed candidate' => [['candidates' => [false]], GeminiResponseFailureReason::MISSING_CANDIDATE, true];
        yield 'unspecified block is not a block' => [
            ['promptFeedback' => ['blockReason' => 'BLOCK_REASON_UNSPECIFIED']], GeminiResponseFailureReason::MISSING_CANDIDATE, true];
        yield 'truncated complete-looking JSON' => [
            ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{}']]]]]],
            GeminiResponseFailureReason::OUTPUT_TRUNCATED, false];
        yield 'unsafe finish' => [['candidates' => [['finishReason' => 'SAFETY']]], GeminiResponseFailureReason::UNSAFE_FINISH_REASON, false];
        yield 'absent finish' => [['candidates' => [[]]], GeminiResponseFailureReason::UNSAFE_FINISH_REASON, false];
    }

    public function testUsableCandidateIsReturnedWithoutChangingContent(): void
    {
        // Arrange:
        $candidate = ['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"value":1}']]]];

        // Act:
        $actual = GeminiResponseGuard::assertUsable(['candidates' => [$candidate]], 'semantic');

        // Assert:
        self::assertSame($candidate, $actual);
    }

    public function testErrorContextExcludesRemoteContentAndNormalizesInvalidMetadata(): void
    {
        // Arrange:
        $response = ['candidates' => [['finishReason' => 'MAX_TOKENS', 'finishMessage' => 'private text',
            'content' => ['parts' => [['text' => 'private document']]]]],
            'modelVersion' => 'private model/path', 'responseId' => 'private-id',
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 149,
                'thoughtsTokenCount' => 3933, 'other' => 'private']];
        $error = null;

        // Act:
        try {
            GeminiResponseGuard::assertUsable($response, 'extraction');
        } catch (GeminiResponseException $caught) {
            $error = $caught;
        }

        // Assert:
        self::assertInstanceOf(GeminiResponseException::class, $error);
        self::assertSame(['task_type' => 'extraction', 'finish_reason' => 'MAX_TOKENS',
            'model_version' => 'UNKNOWN', 'prompt_tokens' => 100, 'candidates_tokens' => 149,
            'thoughts_tokens' => 3933], $error->context());
    }

    public function testExceptionRejectsContentEvenUnderAnUnknownContextKey(): void
    {
        // Arrange:
        $context = ['task_type' => 'semantic', 'document' => 'private'];

        // Assert:
        self::expectException(\InvalidArgumentException::class);

        // Act:
        new GeminiResponseException(GeminiResponseFailureReason::OUTPUT_TRUNCATED, $context);
    }

    public function testUnknownTaskTypeIsRejected(): void
    {
        // Arrange:
        $response = ['candidates' => [['finishReason' => 'STOP']]];

        // Assert:
        self::expectException(\InvalidArgumentException::class);

        // Act:
        GeminiResponseGuard::assertUsable($response, 'unknown');
    }
}
