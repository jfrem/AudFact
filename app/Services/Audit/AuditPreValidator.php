<?php

namespace App\Services\Audit;

use App\Models\AttachmentsModel;
use App\Models\AuditConfigModel;
use App\Models\DispensationModel;
use Core\Logger;

class AuditPreValidator
{
    private const HUMAN_REVIEW_SERVICE_TYPES = ['TUTELA'];

    private const ERROR_DISPENSATION_NOT_FOUND = 'Dispensación no encontrada';
    private const ERROR_ATTACHMENT_NOT_FOUND = 'Adjunto no encontrado';
    private const ERROR_MISSING_ATTACHMENTS = 'Documentos requeridos sin archivo adjunto';
    private const ERROR_MIPRES_INCOMPLETE = 'Dispensación MIPRES incompleta: faltan campos obligatorios';
    private const ERROR_HUMAN_REVIEW_REQUIRED = 'Tipo de servicio requiere revisión humana';
    private const ERROR_ATTACHMENT_MAX_PAGES_EXCEEDED = 'Adjunto supera el maximo de páginas permitidas';
    private const ERROR_PREPARING_ATTACHMENT = 'Error preparando adjunto';

    private const REQUIRED_MIPRES_FIELDS = ['Mipres', 'IdPrincipal', 'IdDirec', 'IdProg', 'IdEntr', 'IdRepEnt'];

    private DispensationModel $dispensationModel;
    private AttachmentsModel $attachmentsModel;
    private AuditConfigModel $auditConfigModel;
    private AuditFileManager $fileManager;
    private AuditPersistenceService $persistence;

    public function __construct(
        DispensationModel $dispensationModel,
        AttachmentsModel $attachmentsModel,
        AuditConfigModel $auditConfigModel,
        AuditFileManager $fileManager,
        AuditPersistenceService $persistence
    ) {
        $this->dispensationModel = $dispensationModel;
        $this->attachmentsModel = $attachmentsModel;
        $this->auditConfigModel = $auditConfigModel;
        $this->fileManager = $fileManager;
        $this->persistence = $persistence;
    }

    public function validate(string $invoiceId, string $disDetNro): array
    {
        $dataFetchStart = hrtime(true);

        $dispensationData = $this->dispensationModel->getDispensationData($disDetNro);
        Logger::info('Dispensación encontrada', ['DisDetNro' => $disDetNro]);

        if (empty($dispensationData)) {
            return $this->fail($disDetNro, self::ERROR_DISPENSATION_NOT_FOUND, null, $dataFetchStart);
        }

        $dispensation = $dispensationData[0];
        $tipoServicio = trim((string) ($dispensation['Tipo'] ?? ''));
        $auditConfig = $this->loadAuditConfig((string) ($dispensation['NitSec'] ?? ''));

        if (in_array(strtoupper($tipoServicio), self::HUMAN_REVIEW_SERVICE_TYPES, true)) {
            Logger::info('Auditoría omitida — requiere revisión humana', [
                'DisDetNro' => $disDetNro,
                'tipoServicio' => $tipoServicio,
            ]);

            $result = [
                'response' => AuditResponseSchema::RESPONSE_HUMAN_REVIEW,
                'severity' => 'ninguna',
                'message' => self::ERROR_HUMAN_REVIEW_REQUIRED,
                'tipoServicio' => $tipoServicio,
                'documento' => $dispensation['NumeroFactura'] ?? '',
                'DisDetNro' => $disDetNro,
                'data' => ['items' => []],
                'metrics' => AuditResponseSchema::getEmptyMetrics(),
                'config_used' => AuditResponseSchema::getEmptyConfig(),
                '_errorOrigin' => 'business',
            ];

            $this->persistence->saveResponse($disDetNro, $result);

            return $this->output($result, $dispensationData, [], $dataFetchStart, $auditConfig);
        }

        if (strtoupper($tipoServicio) === 'MIPRES') {
            $missingFields = $this->getMissingMipresFields($dispensation);
            if (!empty($missingFields)) {
                $fieldList = implode(', ', $missingFields);
                Logger::warning('Auditoría abortada por campos MIPRES incompletos', [
                    'DisDetNro' => $disDetNro,
                    'tipoServicio' => $tipoServicio,
                    'missingFields' => $missingFields,
                ]);

                $errorMessage = self::ERROR_MIPRES_INCOMPLETE . ': ' . $fieldList;
                
                $items = [[
                    'item' => 'Campos MIPRES Completos',
                    'detalle' => 'Faltan los siguientes campos: ' . $fieldList,
                    'severidad' => 'alta',
                    'documento' => 'Dispensación'
                ]];
                return $this->fail($disDetNro, $errorMessage, $dispensation, $dataFetchStart, null, [], $items);
            }
        }

        $resolvedInvoiceId = (string) ($dispensation['NumeroFactura'] ?? $invoiceId);
        if ($resolvedInvoiceId === '') {
            return $this->fail($disDetNro, self::ERROR_ATTACHMENT_NOT_FOUND, $dispensation, $dataFetchStart);
        }

        $attachments = $this->attachmentsModel->getRequiredAttachmentsByInvoiceId(
            $resolvedInvoiceId,
            (string) ($dispensation['NitSec'] ?? '')
        );

        if (empty($attachments)) {
            return $this->fail($disDetNro, self::ERROR_ATTACHMENT_NOT_FOUND, $dispensation, $dataFetchStart);
        }

        $availableDocuments = $this->extractAvailableDocuments($attachments);

        $missingFiles = $this->fileManager->getMissingRequiredAttachments($attachments, $dispensationData);
        if (!empty($missingFiles)) {
            $missingList = implode(', ', $missingFiles);
            $errorMsg = self::ERROR_MISSING_ATTACHMENTS . ': ' . $missingList;
            Logger::warning('Auditoría abortada por documentos faltantes', [
                'DisDetNro' => $disDetNro,
                'missingDocuments' => $missingFiles,
                'totalDocuments' => count($attachments),
            ]);

            $items = [[
                'item' => 'Documentos Obligatorios Presentes',
                'detalle' => 'Faltan los siguientes documentos: ' . $missingList,
                'severidad' => 'alta',
                'documento' => 'Múltiples'
            ]];

            return $this->fail($disDetNro, $errorMsg, $dispensation, $dataFetchStart, null, $availableDocuments, $items);
        }

        $dataFetchMs = (hrtime(true) - $dataFetchStart) / 1e6;

        $filePrepStart = hrtime(true);
        $files = [];

        try {
            $files = $this->fileManager->prepareAttachments($attachments, $dispensationData);
            foreach ($files as $file) {
                $pages = (int) ($file['pages'] ?? 1);
                if ($pages > 2) {
                    Logger::warning('Archivo con más de 2 páginas', [
                        'DisDetNro' => $disDetNro,
                        'file' => $file['label'] ?? 'N/A',
                        'pages' => $pages,
                    ]);

                    foreach ($files as $preparedFile) {
                        $this->fileManager->cleanup($preparedFile);
                    }

                    $failedDocument = (string) ($file['label'] ?? '');
                    $items = [[
                        'item' => 'Cantidad de páginas',
                        'detalle' => self::ERROR_ATTACHMENT_MAX_PAGES_EXCEEDED,
                        'severidad' => 'alta',
                        'documento' => $failedDocument !== '' ? $failedDocument : AuditResponseSchema::DOCUMENTO_MULTIPLE,
                    ]];

                    return $this->fail(
                        $disDetNro,
                        self::ERROR_ATTACHMENT_MAX_PAGES_EXCEEDED,
                        $dispensation,
                        $dataFetchStart,
                        $filePrepStart,
                        $availableDocuments,
                        $items
                    );
                }
            }
        } catch (\Exception $e) {
            $errorDetail = $e->getMessage();
            Logger::error('Error preparando adjunto', ['error' => $errorDetail]);
            foreach ($files as $preparedFile) {
                $this->fileManager->cleanup($preparedFile);
            }

            $items = [[
                'item' => 'Preparación de Documentos',
                'detalle' => $errorDetail,
                'severidad' => 'alta',
                'documento' => AuditResponseSchema::DOCUMENTO_MULTIPLE,
            ]];

            return $this->fail(
                $disDetNro,
                self::ERROR_PREPARING_ATTACHMENT . ': ' . $errorDetail,
                $dispensation,
                $dataFetchStart,
                $filePrepStart,
                $availableDocuments,
                $items
            );
        }

        $filePrepMs = (hrtime(true) - $filePrepStart) / 1e6;

        Logger::info('Documentos preparados para auditoría', [
            'DisDetNro' => $disDetNro,
            'totalDocuments' => count($files),
            'documentTypes' => array_map(fn($f) => $f['label'] ?? 'N/A', $files),
        ]);

        return [
            'result' => null,
            'dispensationData' => $dispensationData,
            'dispensation' => $dispensation,
            'files' => $files,
            'dataFetchMs' => $dataFetchMs,
            'filePrepMs' => $filePrepMs,
            'auditConfig' => $auditConfig,
        ];
    }

    private function loadAuditConfig(string $nitSec): array
    {
        if (trim($nitSec) === '') {
            return [];
        }

        try {
            $config = $this->auditConfigModel->getConfig($nitSec);
            if ($config === null || !($config['activo'] ?? false)) {
                Logger::warning('Cliente sin configuración activa de auditoría; usando fallback', [
                    'nitSec' => $nitSec,
                ]);
                return [];
            }

            Logger::info('Configuración dinámica de auditoría cargada', [
                'nitSec' => $nitSec,
                'documents' => count($config['documents'] ?? []),
            ]);

            return $config;
        } catch (\Throwable $e) {
            Logger::warning('No se pudo cargar configuración dinámica de auditoría; usando fallback', [
                'nitSec' => $nitSec,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function getMissingMipresFields(array $dispensation): array
    {
        $missing = [];
        foreach (self::REQUIRED_MIPRES_FIELDS as $field) {
            $value = $dispensation[$field] ?? null;
            $normalized = is_string($value) ? trim($value) : $value;

            if ($normalized === null || $normalized === '' || (is_numeric($normalized) && (float) $normalized === 0.0)) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    private function fail(
        string $disDetNro,
        string $message,
        ?array $dispensation,
        float $dataFetchStart,
        ?float $filePrepStart = null,
        array $availableDocuments = [],
        array $items = []
    ): array {
        $dataFetchMs = (hrtime(true) - $dataFetchStart) / 1e6;
        $filePrepMs = $filePrepStart !== null ? (hrtime(true) - $filePrepStart) / 1e6 : 0.0;

        $severity = 'ninguna';
        foreach ($items as $item) {
            if (($item['severidad'] ?? '') === 'alta') {
                $severity = 'alta';
                break;
            }
        }

        $result = [
            'response' => 'error',
            'severity' => $severity,
            'message' => $message,
            'documento' => $dispensation['NumeroFactura'] ?? '',
            'DisDetNro' => $disDetNro,
            'data' => ['items' => $items],
            'metrics' => AuditResponseSchema::getEmptyMetrics(),
            'config_used' => AuditResponseSchema::getEmptyConfig(),
            '_errorOrigin' => 'business',
        ];

        return [
            'result' => $result,
            'dispensationData' => $dispensation !== null ? [$dispensation] : [],
            'dispensation' => $dispensation,
            'files' => [],
            'availableDocuments' => $availableDocuments,
            'dataFetchMs' => $dataFetchMs,
            'filePrepMs' => $filePrepMs,
            'auditConfig' => [],
        ];
    }

    private function output(
        array $result,
        array $dispensationData,
        array $files,
        float $dataFetchStart,
        array $auditConfig = []
    ): array {
        $dataFetchMs = (hrtime(true) - $dataFetchStart) / 1e6;

        return [
            'result' => $result,
            'dispensationData' => $dispensationData,
            'dispensation' => $dispensationData[0] ?? null,
            'files' => $files,
            'availableDocuments' => [],
            'dataFetchMs' => $dataFetchMs,
            'filePrepMs' => 0.0,
            'auditConfig' => $auditConfig,
        ];
    }

    private function extractAvailableDocuments(array $attachments): array
    {
        $documents = [];

        foreach ($attachments as $attachment) {
            $storageType = strtoupper(trim((string) ($attachment['TipoAlmacenamiento'] ?? 'SIN_DOCUMENTOS')));
            if ($storageType === 'SIN_DOCUMENTOS') {
                continue;
            }

            $name = trim((string) ($attachment['nombre_documento'] ?? ''));
            if ($name !== '') {
                $documents[] = $name;
            }
        }

        return array_values(array_unique($documents));
    }
}
