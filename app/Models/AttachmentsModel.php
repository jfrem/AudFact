<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use PDO;

class AttachmentsModel extends Model
{

    /**
     * Summary of getAttachmentsByInvoiceId
     * @param string $invoiceId (DisDetNro) 
     * @param string $nitSec
     * @return array
     * Este método obtiene los documentos adjuntos relacionados con una factura específica,
     * validando que se cumplan los requisitos de documentos necesarios para la dispensación.
     * Devuelve un array con la información de cada documento, incluyendo el
     * tipo de almacenamiento (BLOB, URL o SIN_DOCUMENTOS) y la URL si aplica.
     * Además, registra en el log la cantidad de documentos encontrados y
     * cuántos de ellos no cumplen con los requisitos.
     */
    public function getAttachmentsByInvoiceId(string $invoiceId, string $nitSec): array
    {
        $sql = "SELECT
                a.DisId AS [dispiensa],
                d.DisDetNro AS [factura],
                n.NitSec AS [cliente],
                NitMedDocId AS [id_documento],
                NitMedDocNom AS [nombre_documento],
                NitMedDocCodAlt AS [nombre_alternativo],
                AdjDisDocUrl AS [almacenamiento_remoto],
                CASE
                    WHEN AdjDisDocUrl IS NOT NULL AND AdjDisDocUrl <> '' THEN 'URL'
                    WHEN AdjDisDoc IS NOT NULL AND DATALENGTH(AdjDisDoc) > 0 THEN 'BLOB'
                ELSE 'SIN_DOCUMENTOS'
                END AS TipoAlmacenamiento
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                LEFT JOIN DispensacionDetalleServicio d WITH (NOLOCK) ON d.DisId=a.DisId and d.DisDetId=a.DisDetId
                LEFT JOIN NitDocumentos n WITH (NOLOCK) ON n.NitMedDocId=a.AdjDisId
                WHERE n.NitSec = :nitSec AND d.DisDetNro = :invoiceId";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':invoiceId', $invoiceId, PDO::PARAM_STR);
        $stmt->bindParam(':nitSec', $nitSec, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Logger::info("Documentos adjuntos obtenidos", [
            'invoiceId' => $invoiceId,
            'resultCount' => count($result)
        ]);

        return $result;
    }

    /**
     * Obtiene un documento adjunto por ID para dispensas
     * @param string $attachmentId ID del tipo de documento
     * @param string $invoiceId Identificador de la dispensa
     * @return array|false
     */
    public function getAttachmentByIdForDispensation(string $attachmentId, string $invoiceId): array|false
    {
        $sql = "SELECT
                    a.AdjDisId,
                    a.AdjDisNom,
                    a.AdjDisDoc,
                    a.AdjDisDocUrl,
                    CASE
                        WHEN a.AdjDisDoc IS NOT NULL THEN 'BLOB'
                        WHEN a.AdjDisDocUrl IS NOT NULL THEN 'URL'
                        ELSE 'SIN_DOCUMENTOS'
                    END AS TipoAlmacenamiento,
                    DATALENGTH(a.AdjDisDoc) AS BlobSize
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                LEFT JOIN DispensacionDetalleServicio d WITH (NOLOCK) ON d.DisId=a.DisId and d.DisDetId=a.DisDetId
                WHERE a.AdjDisId = :attachmentId AND d.DisDetNro = :invoiceId";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':attachmentId', $attachmentId, PDO::PARAM_STR);
        $stmt->bindParam(':invoiceId', $invoiceId, PDO::PARAM_STR);
        $stmt->execute();

        Logger::info("Fetching attachment for dispensation", [
            'attachmentId' => $attachmentId,
            'invoiceId' => $invoiceId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene el stream del BLOB de un documento adjunto para dispensas
     * @param string $attachmentId ID del tipo de documento
     * @param string $invoiceId Identificador de la dispensa (DisDetNro)
     * @return array Array con 'stream' y función 'close'
     */
    public function getAttachmentBlobStreamByIdForDispensation(string $attachmentId, string $invoiceId): array
    {
        $sql = "SELECT a.AdjDisDoc FROM AdjuntosDispensacion a WITH (NOLOCK)
                LEFT JOIN DispensacionDetalleServicio d WITH (NOLOCK) ON d.DisId=a.DisId and d.DisDetId=a.DisDetId
                WHERE a.AdjDisId = :attachmentId AND d.DisDetNro = :invoiceId";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':attachmentId', $attachmentId, PDO::PARAM_STR);
        $stmt->bindParam(':invoiceId', $invoiceId, PDO::PARAM_STR);
        $stmt->execute();

        $stream = null;
        // Vincular la columna BLOB como stream
        $stmt->bindColumn(1, $stream, PDO::PARAM_LOB);

        if (!$stmt->fetch(PDO::FETCH_BOUND)) {
            $stmt->closeCursor();
            return [
                'stream' => null,
                'close' => function () {}
            ];
        }

        Logger::info("Fetching attachment BLOB stream for dispensation", [
            'attachmentId' => $attachmentId,
            'invoiceId' => $invoiceId
        ]);

        return [
            'stream' => $stream,
            'close' => function () use ($stmt) {
                $stmt->closeCursor();
            }
        ];
    }

    /**
     * Cuenta total de documentos auditados por la IA en el histórico cruzado con vista de facturas.
     * @param array $filters Filtros opcionales (facNro, facNitSec)
     * @return int
     */
    public function countAuditHistory(array $filters = []): int
    {
        $params = [];
        $where = "WHERE (a.AdjDisUsuAudi = 'Z-IA' OR a.AdjDisUsuRec = 'Z-IA')";

        if (!empty($filters['facNro'])) {
            $where .= " AND v.Dispensa = :facNro";
            $params['facNro'] = $filters['facNro'];
        }

        if (!empty($filters['facNitSec'])) {
            $where .= " AND v.FacNitSec = :facNitSec";
            $params['facNitSec'] = $filters['facNitSec'];
        }

        $sql = "SELECT COUNT(*) as total FROM (
                    SELECT 1 as n
                    FROM AdjuntosDispensacion a WITH (NOLOCK)
                    INNER JOIN vw_discolnet_dispensas v WITH (NOLOCK) ON a.DisId = v.FacSec
                    $where
                    GROUP BY v.Dispensa, a.DisId, a.DisDetId, a.AdjDisId, a.AdjDisNom, a.AdjDisEstSop, a.AdjDisObsRec, a.AdjDisUsuAudi, a.AdJDisFecAudi, a.AdjDisUsuRec
                ) AS SubQuery";

        $stmt = $this->readDb->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['total'] : 0;
    }

    /**
     * Obtiene el historial de documentos auditados por IA integrando NroFactura con soporte de paginación
     * @param int $page Página actual
     * @param int $pageSize Tamaño de la página
     * @param array $filters Filtros opcionales (facNro, facNitSec)
     * @return array
     */
    public function getAuditHistory(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $params = [];
        $where = "WHERE (a.AdjDisUsuAudi = 'Z-IA' OR a.AdjDisUsuRec = 'Z-IA')";

        if (!empty($filters['facNro'])) {
            $where .= " AND v.Dispensa = :facNro";
            $params['facNro'] = $filters['facNro'];
        }

        if (!empty($filters['facNitSec'])) {
            $where .= " AND v.FacNitSec = :facNitSec";
            $params['facNitSec'] = $filters['facNitSec'];
        }

        $offset = ($page - 1) * $pageSize;
        $sql = "SELECT 
                    v.Dispensa AS NroFactura,
                    a.DisId AS DispensacionID,
                    a.DisDetId AS DetalleID,
                    a.AdjDisId AS AdjuntoID,
                    a.AdjDisNom AS NombreDocumento,
                    a.AdjDisEstSop AS EstadoSoporte,
                    a.AdjDisObsRec AS ObservacionRechazo,
                    a.AdjDisUsuAudi AS UsuarioAuditor,
                    a.AdJDisFecAudi AS FechaAuditoria,
                    a.AdjDisUsuRec AS UsuarioRechazo
                FROM 
                    AdjuntosDispensacion a WITH (NOLOCK)
                INNER JOIN vw_discolnet_dispensas v WITH (NOLOCK) ON a.DisId = v.FacSec
                $where
                GROUP BY 
                    v.Dispensa,
                    a.DisId,
                    a.DisDetId,
                    a.AdjDisId,
                    a.AdjDisNom,
                    a.AdjDisEstSop,
                    a.AdjDisObsRec,
                    a.AdjDisUsuAudi,
                    a.AdJDisFecAudi,
                    a.AdjDisUsuRec
                ORDER BY 
                    a.AdJDisFecAudi DESC
                OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

        $stmt = $this->readDb->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->bindValue(':pageSize', (int) $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Logger::info("Historial de auditorias de documentos obtenido", [
            'filters' => $filters,
            'page' => $page,
            'pageSize' => $pageSize,
            'resultCount' => count($result)
        ]);

        return $result;
    }
}
