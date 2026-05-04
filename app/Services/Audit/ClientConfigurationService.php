<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditStatusModel;
use App\Models\AttachmentsModel;

/**
 * Servicio de configuración de auditoría por cliente.
 * 
 * Centraliza la lógica de obtención y guardado de configuración,
 * que incluye la consolidación de campos base (hardcodeados) y
 * campos visuales específicos de los adjuntos (AttachmentsModel).
 */
final class ClientConfigurationService
{
    /**
     * Campos de la tabla AudDispEst (Datos principales)
     */
    private const BASE_FIELDS = [
        'NITCliente', 'TipoDocumentoPaciente', 'DocumentoPaciente', 'NombrePaciente',
        'RegimenPaciente', 'CodigoDiagnostico', 'FechaFormula', 'FechaAutorizacion',
        'NumeroAutorizacion', 'CodigoArticulo', 'CodigoProducto', 'CUM', 'Lote',
        'FechaVencimiento', 'Mipres', 'VlrCobrado', 'Cliente', 'IPS', 'Medico',
        'NombreArticulo', 'Laboratorio', 'CantidadEntregada', 'CantidadPrescrita'
    ];

    public function __construct(
        private readonly AuditStatusModel $auditStatusModel,
        private readonly AttachmentsModel $attachmentsModel
    ) {}

    /**
     * @return array<string, mixed>|null Retorna el array de configuración o null si no se encuentra
     */
    public function getConfigForClient(string $clientId): ?array
    {
        $config = $this->auditStatusModel->getConfigByClient($clientId);

        if (!$config) {
            return null;
        }

        $documents = $this->attachmentsModel->getDocumentTypes();
        $configDocs = [];

        foreach ($documents as $doc) {
            $docName = $doc['DocumentoNombre'];
            $docId = (int)$doc['DocumentoId'];
            
            $fields = [];
            
            // Mezclar campos base con overrides
            foreach (self::BASE_FIELDS as $fieldName) {
                $override = $config['documents'][$docName]['fields'][$fieldName] ?? null;

                $fields[] = [
                    'campoNombre'         => $fieldName,
                    'tipoCampo'           => $override['tipoCampo'] ?? 'E',
                    'enabled'             => $override['enabled'] ?? true,
                    'descripcionOverride' => $override['descripcionOverride'] ?? '',
                    'severityOverride'    => $override['severityOverride'] ?? 'ALTA',
                    'rol'                 => $override['rol'] ?? 'AUTORITATIVO',
                    'omitirSi'            => $override['omitirSi'] ?? null,
                ];
            }

            // Campos visuales específicos de este documento
            $visualFields = $this->attachmentsModel->getFieldsByDocument($docId);
            foreach ($visualFields as $vf) {
                $fieldName = $vf['CampoNombre'];
                $override = $config['documents'][$docName]['fields'][$fieldName] ?? null;

                $fields[] = [
                    'campoNombre'         => $fieldName,
                    'tipoCampo'           => 'V',
                    'enabled'             => $override['enabled'] ?? true,
                    'descripcionOverride' => $override['descripcionOverride'] ?? $vf['CampoDescripcion'],
                    'severityOverride'    => $override['severityOverride'] ?? 'ALTA',
                    'rol'                 => $override['rol'] ?? 'AUTORITATIVO',
                    'omitirSi'            => $override['omitirSi'] ?? null,
                ];
            }

            $configDocs[$docName] = [
                'docId'  => $docId,
                'fields' => $fields
            ];
        }

        return [
            'nitSec'       => $config['nitSec'],
            'activo'       => $config['activo'],
            'systemPrompt' => $config['systemPrompt'],
            'documents'    => $configDocs
        ];
    }

    public function saveConfigForClient(string $clientId, array $data): bool
    {
        return $this->auditStatusModel->saveConfigByClient($clientId, $data);
    }
}
