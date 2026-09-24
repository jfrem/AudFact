-- =============================================================
-- Migración: Agregar columna DiasVigencia a Discolnet.dbo.AudDisp
-- =============================================================
-- Tabla: Discolnet.dbo.AudDisp
--
-- Propósito:
--   Permitir la configuración dinámica del plazo en días de vigencia
--   para la entrega de medicamentos o servicios por cliente / aseguradora.
--
-- Comportamiento y Fallback:
--   - Nivel 1: Evidencia visual extraída del documento (Gemini).
--   - Nivel 2: AudDisp.DiasVigencia configurado para el cliente.
--   - Nivel 3: Fallback global del sistema (60 días).
--
-- Idempotencia:
--   Si la columna existe, conserva su definición y sus valores.
--   Si falta, agrega una columna nullable con DEFAULT 60.
--   NULL explícito no activa ese DEFAULT: el evaluador aplica 60 días
--   y conserva la distinción entre fallback global y plazo del cliente.
--   Verificar/aplicar antes de desplegar los lectores de DiasVigencia.
--   Rollback de aplicación: conservar esta columna y volver a la imagen previa.
-- =============================================================

IF COL_LENGTH('Discolnet.dbo.AudDisp', 'DiasVigencia') IS NULL
BEGIN
    ALTER TABLE Discolnet.dbo.AudDisp
    ADD DiasVigencia INT NULL CONSTRAINT DF_AudDisp_DiasVigencia DEFAULT 60;

    PRINT 'Columna DiasVigencia agregada exitosamente a Discolnet.dbo.AudDisp.';
END
ELSE
BEGIN
    PRINT 'La columna DiasVigencia ya existe en Discolnet.dbo.AudDisp. No se requieren cambios DDL.';
END
GO
