# Feature: Pipeline de Auditoría IA Event-Driven

## Descripción

Pipeline distribuido que audita facturas de dispensación farmacéutica usando `FacSec` como identidad canónica, documentos adjuntos como evidencia y Google Gemini API para extracción multimodal. El procesamiento pesado no corre en el request HTTP: se encola en Redis Streams y lo ejecutan workers independientes.

## Fuentes de Verdad

- Rutas: `app/Routes/web.php`
- Arquitectura técnica: `plans/architecture.md`
- Flujo secuencial: `plans/data-flows.md`
- Contrato de identidad: `plans/audit-identity-contract.md`
- Skill operativa: `.agent/skills/audfact-audit-gemini/SKILL.md`

## Endpoints

| Método | Ruta | Controlador | Descripción |
|---|---|---|---|
| `POST` | `/audit/single` | `AuditController::single` | Encola una auditoría individual por `FacSec` |
| `POST` | `/audit/async` | `AuditController::async` | Encola un batch por cliente/rango y responde `202` |
| `GET` | `/audit/status/{auditId}` | `AuditController::status` | Estado Redis de una auditoría individual |
| `GET` | `/audit/jobs/{jobId}` | `AuditController::jobStatus` | Estado y progreso de un job batch |
| `GET` | `/audit/results` | `AuditController::results` | Resumen paginado de auditorías persistidas |
| `GET` | `/audit/results/{facSec}` | `AuditController::resultDetail` | Detalle persistido por `FacSec` |
| `GET` | `/audit/stats` | `AuditController::stats` | Conteos agregados para dashboard |
| `GET` | `/audit/documents-history` | `AuditController::documentsHistory` | Historial paginado de documentos auditados |
| `GET` | `/audit/{facNro}/timings` | `AuditController::timings` | Timings persistidos por factura/dispensa |
| `GET` | `/audit/dlq` | `AuditDlqController::index` | Eventos fallidos definitivos |
| `POST` | `/audit/dlq/reprocess` | `AuditDlqController::reprocess` | Reproceso administrativo de un evento DLQ |

## Componentes Principales

| Componente | Responsabilidad |
|---|---|
| `AuditController` | Valida solicitudes, resuelve identidad en `single`, registra jobs y publica eventos iniciales |
| `BatchRequestedWorker` | Consume `batch_requested`, consulta SQL Server, reserva `FacSec` y publica `audit_created` |
| `DocumentAuditOrchestrator` | Resuelve FDV/config/adjuntos y publica `document_registered` por documento |
| `DocumentExtractionContractBuilder` | Construye function declarations Gemini dinámicas desde `audit-config` |
| `DocumentExtractionWorker` | Descarga adjuntos, valida integridad, usa cache por `document_hash` e invoca Gemini |
| `DocumentIntegrityValidator` | Rechaza documentos vacíos, corruptos, con MIME inconsistente o no soportados antes de Gemini |
| `DocumentNormalizer` | Normaliza evidencia extraída de forma determinística |
| `FieldValueResolver` / `ResolvedAuditValue` | Resuelve FDV y documento con un contrato comun de valores escalares, sets, sumatorias y ambiguedad |
| `DocumentPolicyEngine` | Compara valores resueltos por `TipoCampo`/`TipoDato` y emite hallazgos canonicos |
| `RulesEvaluationWorker` | Evalúa reglas por documento, convierte `document_rejected` en hallazgo `RECHAZADO` y construye el outcome final |
| `AuditAggregationWorker` | Valida, persiste en SQL, cierra Redis y publica eventos terminales |
| `AuditStateStore` | Estado Redis por auditoría: contadores, timings, documentos y outcome |
| `BatchJobStore` | Estado Redis por job, idempotencia HTTP y reservas por `FacSec` |
| `AuditStatusModel` | Persistencia en `Discolnet.dbo.AudDispEst` y actualización de `AdjuntosDispensacion` |

## Flujo de Eventos

```text
batch_requested
  -> audit_created
  -> document_registered
  -> document_extracted | document_rejected
  -> document_normalized
  -> rules_evaluated
  -> audit_completed | audit_failed | batch_completed | batch_completed_with_errors
```

`document_rejected` salta la normalización y entra directamente a policy. `RulesEvaluationWorker` lo transforma en un hallazgo canónico `RECHAZADO` con `tipo_auditoria=integrity`.

## Evaluacion Multi-Item

La policy no compara un item aislado contra toda la factura. Antes de evaluar, `FieldValueResolver` transforma ambos lados en `ResolvedAuditValue`:

- `TipoCampo=B` + `TipoDato=quantity`: suma cantidades de todos los items de FDV y del documento.
- `TipoDato=trace_token`: compara sets completos de trazabilidad (`Lote`, seriales) y persiste `valoresFuenteVerdad` / `valoresDocumento`.
- Campos no sumables ni set-based con multiples valores distintos quedan `NO_CONCLUYENTE` por ambiguedad.

Caso de regresion cubierto: `D13260500540` con dos items debe producir `Lote={5D03364,5G00989}`, `CantidadEntregada=7` y `CantidadPrescrita=30` como `COINCIDE`.

## Contrato de Hallazgos

Los hallazgos persistidos en `AudDispEst.Hallazgos` conservan el contrato JSON v1. Cuando un hallazgo configurable falla (`VALOR_DISTINTO`, `NO_ENCONTRADO`, `NO_CONCLUYENTE` o `RECHAZADO` con codigo disponible), el `detalle` inicia con el prefijo textual `-<codigoCampo>- ` tomado de `AudDispCampo.CodigoCampo`, seguido por el detalle funcional del hallazgo. Los hallazgos `COINCIDE` no reciben prefijo.

## Contrato de Identidad

| Campo | Rol |
|---|---|
| `FacSec` / `fac_sec` | Identidad canónica de auditoría, idempotencia y persistencia (`AudDispEst.FacSec`) |
| `DisDetNro` / `dis_det_nro` | Llave operativa para adjuntos y `FacNro` persistido |
| `facNitSec` / `fac_nit_sec` | Cliente/NIT usado para configuración, filtros y métricas |

## Configuración Runtime

| Variable | Default | Uso |
|---|---|---|
| `AUDIT_WORKER_BATCH_REPLICAS` | `2` | Workers que procesan batches |
| `AUDIT_WORKER_ORCHESTRATOR_REPLICAS` | `3` | Workers que resuelven FDV/config/adjuntos |
| `AUDIT_WORKER_EXTRACTION_REPLICAS` | `8` | Workers que consumen Gemini |
| `AUDIT_WORKER_POLICY_REPLICAS` | `2` | Workers de evaluación de reglas |
| `AUDIT_IDEMPOTENCY_KEY_TTL` | `300` | TTL de `X-Idempotency-Key` para `/audit/async` |
| `AUDIT_PENDING_RECLAIM_IDLE_MS` | `600000` | Idle mínimo antes de reclamar mensajes pending |
| `AUDIT_EVENT_MAX_RETRIES` | `3` | Reintentos antes de enviar a DLQ |

## Ejemplos

Auditoría individual:

```powershell
curl.exe -X POST http://localhost:8080/audit/single `
  -H "Content-Type: application/json" `
  -d "{\"FacSec\":\"87723098\"}"
```

Batch async:

```powershell
curl.exe -X POST http://localhost:8080/audit/async `
  -H "Content-Type: application/json" `
  -H "X-Idempotency-Key: demo-20260601-001" `
  -d "{\"facNitSec\":1165,\"date\":\"2025-07-01\",\"dateTo\":\"2025-07-31\",\"limit\":20}"
```

Consultar estado:

```powershell
curl.exe http://localhost:8080/audit/jobs/{jobId}
curl.exe http://localhost:8080/audit/status/{auditId}
```
