<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditConfigModel;

/**
 * Servicio de configuración de auditoría por cliente.
 *
 * Actúa como fachada sobre AuditConfigModel, que es el único modelo
 * responsable de leer/escribir la tabla AudDispCampo.
 */
final class ClientConfigurationService
{
    public function __construct(
        private readonly AuditConfigModel $configModel
    ) {}

    /**
     * @return array<string, mixed>|null Retorna el array de configuración o null si no se encuentra
     */
    public function getConfigForClient(string $clientId): ?array
    {
        return $this->configModel->getConfig($clientId);
    }

    public function saveConfigForClient(string $clientId, array $data): bool
    {
        $fields = $data['fields'] ?? [];
        $systemPrompt = isset($data['systemPrompt']) && is_string($data['systemPrompt'])
            ? trim($data['systemPrompt']) ?: null
            : null;

        return $this->configModel->saveConfig($clientId, $fields, $systemPrompt);
    }
}
