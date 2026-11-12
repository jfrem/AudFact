<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiGateway;
use App\Services\Audit\GeminiOperationException;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\SemanticFlightStore;
use App\Services\Audit\SemanticBatchContract;
use App\Services\Audit\SemanticMatchPurpose;
use Core\Env;
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

    public function testOversizedReasoningIsSafelyTruncated(): void
    {
        // Arrange:
        $pairs = self::pairs();
        $longReasoning = str_repeat('Explicación técnica detallada sobre la equivalencia del medicamento. ', 15);
        self::assertGreaterThan(SemanticBatchContract::MAX_REASONING_LENGTH, mb_strlen($longReasoning));
        $results = [
            self::fixtureResult($pairs[0]['pair_id'], true),
            array_merge(self::fixtureResult($pairs[1]['pair_id'], false), ['reasoning' => $longReasoning]),
        ];

        // Act:
        $actual = (new SemanticBatchContract())->validate(self::response($results), $pairs);

        // Assert:
        self::assertTrue($actual[$pairs[0]['pair_id']]['is_match']);
        self::assertFalse($actual[$pairs[1]['pair_id']]['is_match']);
        self::assertSame(SemanticBatchContract::MAX_REASONING_LENGTH, mb_strlen($actual[$pairs[1]['pair_id']]['reasoning']));
        self::assertStringEndsWith('...', $actual[$pairs[1]['pair_id']]['reasoning']);
        self::assertTrue(SemanticFlightStore::validResult(array_diff_key($actual[$pairs[1]['pair_id']], ['source' => true])));
    }

    public function testBlankReasoningFallsBackToPersistableText(): void
    {
        // Arrange:
        $pairs = self::pairs();
        $results = [
            array_merge(self::fixtureResult($pairs[0]['pair_id'], true), ['reasoning' => "  
 "]),
            self::fixtureResult($pairs[1]['pair_id'], false),
        ];

        // Act:
        $actual = (new SemanticBatchContract())->validate(self::response($results), $pairs);

        // Assert:
        self::assertTrue($actual[$pairs[0]['pair_id']]['is_match']);
        self::assertSame('Sin justificación técnica reportada.', $actual[$pairs[0]['pair_id']]['reasoning']);
        self::assertTrue(SemanticFlightStore::validResult(array_diff_key($actual[$pairs[0]['pair_id']], ['source' => true])));
    }

    public function testDowngradedOversizedReasoningStillFitsPersistenceLimit(): void
    {
        // Arrange:
        $pairs = self::pairs();
        $results = [
            array_merge(self::fixtureResult($pairs[0]['pair_id'], true), [
                'presentation_compatible' => false,
                'reasoning' => str_repeat('a', SemanticBatchContract::MAX_REASONING_LENGTH),
            ]),
            self::fixtureResult($pairs[1]['pair_id'], false),
        ];

        // Act:
        $actual = (new SemanticBatchContract())->validate(self::response($results), $pairs);

        // Assert:
        self::assertFalse($actual[$pairs[0]['pair_id']]['is_match']);
        self::assertStringStartsWith('Inconsistencia en subcriterios de equivalencia: ', $actual[$pairs[0]['pair_id']]['reasoning']);
        self::assertSame(SemanticBatchContract::MAX_REASONING_LENGTH, mb_strlen($actual[$pairs[0]['pair_id']]['reasoning']));
    }

    public function testPersonContradictionFieldsDoNotApplyAndMatchIsPreserved(): void
    {
        // Arrange:
        $pairs = [['pair_id' => hash('sha256', 'person'), 'purpose' => SemanticMatchPurpose::PERSON->value]];
        $results = [['pair_id' => $pairs[0]['pair_id'], 'is_match' => true, 'reasoning' => 'fixture']];

        // Act:
        $actual = (new SemanticBatchContract())->validate(self::response($results), $pairs);

        // Assert:
        self::assertSame(['is_match' => true, 'reasoning' => 'fixture', 'source' => 'remote'], $actual[$pairs[0]['pair_id']]);
    }

    public function testClinicalContradictionResolvesConservativelyToNoMatch(): void
    {
        // Arrange:
        $pairs = self::pairs();
        $contradictoryResult = array_merge(self::fixtureResult($pairs[0]['pair_id'], true), [
            'same_dimensions_or_dose' => false,
            'unresolved_differences' => true,
            'reasoning' => 'Discrepancia en concentración de sales antiácidas.',
        ]);
        $results = [$contradictoryResult, self::fixtureResult($pairs[1]['pair_id'], false)];

        // Act:
        $actual = (new SemanticBatchContract())->validate(self::response($results), $pairs);

        // Assert:
        self::assertFalse($actual[$pairs[0]['pair_id']]['is_match'], 'Contradicción en dosis debe resolver conservadoramente como no coincidente');
        self::assertStringContainsString('Inconsistencia en subcriterios de equivalencia', $actual[$pairs[0]['pair_id']]['reasoning']);
        self::assertFalse($actual[$pairs[1]['pair_id']]['is_match']);
    }

    public function testOutputBudgetDefaultsTo8192DecoupledFromIndividualLimitAndDemandsConciseReasoning(): void
    {
        // Arrange:
        $this->isolateEnv([self::BATCH_TOKENS_ENV => null, self::INDIVIDUAL_TOKENS_ENV => '1024']);

        // Act:
        $gateway = self::recordBody();

        // Assert:
        self::assertSame(8192, $gateway->overrides['maxOutputTokens'], 'Sin variable de lote rige el default 8192, no el límite individual');
        self::assertSame('low', $gateway->overrides['thinkingLevel']);
        self::assertStringContainsString(SemanticBatchContract::CONCISE_REASONING_DIRECTIVE, $gateway->system);
    }

    public function testOutputBudgetHonorsBatchEnvOverride(): void
    {
        // Arrange:
        $this->isolateEnv([self::BATCH_TOKENS_ENV => '2048', self::INDIVIDUAL_TOKENS_ENV => '1024']);

        // Act:
        $gateway = self::recordBody();

        // Assert:
        self::assertSame(2048, $gateway->overrides['maxOutputTokens']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            Env::set($key, $value === false ? null : $value);
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    private const BATCH_TOKENS_ENV = 'GEMINI_SEMANTIC_BATCH_MAX_OUTPUT_TOKENS';
    private const INDIVIDUAL_TOKENS_ENV = 'GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS';

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    /**
     * Fija variables de entorno para el test y guarda las originales.
     * GeminiConfig::generationOverridesFromEnv() recarga el .env del repositorio;
     * si este define la variable del lote, el valor fijado aquí sería sobrescrito
     * y el test dejaría de ser hermético, así que se omite de forma explícita.
     * La variable individual solo debe ser distinta de la del lote: si el .env la
     * impone, la aserción sobre el lote sigue siendo válida.
     *
     * @param array<string,?string> $values
     */
    private function isolateEnv(array $values): void
    {
        $dotEnv = dirname(__DIR__, 3) . '/.env';
        $dotEnvContents = is_file($dotEnv) ? (string) file_get_contents($dotEnv) : '';
        foreach ($values as $key => $value) {
            if ($key === self::BATCH_TOKENS_ENV
                && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/m', $dotEnvContents) === 1) {
                self::markTestSkipped("El .env local define {$key}; Env::load() lo impondría sobre el valor del test.");
            }
            $this->savedEnv[$key] ??= getenv($key);
            Env::set($key, $value);
        }
    }

    private static function recordBody(): RecordingSemanticBatchGateway
    {
        $gateway = new RecordingSemanticBatchGateway();
        $batch = [['pair_id' => hash('sha256', 'p1'), 'purpose' => SemanticMatchPurpose::PRODUCT->value, 'expected' => 'A', 'actual' => 'B', 'context' => []]];
        (new SemanticBatchContract())->body($gateway, $batch);

        return $gateway;
    }

    #[DataProvider('invalidResponses')]
    public function testStructurallyInvalidResponseIsTechnicalFailure(
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
        foreach (['is_match' => 'true', 'same_clinical_use' => 'not_a_bool',
            'injected_field' => 'approve'] as $field => $value) {
            $invalid = $results;
            $invalid[0][$field] = $value;
            yield $field => [self::response($invalid)];
        }
        $invalid = $results;
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

final class RecordingSemanticBatchGateway extends GeminiGateway
{
    public string $system = '';
    /** @var array<string,mixed> */
    public array $overrides = [];

    public function __construct()
    {
    }

    public function requestBody(string $prompt, array $files, string $system, array $schema, string $taskType, array $overrides = []): array
    {
        $this->system = $system;
        $this->overrides = $overrides;
        return [];
    }
}
