-- =============================================================
-- Catálogo global de campos auditables — AudFact
-- =============================================================
-- Tabla: Discolnet.dbo.AudCatalogoCampo
-- Reemplaza: constantes hardcodeadas en FieldClassifier.php
--
-- tipo_comparacion : exact | semantic | visual | business
-- columna_sql      : alias usado en vw_discolnet_dispensas (NULL si solo en docs)
-- doc_autoritativo : tipo canónico del documento principal del campo
-- doc_alternativos : JSON array de tipos canónicos alternativos
-- umbral_semantico : solo campos tipo=semantic (NULL para los demás)
-- auditable        : 0 = campo técnico interno, no aparece en documentos físicos
-- =============================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.objects
    WHERE name = 'AudCatalogoCampo' AND type = 'U'
      AND schema_id = SCHEMA_ID('dbo')
      AND parent_object_id = 0
      AND DB_NAME() = 'Discolnet'
)
BEGIN
    CREATE TABLE Discolnet.dbo.AudCatalogoCampo (
        CampoNombre       VARCHAR(100)  NOT NULL,
        TipoComparacion   VARCHAR(20)   NOT NULL
            CONSTRAINT CK_AudCatalogoCampo_Tipo
            CHECK (TipoComparacion IN ('exact','semantic','visual','business')),
        ColumnaSql        VARCHAR(100)  NULL,
        DocAutoritativo   VARCHAR(80)   NULL,
        DocAlternativos   NVARCHAR(500) NULL,
        SeveridadDefault  VARCHAR(10)   NOT NULL DEFAULT 'media'
            CONSTRAINT CK_AudCatalogoCampo_Sev
            CHECK (SeveridadDefault IN ('alta','media','baja')),
        UmbralSemantico   DECIMAL(4,3)  NULL,
        Auditable         BIT           NOT NULL DEFAULT 1,
        Activo            BIT           NOT NULL DEFAULT 1,
        FecCre            DATETIME      NOT NULL DEFAULT GETDATE(),
        FecMod            DATETIME      NOT NULL DEFAULT GETDATE(),
        CONSTRAINT PK_AudCatalogoCampo PRIMARY KEY (CampoNombre)
    );

    PRINT 'Tabla AudCatalogoCampo creada';
END
ELSE
    PRINT 'Tabla AudCatalogoCampo ya existe — omitida';
GO

-- =============================================================
-- SEED: campos tipo exact
-- =============================================================
MERGE Discolnet.dbo.AudCatalogoCampo AS t
USING (VALUES
    ('NumeroFactura',        'exact', 'NumeroFactura',        'FACTURA',        NULL,                                  'alta',  NULL,  1),
    ('NumeroFormula',        'exact', NULL,                   'FORMULA_MEDICA', '["FACTURA","AUTORIZACION"]',          'media', NULL,  1),
    ('Autorizacion',         'exact', 'NumeroAutorizacion',   'AUTORIZACION',   '["FORMULA_MEDICA","FACTURA"]',        'alta',  NULL,  1),
    ('TipoIdentificacion',   'exact', 'TipoDocumentoPaciente','FORMULA_MEDICA', NULL,                                  'media', NULL,  1),
    ('NumeroIdentificacion', 'exact', 'DocumentoPaciente',    'FORMULA_MEDICA', '["ACTA_DE_ENTREGA","AUTORIZACION"]',  'alta',  NULL,  1),
    ('FechaFormula',         'exact', 'FechaFormula',         'FORMULA_MEDICA', NULL,                                  'media', NULL,  1),
    ('FechaAutorizacion',    'exact', 'FechaAutorizacion',    'AUTORIZACION',   NULL,                                  'media', NULL,  1),
    ('FechaEntrega',         'exact', 'FechaEntrega',         'ACTA_DE_ENTREGA','["FACTURA"]',                        'media', NULL,  1),
    ('FechaVencimiento',     'exact', 'FechaVencimiento',     'FACTURA',        NULL,                                  'media', NULL,  1),
    ('VlrCobrado',           'exact', 'VlrCobrado',           'FACTURA',        NULL,                                  'alta',  NULL,  1),
    ('Mipres',               'exact', 'Mipres',               'FORMULA_MEDICA', NULL,                                  'baja',  NULL,  1),
    ('IdPrincipal',          'exact', 'IdPrincipal',          'FACTURA',        NULL,                                  'baja',  NULL,  1),
    ('IdDirec',              'exact', 'IdDirec',              'FACTURA',        NULL,                                  'baja',  NULL,  1),
    ('IdProg',               'exact', 'IdProg',               'FACTURA',        NULL,                                  'baja',  NULL,  1),
    ('IdEntr',               'exact', 'IdEntr',               'FACTURA',        NULL,                                  'baja',  NULL,  1),
    ('IdRepEnt',             'exact', 'IdRepEnt',             'FACTURA',        NULL,                                  'baja',  NULL,  1),
    ('Lote',                 'exact', 'Lote',                 'FACTURA',        NULL,                                  'baja',  NULL,  1),
    ('NITCliente',           'exact', 'NITCliente',           'AUTORIZACION',   NULL,                                  'media', NULL,  1),
    ('TipoDocumentoMedico',  'exact', 'TipoDocumentoMedico',  'FORMULA_MEDICA', NULL,                                  'media', NULL,  1),
    ('DocumentoMedico',      'exact', 'DocumentoMedico',      'FORMULA_MEDICA', NULL,                                  'media', NULL,  1),
    ('CodigoDiagnostico',    'exact', 'CodigoDiagnostico',    'FORMULA_MEDICA', NULL,                                  'media', NULL,  1),
    ('CodigoArticulo',       'exact', 'CodigoArticulo',       'FACTURA',        NULL,                                  'media', NULL,  1),
    ('CodigoProducto',       'exact', 'CodigoProducto',       'FACTURA',        NULL,                                  'media', NULL,  1),
    ('CUM',                  'exact', 'CUM',                  'FACTURA',        NULL,                                  'media', NULL,  1),
    ('Tipo',                 'exact', 'Tipo',                 'FACTURA',        NULL,                                  'media', NULL,  1),
    -- Campos presentes en AudDispCampo (clientes activos) pero ausentes del catálogo anterior
    ('FechaNacimiento',      'exact', 'FechaNacimiento',      'FORMULA_MEDICA', '["AUTORIZACION"]',                   'media', NULL,  1),
    ('IPS_NIT',              'exact', 'IPS_NIT',              'AUTORIZACION',   '["FACTURA"]',                        'media', NULL,  1),
    -- Campo técnico interno — no aparece en documentos físicos, no auditable
    ('IdFact',               'exact', 'IdFact',               NULL,             NULL,                                  'baja',  NULL,  0)
) AS s (CampoNombre, TipoComparacion, ColumnaSql, DocAutoritativo, DocAlternativos, SeveridadDefault, UmbralSemantico, Auditable)
ON t.CampoNombre = s.CampoNombre
WHEN NOT MATCHED THEN
    INSERT (CampoNombre, TipoComparacion, ColumnaSql, DocAutoritativo, DocAlternativos, SeveridadDefault, UmbralSemantico, Auditable)
    VALUES (s.CampoNombre, s.TipoComparacion, s.ColumnaSql, s.DocAutoritativo, s.DocAlternativos, s.SeveridadDefault, s.UmbralSemantico, s.Auditable);

PRINT 'Seed exact: OK';
GO

-- =============================================================
-- SEED: campos tipo semantic (con umbral de similitud coseno)
-- Umbrales calibrados para modelo gemini-embedding-001
-- =============================================================
MERGE Discolnet.dbo.AudCatalogoCampo AS t
USING (VALUES
    ('NombrePaciente',  'semantic', 'NombrePaciente', 'FORMULA_MEDICA', '["ACTA_DE_ENTREGA","AUTORIZACION"]', 'alta',  0.88),
    ('NombreArticulo',  'semantic', 'NombreArticulo',  'FORMULA_MEDICA', '["FACTURA","ACTA_DE_ENTREGA"]',      'alta',  0.82),
    ('Medico',          'semantic', 'Medico',           'FORMULA_MEDICA', '["AUTORIZACION"]',                  'media', 0.88),
    ('Laboratorio',     'semantic', 'Laboratorio',      'FACTURA',        NULL,                                'media', 0.85),
    ('IPS',             'semantic', 'IPS',              'AUTORIZACION',   NULL,                                'media', 0.80),
    ('Cliente.Entidad', 'semantic', 'Cliente',          'AUTORIZACION',   NULL,                                'media', 0.85)
) AS s (CampoNombre, TipoComparacion, ColumnaSql, DocAutoritativo, DocAlternativos, SeveridadDefault, UmbralSemantico)
ON t.CampoNombre = s.CampoNombre
WHEN NOT MATCHED THEN
    INSERT (CampoNombre, TipoComparacion, ColumnaSql, DocAutoritativo, DocAlternativos, SeveridadDefault, UmbralSemantico)
    VALUES (s.CampoNombre, s.TipoComparacion, s.ColumnaSql, s.DocAutoritativo, s.DocAlternativos, s.SeveridadDefault, s.UmbralSemantico);

PRINT 'Seed semantic: OK';
GO

-- =============================================================
-- SEED: campos tipo visual y business
-- =============================================================
MERGE Discolnet.dbo.AudCatalogoCampo AS t
USING (VALUES
    ('FirmaActaEntrega', 'visual',    NULL,               'ACTA_DE_ENTREGA', NULL, 'alta',  NULL),
    ('SelloRecepcion',   'visual',    NULL,               'ACTA_DE_ENTREGA', NULL, 'baja',  NULL),
    ('FirmaPrescriptor', 'visual',    NULL,               'FORMULA_MEDICA',  NULL, 'alta',  NULL),
    ('CantidadEntregada','business',  'CantidadEntregada','ACTA_DE_ENTREGA', '["FACTURA"]', 'alta', NULL),
    ('CantidadPrescrita','business',  'CantidadPrescrita','FORMULA_MEDICA',  NULL,           'alta', NULL),
    ('Cliente.Regimen',  'business',  'RegimenPaciente',  'AUTORIZACION',    NULL,           'alta', NULL)
) AS s (CampoNombre, TipoComparacion, ColumnaSql, DocAutoritativo, DocAlternativos, SeveridadDefault, UmbralSemantico)
ON t.CampoNombre = s.CampoNombre
WHEN NOT MATCHED THEN
    INSERT (CampoNombre, TipoComparacion, ColumnaSql, DocAutoritativo, DocAlternativos, SeveridadDefault, UmbralSemantico)
    VALUES (s.CampoNombre, s.TipoComparacion, s.ColumnaSql, s.DocAutoritativo, s.DocAlternativos, s.SeveridadDefault, s.UmbralSemantico);

PRINT 'Seed visual/business: OK';
PRINT '';
PRINT 'Total campos registrados:';
SELECT COUNT(*) AS TotalCampos, SUM(CAST(Auditable AS INT)) AS CamposAuditables
FROM Discolnet.dbo.AudCatalogoCampo;
GO
