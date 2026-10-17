-- =============================================================================
-- Migración: Creación de tabla para control de costos IA (AudDispCosto)
-- Base de datos: Discolnet.dbo
-- =============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'AudDispCosto' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE Discolnet.dbo.AudDispCosto (
        [AudCostoId]             BIGINT IDENTITY(1, 1) NOT NULL,
        [FacNro]                 VARCHAR(100) NOT NULL,
        [FacNitSec]              VARCHAR(100) NOT NULL,
        [FacSec]                 NVARCHAR(320) NULL,           -- DisId (llave legacy de dispensación)
        [Intento]                INT NOT NULL DEFAULT 1,       -- Consecutivo: 1 = Inicial, 2 = 1ra reauditoría, etc.
        [TokensEntrada]          BIGINT NOT NULL DEFAULT 0,
        [TokensSalida]           BIGINT NOT NULL DEFAULT 0,
        [TokensPensamiento]      BIGINT NOT NULL DEFAULT 0,
        [TotalTokens]            BIGINT NOT NULL DEFAULT 0,
        [ModeloIA]               VARCHAR(50) NOT NULL DEFAULT 'gemini-3.7-flash',
        [TarifaEntrada1M]        DECIMAL(10, 4) NOT NULL DEFAULT 0.7500, -- Tarifa unitaria vigente en USD
        [TarifaSalida1M]         DECIMAL(10, 4) NOT NULL DEFAULT 3.7500, -- Tarifa unitaria vigente en USD
        [CostoUSD]               DECIMAL(18, 8) NOT NULL,                -- Costo liquidado en USD
        [TrmAplicada]            DECIMAL(10, 2) NOT NULL DEFAULT 0.00,   -- TRM oficial vigente al momento de auditar (ej. 3194.44)
        [CostoCOP]               DECIMAL(18, 4) NOT NULL DEFAULT 0.0000, -- Costo liquidado en COP (CostoUSD * TrmAplicada)
        [DocsProcesados]         INT NOT NULL DEFAULT 0,
        [DocsCacheHit]           INT NOT NULL DEFAULT 0,
        [EsCacheHitTotal]        BIT NOT NULL DEFAULT 0,       -- 1 si la extracción provino 100% de caché (costo $0)
        [FechaAuditoria]         DATETIME2 NOT NULL DEFAULT GETDATE(),

        CONSTRAINT PK_AudDispCosto PRIMARY KEY CLUSTERED ([AudCostoId]),
        CONSTRAINT UQ_AudDispCosto_Fac_Intento UNIQUE ([FacNro], [Intento])
    );

    CREATE NONCLUSTERED INDEX IX_AudDispCosto_Fecha
    ON Discolnet.dbo.AudDispCosto ([FechaAuditoria] DESC)
    INCLUDE ([FacNro], [FacNitSec], [CostoUSD], [CostoCOP], [TrmAplicada], [TotalTokens], [Intento]);

    CREATE NONCLUSTERED INDEX IX_AudDispCosto_Cliente
    ON Discolnet.dbo.AudDispCosto ([FacNitSec], [FechaAuditoria] DESC)
    INCLUDE ([CostoUSD], [CostoCOP]);

    CREATE NONCLUSTERED INDEX IX_AudDispCosto_FacNro
    ON Discolnet.dbo.AudDispCosto ([FacNro], [Intento] DESC);
END;
