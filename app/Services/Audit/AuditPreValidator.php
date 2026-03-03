<?php

namespace App\Services\Audit;

use App\Models\AttachmentsModel;
use App\Models\DispensationModel;
use Core\Logger;

/**
 * Validaciones pre-IA para el flujo de auditoría.
 *
 * Extraído de AuditOrchestrator::auditInvoice() para reducir complejidad
 * y facilitar testing unitario.
 */
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
    private AuditFileManager $fileManager;
    private AuditPersistenceService $persistence;

    public function __construct(
        DispensationModel $dispensationModel,
        AttachmentsModel $attachmentsModel,
        AuditFileManager $fileManager,
        AuditPersistenceService $persistence
    ) {
        $this->dispensationModel = $dispensationModel;
        $this->attachmentsModel = $attachmentsModel;
        $this->fileManager = $fileManager;
        $this->persistence = $persistence;
    }

    /**
     * Ejecuta todas las validaciones pre-IA.
     *
     * @param string $invoiceId ID de la factura
     * @param string $disDetNro Identificador de dispensación
     * @return array{result: null|array, dispensation: array|null, files: array, dataFetchMs: float, filePrepMs: float}
     */
    public function validate(string $invoiceId, string $disDetNro): array
    {
        $dataFetchStart = hrtime(true);

        // 1. Dispensación existe
        $dispensationData = $this->dispensationModel->getDispensationData($disDetNro);
        Logger::info('Dispensación encontrada', ['DisDetNro' => $disDetNro]);

        if (empty($dispensationData)) {
            return $this->fail($disDetNro, self::ERROR_DISPENSATION_NOT_FOUND, null, $dataFetchStart);
        }

        $dispensation = $dispensationData[0];
        $tipoServicio = trim((string) ($dispensation['Tipo'] ?? ''));

        // 2. Tipo de servicio que requiere revisión humana
        if (in_array(strtoupper($tipoServicio), self::HUMAN_REVIEW_SERVICE_TYPES, true)) {
            Logger::info('Auditoría omitida — requiere revisión humana', [
                'DisDetNro' => $disDetNro,
                'tipoServicio' => $tipoServicio,
            ]);

            $result = [
                'response' => 'human_review',
                'message' => self::ERROR_HUMAN_REVIEW_REQUIRED,
                'tipoServicio' => $tipoServicio,
                'documento' => $dispensation['NumeroFactura'] ?? '',
                'DisDetNro' => $disDetNro,
                'data' => ['items' => []],
            ];

            $this->persistence->saveResponse($disDetNro, $result);

            return $this->output($result, $dispensationData, [], $dataFetchStart);
        }

        // 3. Campos MIPRES completos
        if (strtoupper($tipoServicio) === 'MIPRES') {
            $missingFields = $this->getMissingMipresFields($dispensation);
            if (!empty($missingFields)) {
                Logger::warning('Auditoría abortada por campos MIPRES incompletos', [
                    'DisDetNro' => $disDetNro,
                    'tipoServicio' => $tipoServicio,
                    'missingFields' => $missingFields,
                ]);

                $errorMessage = self::ERROR_MIPRES_INCOMPLETE . ': ' . implode(', ', $missingFields);
                return $this->fail($disDetNro, $errorMessage, $dispensation, $dataFetchStart);
            }
        }

        // 4. Invoice ID válido
        $resolvedInvoiceId = (string) ($dispensation['NumeroFactura'] ?? $invoiceId);
        if ($resolvedInvoiceId === '') {
            return $this->fail($disDetNro, self::ERROR_ATTACHMENT_NOT_FOUND, $dispensation, $dataFetchStart);
        }

        // 5. Adjuntos existen
        $attachments = $this->attachmentsModel->getAttachmentsByInvoiceId(
            $resolvedInvoiceId,
            (string) ($dispensation['NitSec'] ?? '')
        );

        if (empty($attachments)) {
            return $this->fail($disDetNro, self::ERROR_ATTACHMENT_NOT_FOUND, $dispensation, $dataFetchStart);
        }

        // 6. Documentos requeridos presentes
        $missingFiles = $this->fileManager->getMissingRequiredAttachments($attachments, $dispensationData);
        if (!empty($missingFiles)) {
            $errorMsg = self::ERROR_MISSING_ATTACHMENTS . ': ' . implode(', ', $missingFiles);
            Logger::warning('Auditoría abortada por documentos faltantes', [
                'DisDetNro' => $disDetNro,
                'missingDocuments' => $missingFiles,
                'totalDocuments' => count($attachments),
            ]);
            return $this->fail($disDetNro, $errorMsg, $dispensation, $dataFetchStart);
        }

        $dataFetchMs = (hrtime(true) - $dataFetchStart) / 1e6;

        // 7. Preparar archivos y validar páginas
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

                    return $this->fail($disDetNro, self::ERROR_ATTACHMENT_MAX_PAGES_EXCEEDED, $dispensation, $dataFetchStart, $filePrepStart);
                }
            }
        } catch (\Exception $e) {
            Logger::error('Error preparando adjunto', ['error' => $e->getMessage()]);
            foreach ($files as $preparedFile) {
                $this->fileManager->cleanup($preparedFile);
            }
            return $this->fail(
                $disDetNro,
                self::ERROR_PREPARING_ATTACHMENT . ': ' . $e->getMessage(),
                $dispensation,
                $dataFetchStart,
                $filePrepStart
            );
        }

        $filePrepMs = (hrtime(true) - $filePrepStart) / 1e6;

        Logger::info('Documentos preparados para auditoría', [
            'DisDetNro' => $disDetNro,
            'totalDocuments' => count($files),
            'documentTypes' => array_map(fn($f) => $f['label'] ?? 'N/A', $files),
        ]);

        // Todas las validaciones pasaron
        return [
            'result' => null,
            'dispensationData' => $dispensationData,
            'dispensation' => $dispensation,
            'files' => $files,
            'dataFetchMs' => $dataFetchMs,
            'filePrepMs' => $filePrepMs,
        ];
    }

    /**
     * Valida campos MIPRES requeridos.
     */
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

    /**
     * Genera resultado de fallo (terminate) con timings.
     */
    private function fail(
        string $disDetNro,
        string $message,
        ?array $dispensation,
        float $dataFetchStart,
        ?float $filePrepStart = null
    ): array {
        $dataFetchMs = (hrtime(true) - $dataFetchStart) / 1e6;
        $filePrepMs = $filePrepStart !== null ? (hrtime(true) - $filePrepStart) / 1e6 : 0.0;

        $result = [
            'response' => 'error',
            'message' => $message,
            'documento' => $dispensation['NumeroFactura'] ?? '',
            'DisDetNro' => $disDetNro,
            'data' => ['items' => []],
            '_errorOrigin' => 'business',
        ];

        return [
            'result' => $result,
            'dispensationData' => $dispensation !== null ? [$dispensation] : [],
            'dispensation' => $dispensation,
            'files' => [],
            'dataFetchMs' => $dataFetchMs,
            'filePrepMs' => $filePrepMs,
        ];
    }

    /**
     * Genera estructura de salida estándar.
     */
    private function output(
        array $result,
        array $dispensationData,
        array $files,
        float $dataFetchStart
    ): array {
        $dataFetchMs = (hrtime(true) - $dataFetchStart) / 1e6;

        return [
            'result' => $result,
            'dispensationData' => $dispensationData,
            'dispensation' => $dispensationData[0] ?? null,
            'files' => $files,
            'dataFetchMs' => $dataFetchMs,
            'filePrepMs' => 0.0,
        ];
    }
}
