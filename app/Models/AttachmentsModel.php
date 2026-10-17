<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use PDO;

class AttachmentsModel extends Model
{
    /**
     * Obtiene los adjuntos físicos vinculados a una dispensación con proyección canónica dual
     * (claves técnicas en inglés para el pipeline IA y alias descriptivos para la API REST/Frontend).
     *
     * @param string      $disDetNro     Identificador operativo de dispensación (DisDetNro).
     * @param string|null $nitSec        Identificador opcional del cliente (si es null, se resuelve vía Dispensacion.DisNitSec).
     * @param string|null $attachmentId  Identificador opcional del adjunto físico (AdjDisId) para reauditoría selectiva.
     * @return array<int, array<string, mixed>>
     */
    public function getPhysicalAttachmentsByDisDetNro(string $disDetNro, ?string $nitSec = null, ?string $attachmentId = null): array
    {
        $nitSec = ($nitSec !== null && trim($nitSec) !== '') ? trim($nitSec) : null;
        $attachmentId = ($attachmentId !== null && trim($attachmentId) !== '') ? trim($attachmentId) : null;

        $sql = "SELECT
                a.DisId AS [dispensacion_id],
                d.DisDetNro AS [dis_det_nro],
                disp.DisNitSec AS [cliente],
                a.AdjDisId AS [attachment_id],
                a.AdjDisId AS [id_adjunto_fisico],
                a.AdjDisId,
                a.DisDetId AS [dis_det_id],
                n.NitMedDocId AS [physical_catalog_id],
                n.NitMedDocId AS [id_documento],
                n.NitMedDocNom AS [physical_document_name],
                n.NitMedDocNom AS [nombre_documento],
                n.NitMedDocCodAlt AS [physical_catalog_alias],
                n.NitMedDocCodAlt AS [nombre_alternativo],
                a.AdjDisCodDocAlt AS [physical_stored_alias],
                a.AdjDisDocUrl AS [remote_storage],
                a.AdjDisDocUrl AS [almacenamiento_remoto],
                a.AdjDisDocUrl,
                a.AdjDisNom,
                DATALENGTH(a.AdjDisDoc) AS [BlobSize],
                CASE
                    WHEN a.AdjDisDoc IS NOT NULL AND DATALENGTH(a.AdjDisDoc) > 0 THEN 'BLOB'
                    WHEN a.AdjDisDocUrl IS NOT NULL AND a.AdjDisDocUrl <> '' THEN 'URL'
                    ELSE 'SIN_DOCUMENTOS'
                END AS [storage_type],
                CASE
                    WHEN a.AdjDisDoc IS NOT NULL AND DATALENGTH(a.AdjDisDoc) > 0 THEN 'BLOB'
                    WHEN a.AdjDisDocUrl IS NOT NULL AND a.AdjDisDocUrl <> '' THEN 'URL'
                    ELSE 'SIN_DOCUMENTOS'
                END AS [TipoAlmacenamiento]
                FROM AdjuntosDispensacion a WITH (NOLOCK)
                INNER JOIN DispensacionDetalleServicio d WITH (NOLOCK) ON d.DisId = a.DisId AND d.DisDetId = a.DisDetId
                INNER JOIN Dispensacion disp WITH (NOLOCK) ON disp.DisId = d.DisId
                LEFT JOIN NitDocumentos n WITH (NOLOCK) ON n.NitMedDocId = a.AdjDisDocId AND n.NitSec = disp.DisNitSec
                WHERE d.DisDetNro = :disDetNro";

        if ($nitSec !== null) {
            $sql .= " AND disp.DisNitSec = :nitSec";
        }

        if ($attachmentId !== null) {
            $sql .= " AND a.AdjDisId = :attachmentId";
        }

        $sql .= " ORDER BY a.AdjDisId ASC";

        $result = $this->read(function (PDO $connection) use ($sql, $disDetNro, $nitSec, $attachmentId): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':disDetNro', $disDetNro, PDO::PARAM_STR);
            if ($nitSec !== null) {
                $stmt->bindValue(':nitSec', $nitSec, PDO::PARAM_STR);
            }
            if ($attachmentId !== null) {
                $stmt->bindValue(':attachmentId', $attachmentId, PDO::PARAM_STR);
            }
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        Logger::info("Adjuntos físicos obtenidos", [
            'disDetNro'    => $disDetNro,
            'nitSec'       => $nitSec,
            'attachmentId' => $attachmentId,
            'resultCount'  => count($result),
        ]);

        return $result;
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
