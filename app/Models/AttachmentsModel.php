<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use PDO;

class AttachmentsModel extends Model
{

    /**
     * Obtiene documentos adjuntos de una dispensación por DisDetNro.
     *
     * @param string $disDetNro Identificador operativo de dispensación (ej: X18251205308)
     * @param string $nitSec 1165
     * @return array
     * Este método obtiene los documentos adjuntos relacionados con una dispensación específica,
     * validando que se cumplan los requisitos documentales configurados para el cliente.
     * Devuelve un array con la información de cada documento, incluyendo el
     * tipo de almacenamiento (BLOB, URL o SIN_DOCUMENTOS) y la URL si aplica.
     * Además, registra en el log la cantidad de documentos encontrados y
     * cuántos de ellos no cumplen con los requisitos.
     */
    public function getAttachmentsByDisDetNro(string $disDetNro, string $nitSec): array
    {
        $sql = "SELECT
                a.DisId AS [dispensacion_id],
                d.DisDetNro AS [dis_det_nro],
                n.NitSec AS [cliente],
                NitMedDocId AS [id_documento],
                NitMedDocNom AS [nombre_documento],
                NitMedDocCodAlt AS [nombre_alternativo],
                AdjDisDocUrl AS [almacenamiento_remoto],
                CASE
                    WHEN AdjDisDoc IS NOT NULL AND DATALENGTH(AdjDisDoc) > 0 THEN 'BLOB'
                    WHEN AdjDisDocUrl IS NOT NULL AND AdjDisDocUrl <> '' THEN 'URL'
                    ELSE 'SIN_DOCUMENTOS'
                END AS TipoAlmacenamiento
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                LEFT JOIN DispensacionDetalleServicio d WITH (NOLOCK) ON d.DisId=a.DisId and d.DisDetId=a.DisDetId
                LEFT JOIN NitDocumentos n WITH (NOLOCK) ON n.NitMedDocId=a.AdjDisId
                WHERE d.DisDetNro = :disDetNro AND n.NitSec = :nitSec";

        $result = $this->read(function (PDO $connection) use ($sql, $disDetNro, $nitSec): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':disDetNro', $disDetNro, PDO::PARAM_STR);
            $stmt->bindValue(':nitSec', $nitSec, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        Logger::info("Documentos adjuntos obtenidos", [
            'disDetNro' => $disDetNro,
            'resultCount' => count($result)
        ]);

        return $result;
    }

    /**
     * Obtiene todos los adjuntos físicos vinculados a una dispensación sin filtrar por opcionalidad lógica,
     * garantizando un mapeo uno-a-uno e identificadores físicos explícitos para el pipeline IA.
     *
     * @param string $disDetNro Identificador operativo de dispensación.
     * @param string $nitSec NIT del cliente
     * @return array
     */
    public function getPhysicalAttachmentsByDisDetNro(string $disDetNro, string $nitSec): array
    {
        $sql = "SELECT
                a.DisId AS [dispensacion_id],
                d.DisDetNro AS [dis_det_nro],
                a.AdjDisId AS [attachment_id],
                n.NitMedDocId AS [physical_catalog_id],
                n.NitMedDocNom AS [physical_document_name],
                n.NitMedDocCodAlt AS [physical_catalog_alias],
                a.AdjDisCodDocAlt AS [physical_stored_alias],
                a.AdjDisDocUrl AS [remote_storage],
                CASE
                    WHEN a.AdjDisDoc IS NOT NULL AND DATALENGTH(a.AdjDisDoc) > 0 THEN 'BLOB'
                    WHEN a.AdjDisDocUrl IS NOT NULL AND a.AdjDisDocUrl <> '' THEN 'URL'
                    ELSE 'SIN_DOCUMENTOS'
                END AS [storage_type]
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                INNER JOIN DispensacionDetalleServicio d WITH (NOLOCK)
                    ON d.DisId = a.DisId
                   AND d.DisDetId = a.DisDetId
                LEFT JOIN NitDocumentos n WITH (NOLOCK)
                    ON n.NitMedDocId = a.AdjDisId
                   AND n.NitSec = :nitSec
                WHERE d.DisDetNro = :disDetNro
                ORDER BY a.AdjDisId ASC";

        $result = $this->read(function (PDO $connection) use ($sql, $disDetNro, $nitSec): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':disDetNro', $disDetNro, PDO::PARAM_STR);
            $stmt->bindValue(':nitSec', $nitSec, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        Logger::info("Adjuntos físicos obtenidos para resolución", [
            'disDetNro' => $disDetNro,
            'resultCount' => count($result)
        ]);

        return $result;
    }

    /**
     * Obtiene un documento adjunto por ID para dispensas
     * @param string $attachmentId ID del tipo de documento (NitMedDocId - 1)
     * @param string $disDetNro Identificador operativo de dispensación (DisDetNro - X18251205308)
     * @return array|false
     */
    public function getAttachmentByIdForDisDetNro(string $attachmentId, string $disDetNro): array|false
    {
        $sql = "SELECT
                    a.AdjDisId,
                    a.AdjDisNom,
                    a.AdjDisDocUrl,
                    CASE
                        WHEN AdjDisDoc IS NOT NULL AND DATALENGTH(AdjDisDoc) > 0 THEN 'BLOB'
                        WHEN AdjDisDocUrl IS NOT NULL AND AdjDisDocUrl <> '' THEN 'URL'
                        ELSE 'SIN_DOCUMENTOS'
                    END AS TipoAlmacenamiento,
                    DATALENGTH(a.AdjDisDoc) AS BlobSize
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                LEFT JOIN DispensacionDetalleServicio d WITH (NOLOCK) ON d.DisId=a.DisId and d.DisDetId=a.DisDetId
                WHERE a.AdjDisId = :attachmentId AND d.DisDetNro = :disDetNro";

        Logger::info("Fetching attachment for dispensation", [
            'attachmentId' => $attachmentId,
            'disDetNro' => $disDetNro
        ]);

        return $this->read(function (PDO $connection) use ($sql, $attachmentId, $disDetNro): array|false {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':attachmentId', $attachmentId, PDO::PARAM_STR);
            $stmt->bindValue(':disDetNro', $disDetNro, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });
    }

    /**
     * Obtiene el stream del BLOB de un documento adjunto para dispensas
     * @param string $attachmentId ID del tipo de documento (NitMedDocId - 1)
     * @param string $disDetNro Identificador operativo de dispensación (DisDetNro - X18251205308)
     * @return array Array con 'stream' y función 'close'
     */
    public function getAttachmentBlobStreamByIdForDisDetNro(string $attachmentId, string $disDetNro): array
    {
        $sql = "SELECT a.AdjDisDoc FROM AdjuntosDispensacion a WITH (NOLOCK)
                LEFT JOIN DispensacionDetalleServicio d WITH (NOLOCK) ON d.DisId=a.DisId and d.DisDetId=a.DisDetId
                WHERE a.AdjDisId = :attachmentId AND d.DisDetNro = :disDetNro";

        return $this->read(function (PDO $connection) use ($sql, $attachmentId, $disDetNro): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':attachmentId', $attachmentId, PDO::PARAM_STR);
            $stmt->bindValue(':disDetNro', $disDetNro, PDO::PARAM_STR);
            $stmt->execute();

            $stream = null;
            $stmt->bindColumn(1, $stream, PDO::PARAM_LOB);

            if (!$stmt->fetch(PDO::FETCH_BOUND)) {
                $stmt->closeCursor();
                return [
                    'stream' => null,
                    'close' => static function (): void {},
                ];
            }

            Logger::info("Fetching attachment BLOB stream for dispensation", [
                'attachmentId' => $attachmentId,
                'disDetNro' => $disDetNro
            ]);

            return [
                'stream' => $stream,
                'close' => static function () use ($stmt, $connection): void {
                    $stmt->closeCursor();
                    unset($connection);
                },
            ];
        });
    }

    /**
     * Materializa el BLOB y su longitud declarada dentro del mismo intento SQL.
     *
     * @return array{bytes:string,expected_size:int}|null
     */
    public function getAttachmentBlobBytesByIdForDisDetNro(
        string $attachmentId,
        string $disDetNro
    ): ?array {
        $sql = "SELECT a.AdjDisDoc, DATALENGTH(a.AdjDisDoc) AS BlobSize
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                LEFT JOIN DispensacionDetalleServicio d WITH (NOLOCK)
                    ON d.DisId=a.DisId and d.DisDetId=a.DisDetId
                WHERE a.AdjDisId = :attachmentId AND d.DisDetNro = :disDetNro";

        return $this->read(function (PDO $connection) use ($sql, $attachmentId, $disDetNro): ?array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':attachmentId', $attachmentId, PDO::PARAM_STR);
            $stmt->bindValue(':disDetNro', $disDetNro, PDO::PARAM_STR);
            $stmt->execute();

            $blob = null;
            $expectedSize = null;
            $stmt->bindColumn(1, $blob, PDO::PARAM_LOB);
            $stmt->bindColumn(2, $expectedSize, PDO::PARAM_INT);

            try {
                if (!$stmt->fetch(PDO::FETCH_BOUND)) {
                    return null;
                }

                if (is_resource($blob)) {
                    $bytes = stream_get_contents($blob);
                } elseif (is_string($blob)) {
                    $bytes = $blob;
                } else {
                    $bytes = '';
                }

                return [
                    'bytes' => is_string($bytes) ? $bytes : '',
                    'expected_size' => max(0, (int) $expectedSize),
                ];
            } finally {
                $stmt->closeCursor();
            }
        });
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
            $params[':facNro'] = $filters['facNro'];
        }

        if (!empty($filters['facNitSec'])) {
            $where .= " AND v.NitSec = :facNitSec";
            $params[':facNitSec'] = $filters['facNitSec'];
        }

        $sql = "SELECT COUNT(*) as total FROM (
                    SELECT 1 as n
                    FROM AdjuntosDispensacion a WITH (NOLOCK)
                    INNER JOIN vw_discolnet_dispensas v WITH (NOLOCK) ON a.DisId = v.facsec
                    $where
                    GROUP BY v.Dispensa, a.DisId, a.DisDetId, a.AdjDisId, a.AdjDisNom, a.AdjDisEstSop, a.AdjDisObsRec, a.AdjDisUsuAudi, a.AdJDisFecAudi, a.AdjDisUsuRec
                ) AS SubQuery";

        $result = $this->read(function (PDO $connection) use ($sql, $params): array|false {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });
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
            $params[':facNro'] = $filters['facNro'];
        }

        if (!empty($filters['facNitSec'])) {
            $where .= " AND v.NitSec = :facNitSec";
            $params[':facNitSec'] = $filters['facNitSec'];
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
                INNER JOIN vw_discolnet_dispensas v WITH (NOLOCK) ON a.DisId = v.facsec
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

        $result = $this->read(function (PDO $connection) use (
            $sql,
            $params,
            $offset,
            $pageSize
        ): array {
            $stmt = $connection->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
            $stmt->bindValue(':pageSize', (int) $pageSize, PDO::PARAM_INT);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        Logger::info("Historial de auditorias de documentos obtenido", [
            'filters' => $filters,
            'page' => $page,
            'pageSize' => $pageSize,
            'resultCount' => count($result)
        ]);

        return $result;
    }
}
