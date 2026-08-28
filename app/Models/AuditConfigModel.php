<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use PDO;

/**
 * Gestiona la configuración de auditoría por cliente.
 *
 * Tablas involucradas:
 *   - AudDisp        : Cabecera (1 fila por cliente)
 *   - AudDispCampo   : Toggles campo × documento × cliente
 *   - NitDocumentos  : Documentos requeridos por cliente (solo lectura, db2)
 */
class AuditConfigModel extends Model
{
    // -------------------------------------------------------------------------
    // LECTURA
    // -------------------------------------------------------------------------

    /**
     * @param string $nitSec
     * @return array|null
     */
    public function getConfig(string $nitSec): ?array
    {
        $header = $this->getHeader($nitSec);
        if ($header === null) {
            return null;
        }

        $sql = "SELECT nd.NitMedDocId AS docId, nd.NitMedDocNom AS docNombre,
               cat.CampoNombre, cat.TipoCampo, cat.TipoDato,
               cat.CodigoCampo, cat.EsVisual,
               cat.Descripcion AS DescripcionDefault,
               cat.Severidad   AS SeveridadDefault,
               ac.Orden, ac.DescripcionOverride, ac.SeveridadOverride,
               ac.AplicaServicio, ac.EsMultiItem
            FROM Discolnet.dbo.AudDispCampo ac WITH (NOLOCK)
            INNER JOIN Discolnet.dbo.AudDispCampoCatalogo cat WITH (NOLOCK)
                ON cat.CampoNombre = ac.CampoNombre
            INNER JOIN NitDocumentos nd WITH (NOLOCK)
                ON nd.NitSec = ac.FacNitSec AND nd.NitMedDocId = ac.NitMedDocId
            WHERE ac.FacNitSec = :nitSec AND ac.Activo = 1 AND nd.NitMedDocOpc = 'N'
            ORDER BY nd.NitMedDocId ASC, cat.EsVisual ASC, ac.Orden ASC";

        $rows = $this->read(function (PDO $connection) use ($sql, $nitSec): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':nitSec', $nitSec, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        Logger::info('AuditConfigModel::getConfig', [
            'nitSec'   => $nitSec,
            'rowCount' => count($rows),
        ]);

        // Agrupar por documento
        $documents = [];
        foreach ($rows as $row) {
            $docNombre = $row['docNombre'];
            if (!isset($documents[$docNombre])) {
                $documents[$docNombre] = [
                    'docId'        => (int) $row['docId'],
                    'fields'       => [],
                    'visualChecks' => [],
                ];
            }

            $aplicaServicio = self::normalizeAplicaServicio($row['AplicaServicio'] ?? null);
            $description    = $row['DescripcionOverride'] ?? $row['DescripcionDefault'] ?? '';
            $orden          = (int) $row['Orden'];
            $codigoCampo    = $row['CodigoCampo'];

            if (!$row['EsVisual']) {
                $documents[$docNombre]['fields'][] = [
                    'campoNombre'    => $row['CampoNombre'],
                    'tipoCampo'      => $row['TipoCampo'],
                    'tipoDato'       => strtolower(trim((string) $row['TipoDato'])),
                    'orden'          => $orden,
                    'severity'       => $row['SeveridadOverride'] ?? $row['SeveridadDefault'] ?? 'media',
                    'codigoCampo'    => $codigoCampo,
                    'description'    => $description,
                    'aplicaServicio' => $aplicaServicio,
                    'esMultiItem'    => (bool) ($row['EsMultiItem'] ?? false),
                ];
            } else {
                // Visual checks retornados como objetos completos
                $documents[$docNombre]['visualChecks'][] = [
                    'check'          => $row['CampoNombre'],
                    'description'    => $description,
                    'severity'       => $row['SeveridadOverride'] ?? $row['SeveridadDefault'] ?? 'alta',
                    'orden'          => $orden,
                    'codigoCampo'    => $codigoCampo,
                    'aplicaServicio' => $aplicaServicio,
                ];
            }
        }

        return [
            'nitSec'       => $nitSec,
            'activo'       => (bool) $header['Activo'],
            'systemPrompt' => $header['SystemPrompt'],
            'factorConv'   => (bool) ($header['FactorConv'] ?? false),
            'documents'    => empty($documents) ? new \stdClass() : $documents,
        ];
    }

    /**
     * Retorna la fila de cabecera de AudDisp para el cliente, o null si no existe.
     */
    public function getHeader(string $nitSec): ?array
    {
        $sql = "
            SELECT FacNitSec, SystemPrompt, Activo, FecCre, FecMod, FactorConv
            FROM Discolnet.dbo.AudDisp WITH (NOLOCK)
            WHERE FacNitSec = :nitSec
        ";
        return $this->read(function (PDO $connection) use ($sql, $nitSec): ?array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':nitSec', $nitSec, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } finally {
                $stmt->closeCursor();
            }
        });
    }

    // -------------------------------------------------------------------------
    // ESCRITURA
    // -------------------------------------------------------------------------

    /**
     * Guarda (upsert completo) la configuración de un cliente.
     *
     * Estrategia:
     *   1. MERGE en AudDisp (upsert del header).
     *   2. DELETE + INSERT en AudDispCampo para los campos enviados.
     *
     * @param string      $nitSec       NIT del cliente
     * @param array       $fields       Lista de campos a activar:
     *                                  [['docId'=>1,'campoNombre'=>'NumeroFactura',
     *                                    'orden'=>1,'description'=>null,
     *                                    'severity'=>null], ...]
     * @param string|null $systemPrompt Prompt personalizado (opcional)
     */
    public function saveConfig(
        string $nitSec,
        array $fields,
        ?string $systemPrompt = null,
        bool $factorConv = false
    ): bool {
        return $this->nonReplayableWrite(function (PDO $db) use (
            $nitSec,
            $fields,
            $systemPrompt,
            $factorConv
        ): bool {
            try {
                $db->beginTransaction();
                $this->upsertHeader($db, $nitSec, $systemPrompt, $factorConv);
                $this->replaceFields($db, $nitSec, $fields);
                $db->commit();
            } catch (\Throwable $error) {
                try {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } catch (\Throwable $rollbackError) {
                    Logger::error('AuditConfigModel::saveConfig rollback fallido', [
                        'nitSec' => $nitSec,
                        'error_class' => $rollbackError::class,
                    ]);
                }

                Logger::error('AuditConfigModel::saveConfig — ERROR', [
                    'nitSec' => $nitSec,
                    'error_class' => $error::class,
                ]);
                throw $error;
            }

            Logger::info('AuditConfigModel::saveConfig — OK', [
                'nitSec' => $nitSec,
                'fieldCount' => count($fields),
            ]);

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // MÉTODOS PRIVADOS
    // -------------------------------------------------------------------------

    /**
     * Inserta o actualiza la fila en AudDisp.
     * Usa MERGE para ser idempotente (primera vez crea, siguientes actualizan).
     */
    private function upsertHeader(\PDO $db, string $nitSec, ?string $systemPrompt, bool $factorConv = false): void
    {
        $sql = "
            MERGE Discolnet.dbo.AudDisp AS target
            USING (SELECT :nitSec AS FacNitSec) AS source
                ON target.FacNitSec = source.FacNitSec
            WHEN MATCHED THEN
                UPDATE SET
                    SystemPrompt = :promptU,
                    FactorConv   = :fcU,
                    FecMod       = GETDATE()
            WHEN NOT MATCHED THEN
                INSERT (FacNitSec, SystemPrompt, Activo, FactorConv, FecCre, FecMod)
                VALUES (:nitSecI, :promptI, 1, :fcI, GETDATE(), GETDATE());";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':nitSec', $nitSec, PDO::PARAM_STR);

        if ($systemPrompt === null) {
            $stmt->bindValue(':promptU', null, PDO::PARAM_NULL);
            $stmt->bindValue(':promptI', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':promptU', $systemPrompt, PDO::PARAM_STR);
            $stmt->bindValue(':promptI', $systemPrompt, PDO::PARAM_STR);
        }

        $stmt->bindValue(':nitSecI', $nitSec, PDO::PARAM_STR);

        $fcVal = $factorConv ? 1 : 0;
        $stmt->bindValue(':fcU', $fcVal, PDO::PARAM_INT);
        $stmt->bindValue(':fcI', $fcVal, PDO::PARAM_INT);

        try {
            $stmt->execute();
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Reemplaza todos los toggles del cliente:
     * borra los anteriores e inserta los nuevos en lote.
     *
     * @param array $fields  [['docId'=>int,'campoNombre'=>string,'orden'=>int,
     *                         'description'=>?string,'severity'=>?string], ...]
     */
    private function replaceFields(\PDO $db, string $nitSec, array $fields): void
    {
        $delSql  = "DELETE FROM Discolnet.dbo.AudDispCampo WHERE FacNitSec = :nitSec";
        $delStmt = $db->prepare($delSql);
        $delStmt->bindParam(':nitSec', $nitSec, PDO::PARAM_STR);
        try {
            $delStmt->execute();
        } finally {
            $delStmt->closeCursor();
        }

        if (empty($fields)) {
            return;
        }

        $insSql = "
            INSERT INTO Discolnet.dbo.AudDispCampo
                (FacNitSec, NitMedDocId, CampoNombre, Activo, Orden,
                 DescripcionOverride, SeveridadOverride, AplicaServicio, EsMultiItem)
            VALUES
                (:nitSec, :docId, :campoNombre, 1, :orden,
                 :description, :severity, :aplicaServicio, :esMultiItem)";
        $insStmt = $db->prepare($insSql);

        try {
            foreach ($fields as $field) {
                $docId          = (int)    $field['docId'];
                $campo          = (string) $field['campoNombre'];
                $orden          = (int)    ($field['orden']     ?? 0);
                $description    = $field['description'] ?? null;
                $severity       = $field['severity']    ?? null;
                $aplicaServicio = self::normalizeAplicaServicio($field['aplicaServicio'] ?? null);
                $esMultiItem    = (int) (bool) ($field['esMultiItem'] ?? false);

                $insStmt->bindValue(':nitSec',         $nitSec, PDO::PARAM_STR);
                $insStmt->bindValue(':docId',          $docId, PDO::PARAM_INT);
                $insStmt->bindValue(':campoNombre',    $campo, PDO::PARAM_STR);
                $insStmt->bindValue(':orden',          $orden, PDO::PARAM_INT);
                $insStmt->bindValue(':aplicaServicio', $aplicaServicio, PDO::PARAM_STR);
                if ($description === null) {
                    $insStmt->bindValue(':description', null, PDO::PARAM_NULL);
                } else {
                    $insStmt->bindValue(':description', (string) $description, PDO::PARAM_STR);
                }
                if ($severity === null) {
                    $insStmt->bindValue(':severity', null, PDO::PARAM_NULL);
                } else {
                    $insStmt->bindValue(':severity', (string) $severity, PDO::PARAM_STR);
                }
                $insStmt->bindValue(':esMultiItem', $esMultiItem, PDO::PARAM_INT);
                $insStmt->execute();
            }
        } finally {
            $insStmt->closeCursor();
        }
    }

    /**
     * Retorna el catálogo completo de campos.
     */
    public function getCatalog(): array
    {
        $sql = "SELECT CampoNombre, CodigoCampo, TipoCampo, TipoDato,
                       Descripcion, Severidad, EsVisual
                FROM Discolnet.dbo.AudDispCampoCatalogo WITH (NOLOCK)
                ORDER BY EsVisual ASC, CampoNombre ASC";
        return $this->read(function (PDO $connection) use ($sql): array {
            $stmt = $connection->prepare($sql);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });
    }

    /**
     * Verifica si un campo existe en el catálogo.
     */
    public function catalogFieldExists(string $campoNombre): bool
    {
        $sql = "SELECT 1 FROM Discolnet.dbo.AudDispCampoCatalogo WITH (NOLOCK)
                WHERE CampoNombre = :campo";
        return $this->read(function (PDO $connection) use ($sql, $campoNombre): bool {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':campo', $campoNombre, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            } finally {
                $stmt->closeCursor();
            }
        });
    }

    private static function normalizeAplicaServicio(mixed $value): string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));
        return $normalized !== '' ? $normalized : 'TODOS';
    }
}
