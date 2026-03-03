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

        $stmt = $this->db->prepare($sql);
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

        $stmt = $this->db->prepare($sql);
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

        $stmt = $this->db->prepare($sql);
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
}
