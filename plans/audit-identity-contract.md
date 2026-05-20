# Contrato de Identidad de Auditoria

Este documento define las llaves que deben permanecer alineadas durante todo el flujo E2E de auditoria. Es la referencia para evitar mezclar identificadores de factura, dispensacion y adjuntos.

## Contrato Canonico

```text
Factura.FacSec == vw_discolnet_dispensas.facsecF == AudDispEst.FacSec
DisDetNro == vw_discolnet_dispensas.Dispensa == AudDispEst.FacNro
```

`vw_discolnet_dispensas.facsec` no es la llave canonica de auditoria. No debe mapearse como `FacSec` ni usarse para filtrar o persistir resultados en `AudDispEst`.

## Roles de Cada Identificador

| Identificador | Fuente | Uso correcto |
|---|---|---|
| `Factura.FacSec` | Tabla `Factura` | Llave canonica seleccionada por el batch. |
| `facsecF` | Vista `vw_discolnet_dispensas` | Version de la FDV equivalente a `Factura.FacSec`; se expone como `FacSec`. |
| `FacSec` | Contrato PHP / Redis / `AudDispEst` | Llave de auditoria y de persistencia final. |
| `DisDetNro` | `DispensacionDetalleServicio` | Llave operativa de la dispensacion usada por endpoints y workers. |
| `Dispensa` | Vista `vw_discolnet_dispensas` | Equivalente FDV de `DisDetNro`; se expone como `NumeroFactura`. |
| `FacNro` | `AudDispEst` | Almacena el `DisDetNro` auditado para busqueda operativa. |
| `facsec` | Vista `vw_discolnet_dispensas` | Identificador legacy/de agrupacion; no es llave de auditoria. |

## Flujo E2E

1. `InvoicesModel::getInvoices()` selecciona `tb3.FacSec AS FacSec` y `tb2.DisDetNro AS Dispensa`.
2. `AuditBatchOrchestrator` inicializa Redis con `fac_sec = FacSec` y `dis_det_nro = Dispensa`.
3. `DocumentAuditOrchestrator` resuelve la FDV por `dis_det_nro`.
4. `DispensationModel::getDispensationData()` expone `facsecF AS FacSec` y `Dispensa AS NumeroFactura`.
5. `DocumentAuditOrchestrator` valida el contrato:
   - si el evento trae `fac_sec`, debe coincidir con `FDV.header.FacSec`;
   - `payload.dis_det_nro` debe coincidir con `FDV.header.NumeroFactura`;
   - si el evento trae `fac_nit_sec`, debe coincidir con `FDV.header.NitSec`.
6. `AuditAggregationWorker` persiste `FacSec = audit.fac_sec` y `FacNro = audit.dis_det_nro`.
7. `AuditStatusModel` hace `MERGE` con `ON target.FacSec = source.FacSec`.

## Adjuntos

Los adjuntos no se resuelven por `FacSec`. Se resuelven por `DisDetNro`, que permite obtener `DisId + DisDetId` y llegar a `AdjuntosDispensacion`.

Esto no contradice la llave canonica: `FacSec` identifica la auditoria; `DisDetNro` identifica la entrega/documentos.

## Fallos Esperados

Si el batch envia una factura y la FDV devuelve otra identidad, el orquestador debe fallar con:

```text
AUDIT_IDENTITY_MISMATCH
```

Ese fallo debe ir a retry/DLQ segun el mecanismo normal del pipeline, porque indica datos incongruentes o una consulta que rompio el contrato.
