<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AuditConfigModel;
use App\Models\AttachmentsModel;
use App\Models\ClientsModel;
use App\Models\DispensationModel;
use Core\Logger;
use RuntimeException;

/**
 * Servicio de datos para el pipeline de auditoría.
 *
 * Accede directamente a los modelos PHP (SQL Server vía PDO) para resolver
 * FDV, audit-config, cliente y catálogo documental dentro del pipeline.
 */
class AuditDataService
{
    private DispensationModel $dispensationModel;
    private ClientsModel $clientsModel;
    private AuditConfigModel $auditConfigModel;
    private AttachmentsModel $attachmentsModel;

    public function __construct(
        ?DispensationModel $dispensationModel = null,
        ?ClientsModel      $clientsModel      = null,
        ?AuditConfigModel  $auditConfigModel  = null,
        ?AttachmentsModel  $attachmentsModel  = null,
    ) {
        $this->dispensationModel = $dispensationModel ?? new DispensationModel();
        $this->clientsModel      = $clientsModel      ?? new ClientsModel();
        $this->auditConfigModel  = $auditConfigModel  ?? new AuditConfigModel();
        $this->attachmentsModel  = $attachmentsModel  ?? new AttachmentsModel();
    }

    /**
     * Obtiene la fuente de verdad (FDV) desde SQL Server por FacSec canónico.
     *
     * @return array<string,mixed> Datos formateados de la dispensación con items.
     * @throws RuntimeException Si la factura no existe.
     */
    public function getDispensationByFacSec(string $facSec): array
    {
        $start = microtime(true);
        $rows  = $this->dispensationModel->getDispensationDataByFacSec($facSec);

        if ($rows === []) {
            throw new RuntimeException("FDV vacía: no existe FacSec '{$facSec}'", 404);
        }

        $data = DispensationModel::formatDispensation($rows);

        Logger::info('AuditDataService::getDispensationByFacSec', [
            'fac_sec'     => $facSec,
            'dis_det_nro' => (string) ($data['header']['NumeroFactura'] ?? ''),
            'items'       => count($data['items']),
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);

        return $data;
    }

    /**
     * Obtiene la configuración de auditoría para un cliente (NitSec).
     *
     * @return array<string,mixed> Configuración activa del cliente.
     * @throws RuntimeException Si la configuración no existe o está inactiva.
     */
    public function getAuditConfig(string $nitSec): array
    {
        $start  = microtime(true);
        $config = $this->auditConfigModel->getConfig($nitSec);

        if ($config === null) {
            throw new RuntimeException("audit-config no existe para NitSec '{$nitSec}'");
        }

        if (!($config['activo'] ?? false)) {
            throw new RuntimeException("audit-config inactiva para NitSec '{$nitSec}'");
        }

        Logger::info('AuditDataService::getAuditConfig', [
            'nit_sec'     => $nitSec,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);

        return $config;
    }

    /**
     * Obtiene el catálogo de documentos requeridos para un cliente.
     *
     * @return array<int,array<string,mixed>> Lista de tipos documentales del cliente.
     */
    public function getClientDocuments(string $nitSec): array
    {
        $start = microtime(true);
        $docs  = $this->clientsModel->getDocumentsByClient((int) $nitSec);

        Logger::info('AuditDataService::getClientDocuments', [
            'nit_sec'     => $nitSec,
            'count'       => count($docs),
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);

        return $docs;
    }

    /**
     * Obtiene los adjuntos asociados a una dispensación.
     *
     * @return array<int,array<string,mixed>> Lista de adjuntos con metadata y BLOBs.
     */
    public function getAttachments(string $disDetNro, string $nitSec): array
    {
        $start = microtime(true);
        $atts  = $this->attachmentsModel->getRequiredAttachmentsByDisDetNro($disDetNro, $nitSec);

        Logger::info('AuditDataService::getAttachments', [
            'dis_det_nro' => $disDetNro,
            'nit_sec'     => $nitSec,
            'count'       => count($atts),
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);

        return $atts;
    }
}
