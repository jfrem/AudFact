<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\Env;
use App\Models\AttachmentsModel;
use App\Models\AuditStatusModel;
use App\Models\DispensationModel;
use GuzzleHttp\Client;

/**
 * Factory centralizada para construir AuditOrchestrator.
 *
 * Elimina la duplicación entre AuditController y bin/audit-worker.php (hallazgo A03).
 * Ambos consumen esta factory para garantizar configuración idéntica.
 *
 * @since 3.0.1
 */
class AuditOrchestratorFactory
{
    /**
     * Construye un AuditOrchestrator completamente configurado desde .env.
     *
     * @throws \RuntimeException Si faltan variables de entorno requeridas
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
        // B-NEW-01: Validación básica de formato (debe contener 'gemini' y segmentos)
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

        $responseMimeType = (string) Env::get('GEMINI_RESPONSE_MIME', '');
        if ($responseMimeType === '') {
            throw new \RuntimeException('GEMINI_RESPONSE_MIME no está configurada en .env');
        }

        $temperature = Env::get('GEMINI_TEMPERATURE');
        $topP = Env::get('GEMINI_TOP_P');
        $topK = Env::get('GEMINI_TOP_K');
        $thinkingBudget = Env::get('GEMINI_THINKING_BUDGET');
        $seed = Env::get('GEMINI_SEED');

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
        $fileManager = new AuditFileManager();
        $persistence = new AuditPersistenceService(new AuditStatusModel());

        $preValidator = new AuditPreValidator(
            $dispensationModel,
            $attachmentsModel,
            $fileManager,
            $persistence
        );

        return new AuditOrchestrator(
            $fileManager,
            new AuditPromptBuilder(),
            new AuditResultValidator(),
            new JsonResponseParser(),
            $gateway,
            $persistence,
            new AuditTelemetryService(),
            $preValidator
        );
    }
}
