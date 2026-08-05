# Contrato de Identidad de Auditoría

Este documento define las llaves que deben permanecer alineadas durante todo el flujo E2E de auditoría. Es la referencia para evitar mezclar identificadores de dispensación y adjuntos.

## Contrato Canónico

```text
vw_discolnet_dispensas.DisId == AudDispEst.FacSec (columna legacy, almacena DisId)
DisDetNro == vw_discolnet_dispensas.Dispensa == AudDispEst.FacNro
```

`AudDispEst.FacNro` es la llave primaria operativa de resultados persistidos en la base productiva.

> **Deuda Técnica**: La columna `AudDispEst.FacSec` almacena el valor lógico `DisId` sin renombrarse. Los registros históricos escritos con valores `FacSec` reales antes de este cambio **no están garantizados** sin un backfill futuro.

## Roles de Cada Identificador

| Identificador | Fuente | Uso correcto |
|---|---|---|
| `DisId` | Vista `vw_discolnet_dispensas` | Llave canónica de auditoría. Identifica la dispensación que se audita. |
| `dis_id` | Contrato PHP / Redis (pipeline snake_case) | Alias snake_case de `DisId` en eventos y payloads internos. |
| `AudDispEst.FacSec` | Tabla `AudDispEst` (columna legacy) | Almacena el `DisId`; no renombrada por decisión operativa. |
| `DisDetNro` | `DispensacionDetalleServicio` | Llave operativa de la dispensación usada por endpoints y workers. |
| `Dispensa` | Vista `vw_discolnet_dispensas` | Equivalente FDV de `DisDetNro`; se expone como `NumeroFactura`. |
| `dis_det_nro` | Contrato PHP / Redis (pipeline snake_case) | Alias snake_case de `Dispensa` en eventos internos. |
| `FacNro` | `AudDispEst` | Almacena el `DisDetNro` auditado y es la llave primaria operativa de resultados persistidos. |
| `facsec` | Vista `vw_discolnet_dispensas` | Identificador legacy/de agrupación; **no** es llave de auditoría. |

## Flujo E2E

1. `InvoicesModel::getInvoicesForAuditBatch()` selecciona `tb2.DisId AS DisId` y `tb2.DisDetNro AS Dispensa`.
2. `AuditBatchOrchestrator` inicializa Redis con `dis_id = DisId` y `dis_det_nro = Dispensa`.
3. `POST /audit/single` recibe `disId`, resuelve la FDV por `DisId` y deriva `DisDetNro` desde `NumeroFactura`.
4. `DocumentAuditOrchestrator` resuelve la FDV por `dis_id` + `dis_det_nro`; valida identidad cruzada.
5. `DispensationModel::getDispensationData()` acepta filtros via whitelist (`dis_id` → `DisId`, `dis_det_nro` → `Dispensa`, `DisId` → `DisId`, `Dispensa` → `Dispensa`) y expone `DisId AS DisId` y `Dispensa AS NumeroFactura`.
6. `DocumentAuditOrchestrator` valida el contrato:
   - `payload.dis_id` debe coincidir con `FDV.header.DisId`;
   - `payload.dis_det_nro` debe coincidir con `FDV.header.NumeroFactura`;
   - si el evento trae `fac_nit_sec`, debe coincidir con `FDV.header.NitSec`.
7. `AuditPersistenceWorker` entrega el outcome a `AuditResultPersistenceModel`, que persiste `FacSec = audit.dis_id` (columna legacy) y `FacNro = audit.dis_det_nro`.
8. `AuditResultPersistenceModel` ejecuta un upsert serializable por `FacNro`; en cada escritura conserva `FacSec = audit.dis_id` como columna legacy que almacena `DisId`.

## Adjuntos

Los adjuntos no se resuelven por `DisId`. Se resuelven por `DisDetNro`, que permite obtener `DisId + DisDetId` y llegar a `AdjuntosDispensacion`.

Esto no contradice la identidad interna: `DisId` se conserva en `FacSec` para trazabilidad e idempotencia, y `DisDetNro`/`FacNro` identifica la fila persistida y los documentos de la entrega.

## Fallos Esperados

Si un evento envía una dispensación y la FDV devuelve otra identidad, el orquestador debe fallar con:

```text
AUDIT_IDENTITY_MISMATCH
```

Ese fallo debe ir a retry/DLQ según el mecanismo normal del pipeline, porque indica datos incongruentes o una consulta que rompió el contrato.
