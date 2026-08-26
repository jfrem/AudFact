<?php

declare(strict_types=1);

namespace App\Models;

use Core\Cache;
use Core\Logger;
use Core\SqlServerConnectionExecutor;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Escritura transaccional del resultado global y del detalle documental.
 */
class AuditResultPersistenceModel extends Model
{
    private const AUDIT_USER = 'Z-IA';
    private const ATTACHMENT_STATUS_APPROVED = 'C';
    private const ATTACHMENT_STATUS_REJECTED = 'R';
    private const ATTACHMENT_REJECTED_FLAG = 'S';
    private const ATTACHMENT_APPROVED_FLAG = 'N';
    private const REJECTION_SUPPORT_CODE = 30;

    public function __construct(?SqlServerConnectionExecutor $executor = null)
    {
        parent::__construct($executor);
    }

    /**
     * @param array<string,mixed> $auditResultData
     * @param array<int,array<string,mixed>> $documentDecisions
     */
    public function persist(array $auditResultData, array $documentDecisions): void
    {
        $data = $this->normalizeAuditResultData($auditResultData);
        if ($documentDecisions === []) {
            throw new \InvalidArgumentException(
                'La auditoría no produjo decisiones documentales para persistencia.'
            );
        }

        Logger::info('AuditTrace: persist_audit_start', [
            'DisId' => $data['DisId'],
            'FacNro' => $data['FacNro'],
            'decisions_count' => count($documentDecisions),
        ]);

        $this->idempotentWrite(function (PDO $writeDb) use ($data, $documentDecisions): void {
            try {
                $writeDb->beginTransaction();
                $this->upsertAuditResult($writeDb, $data);
                $this->persistAttachmentDecisions(
                    $writeDb,
                    $data['FacNro'],
                    $data['FacNitSec'],
                    $documentDecisions
                );
                $writeDb->commit();
            } catch (\Throwable $error) {
                try {
                    if ($writeDb->inTransaction()) {
                        $writeDb->rollBack();
                    }
                } catch (\Throwable $rollbackError) {
                    Logger::error('AuditResultPersistenceModel: rollback fallido', [
                        'DisId' => $data['DisId'],
                        'FacNro' => $data['FacNro'],
                        'error_class' => $rollbackError::class,
                    ]);
                }

                Logger::error('AuditResultPersistenceModel: transacción revertida', [
                    'DisId' => $data['DisId'],
                    'FacNro' => $data['FacNro'],
                    'error_class' => $error::class,
                ]);
                throw $error;
            }
        });

        $this->invalidateResultCache(
            $data['FacNitSec'] !== '' ? $data['FacNitSec'] : 'all'
        );
        Logger::info('AuditTrace: persist_audit_committed', [
            'DisId' => $data['DisId'],
            'FacNro' => $data['FacNro'],
        ]);
    }

    public function updateFinalTimings(string $facNro, string $hallazgosJson, int $durationMs): bool
    {
        $facNro = trim($facNro);
        if ($facNro === '') {
            throw new \InvalidArgumentException('FacNro es obligatorio para actualizar timings.');
        }

        $decoded = json_decode($hallazgosJson, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Hallazgos debe ser un objeto JSON válido.');
        }

        $sql = "UPDATE Discolnet.dbo.AudDispEst
                SET [Hallazgos] = :Hallazgos,
                    [DuracionProcesamientoMs] = :DuracionProcesamientoMs,
                    [FechaActualizacion] = GETDATE()
                WHERE [FacNro] = :FacNro";

        return $this->nonReplayableWrite(function (PDO $connection) use (
            $sql,
            $hallazgosJson,
            $durationMs,
            $facNro
        ): bool {
            $statement = $connection->prepare($sql);
            $statement->bindValue(':Hallazgos', $hallazgosJson, PDO::PARAM_STR);
            $statement->bindValue(
                ':DuracionProcesamientoMs',
                max(0, $durationMs),
                PDO::PARAM_INT
            );
            $statement->bindValue(':FacNro', $facNro, PDO::PARAM_STR);
            $statement->execute();

            try {
                return $statement->rowCount() > 0;
            } finally {
                $statement->closeCursor();
            }
        });
    }

    /**
     * @param array<string,mixed> $data
     */
    private function upsertAuditResult(PDO $connection, array $data): void
    {
        $sql = <<<'SQL'
            SET NOCOUNT ON;
            DECLARE @FacSec NVARCHAR(320) = :DisId;
            DECLARE @FacNro NVARCHAR(100) = :FacNro;
            DECLARE @EstAud BIT = :EstAud;
            DECLARE @EstadoDetallado VARCHAR(50) = :EstadoDetallado;
            DECLARE @RequiereRevisionHumana BIT = :RequiereRevisionHumana;
            DECLARE @Severidad VARCHAR(20) = :Severidad;
            DECLARE @Hallazgos NVARCHAR(MAX) = :Hallazgos;
            DECLARE @DetalleError NVARCHAR(MAX) = :DetalleError;
            DECLARE @DocumentosProcesados INT = :DocumentosProcesados;
            DECLARE @DocumentoFallido VARCHAR(255) = :DocumentoFallido;
            DECLARE @DuracionProcesamientoMs INT = :DuracionProcesamientoMs;
            DECLARE @FacNitSec VARCHAR(100) = :FacNitSec;

            UPDATE Discolnet.dbo.AudDispEst WITH (UPDLOCK, SERIALIZABLE)
            SET [FacSec] = @FacSec,
                [EstAud] = @EstAud,
                [EstadoDetallado] = @EstadoDetallado,
                [RequiereRevisionHumana] = @RequiereRevisionHumana,
                [Severidad] = @Severidad,
                [Hallazgos] = @Hallazgos,
                [DetalleError] = @DetalleError,
                [DocumentosProcesados] = @DocumentosProcesados,
                [DocumentoFallido] = @DocumentoFallido,
                [DuracionProcesamientoMs] = @DuracionProcesamientoMs,
                [FacNitSec] = @FacNitSec,
                [FechaActualizacion] = GETDATE()
            WHERE [FacNro] = @FacNro;

            IF @@ROWCOUNT = 0
            BEGIN
                INSERT INTO Discolnet.dbo.AudDispEst (
                    [FacSec], [FacNro], [EstAud], [EstadoDetallado],
                    [RequiereRevisionHumana], [Severidad], [Hallazgos],
                    [DetalleError], [DocumentosProcesados], [DocumentoFallido],
                    [DuracionProcesamientoMs], [FacNitSec]
                )
                VALUES (
                    @FacSec, @FacNro, @EstAud, @EstadoDetallado,
                    @RequiereRevisionHumana, @Severidad, @Hallazgos,
                    @DetalleError, @DocumentosProcesados, @DocumentoFallido,
                    @DuracionProcesamientoMs, @FacNitSec
                );
            END;
            SQL;

        $statement = $connection->prepare($sql);
        $this->bindAuditResult($statement, $data);
        $statement->execute();
        $statement->closeCursor();
    }

    /**
     * @param array<string,mixed> $data
     */
    private function bindAuditResult(PDOStatement $statement, array $data): void
    {
        $statement->bindValue(':DisId', $data['DisId'], PDO::PARAM_STR);
        $statement->bindValue(':FacNro', $data['FacNro'], PDO::PARAM_STR);
        $statement->bindValue(':EstAud', $data['EstAud'], PDO::PARAM_INT);
        $statement->bindValue(':EstadoDetallado', $data['EstadoDetallado'], PDO::PARAM_STR);
        $statement->bindValue(
            ':RequiereRevisionHumana',
            $data['RequiereRevisionHumana'],
            PDO::PARAM_INT
        );
        $statement->bindValue(':Severidad', $data['Severidad'], PDO::PARAM_STR);
        $statement->bindValue(':Hallazgos', $data['Hallazgos'], PDO::PARAM_STR);
        self::bindNullableString($statement, ':DetalleError', $data['DetalleError']);
        $statement->bindValue(
            ':DocumentosProcesados',
            $data['DocumentosProcesados'],
            PDO::PARAM_INT
        );
        self::bindNullableString($statement, ':DocumentoFallido', $data['DocumentoFallido']);
        $statement->bindValue(
            ':DuracionProcesamientoMs',
            $data['DuracionProcesamientoMs'],
            PDO::PARAM_INT
        );
        $statement->bindValue(':FacNitSec', $data['FacNitSec'], PDO::PARAM_STR);
    }

    /**
     * @param array<int,array<string,mixed>> $documentDecisions
     */
    private function persistAttachmentDecisions(
        PDO $connection,
        string $facNro,
        string $nitSec,
        array $documentDecisions
    ): void {
        $attachments = $this->fetchDispensationAttachments($connection, $facNro, $nitSec);
        if ($attachments === []) {
            throw new RuntimeException("No se encontraron adjuntos para la dispensación {$facNro}.");
        }

        $decisionsByAttachmentId = [];
        $decisionsByName = [];
        foreach ($documentDecisions as $decision) {
            $normalized = $this->normalizeDocumentDecision($decision, $facNro);
            if ($normalized['attachmentId'] !== null) {
                $decisionsByAttachmentId[$normalized['attachmentId']] = $normalized;
            }
            $decisionsByName[strtoupper($normalized['documentName'])] = $normalized;
        }

        $updatesByAttachmentId = [];
        $matchedAttachmentIds = [];
        $matchedNames = [];

        foreach ($attachments as $attachment) {
            $attachmentId = (int) $attachment['AdjDisId'];
            $documentName = strtoupper(trim((string) ($attachment['AdjDisNom'] ?? '')));

            // 1. Prioridad: Emparejamiento por attachment_id estable
            if (isset($decisionsByAttachmentId[$attachmentId])) {
                $decision = $decisionsByAttachmentId[$attachmentId];
                $matchedAttachmentIds[$attachmentId] = true;
                $matchedNames[strtoupper($decision['documentName'])] = true;
                $updatesByAttachmentId[$attachmentId] = $decision;
                continue;
            }

            // 2. Fallback: Emparejamiento por nombre de documento
            $decision = $decisionsByName[$documentName] ?? null;
            if ($decision !== null) {
                $matchedNames[$documentName] = true;
                $matchedAttachmentIds[$attachmentId] = true;
                $updatesByAttachmentId[$attachmentId] = $decision;
                continue;
            }

            Logger::warning('Persistencia: adjunto físico sin decisión lógica', [
                'adjDisId' => $attachmentId,
                'adjDisNom' => $documentName,
                'facNro' => $facNro,
            ]);
        }

        $fallback = $this->resolveFallbackAttachment($attachments);
        foreach ($documentDecisions as $decision) {
            $normalized = $this->normalizeDocumentDecision($decision, $facNro);
            $docName = strtoupper($normalized['documentName']);
            $attId = $normalized['attachmentId'];

            $isMatched = ($attId !== null && isset($matchedAttachmentIds[$attId]))
                || isset($matchedNames[$docName]);

            if (!$isMatched) {
                if ($normalized['approved']) {
                    continue;
                }

                $fallbackId = (int) $fallback['AdjDisId'];
                $updatesByAttachmentId[$fallbackId] = $normalized;
                Logger::info('Persistencia: decisión huérfana asignada al adjunto fallback', [
                    'documentName' => $normalized['documentName'],
                    'fallbackAdjDisId' => $fallbackId,
                    'facNro' => $facNro,
                ]);
            }
        }

        $this->executeAttachmentUpdates(
            $connection,
            (string) $attachments[0]['DisId'],
            (int) $attachments[0]['DisDetId'],
            $updatesByAttachmentId
        );
    }

    /**
     * @return array<int,array{AdjDisId:mixed,AdjDisNom:mixed,DisId:mixed,DisDetId:mixed}>
     */
    private function fetchDispensationAttachments(PDO $connection, string $facNro, string $nitSec): array
    {
        $sql = "SELECT DISTINCT
                    a.AdjDisId,
                    COALESCE(n.NitMedDocNom, a.AdjDisNom) AS AdjDisNom,
                    a.DisId,
                    a.DisDetId
                FROM AdjuntosDispensacion a
                INNER JOIN DispensacionDetalleServicio d
                    ON d.DisId = a.DisId AND d.DisDetId = a.DisDetId
                LEFT JOIN NitDocumentos n
                    ON n.NitMedDocCodAlt = a.AdjDisCodDocAlt AND n.NitSec = :nitSec
                WHERE d.DisDetNro = :facNro
                ORDER BY a.AdjDisId ASC";

        $statement = $connection->prepare($sql);
        $statement->bindValue(':nitSec', $nitSec, PDO::PARAM_STR);
        $statement->bindValue(':facNro', $facNro, PDO::PARAM_STR);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,array{AdjDisId:mixed,AdjDisNom:mixed,DisId:mixed,DisDetId:mixed}> $attachments
     * @return array{AdjDisId:mixed,AdjDisNom:mixed,DisId:mixed,DisDetId:mixed}
     */
    private function resolveFallbackAttachment(array $attachments): array
    {
        foreach ($attachments as $attachment) {
            if (strtoupper(trim((string) ($attachment['AdjDisNom'] ?? ''))) === 'DISPENSA') {
                return $attachment;
            }
        }

        return $attachments[0];
    }

    /**
     * @param array<int,array{documentName:string,approved:bool,observation:?string}> $updates
     */
    private function executeAttachmentUpdates(
        PDO $connection,
        string $disId,
        int $disDetId,
        array $updates
    ): void {
        $values = [];
        foreach (array_keys($updates) as $index => $attachmentId) {
            $values[] = "(:attachmentId{$index}, :approved{$index}, :observation{$index})";
        }

        $attachmentSql = '';
        if ($values !== []) {
            $attachmentSql = sprintf(
                <<<'SQL'
                UPDATE target
                SET target.AdjDisObsRec = CASE WHEN source.Approved = 1 THEN NULL ELSE source.Observation END,
                    target.RecConSopCod = CASE WHEN source.Approved = 1 THEN NULL ELSE @SupportCode END,
                    target.AdjDisEstSop = CASE WHEN source.Approved = 1 THEN @ApprovedStatus ELSE @RejectedStatus END,
                    target.AdjDisUsuAudi = @AuditUser,
                    target.AdJDisFecAudi = GETDATE(),
                    target.AdjDisRec = CASE WHEN source.Approved = 1 THEN @ApprovedFlag ELSE @RejectedFlag END,
                    target.AdjDisUsuRec = CASE WHEN source.Approved = 1 THEN NULL ELSE @AuditUser END,
                    target.AdjDisFecRec = CASE WHEN source.Approved = 1 THEN NULL ELSE GETDATE() END
                FROM AdjuntosDispensacion AS target
                INNER JOIN (VALUES %s) AS source (AdjDisId, Approved, Observation)
                    ON source.AdjDisId = target.AdjDisId
                WHERE target.DisId = @DisId
                  AND target.DisDetId = @DisDetId;

                SQL,
                implode(', ', $values)
            );
        }

        $sql = "SET NOCOUNT ON;
                DECLARE @DisId NVARCHAR(320) = :disId;
                DECLARE @DisDetId INT = :disDetId;
                DECLARE @AuditUser VARCHAR(10) = :auditUser;
                DECLARE @SupportCode INT = :supportCode;
                DECLARE @ApprovedStatus CHAR(1) = :approvedStatus;
                DECLARE @RejectedStatus CHAR(1) = :rejectedStatus;
                DECLARE @ApprovedFlag CHAR(1) = :approvedFlag;
                DECLARE @RejectedFlag CHAR(1) = :rejectedFlag;

                {$attachmentSql}
                UPDATE DispensacionDetalleServicio
                SET DisDetUsuAud = @AuditUser,
                    DisDetFecAud = GETDATE()
                WHERE DisId = @DisId AND DisDetId = @DisDetId;";

        $statement = $connection->prepare($sql);
        $statement->bindValue(':disId', $disId, PDO::PARAM_STR);
        $statement->bindValue(':disDetId', $disDetId, PDO::PARAM_INT);
        $statement->bindValue(':auditUser', self::AUDIT_USER, PDO::PARAM_STR);
        $statement->bindValue(':supportCode', self::REJECTION_SUPPORT_CODE, PDO::PARAM_INT);
        $statement->bindValue(':approvedStatus', self::ATTACHMENT_STATUS_APPROVED, PDO::PARAM_STR);
        $statement->bindValue(':rejectedStatus', self::ATTACHMENT_STATUS_REJECTED, PDO::PARAM_STR);
        $statement->bindValue(':approvedFlag', self::ATTACHMENT_APPROVED_FLAG, PDO::PARAM_STR);
        $statement->bindValue(':rejectedFlag', self::ATTACHMENT_REJECTED_FLAG, PDO::PARAM_STR);

        foreach (array_keys($updates) as $position => $attachmentId) {
            $decision = $updates[$attachmentId];
            $statement->bindValue(
                ":attachmentId{$position}",
                $attachmentId,
                PDO::PARAM_INT
            );
            $statement->bindValue(
                ":approved{$position}",
                $decision['approved'] ? 1 : 0,
                PDO::PARAM_INT
            );
            self::bindNullableString(
                $statement,
                ":observation{$position}",
                $decision['observation']
            );
        }

        $statement->execute();
        $statement->closeCursor();
    }

    /**
     * @param array<string,mixed> $decision
     * @return array{documentName:string,approved:bool,observation:?string,attachmentId:?int}
     */
    private function normalizeDocumentDecision(array $decision, string $facNro): array
    {
        $documentName = trim((string) ($decision['documentName'] ?? ''));
        if ($documentName === '') {
            throw new \InvalidArgumentException(
                "La decisión documental de {$facNro} no tiene documentName."
            );
        }

        $approved = (bool) ($decision['approved'] ?? false);
        $observation = null;
        if (!$approved) {
            $payload = $decision['payload'] ?? null;
            if (!is_array($payload) || empty($payload['hallazgos'])) {
                throw new \InvalidArgumentException(sprintf(
                    'El documento "%s" de la dispensación %s requiere hallazgos estructurados.',
                    $documentName,
                    $facNro
                ));
            }

            $observation = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        $attachmentId = null;
        if (isset($decision['attachment_id']) && is_numeric($decision['attachment_id'])) {
            $attachmentId = (int) $decision['attachment_id'];
        } elseif (isset($decision['attachmentId']) && is_numeric($decision['attachmentId'])) {
            $attachmentId = (int) $decision['attachmentId'];
        } elseif (isset($decision['adj_dis_id']) && is_numeric($decision['adj_dis_id'])) {
            $attachmentId = (int) $decision['adj_dis_id'];
        } elseif (isset($decision['AdjDisId']) && is_numeric($decision['AdjDisId'])) {
            $attachmentId = (int) $decision['AdjDisId'];
        }

        return [
            'documentName' => $documentName,
            'approved' => $approved,
            'observation' => $observation,
            'attachmentId' => $attachmentId,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{
     *   DisId:string,FacNro:string,EstAud:int,EstadoDetallado:string,
     *   RequiereRevisionHumana:int,Severidad:string,Hallazgos:string,
     *   DetalleError:?string,DocumentosProcesados:int,DocumentoFallido:?string,
     *   DuracionProcesamientoMs:int,FacNitSec:string
     * }
     */
    private function normalizeAuditResultData(array $data): array
    {
        $disId = trim((string) ($data['DisId'] ?? ''));
        $facNro = trim((string) ($data['FacNro'] ?? ''));
        if ($disId === '' || $facNro === '') {
            throw new \InvalidArgumentException(
                'DisId y FacNro son obligatorios para persistir la auditoría.'
            );
        }

        $hallazgos = (string) ($data['Hallazgos'] ?? '');
        json_decode($hallazgos, true);
        if ($hallazgos === '' || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Hallazgos debe contener JSON válido.');
        }

        return [
            'DisId' => $disId,
            'FacNro' => $facNro,
            'EstAud' => (int) ($data['EstAud'] ?? 0),
            'EstadoDetallado' => (string) ($data['EstadoDetallado'] ?? ''),
            'RequiereRevisionHumana' => (int) ($data['RequiereRevisionHumana'] ?? 0),
            'Severidad' => (string) ($data['Severidad'] ?? ''),
            'Hallazgos' => $hallazgos,
            'DetalleError' => self::nullableString($data['DetalleError'] ?? null),
            'DocumentosProcesados' => max(0, (int) ($data['DocumentosProcesados'] ?? 0)),
            'DocumentoFallido' => self::nullableString($data['DocumentoFallido'] ?? null),
            'DuracionProcesamientoMs' => max(0, (int) ($data['DuracionProcesamientoMs'] ?? 0)),
            'FacNitSec' => trim((string) ($data['FacNitSec'] ?? '')),
        ];
    }

    private static function bindNullableString(
        PDOStatement $statement,
        string $placeholder,
        ?string $value
    ): void {
        $statement->bindValue(
            $placeholder,
            $value,
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function invalidateResultCache(string $scope): void
    {
        Cache::invalidateQueryResults($scope);
    }
}
