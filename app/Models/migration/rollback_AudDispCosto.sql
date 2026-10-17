-- =============================================================================
-- Rollback: Eliminación de tabla AudDispCosto e índices asociados
-- Base de datos: Discolnet.dbo
-- =============================================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'AudDispCosto' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    DROP TABLE Discolnet.dbo.AudDispCosto;
END;
