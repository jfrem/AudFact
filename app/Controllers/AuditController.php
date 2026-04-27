<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentsModel;
use App\Models\AuditStatusModel;
use App\Models\InvoicesModel;
use App\Services\Audit\Events\AuditEvent;
use App\Services\Audit\Events\AuditEventPublisher;
use App\Services\Audit\Events\AuditStateStore;
use Core\Exceptions\HttpResponseException;
use Core\Cache;
use Core\Response;
use Core\Logger;
use RuntimeException;

class AuditController extends Controller
{
    public function single(): void
    {
        $data = $this->validate([
            'DisDetNro' => 'required|string|max:255',
        ]);

        $disDetNro = trim((string) $data['DisDetNro']);
        if ($disDetNro === '') {
            Response::error('DisDetNro es requerido', 422);
        }

        $stateStore = $this->buildStateStore();
        $publisher = $this->buildEventPublisher();

        $auditId = AuditEvent::uuidV4();
        $auditInitialized = false;

        try {
            $initialized = $stateStore->initAudit($auditId, $disDetNro);
            if (!$initialized) {
                Logger::error('AuditController::single no se pudo inicializar estado', [
                    'audit_id' => $auditId,
                ]);
                Response::error('No se pudo encolar la auditoría', 503);
            }
            $auditInitialized = true;

            $event = AuditEvent::create(
                eventType: AuditEvent::TYPE_AUDIT_CREATED,
                auditId: $auditId,
                jobId: null,
                documentId: null,
                payload: [
                    'dis_det_nro'  => $disDetNro,
                    'fac_nit_sec'  => null,
                    'source'       => 'single',
                ],
            );

            $publisher->publish($event);
        } catch (RuntimeException $e) {
            if ($auditInitialized) {
                $stateStore->deleteAudit($auditId);
            }
            Logger::error('AuditController::single falló encolando', [
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo encolar la auditoría', 503);
        }

        Response::success(
            [
                'audit_id'    => $auditId,
                'status'      => AuditStateStore::AUDIT_STATUS_PENDING,
                'dis_det_nro' => $disDetNro,
            ],
            'Auditoría encolada',
            202,
        );
    }

    public function results(): void
    {
        try {
            $validated = $this->validateQuery([
                'facNitSec' => 'nullable|integer|min_value:1',
                'facNro' => 'nullable|string|max:50',
                'dateFrom' => 'nullable|date',
                'dateTo' => 'nullable|date',
                'page' => 'nullable|integer|min_value:1',
                'pageSize' => 'nullable|integer|min_value:1|max_value:100',
            ]);

            if (
                isset($validated['dateFrom'], $validated['dateTo']) &&
                $validated['dateFrom'] !== '' &&
                $validated['dateTo'] !== ''
            ) {
                $dtFrom = \DateTime::createFromFormat('Y-m-d', $validated['dateFrom']);
                $dtTo = \DateTime::createFromFormat('Y-m-d', $validated['dateTo']);
                if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
                    Response::error('dateFrom no puede ser mayor que dateTo', 422);
                }
            }

            $filters = [];
            foreach (['facNitSec', 'facNro', 'dateFrom', 'dateTo'] as $key) {
                if (isset($validated[$key]) && $validated[$key] !== '') {
                    $filters[$key] = $validated[$key];
                }
            }

            $page = (isset($validated['page']) && $validated['page'] !== '') ? (int)$validated['page'] : 1;
            $pageSize = (isset($validated['pageSize']) && $validated['pageSize'] !== '') ? (int)$validated['pageSize'] : 20;

            Logger::info('AuditController::results', [
                'filters'  => $filters,
                'page'     => $page,
                'pageSize' => $pageSize,
            ]);

            // Caché read-through: 60s TTL para consultas idénticas
            $facNitSecFilter = $filters['facNitSec'] ?? 'all';
            $allVersion = Cache::getQueryResultsVersion('all');
            $scopeVersion = $facNitSecFilter !== 'all'
                ? Cache::getQueryResultsVersion((string) $facNitSecFilter)
                : 0;
            $cacheKey = 'query:results:' . $facNitSecFilter . ':v' . $allVersion . ':' . $scopeVersion . ':'
                . md5(json_encode([$filters, $page, $pageSize]));
            $cacheTtl = 60;

            $payload = Cache::remember($cacheKey, function () use ($filters, $page, $pageSize) {
                $model = $this->buildAuditStatusModel();
                $total = $model->countAudits($filters);
                $results = $model->searchAudits($filters, $page, $pageSize);
                $totalPages = (int)ceil($total / $pageSize);

                return [
                    'items'      => $results,
                    'total'      => $total,
                    'page'       => $page,
                    'pageSize'   => $pageSize,
                    'totalPages' => $totalPages,
                    'filters'    => $filters,
                ];
            }, $cacheTtl);

            Response::success($payload, 'Resultados de auditorías');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            Logger::error('Excepción en AuditController::results: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            Response::error('Resultados de auditoría temporalmente no disponibles', 503);
        }
    }

    public function documentsHistory(): void
    {
        try {
            $validated = $this->validateQuery([
                'facNitSec' => 'nullable|integer|min_value:1',
                'facNro' => 'nullable|string|max:50',
                'page' => 'nullable|integer|min_value:1',
                'pageSize' => 'nullable|integer|min_value:1|max_value:100',
            ]);

            $filters = [];
            foreach (['facNitSec', 'facNro'] as $key) {
                if (isset($validated[$key]) && $validated[$key] !== '') {
                    $filters[$key] = $validated[$key];
                }
            }

            $page = (isset($validated['page']) && $validated['page'] !== '') ? (int)$validated['page'] : 1;
            $pageSize = (isset($validated['pageSize']) && $validated['pageSize'] !== '') ? (int)$validated['pageSize'] : 20;

            $model = new AttachmentsModel();
            $totalItems = $model->countAuditHistory($filters);
            $totalPages = (int) ceil($totalItems / $pageSize);

            $results = $model->getAuditHistory($page, $pageSize, $filters);

            Response::success([
                'items' => $results,
                'total' => $totalItems,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => $totalPages,
                'filters' => $filters
            ], 'Historial de auditorías de documentos');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Logger::error("Error unexpected querying document audit history", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Response::error('Error unexpected querying document audit history', 500);
        }
    }

    public function async(): void
    {
        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date' => 'required|date',
            'dateTo' => 'optional|date',
            'limit' => 'nullable|integer|min_value:1|max_value:100',
        ]);

        $dateFrom = (string) $data['date'];
        $dateTo = (isset($data['dateTo']) && $data['dateTo'] !== '') ? (string) $data['dateTo'] : null;

        if ($dateTo !== null) {
            $dtFrom = \DateTime::createFromFormat('Y-m-d', $dateFrom);
            $dtTo = \DateTime::createFromFormat('Y-m-d', $dateTo);
            if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
                Response::error('dateTo debe ser mayor o igual a date', 422);
            }
        }

        $facNitSec = (int) $data['facNitSec'];
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;

        $stateStore = $this->buildStateStore();
        $publisher = $this->buildEventPublisher();

        $jobId = AuditEvent::uuidV4();
        $batchSlotClaimed = false;
        $jobInitialized = false;
        $createdAuditIds = [];
        $total = 0;
        $responseStatus = AuditStateStore::JOB_STATUS_PENDING;

        try {
            $claimed = $stateStore->claimBatchSlot($facNitSec, $dateFrom, $dateTo, $jobId);
            if (!$claimed) {
                Response::error(
                    'Ya existe un batch activo para el cliente y rango solicitado',
                    409
                );
            }
            $batchSlotClaimed = true;

            $invoices = $this->getInvoicesModel()->getInvoices($facNitSec, $dateFrom, $dateTo, $limit);

            if (!$stateStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit)) {
                throw new RuntimeException('No se pudo inicializar el job');
            }
            $jobInitialized = true;

            $publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_BATCH_CREATED,
                auditId: null,
                jobId: $jobId,
                documentId: null,
                payload: [
                    'fac_nit_sec' => (string) $facNitSec,
                    'date_from'   => $dateFrom,
                    'date_to'     => $dateTo,
                    'limit'       => $limit,
                    'total'       => count($invoices),
                ],
            ));

            foreach ($invoices as $invoice) {
                $disDetNro = isset($invoice['Dispensa']) ? trim((string) $invoice['Dispensa']) : '';
                $facSec = isset($invoice['FacSec']) ? trim((string) $invoice['FacSec']) : '';
                if ($disDetNro === '' || $facSec === '') {
                    Logger::warning('AuditController::async factura inválida, omitida', [
                        'job_id' => $jobId,
                        'invoice' => $invoice,
                    ]);
                    continue;
                }

                $auditId = AuditEvent::uuidV4();
                if (!$stateStore->initAudit($auditId, $disDetNro, $jobId, (string) $facNitSec, $facSec)) {
                    Logger::warning('AuditController::async no se pudo inicializar auditoría', [
                        'job_id' => $jobId,
                        'audit_id' => $auditId,
                    ]);
                    continue;
                }

                $createdAuditIds[] = $auditId;

                if (!$stateStore->registerAuditInJob($jobId, $auditId, $disDetNro)) {
                    throw new RuntimeException('No se pudo registrar la auditoría en el job');
                }

                $publisher->publish(AuditEvent::create(
                    eventType: AuditEvent::TYPE_AUDIT_CREATED,
                    auditId: $auditId,
                    jobId: $jobId,
                    documentId: null,
                    payload: [
                        'dis_det_nro' => $disDetNro,
                        'fac_nit_sec' => (string) $facNitSec,
                        'fac_sec'     => $facSec,
                        'source'      => 'batch',
                    ],
                ));

                $total++;
            }

            if ($total === 0) {
                $stateStore->patchJob($jobId, [
                    'status' => AuditStateStore::JOB_STATUS_COMPLETED,
                    'total'  => 0,
                ]);
                $responseStatus = AuditStateStore::JOB_STATUS_COMPLETED;
            }
        } catch (HttpResponseException $e) {
            $this->cleanupAsyncEnqueueState(
                $stateStore,
                $facNitSec,
                $dateFrom,
                $dateTo,
                $jobId,
                $batchSlotClaimed,
                $jobInitialized,
                $createdAuditIds
            );
            throw $e;
        } catch (RuntimeException $e) {
            $this->cleanupAsyncEnqueueState(
                $stateStore,
                $facNitSec,
                $dateFrom,
                $dateTo,
                $jobId,
                $batchSlotClaimed,
                $jobInitialized,
                $createdAuditIds
            );
            Logger::error('AuditController::async falló encolando batch', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo encolar el batch', 503);
        }

        Response::success(
            [
                'job_id' => $jobId,
                'status' => $responseStatus,
                'total'  => $total,
            ],
            'Batch de auditoría encolado',
            202,
        );
    }

    public function jobStatus(string $jobId): void
    {
        if (!AuditEvent::isUuidV4($jobId)) {
            Response::error('jobId inválido', 422);
        }

        try {
            $state = $this->buildStateStore()->getJob($jobId);
        } catch (RuntimeException $e) {
            Logger::error('AuditController::jobStatus falló', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo consultar el estado del job', 503);
        }

        if ($state === null) {
            Response::error('No se encontró el job solicitado', 404);
        }

        Response::success(self::formatJobStatus($state), 'Estado del job');
    }

    private static function formatJobStatus(array $state): array
    {
        $total = (int) ($state['total'] ?? 0);
        $done = (int) ($state['done'] ?? 0);
        $failed = (int) ($state['failed'] ?? 0);
        $pending = max(0, $total - $done - $failed);

        $audits = [];
        $auditsMap = is_array($state['audits'] ?? null) ? $state['audits'] : [];
        foreach ($auditsMap as $auditId => $auditData) {
            if (!is_string($auditId) || !is_array($auditData)) {
                continue;
            }
            $audits[] = [
                'audit_id'    => $auditId,
                'dis_det_nro' => (string) ($auditData['dis_det_nro'] ?? ''),
                'status'      => (string) ($auditData['status'] ?? AuditStateStore::AUDIT_STATUS_PENDING),
            ];
        }

        return [
            'job_id'     => (string) ($state['job_id'] ?? ''),
            'status'     => (string) ($state['status'] ?? AuditStateStore::JOB_STATUS_PENDING),
            'total'      => $total,
            'done'       => $done,
            'failed'     => $failed,
            'pending'    => $pending,
            'created_at' => (string) ($state['created_at'] ?? ''),
            'updated_at' => (string) ($state['updated_at'] ?? ''),
            'audits'     => $audits,
        ];
    }

    private function cleanupAsyncEnqueueState(
        AuditStateStore $stateStore,
        int $facNitSec,
        string $dateFrom,
        ?string $dateTo,
        string $jobId,
        bool $batchSlotClaimed,
        bool $jobInitialized,
        array $createdAuditIds
    ): void {
        foreach (array_reverse($createdAuditIds) as $auditId) {
            $stateStore->deleteAudit($auditId);
        }

        if ($jobInitialized) {
            $stateStore->deleteJob($jobId);
        }

        if ($batchSlotClaimed) {
            $stateStore->releaseBatchSlot($facNitSec, $dateFrom, $dateTo);
        }
    }

    public function configByClient(string $clientId): void
    {
        $model = $this->buildAuditStatusModel();
        $config = $model->getConfigByClient($clientId);

        if (!$config) {
            Response::error('Cliente no encontrado o sin configuración', 404);
        }

        // Obtener catálogo de campos por documento
        $attModel = new AttachmentsModel();
        $documents = $attModel->getDocumentTypes();
        
        $configDocs = [];
        foreach ($documents as $doc) {
            $docName = $doc['DocumentoNombre'];
            $docId = (int)$doc['DocumentoId'];
            
            // Mezclar campos de AudDispEst (datos exactos/semánticos) y AdjuntosDispensacion (visuales)
            $fields = [];
            
            // Campos de la tabla AudDispEst (Datos principales)
            // Aquí usamos una lista predefinida o la obtenemos de la lógica del modelo
            $baseFields = [
                'NITCliente', 'TipoDocumentoPaciente', 'DocumentoPaciente', 'NombrePaciente',
                'RegimenPaciente', 'CodigoDiagnostico', 'FechaFormula', 'FechaAutorizacion',
                'NumeroAutorizacion', 'CodigoArticulo', 'CodigoProducto', 'CUM', 'Lote',
                'FechaVencimiento', 'Mipres', 'VlrCobrado', 'Cliente', 'IPS', 'Medico',
                'NombreArticulo', 'Laboratorio', 'CantidadEntregada', 'CantidadPrescrita'
            ];

            foreach ($baseFields as $fieldName) {
                // Buscar override en la config del cliente
                $override = $config['documents'][$docName]['fields'][$fieldName] ?? null;

                $fields[] = [
                    'campoNombre' => $fieldName,
                    'tipoCampo' => $override['tipoCampo'] ?? 'E', // Default: Exacto
                    'enabled' => $override['enabled'] ?? true,
                    'descripcionOverride' => $override['descripcionOverride'] ?? '',
                    'severityOverride' => $override['severityOverride'] ?? 'ALTA',
                    'rol' => $override['rol'] ?? 'AUTORITATIVO',
                    'omitirSi' => $override['omitirSi'] ?? null,
                ];
            }

            // Campos visuales específicos de este documento (AdjuntosDispensacion)
            $visualFields = $attModel->getFieldsByDocument($docId);
            foreach ($visualFields as $vf) {
                $fieldName = $vf['CampoNombre'];
                $override = $config['documents'][$docName]['fields'][$fieldName] ?? null;

                $fields[] = [
                    'campoNombre' => $fieldName,
                    'tipoCampo' => 'V', // Visual
                    'enabled' => $override['enabled'] ?? true,
                    'descripcionOverride' => $override['descripcionOverride'] ?? $vf['CampoDescripcion'],
                    'severityOverride' => $override['severityOverride'] ?? 'ALTA',
                    'rol' => $override['rol'] ?? 'AUTORITATIVO',
                    'omitirSi' => $override['omitirSi'] ?? null,
                ];
            }

            $configDocs[$docName] = [
                'docId' => $docId,
                'fields' => $fields
            ];
        }

        Response::success([
            'nitSec' => $config['nitSec'],
            'activo' => $config['activo'],
            'systemPrompt' => $config['systemPrompt'],
            'documents' => $configDocs
        ], 'Configuración de auditoría recuperada');
    }

    public function saveAuditConfig(string $clientId): void
    {
        $data = $this->getJsonBody();
        
        $model = $this->buildAuditStatusModel();
        $success = $model->saveConfigByClient($clientId, $data);

        if ($success) {
            Response::success(null, 'Configuración guardada exitosamente');
        } else {
            Response::error('Error al guardar la configuración', 500);
        }
    }

    protected function buildAuditStatusModel(): AuditStatusModel
    {
        return new AuditStatusModel();
    }

    protected function getInvoicesModel(): InvoicesModel
    {
        return new InvoicesModel();
    }

    protected function buildStateStore(): AuditStateStore
    {
        return new AuditStateStore();
    }

    protected function buildEventPublisher(): AuditEventPublisher
    {
        return new AuditEventPublisher();
    }
}
