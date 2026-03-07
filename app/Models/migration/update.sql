/*
AUDITORIA RECHAZADA
*/
UPDATE AdjuntosDispensacion
SET
    AdjDisObsRec     = 'AUDITORIA RECHAZADA POR IA',
    RecConSopCod     = '30',
    AdjDisRec        = 'S',
    AdjDisEstSop     = 'R',
    AdjDisUsuRec     = 'Z-IA',
    AdjDisFecRec     = GETDATE(),
    AdjDisUsuAudi    = 'Z-IA',
    AdJDisFecAudi    = GETDATE()
WHERE
    DisId = @DisId -- '90649421'
    AND DisDetId = @DisDetId -- '1'
    AND AdjDisId = @AdjDisId -- '1';


/*
AUDITORIA APROBADA
*/
UPDATE AdjuntosDispensacion
SET
    AdjDisObsRec     = NULL,
    RecConSopCod     = NULL,
    AdjDisEstSop     = 'C',
    AdjDisUsuAudi    = 'Z-IA',
    AdJDisFecAudi    = GETDATE(),
    AdjDisRec        = 'N',
    AdjDisUsuRec     = NULL,
    AdjDisFecRec     = NULL
WHERE
    DisId = @DisId -- '90649421'
    AND DisDetId = @DisDetId -- '1'
    AND AdjDisId = @AdjDisId -- '1';