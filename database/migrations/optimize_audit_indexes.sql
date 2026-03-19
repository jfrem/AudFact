-- =============================================================
-- Índices Optimizados para Auditoría Histórica — AudFact v3.0
-- =============================================================
-- Requiere: Acceso DBA a SQL Server de producción.
-- Tablas: dbo.AudDispEst, dbo.AdjuntosDispensacionDetalle
-- Objetivo: Optimizar consultas de paginación y filtrado
--           frecuentes en GET /audit/results y GET /audit/documents-history
-- =============================================================

-- 1. Índice compuesto: búsquedas por NIT + rango de fecha (consulta más frecuente)
--    Cubre: searchAudits(), countAudits() en AuditStatusModel
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_AudDispEst_NitFecha'
    AND object_id = OBJECT_ID('dbo.AudDispEst')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_AudDispEst_NitFecha
    ON dbo.AudDispEst (FacNitSec, FechaCreacion DESC)
    INCLUDE (EstAud, Severidad, EstadoDetallado, RequiereRevisionHumana, FacNro);

    PRINT 'Índice IX_AudDispEst_NitFecha creado exitosamente';
END
ELSE
    PRINT 'Índice IX_AudDispEst_NitFecha ya existe — omitido';
GO

-- 2. Índice para búsquedas por número de factura (FacNro)
--    Cubre: búsquedas individuales por número de dispensa
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_AudDispEst_FacNro'
    AND object_id = OBJECT_ID('dbo.AudDispEst')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_AudDispEst_FacNro
    ON dbo.AudDispEst (FacNro)
    INCLUDE (EstAud, Severidad, FacNitSec, FechaCreacion);

    PRINT 'Índice IX_AudDispEst_FacNro creado exitosamente';
END
ELSE
    PRINT 'Índice IX_AudDispEst_FacNro ya existe — omitido';
GO

-- 3. Índice para consultas de severidad (dashboard / estadísticas)
--    Cubre: filtrado por severidad en reportes
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_AudDispEst_Severidad'
    AND object_id = OBJECT_ID('dbo.AudDispEst')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_AudDispEst_Severidad
    ON dbo.AudDispEst (Severidad, FechaCreacion DESC)
    INCLUDE (FacNro, FacNitSec, EstAud, EstadoDetallado);

    PRINT 'Índice IX_AudDispEst_Severidad creado exitosamente';
END
ELSE
    PRINT 'Índice IX_AudDispEst_Severidad ya existe — omitido';
GO

-- 4. Índice para historial de documentos auditados por IA
--    Cubre: getAuditHistory(), countAuditHistory() en AttachmentsModel
--    Tabla correcta: AdjuntosDispensacion (NO AdjuntosDispensacionDetalle)
--    WHERE: AdjDisUsuAudi = 'Z-IA' OR AdjDisUsuRec = 'Z-IA'
--    ORDER BY: AdJDisFecAudi DESC
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_AdjDis_AuditHistory'
    AND object_id = OBJECT_ID('dbo.AdjuntosDispensacion')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_AdjDis_AuditHistory
    ON dbo.AdjuntosDispensacion (AdjDisUsuAudi, AdJDisFecAudi DESC)
    INCLUDE (DisId, DisDetId, AdjDisId, AdjDisNom, AdjDisEstSop, AdjDisObsRec, AdjDisUsuRec);

    PRINT 'Índice IX_AdjDis_AuditHistory creado exitosamente';
END
ELSE
    PRINT 'Índice IX_AdjDis_AuditHistory ya existe — omitido';
GO

-- =============================================================
-- NOTAS:
-- - Ejecutar en horario de baja carga (noche/madrugada).
-- - Monitorear impacto en espacio disco (~50-100 MB estimado para 700K registros).
-- - Si la tabla tiene > 1M registros, considerar ONLINE = ON:
--   CREATE ... WITH (ONLINE = ON)  -- Solo Enterprise Edition
-- - Revisar plan de ejecución post-creación:
--   SET STATISTICS IO ON;
--   SELECT TOP 100 * FROM AudDispEst WHERE FacNitSec = '2426'
--     AND FechaCreacion BETWEEN '2026-01-01' AND '2026-03-31';
-- =============================================================
