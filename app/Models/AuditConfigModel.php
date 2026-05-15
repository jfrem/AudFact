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

        $sql = "SELECT
                nd.NitMedDocId          AS docId,
                nd.NitMedDocNom         AS docNombre,
                ac.CampoNombre,
                ac.TipoCampo,
                ac.TipoDato,
                ac.Orden,
                ac.DescripcionOverride,
                ac.SeveridadOverride
            FROM Discolnet.dbo.AudDispCampo ac WITH (NOLOCK)
            INNER JOIN NitDocumentos nd WITH (NOLOCK)
                ON nd.NitSec       = ac.FacNitSec
                AND nd.NitMedDocId = ac.NitMedDocId
            WHERE ac.FacNitSec = :nitSec
              AND ac.Activo    = 1
              AND nd.NitMedDocOpc = 'N'
            ORDER BY nd.NitMedDocId ASC, ac.TipoCampo ASC, ac.Orden ASC";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':nitSec', $nitSec, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

            if ($row['TipoCampo'] !== 'V') {
                $documents[$docNombre]['fields'][] = [
                    'campoNombre' => $row['CampoNombre'],
                    'tipoCampo'   => $row['TipoCampo'],
                    'tipoDato'    => strtolower(trim((string) $row['TipoDato'])),
                    'orden'       => (int) $row['Orden'],
                    'severity'    => $row['SeveridadOverride'] ?? 'media',
                ];
            } else {
                // Visual checks retornados como objetos completos
                $documents[$docNombre]['visualChecks'][] = [
                    'check'       => $row['CampoNombre'],
                    'description' => $row['DescripcionOverride'] ?? '',
                    'severity'    => $row['SeveridadOverride'] ?? 'alta',
                    'orden'       => (int) $row['Orden'],
                ];
            }
        }

        return [
            'nitSec'       => $nitSec,
            'activo'       => (bool) $header['Activo'],
            'systemPrompt' => $header['SystemPrompt'],
            'documents'    => $documents,
        ];
    }

    /**
     * Retorna la fila de cabecera de AudDisp para el cliente, o null si no existe.
     */
    public function getHeader(string $nitSec): ?array
    {
        $sql = "
            SELECT FacNitSec, SystemPrompt, Activo, FecCre, FecMod
            FROM Discolnet.dbo.AudDisp WITH (NOLOCK)
            WHERE FacNitSec = :nitSec
        ";
        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':nitSec', $nitSec, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
     *                                    'tipoCampo'=>'E','tipoDato'=>'text','orden'=>1,
     *                                    'description'=>null,'severity'=>null], ...]
     * @param string|null $systemPrompt Prompt personalizado (opcional)
     */
    public function saveConfig(
        string $nitSec,
        array $fields,
        ?string $systemPrompt = null
    ): bool {
        $db = $this->getWriteDb();

        try {
            $db->beginTransaction();

            $this->upsertHeader($db, $nitSec, $systemPrompt);
            $this->replaceFields($db, $nitSec, $fields);

            $db->commit();

            Logger::info('AuditConfigModel::saveConfig — OK', [
                'nitSec'     => $nitSec,
                'fieldCount' => count($fields),
            ]);

            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            Logger::error('AuditConfigModel::saveConfig — ERROR', [
                'nitSec' => $nitSec,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // MÉTODOS PRIVADOS
    // -------------------------------------------------------------------------

    /**
     * Inserta o actualiza la fila en AudDisp.
     * Usa MERGE para ser idempotente (primera vez crea, siguientes actualizan).
     */
    private function upsertHeader(\PDO $db, string $nitSec, ?string $systemPrompt): void
    {
        $sql = "
            MERGE Discolnet.dbo.AudDisp AS target
            USING (SELECT :nitSec AS FacNitSec) AS source
                ON target.FacNitSec = source.FacNitSec
            WHEN MATCHED THEN
                UPDATE SET
                    SystemPrompt = COALESCE(:promptU, target.SystemPrompt),
                    FecMod       = GETDATE()
            WHEN NOT MATCHED THEN
                INSERT (FacNitSec, SystemPrompt, Activo, FecCre, FecMod)
                VALUES (:nitSecI, :promptI, 1, GETDATE(), GETDATE());";

        $stmt = $db->prepare($sql);
        $stmt->bindParam(':nitSec',  $nitSec,       PDO::PARAM_STR);
        $stmt->bindParam(':promptU', $systemPrompt, PDO::PARAM_STR);
        $stmt->bindParam(':nitSecI', $nitSec,       PDO::PARAM_STR);
        $stmt->bindParam(':promptI', $systemPrompt, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Reemplaza todos los toggles del cliente:
     * borra los anteriores e inserta los nuevos en lote.
     *
     * @param array $fields  [['docId'=>int,'campoNombre'=>string,'tipoCampo'=>string,
     *                         'tipoDato'=>?string,'orden'=>int,'description'=>?string,
     *                         'severity'=>?string], ...]
     */
    private function replaceFields(\PDO $db, string $nitSec, array $fields): void
    {
        $delSql  = "DELETE FROM Discolnet.dbo.AudDispCampo WHERE FacNitSec = :nitSec";
        $delStmt = $db->prepare($delSql);
        $delStmt->bindParam(':nitSec', $nitSec, PDO::PARAM_STR);
        $delStmt->execute();

        if (empty($fields)) {
            return;
        }

        $insSql = "
            INSERT INTO Discolnet.dbo.AudDispCampo
                (FacNitSec, NitMedDocId, CampoNombre, TipoCampo, TipoDato, Activo, Orden,
                 DescripcionOverride, SeveridadOverride)
            VALUES
                (:nitSec, :docId, :campoNombre, :tipoCampo, :tipoDato, 1, :orden,
                 :description, :severity)";
        $insStmt = $db->prepare($insSql);

        foreach ($fields as $field) {
            $docId       = (int)    $field['docId'];
            $campo       = (string) $field['campoNombre'];
            $tipo        = (string) $field['tipoCampo'];
            $tipoDato    = $field['tipoDato'] ?? null;
            $orden       = (int)    ($field['orden']     ?? 0);
            $description = $field['description'] ?? null;
            $severity    = $field['severity']    ?? null;

            $insStmt->bindParam(':nitSec',      $nitSec,      PDO::PARAM_STR);
            $insStmt->bindParam(':docId',       $docId,       PDO::PARAM_INT);
            $insStmt->bindParam(':campoNombre', $campo,       PDO::PARAM_STR);
            $insStmt->bindParam(':tipoCampo',   $tipo,        PDO::PARAM_STR);
            if ($tipoDato === null) {
                $insStmt->bindValue(':tipoDato', null, PDO::PARAM_NULL);
            } else {
                $insStmt->bindValue(':tipoDato', (string) $tipoDato, PDO::PARAM_STR);
            }
            $insStmt->bindParam(':orden',       $orden,       PDO::PARAM_INT);
            $insStmt->bindParam(':description', $description, PDO::PARAM_STR);
            $insStmt->bindParam(':severity',    $severity,    PDO::PARAM_STR);
            $insStmt->execute();
        }
    }
}
