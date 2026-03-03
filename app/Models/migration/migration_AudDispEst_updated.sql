/* 
   Migración para la tabla AudDispEst
   Basado en análisis de respuesta de IA (D14251101574.json)
*/

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[AudDispEst]') AND type in (N'U'))
BEGIN
    DROP TABLE [dbo].[AudDispEst];
END
GO

CREATE TABLE [dbo].[AudDispEst] (
    [FacSec]                  NVARCHAR(50)  NOT NULL, -- Secuencia única de la factura (Alfanumérico según JSON)
    [FacNro]                  NVARCHAR(50)  NOT NULL, -- Número literal de la factura
    [EstAud]                  BIT           NOT NULL DEFAULT 0, -- 0: Pendiente, 1: Procesado
    [EstadoDetallado]         VARCHAR(50)   NULL,     -- Ej: 'warning', 'success', 'error'
    [RequiereRevisionHumana]  BIT           NOT NULL DEFAULT 0, -- Indica si requiere revisión humana
    [Severidad]               VARCHAR(20)   NULL,     -- Ej: 'low', 'medium', 'high'
    [Hallazgos]               NVARCHAR(MAX) NULL,     -- Resumen de discrepancias detectadas
    [DetalleError]            NVARCHAR(MAX) NULL,     -- Detalles técnicos o errores de API
    [DocumentosProcesados]    INT           NOT NULL DEFAULT 0, -- Cantidad de documentos procesados
    [DocumentoFallido]        VARCHAR(255)  NULL,     -- Documento específico que falló o tuvo alerta
    [DuracionProcesamientoMs] INT           NOT NULL DEFAULT 0, -- Tiempo en ms que tardo la auditoria
    [FacNitSec]               VARCHAR(100)  NULL,     -- Identificador NIT + Secuencia
    [FechaCreacion]           DATETIME      NOT NULL DEFAULT GETDATE(), -- Fecha de creacion del registro
    [FechaActualizacion]      DATETIME      NOT NULL DEFAULT GETDATE(), -- Fecha de actualizacion del registro
    
    CONSTRAINT [PK_AudDispEst] PRIMARY KEY CLUSTERED ([FacSec] ASC)
);
GO

/*
Pendiente de implementar:
*/

-- Índice para búsquedas por número de factura
CREATE INDEX [IX_AudDispEst_FacNro] ON [dbo].[AudDispEst] ([FacNro]);
GO

-- Trigger para actualizar FechaActualizacion
CREATE TRIGGER [TR_AudDispEst_UpdateDate]
ON [dbo].[AudDispEst]
AFTER UPDATE
AS
BEGIN
    UPDATE [dbo].[AudDispEst]
    SET [FechaActualizacion] = GETDATE()
    FROM [dbo].[AudDispEst]
    INNER JOIN inserted ON [dbo].[AudDispEst].[FacSec] = inserted.[FacSec];
END;
GO
