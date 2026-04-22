<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\Env;
use Core\Logger;
use App\Models\AttachmentsModel;
use App\Models\AuditConfigModel;
use App\Models\AuditStatusModel;
use App\Models\DispensationModel;
use GuzzleHttp\Client;

class AuditOrchestratorFactory
{
    /**
     * Construye el orquestador de auditoría con sus gateways, modelos y servicios auxiliares.
     *
     * @return AuditOrchestrator Instancia lista para ejecutar el pipeline de auditoría.
     * @throws \RuntimeException Si faltan variables críticas de configuración de Gemini.
     */
    public static function create(): AuditOrchestrator
    {
        $apiKey = (string) Env::get('GEMINI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY no configurada');
        }

        $model = (string) Env::get('GEMINI_MODEL', '');
        if ($model === '') {
            throw new \RuntimeException('GEMINI_MODEL no está configurada en .env');
        }
        if (stripos($model, 'gemini') === false || substr_count($model, '-') < 1) {
            throw new \RuntimeException("GEMINI_MODEL '{$model}' no tiene un formato válido (ej: gemini-2.5-flash-preview-05-20)");
        }

        $timeout = (int) Env::get('GEMINI_TIMEOUT', 300);
        $httpClient = new Client([
            'timeout' => $timeout > 0 ? $timeout : 300,
            'verify'  => true,
        ]);

        $maxOutputTokens = (int) Env::get('GEMINI_MAX_OUTPUT_TOKENS', 0);
        if ($maxOutputTokens <= 0) {
            throw new \RuntimeException('GEMINI_MAX_OUTPUT_TOKENS no está configurada o es inválida en .env');
        }

        $responseMimeType = (string) Env::get('GEMINI_RESPONSE_MIME', 'application/json');

        $temperature = Env::get('GEMINI_TEMPERATURE');
        $topP = Env::get('GEMINI_TOP_P');
        $topK = Env::get('GEMINI_TOP_K');
        $thinkingBudget = Env::get('GEMINI_THINKING_BUDGET');
        $seed = Env::get('GEMINI_SEED');

        if ($seed === null || $seed === '') {
            Logger::warning('GEMINI_SEED no configurada — pipeline opera SIN reproducibilidad.');
        }

        $gateway = new GeminiGateway(
            $httpClient,
            $apiKey,
            $model,
            ($temperature !== null && $temperature !== '') ? (float) $temperature : null,
            ($topP !== null && $topP !== '') ? (float) $topP : null,
            ($topK !== null && $topK !== '') ? (int) $topK : null,
            $maxOutputTokens,
            $responseMimeType,
            Env::get('GEMINI_MEDIA_RESOLUTION') ?: null,
            ($thinkingBudget !== null && $thinkingBudget !== '') ? (int) $thinkingBudget : null,
            ($seed !== null && $seed !== '') ? (int) $seed : null
        );

        $dispensationModel = new DispensationModel();
        $attachmentsModel = new AttachmentsModel();
        $auditConfigModel = new AuditConfigModel();
        $fileManager = new AuditFileManager();
        $persistence = new AuditPersistenceService(new AuditStatusModel());
        $telemetry = new AuditTelemetryService();

        $preValidator = new AuditPreValidator(
            $dispensationModel,
            $attachmentsModel,
            $auditConfigModel,
            $fileManager,
            $persistence
        );

        $extractionPrompt = new ExtractionPromptBuilder();
        $classifier = new FieldClassifier();
        $ruleEngine = new RuleEngine();
        $comparator = new SemanticComparator();

        $embeddingModel = (string) Env::get('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001');
        $embeddingGateway = new EmbeddingGateway($httpClient, $apiKey, $embeddingModel);

        return new AuditOrchestrator(
            $fileManager,
            $gateway,
            $persistence,
            $telemetry,
            $preValidator,
            $extractionPrompt,
            $embeddingGateway,
            $comparator,
            $classifier,
            $ruleEngine
        );
    }
}
