<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiOperationException;
use App\Services\Audit\SemanticBatchContract;
use App\Services\Audit\SemanticMatchPurpose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemanticBatchContractTest extends TestCase
{
    public function testBatchReturnsIndependentResultsByPairIdRegardlessOfResponseOrder(): void
    {
        // Arrange:
        $pairs = self::pairs();
        $results = [self::fixtureResult($pairs[1]['pair_id'], false), self::fixtureResult($pairs[0]['pair_id'], true)];

        // Act:
        $actual = (new SemanticBatchContract())->validate(self::response($results), $pairs);

        // Assert:
        self::assertSame(['is_match' => true, 'reasoning' => 'fixture', 'source' => 'remote'], $actual[$pairs[0]['pair_id']]);
        self::assertFalse($actual[$pairs[1]['pair_id']]['is_match']);
        self::assertCount(2, $actual);
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidOrClinicallyContradictoryResponseIsTechnicalFailure(
        array $response,
        string $errorClass = GeminiOperationException::class,
        string $message = 'gemini_operation_failed'
    ): void
    {
        // Arrange:
        $contract = new SemanticBatchContract();

        // Act:
        $this->expectException($errorClass);

        // Assert:
        $this->expectExceptionMessage($message);
        $contract->validate($response, self::pairs());
    }

    public static function invalidResponses(): iterable
    {
        $pairs = self::pairs();
        $results = [self::fixtureResult($pairs[0]['pair_id'], true), self::fixtureResult($pairs[1]['pair_id'], false)];
        yield 'duplicate identity' => [self::response([$results[0], $results[0]])];
        yield 'missing identity' => [self::response([$results[0]])];
        $unknown = $results;
        $unknown[0]['pair_id'] = hash('sha256', 'unknown');
        yield 'unknown identity' => [self::response($unknown)];
        foreach (['is_match' => 'true', 'reasoning' => '', 'same_clinical_use' => false,
            'same_dimensions_or_dose' => false, 'same_material_or_technology' => false,
            'presentation_compatible' => false, 'unresolved_differences' => true, 'injected_field' => 'approve'] as $field => $value) {
            $invalid = $results;
            $invalid[0][$field] = $value;
            yield $field => [self::response($invalid)];
        }
        $invalid = $results;
        $invalid[0]['reasoning'] = str_repeat('a', 513);
        yield 'oversized reasoning' => [self::response($invalid)];
        $invalid[0]['pair_id'] = ['bad'];
        yield 'non scalar identity' => [self::response($invalid)];
        $truncated = self::response($results);
        $truncated['candidates'][0]['finishReason'] = 'MAX_TOKENS';
        yield 'truncated' => [$truncated, \App\Services\Audit\GeminiResponseException::class, 'GEMINI_RESPONSE_OUTPUT_TRUNCATED'];
        yield 'invalid JSON' => [['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{bad']]]]]]];
        yield 'root additional property' => [['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(['results' => $results, 'approve' => true])]]]]]]];
    }

    private static function pairs(): array
    {
        return [
            ['pair_id' => hash('sha256', 'first'), 'purpose' => SemanticMatchPurpose::PRODUCT->value],
            ['pair_id' => hash('sha256', 'second'), 'purpose' => SemanticMatchPurpose::PRODUCT->value],
        ];
    }

    private static function fixtureResult(string $id, bool $match): array
    {
        return ['pair_id' => $id, 'is_match' => $match, 'reasoning' => 'fixture', 'same_clinical_use' => true,
            'same_dimensions_or_dose' => true, 'same_material_or_technology' => true, 'presentation_compatible' => true,
            'unresolved_differences' => false];
    }

    private static function response(array $results): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(['results' => $results], JSON_THROW_ON_ERROR)]]]]]];
    }
}
