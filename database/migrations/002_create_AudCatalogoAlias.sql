-- =============================================================
-- Aliases de nombres de campo — AudFact
-- =============================================================
-- Tabla: Discolnet.dbo.AudCatalogoAlias
-- Reemplaza: constante FIELD_ALIASES en FieldClassifier.php
--
-- Propósito: mapear nombres SQL legacy / nombres de la BD de dispensación
-- al nombre canónico usado por el pipeline de auditoría.
--
-- Uso principal:
--   - AudDispCampo.CampoNombre puede traer "DocumentoPaciente" (alias SQL)
--   - FieldClassifier.normalizeField() lo convierte a "NumeroIdentificacion"
--   - Con esta tabla, la normalización es dinámica y extensible desde la UI
-- =============================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.objects
    WHERE name = 'AudCatalogoAlias' AND type = 'U'
      AND schema_id = SCHEMA_ID('dbo')
      AND DB_NAME() = 'Discolnet'
)
BEGIN
    CREATE TABLE Discolnet.dbo.AudCatalogoAlias (
        Alias       VARCHAR(100) NOT NULL,
        CampoNombre VARCHAR(100) NOT NULL,
        FecCre      DATETIME     NOT NULL DEFAULT GETDATE(),
        CONSTRAINT PK_AudCatalogoAlias   PRIMARY KEY (Alias),
        CONSTRAINT FK_AudCatalogoAlias_Campo
            FOREIGN KEY (CampoNombre)
            REFERENCES Discolnet.dbo.AudCatalogoCampo (CampoNombre)
    );

    CREATE INDEX IX_AudCatalogoAlias_Campo
        ON Discolnet.dbo.AudCatalogoAlias (CampoNombre);

    PRINT 'Tabla AudCatalogoAlias creada';
END
ELSE
    PRINT 'Tabla AudCatalogoAlias ya existe — omitida';
GO

-- =============================================================
-- SEED: aliases provenientes de vw_discolnet_dispensas
-- y de los nombres históricos usados en AudDispCampo
-- =============================================================
MERGE Discolnet.dbo.AudCatalogoAlias AS t
USING (VALUES
    -- Aliases del modelo SQL (columna original → nombre canónico)
    ('DocumentoPaciente',    'NumeroIdentificacion'),
    ('TipoDocumentoPaciente','TipoIdentificacion'),
    ('NumeroAutorizacion',   'Autorizacion'),
    ('Cliente',              'Cliente.Entidad'),
    ('RegimenPaciente',      'Cliente.Regimen'),
    -- Aliases adicionales de variantes en documentos / extracción Gemini
    ('Paciente',             'NombrePaciente'),
    ('NombreMedico',         'Medico'),
    ('FechaDispensa',        'FechaEntrega'),
    ('CodigoCUM',            'CUM')
) AS s (Alias, CampoNombre)
ON t.Alias = s.Alias
WHEN NOT MATCHED THEN
    INSERT (Alias, CampoNombre)
    VALUES (s.Alias, s.CampoNombre);

PRINT 'Seed AudCatalogoAlias: OK';
PRINT '';
PRINT 'Total aliases registrados:';
SELECT a.Alias, a.CampoNombre
FROM Discolnet.dbo.AudCatalogoAlias a
ORDER BY a.CampoNombre, a.Alias;
GO
