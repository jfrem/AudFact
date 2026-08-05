SELECT DISTINCT TOP (:limit)
    d.NitSec,
    d.FacSec,
    d.Dispensa
FROM vw_discolnet_dispensas d
WHERE d.Fecha_solicitud = :date
    AND d.NitSec = :facNitSec
    AND d.Tipo_servicio in ('POS','MIPRES')
    AND d.pendientes = 0
    AND d.estadodisp = 'A'
    AND NOT EXISTS (
        SELECT 1
        FROM Discolnet.dbo.AudDispEst a WITH (NOLOCK)
        WHERE a.FacSec = d.FacSec
          AND a.EstAud IS NOT NULL
    )
    AND EXISTS (
        SELECT 1
        FROM AdjuntosDispensacion ad WITH (NOLOCK)
        WHERE ad.DisId = d.FacSec
          AND ad.DisDetId = d.DisDetId
          AND ad.AdjDisOpc = 'N'
    )
    AND EXISTS (
        SELECT 1
        FROM AdjuntosDispensacion ad WITH (NOLOCK)
        WHERE ad.DisId = d.FacSec
          AND ad.DisDetId = d.DisDetId
          AND ad.AdjDisOpc = 'N'
          AND ISNULL(ad.AdjDisEstSop, '') <> 'C'
    )
    AND NOT EXISTS (
        SELECT 1
        FROM vw_discolnet_facturas f WITH (NOLOCK)
        WHERE f.DisId = d.FacSec
          AND f.DisDetId = d.DisDetId
          AND f.artsec = d.artsec
          AND f.Fecha = :date
          AND ISNULL(f.KarUni, 0) <> 0
    )
