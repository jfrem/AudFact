<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AuditConfigModel;
use App\Models\AttachmentsModel;
use App\Models\ClientsModel;
use App\Models\DispensationModel;
use Core\Logger;
use DomainException;

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
     * Obtiene la fuente de verdad (FDV) desde SQL Server por filtros arbitrarios.
     *
     * @param array<string,string> $filters Filtros (ej. facsec, Dispensa)
     * @return array<string,mixed> Datos formateados de la dispensación con items.
     * @throws DomainException Si la factura no existe.
     */
    public function getDispensation(array $filters): array
    {
        $start = microtime(true);
        $rows  = $this->dispensationModel->getDispensationData($filters);

        if ($rows === []) {
            $fstr = json_encode($filters);
            throw new DomainException("FDV vacía para filtros: {$fstr}", 404);
        }

        $data = DispensationModel::formatDispensation($rows);

        Logger::info('AuditDataService::getDispensation', [
            'filters'     => $filters,
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
     * @throws DomainException Si la configuración no existe o está inactiva.
     */
    public function getAuditConfig(string $nitSec): array
    {
        $start  = microtime(true);
        $config = $this->auditConfigModel->getConfig($nitSec);

        if ($config === null) {
            throw new DomainException("audit-config no existe para NitSec '{$nitSec}'");
        }

        if (!($config['activo'] ?? false)) {
            throw new DomainException("audit-config inactiva para NitSec '{$nitSec}'");
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
     * @return array<int,array<string,mixed>> Filas físicas de adjuntos, una por attachment_id.
     */
    public function getAttachments(string $disDetNro, string $nitSec): array
    {
        $start = microtime(true);
        $atts  = $this->attachmentsModel->getPhysicalAttachmentsByDisDetNro($disDetNro, $nitSec);

        Logger::info('AuditDataService::getAttachments', [
            'dis_det_nro' => $disDetNro,
            'nit_sec'     => $nitSec,
            'count'       => count($atts),
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);

        return $atts;
    }
}
