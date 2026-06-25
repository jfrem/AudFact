/*
   Migracion de referencia para Discolnet.dbo.AudDispEst.
   Alineada con el esquema productivo donde FacNro es la llave primaria.
   No ejecutar en produccion sin respaldo y ventana de mantenimiento.
*/

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[AudDispEst]') AND type in (N'U'))
BEGIN
    DROP TABLE [dbo].[AudDispEst];
END
GO

CREATE TABLE [dbo].[AudDispEst] (
    [FacSec]                  NVARCHAR(320) NOT NULL, -- Columna legacy: almacena DisId
    [FacNro]                  NVARCHAR(100) NOT NULL, -- DisDetNro/Dispensa auditada; PK real
    [EstAud]                  BIT           NOT NULL DEFAULT 0,
    [EstadoDetallado]         VARCHAR(50)   NULL,
    [RequiereRevisionHumana]  BIT           NOT NULL DEFAULT 0,
    [Severidad]               VARCHAR(20)   NULL,
    [Hallazgos]               NVARCHAR(MAX) NULL,
    [DetalleError]            NVARCHAR(MAX) NULL,
    [DocumentosProcesados]    INT           NOT NULL DEFAULT 0,
    [DocumentoFallido]        VARCHAR(255)  NULL,
    [DuracionProcesamientoMs] INT           NOT NULL DEFAULT 0,
    [FacNitSec]               VARCHAR(100)  NULL,
    [FechaCreacion]           DATETIME      NOT NULL DEFAULT GETDATE(),
    [FechaActualizacion]      DATETIME      NOT NULL DEFAULT GETDATE(),
    [JobId]                   VARCHAR(50)   NULL,

    CONSTRAINT [PK_AudDispEst] PRIMARY KEY CLUSTERED ([FacNro] ASC)
);
GO

CREATE INDEX [IX_AudDispEst_FacSec] ON [dbo].[AudDispEst] ([FacSec]);
GO

CREATE TRIGGER [TR_AudDispEst_UpdateDate]
ON [dbo].[AudDispEst]
AFTER UPDATE
AS
BEGIN
    UPDATE target
    SET [FechaActualizacion] = GETDATE()
    FROM [dbo].[AudDispEst] target
    INNER JOIN inserted ON target.[FacNro] = inserted.[FacNro];
END;
GO
