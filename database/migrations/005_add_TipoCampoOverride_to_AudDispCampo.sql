-- =============================================================
-- Migración: 005_add_TipoCampoOverride_to_AudDispCampo.sql
-- Base de datos: Discolnet
-- Propósito: Añade columna nullable para override de tipo de
--            comparación por documento (patrón análogo a
--            DescripcionOverride y SeveridadOverride).
-- Idempotente: Sí — verifica existencia antes de crear.
-- =============================================================

IF COL_LENGTH('Discolnet.dbo.AudDispCampo', 'TipoCampoOverride') IS NULL
BEGIN
    ALTER TABLE Discolnet.dbo.AudDispCampo
    ADD TipoCampoOverride CHAR(1) NULL;

    PRINT 'Columna TipoCampoOverride agregada exitosamente a Discolnet.dbo.AudDispCampo.';
END
ELSE
BEGIN
    PRINT 'La columna TipoCampoOverride ya existe en Discolnet.dbo.AudDispCampo. Sin cambios.';
END
GO

-- Configuración de negocio: Medico en FORMULA MEDICA → TipoCampo 'P' (Presencia bilateral)
UPDATE ac
SET ac.TipoCampoOverride = 'P'
FROM Discolnet.dbo.AudDispCampo ac
INNER JOIN NitDocumentos nd 
    ON nd.NitSec = ac.FacNitSec 
   AND nd.NitMedDocId = ac.NitMedDocId
WHERE ac.CampoNombre = 'Medico' 
  AND nd.NitMedDocNom = 'FORMULA MEDICA';

PRINT 'Registros de Medico en FORMULA MEDICA actualizados a TipoCampoOverride = P.';
GO
