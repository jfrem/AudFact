<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiGateway;
use App\Services\Audit\SemanticMatchJudge;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SemanticMatchJudgeTest extends TestCase
{
    public function testGeminiFailureReturnsCleanNonCacheableFallback(): void
    {
        $gateway = new ThrowingSemanticGeminiGateway();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
            'Cureband premium gasa antiadherente esteril',
            ['document_type' => 'AUTORIZACION']
        );

        $this->assertFalse($result['is_match']);
        $this->assertSame(
            'No fue posible confirmar equivalencia semántica; requiere revisión humana.',
            $result['reasoning']
        );
        $this->assertStringNotContainsString('Error de evaluación semántica', $result['reasoning']);
        $this->assertStringNotContainsString('Budget 0 is invalid', $result['reasoning']);
        $this->assertSame(['maxOutputTokens' => 2048], $gateway->lastGenerationOverrides);
        $this->assertSame(GeminiGateway::TASK_SEMANTIC_MATCH, $gateway->lastTaskType);
    }

    public function testMalformedStructuredOutputKeepsGeminiMetricsAndCleanFallback(): void
    {
        $gateway = new MalformedSemanticGeminiGateway();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
            'Cureband premium gasa antiadherente esteril',
            ['document_type' => 'AUTORIZACION']
        );

        $this->assertFalse($result['is_match']);
        $this->assertSame(
            'No fue posible confirmar equivalencia semántica; requiere revisión humana.',
            $result['reasoning']
        );
        $this->assertSame(268, $result['gemini_metrics']['total_tokens'] ?? null);
        $this->assertSame(['maxOutputTokens' => 2048], $gateway->lastGenerationOverrides);
        $this->assertSame(GeminiGateway::TASK_SEMANTIC_MATCH, $gateway->lastTaskType);
    }

    public function testGoldenCaseFalsePositiveIsRejectedByConservativeEvidence(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => true,
            'same_material_or_technology' => false,
            'presentation_compatible' => false,
            'unresolved_differences' => true,
            'reasoning' => 'Misma medida, presentación distinta.',
        ]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
            'Cureband premium gasa antiadherente esteril- 7.5cm x 7.5cm- sobre CAJA 18 unds',
            ['document_type' => 'AUTORIZACION']
        );

        $this->assertFalse($result['is_match']);
        $this->assertStringStartsWith('Evidencia semántica insuficiente:', $result['reasoning']);
        $this->assertSame(GeminiGateway::TASK_SEMANTIC_MATCH, $gateway->lastTaskType);
        $this->assertSame([], $gateway->lastFiles);
    }

    public function testSemanticCacheUsesVersionedContractNamespace(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => true,
            'same_material_or_technology' => true,
            'presentation_compatible' => true,
            'unresolved_differences' => false,
            'reasoning' => 'Equivalentes.',
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->with($this->stringContains('audfact:semantic:match:v5:product:'))
            ->willReturn(null);
        $redis->expects($this->once())
            ->method('set')
            ->with(
                $this->stringContains('audfact:semantic:match:v5:product:'),
                $this->isType('string'),
                $this->equalTo(2592000)
            )
            ->willReturn(true);

        $judge = new SemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate('PRODUCTO A', 'PRODUCTO A');

        $this->assertTrue($result['is_match']);
    }

    public function testSemanticCacheHitReturnsLocalGeminiMetrics(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => false,
            'same_clinical_use' => false,
            'same_dimensions_or_dose' => false,
            'same_material_or_technology' => false,
            'presentation_compatible' => false,
            'unresolved_differences' => true,
            'reasoning' => 'No debería invocar Gemini.',
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->willReturn(json_encode([
                'is_match' => false,
                'reasoning' => 'Resultado desde cache.',
            ]));
        $redis->expects($this->never())->method('set');

        $judge = new SemanticMatchJudge($gateway, $redis);

        $result = $judge->evaluate(
            'DULOXETINA 60MG C*30 CAPSULA',
            'DULOXETINA 60 MG-BLISTER 28 unds',
            ['document_type' => 'DISPENSA', 'field' => 'NombreArticulo']
        );

        $this->assertFalse($result['is_match']);
        $this->assertSame('Resultado desde cache.', $result['reasoning']);
        $this->assertTrue($result['cache_hit'] ?? false);
        $this->assertSame('semantic_match', $result['gemini_metrics']['task_type'] ?? null);
        $this->assertSame('DISPENSA', $result['gemini_metrics']['document_type'] ?? null);
        $this->assertTrue($result['gemini_metrics']['cache_hit'] ?? false);
        $this->assertSame('', $gateway->lastTaskType);
    }

    public function testDoseOmissionCausesConservativeRejection(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => false,
            'same_material_or_technology' => true,
            'presentation_compatible' => true,
            'unresolved_differences' => true,
            'reasoning' => 'El documento omite la dosis de 500mg presente en el producto esperado.',
        ]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);
        $result = $judge->evaluate(
            'ACETAMINOFEN 500MG TABLETA',
            'ACETAMINOFEN TABLETA',
            ['document_type' => 'FORMULA MEDICA']
        );

        $this->assertFalse($result['is_match']);
        $this->assertStringStartsWith('Evidencia semántica insuficiente:', $result['reasoning']);
    }

    public function testUnresolvedDifferencesOverridesGeminiMatch(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => true,
            'same_material_or_technology' => true,
            'presentation_compatible' => true,
            'unresolved_differences' => true,
            'reasoning' => 'Posible diferencia en concentración.',
        ]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);
        $result = $judge->evaluate(
            'IBUPROFENO 400MG',
            'IBUPROFENO CAPSULA',
            ['document_type' => 'FORMULA MEDICA']
        );

        $this->assertFalse($result['is_match']);
        $this->assertStringStartsWith('Evidencia semántica insuficiente:', $result['reasoning']);
    }

    public function testProductContractPromptIncludesDecoupledRules(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => true,
            'same_material_or_technology' => true,
            'presentation_compatible' => true,
            'unresolved_differences' => false,
            'reasoning' => 'Mismos productos.',
        ]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);
        $judge->evaluate('Producto A', 'Producto B', ['document_type' => 'FORMULA MEDICA']);

        $this->assertStringContainsString(
            'EQUIVALENCIA DE DENOMINACIÓN (GENÉRICO VS COMERCIAL)',
            $gateway->lastSystemInstruction
        );
        $this->assertStringContainsString(
            'CONTEXTO DOCUMENTAL Y ESPECIFICACIONES',
            $gateway->lastSystemInstruction
        );
        $this->assertStringContainsString(
            'CONTRADICCIÓN EXPLÍCITA',
            $gateway->lastSystemInstruction
        );
        // Verificar que no contiene jergas locales o geográficas quemadas
        $this->assertStringNotContainsString('Colombia', $gateway->lastSystemInstruction);
        $this->assertStringNotContainsString('tirillas', $gateway->lastSystemInstruction);
        $this->assertStringNotContainsString('lancetas', $gateway->lastSystemInstruction);
        // Verificar que usa schema directo de Structured Output (sin envoltorio de function declaration)
        $this->assertSame('object', $gateway->lastResponseSchema['type'] ?? null);
        $this->assertArrayHasKey('properties', $gateway->lastResponseSchema);
        $this->assertArrayNotHasKey('parameters', $gateway->lastResponseSchema);
    }

    public function testDocumentContextIsPassedToPrompt(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'same_clinical_use' => true,
            'same_dimensions_or_dose' => true,
            'same_material_or_technology' => true,
            'presentation_compatible' => true,
            'unresolved_differences' => false,
            'reasoning' => 'Homologación confirmada: Levotiroxina (Eutirox) 200mcg en tabletas.',
        ]);
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);
        $result = $judge->evaluate(
            'LEVOTIROXINA 200MCG C*50 TABLETA',
            'LEVOTIROXINA (EUTIROX)',
            [
                'document_type' => 'FORMULA MEDICA',
                'document_context' => 'Concentracion: 200MCG | Forma: TABLETA | Posologia: TOMAR 1 TAB CADA 24 HORAS',
            ]
        );

        $this->assertTrue($result['is_match']);
        $this->assertStringContainsString('Información Contextual del Documento Soporte', $gateway->lastPrompt);
        $this->assertStringContainsString('Concentracion: 200MCG', $gateway->lastPrompt);
    }

    public function testPersonNameUsesPersonNamespace(): void
    {
        $gateway = new RecordingSemanticGeminiGateway([
            'is_match' => true,
            'reasoning' => 'Misma persona con orden inverso de apellidos.',
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->with($this->stringContains('audfact:semantic:match:v2:person:'))
            ->willReturn(null);
        $redis->expects($this->once())
            ->method('set')
            ->with(
                $this->stringContains('audfact:semantic:match:v2:person:'),
                $this->isType('string'),
                $this->equalTo(2592000)
            )
            ->willReturn(true);

        $judge = new SemanticMatchJudge($gateway, $redis);
        $result = $judge->evaluate(
            'GARCIA LOPEZ JUAN CARLOS',
            'JUAN CARLOS GARCIA LOPEZ',
            ['call_purpose' => SemanticMatchJudge::PURPOSE_PERSON_MATCH]
        );

        $this->assertTrue($result['is_match']);
    }

    public function testPersonNameGeminiFailureReturnsCleanFallbackWithoutCrashing(): void
    {
        $gateway = new ThrowingSemanticGeminiGateway();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $judge = new SemanticMatchJudge($gateway, $redis);
        $result = $judge->evaluate(
            'CARLOS ALBERTO PEREZ',
            'CARLOS PEREZ',
            ['call_purpose' => SemanticMatchJudge::PURPOSE_PERSON_MATCH]
        );

        $this->assertFalse($result['is_match']);
        $this->assertSame(
            'No fue posible confirmar equivalencia semántica; requiere revisión humana.',
            $result['reasoning']
        );
    }
}

final class ThrowingSemanticGeminiGateway extends GeminiGateway
{
    /** @var array<string,mixed> */
    public array $lastGenerationOverrides = [];
    public string $lastTaskType = '';

    public function __construct()
    {
    }

    /**
     * @param  array<int,array<string,mixed>> $files
     * @param  array<string,mixed> $responseSchema
     * @param  array<string,mixed> $generationOverrides
     * @param  array<string,mixed>|null $debugContext
     * @return array<string,mixed>
     */
    public function sendWithStructuredOutput(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $responseSchema,
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastTaskType = $taskType;
        $this->lastGenerationOverrides = $generationOverrides;

        throw new RuntimeException('Budget 0 is invalid. This model only works in thinking mode.');
    }
}

final class MalformedSemanticGeminiGateway extends GeminiGateway
{
    /** @var array<string,mixed> */
    public array $lastGenerationOverrides = [];
    public string $lastTaskType = '';

    public function __construct()
    {
    }

    /**
     * @param  array<int,array<string,mixed>> $files
     * @param  array<string,mixed> $responseSchema
     * @param  array<string,mixed> $generationOverrides
     * @param  array<string,mixed>|null $debugContext
     * @return array<string,mixed>
     */
    public function sendWithStructuredOutput(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $responseSchema,
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastTaskType = $taskType;
        $this->lastGenerationOverrides = $generationOverrides;

        return [
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'invalid-json{{{'],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
            'X-Audit-Metrics' => [
                'task_type' => 'semantic_match',
                'total_tokens' => 268,
            ],
        ];
    }
}

final class RecordingSemanticGeminiGateway extends GeminiGateway
{
    public string $lastPrompt = '';
    public string $lastTaskType = '';
    public string $lastSystemInstruction = '';
    /** @var array<int,array<string,mixed>> */
    public array $lastFiles = [];
    /** @var array<string,mixed> */
    public array $lastResponseSchema = [];

    /**
     * @param array<string,mixed> $args
     */
    public function __construct(private array $args)
    {
    }

    /**
     * @param  array<int,array<string,mixed>> $files
     * @param  array<string,mixed> $responseSchema
     * @param  array<string,mixed> $generationOverrides
     * @param  array<string,mixed>|null $debugContext
     * @return array<string,mixed>
     */
    public function sendWithStructuredOutput(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $responseSchema,
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->lastPrompt = $prompt;
        $this->lastTaskType = $taskType;
        $this->lastFiles = $files;
        $this->lastSystemInstruction = $systemInstruction;
        $this->lastResponseSchema = $responseSchema;

        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($this->args, JSON_UNESCAPED_UNICODE),
                    ]],
                ],
                'finishReason' => 'STOP',
            ]],
            'X-Audit-Metrics' => [
                'task_type' => 'semantic_match',
                'total_tokens' => 100,
            ],
        ];
    }
}
