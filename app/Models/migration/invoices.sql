SELECT TOP (:limit)
    d.NitSec,
    d.FacSec,
    d.Dispensa
FROM vw_discolnet_dispensas d
LEFT JOIN Discolnet.dbo.AudDispEst a WITH (NOLOCK) ON a.FacSec = d.FacSec
left join (select f.DisId,f.DisdetId,f.artsec,f.Documento,sum(f.KarUni)KarUni from vw_discolnet_facturas f with(nolock) where f.Fecha>= :date
    group by f.DisId,f.DisdetId,f.artsec,f.Documento
)f on f.DisId=d.facsec and f.DisdetId=d.DisDetId and f.artsec=d.artsec
WHERE d.Fecha_solicitud = :date
    AND d.NitSec = :facNitSec
    AND d.Tipo_servicio in ('POS','MIPRES')
    AND d.pendientes = 0
    AND d.estadodisp = 'A'
    AND (a.EstAud IS NULL)
GROUP BY d.NitSec, d.FacSec, d.Dispensa
having sum(isnull(f.KarUni,0))=0