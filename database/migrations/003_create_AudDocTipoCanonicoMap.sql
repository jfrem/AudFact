-- =============================================================
-- Mapeo de documentos por cliente a tipos canónicos — AudFact
-- =============================================================
-- Tabla: Discolnet.dbo.AudDocTipoCanonicoMap
--
-- Problema que resuelve:
--   NitDocumentos.NitMedDocNom tiene nombres libres por cliente:
--   "DISPENSA", "FORMULA MEDICA", "VALIDADOR DE DERECHOS", etc.
--   El pipeline de auditoría necesita un tipo canónico estable
--   para que AuditConfigModel, RuleEngine y ExtractionResponseSchema
--   hablen el mismo idioma al buscar campos en documentos extraídos.
--
-- Tipos canónicos definidos:
--   FORMULA_MEDICA        — Fórmula / receta médica
--   AUTORIZACION          — Autorización de servicios / EPS
--   ACTA_DE_ENTREGA       — Acta de entrega / dispensa / soporte de entrega
--   FACTURA               — Factura de venta
--   VALIDADOR_DERECHOS    — Validador / verificador de derechos del paciente
--
-- Instrucción para nuevos clientes:
--   Insertar una fila por cada NitMedDocId del cliente con su TipoCanonicoDoc.
--   Si el tipo canónico no existe aún, definirlo aquí con un comentario.
-- =============================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.objects
    WHERE name = 'AudDocTipoCanonicoMap' AND type = 'U'
      AND schema_id = SCHEMA_ID('dbo')
      AND DB_NAME() = 'Discolnet'
)
BEGIN
    CREATE TABLE Discolnet.dbo.AudDocTipoCanonicoMap (
        NitSec          VARCHAR(20) NOT NULL,
        NitMedDocId     INT         NOT NULL,
        TipoCanonicoDoc VARCHAR(50) NOT NULL,
        Descripcion     VARCHAR(200) NULL,
        FecCre          DATETIME    NOT NULL DEFAULT GETDATE(),
        CONSTRAINT PK_AudDocTipoCanonicoMap PRIMARY KEY (NitSec, NitMedDocId)
    );

    CREATE INDEX IX_AudDocTipoCanonicoMap_Tipo
        ON Discolnet.dbo.AudDocTipoCanonicoMap (TipoCanonicoDoc);

    PRINT 'Tabla AudDocTipoCanonicoMap creada';
END
ELSE
    PRINT 'Tabla AudDocTipoCanonicoMap ya existe — omitida';
GO

-- =============================================================
-- SEED: cliente 2426 — POSITIVA COMPAÑIA DE SEGUROS
-- Fuente: GET /clients/2426/documents
-- =============================================================
MERGE Discolnet.dbo.AudDocTipoCanonicoMap AS t
USING (VALUES
    ('2426', 1, 'ACTA_DE_ENTREGA', 'DISPENSA — soporte físico de entrega al paciente'),
    ('2426', 2, 'AUTORIZACION',    'AUTORIZACION — autorización de servicios EPS'),
    ('2426', 3, 'FORMULA_MEDICA',  'FORMULA MEDICA — receta del médico prescriptor')
) AS s (NitSec, NitMedDocId, TipoCanonicoDoc, Descripcion)
ON t.NitSec = s.NitSec AND t.NitMedDocId = s.NitMedDocId
WHEN NOT MATCHED THEN
    INSERT (NitSec, NitMedDocId, TipoCanonicoDoc, Descripcion)
    VALUES (s.NitSec, s.NitMedDocId, s.TipoCanonicoDoc, s.Descripcion);

PRINT 'Seed cliente 2426: OK';
GO

-- =============================================================
-- SEED: cliente 1165 — EPS SANITAS
-- Fuente: GET /clients/1165/documents
-- NitMedDocId=4 (VALIDADOR DE DERECHOS) — nuevo tipo canónico.
-- NitMedDocId=5 (AUTORIZACION DE SERVICIOS) — mapea a AUTORIZACION.
-- =============================================================
MERGE Discolnet.dbo.AudDocTipoCanonicoMap AS t
USING (VALUES
    ('1165', 1, 'ACTA_DE_ENTREGA',     'ACTA DE ENTREGA — soporte físico de entrega'),
    ('1165', 3, 'FORMULA_MEDICA',      'FORMULA MEDICA — receta del médico prescriptor'),
    ('1165', 4, 'VALIDADOR_DERECHOS',  'VALIDADOR DE DERECHOS — verificación de cobertura EPS'),
    ('1165', 5, 'AUTORIZACION',        'AUTORIZACION DE SERVICIOS — autorización de la EPS')
) AS s (NitSec, NitMedDocId, TipoCanonicoDoc, Descripcion)
ON t.NitSec = s.NitSec AND t.NitMedDocId = s.NitMedDocId
WHEN NOT MATCHED THEN
    INSERT (NitSec, NitMedDocId, TipoCanonicoDoc, Descripcion)
    VALUES (s.NitSec, s.NitMedDocId, s.TipoCanonicoDoc, s.Descripcion);

PRINT 'Seed cliente 1165: OK';
GO

-- =============================================================
-- SEED: cliente 3080 — ASMET SALUD EPS SAS
-- Pendiente: ejecutar GET /clients/3080/documents y completar.
-- =============================================================
-- INSERT INTO Discolnet.dbo.AudDocTipoCanonicoMap ...
PRINT 'Seed cliente 3080: PENDIENTE — completar con GET /clients/3080/documents';
GO

-- =============================================================
-- Verificación final
-- =============================================================
PRINT '';
PRINT 'Mapeos configurados por cliente:';
SELECT
    m.NitSec,
    m.NitMedDocId,
    m.TipoCanonicoDoc,
    m.Descripcion
FROM Discolnet.dbo.AudDocTipoCanonicoMap m
ORDER BY m.NitSec, m.NitMedDocId;
GO

-- =============================================================
-- INSTRUCCIÓN PARA NUEVOS CLIENTES
-- =============================================================
-- Al incorporar un nuevo cliente al sistema de auditoría:
--   1. Consultar GET /clients/{nitSec}/documents
--   2. Para cada NitMedDocId, identificar el TipoCanonicoDoc correspondiente
--   3. Insertar las filas aquí o desde la UI de administración
--
-- Si el tipo de documento no tiene equivalente canónico,
-- definir uno nuevo en este archivo con su descripción
-- y notificar al equipo para actualizar ExtractionResponseSchema.
-- =============================================================
