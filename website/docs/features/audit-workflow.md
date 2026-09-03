# Feature: Pipeline de Auditoría IA Event-Driven

## Descripción

Pipeline distribuido que audita dispensaciones farmacéuticas usando `DisId` como identidad canónica, documentos adjuntos como evidencia y Google Gemini API para extracción multimodal. El procesamiento pesado no corre en el request HTTP: se encola en Redis Streams y lo ejecutan workers independientes.

## Fuentes de Verdad

- Rutas: `app/Routes/web.php`
- Arquitectura técnica: `plans/architecture.md`
- Flujo secuencial: `plans/data-flows.md`
- Contrato de identidad: `plans/audit-identity-contract.md`
- Skill operativa: `.agent/skills/audfact-audit-gemini/SKILL.md`

## Endpoints

| Método | Ruta | Controlador | Descripción |
|---|---|---|---|
| `POST` | `/audit/single` | `AuditController::single` | Encola una auditoría individual por `DisId` |
| `POST` | `/audit/async` | `AuditController::async` | Encola un batch por cliente/rango y responde `202` |
| `GET` | `/audit/status/{auditId}` | `AuditController::status` | Estado Redis de una auditoría individual |
| `GET` | `/audit/jobs/{jobId}` | `AuditController::jobStatus` | Estado y progreso de un job batch |
| `GET` | `/audit/results` | `AuditController::results` | Resumen paginado de auditorías persistidas |
| `GET` | `/audit/results/{facNro}` | `AuditController::resultDetail` | Detalle persistido por `FacNro` |
| `GET` | `/audit/stats` | `AuditController::stats` | Conteos agregados para dashboard |
| `GET` | `/audit/documents-history` | `AuditController::documentsHistory` | Historial paginado de documentos auditados |
| `GET` | `/audit/{facNro}/timings` | `AuditController::timings` | Timings persistidos por factura/dispensa |
| `GET` | `/audit/dlq` | `AuditDlqController::index` | Eventos fallidos definitivos |
| `POST` | `/audit/dlq/reprocess` | `AuditDlqController::reprocess` | Reproceso administrativo de un evento DLQ |

## Componentes Principales

| Componente | Responsabilidad |
|---|---|
| `AuditController` | Valida solicitudes, resuelve identidad en `single`, registra jobs y publica eventos iniciales |
| `BatchRequestedWorker` | Consume `batch_requested`, consulta SQL Server, reserva `DisId` y publica `audit_created` |
| `DocumentAuditOrchestrator` | Resuelve FDV/config/adjuntos, reconcilia documentos lógicos con adjuntos físicos y publica registros o rechazos controlados |
| `DocumentAttachmentMatcher` | Aplica una reconciliación global 1:1 por nombre exacto, ID corroborado y alias único, sin I/O ni heurística de primer candidato |
| `DocumentExtractionContractBuilder` | Construye function declarations Gemini dinámicas desde `audit-config` |
| `AttachmentDownloadWorker` | Descarga adjuntos, valida transferencia completa, guarda el BLOB temporal en Redis y propaga fallos técnicos sin publicar rechazos funcionales |
| `DocumentExtractionWorker` | Consume `document_downloaded`, valida integridad, usa cache por `document_hash`, invoca Gemini (con política de recuperación en 3 fases) y produce exclusivamente rechazos de contenido |
| `DocumentIntegrityValidator` | Rechaza documentos vacíos, corruptos, con MIME inconsistente o no soportados antes de Gemini |
| `DocumentNormalizer` | Normaliza evidencia extraída de forma determinística |
| `FieldValueResolver` / `ResolvedAuditValue` | Resuelve FDV y documento con un contrato comun de valores escalares, sets, sumatorias y ambiguedad |
| `DocumentPolicyEngine` | Compara valores resueltos por `TipoCampo`/`TipoDato` y emite hallazgos canonicos |
| `RulesEvaluationWorker` | Evalúa reglas y convierte contratos cerrados de contenido o `DOCUMENT_MAPPING`; mapping produce código `MAP`, severidad alta y resultado `RECHAZADO` |
| `AuditPersistenceQueue` | Deduplica `rules_evaluated` y mantiene un solo turno de persistencia activo por job |
| `AuditPersistenceWorker` | Consume `audit.persistence:{queue}`, valida, persiste en SQL, cierra Redis, libera el turno y publica eventos terminales |
| `AuditStateStore` | Estado Redis por auditoría: contadores, timings, documentos y outcome |
| `BatchJobStore` | Estado Redis por job, idempotencia/reservas y contadores atómicos `queued/running/completed/failed` basados en transiciones del job |
| `AuditResultPersistenceModel` | Escritura transaccional en `Discolnet.dbo.AudDispEst`, `AdjuntosDispensacion` y `DispensacionDetalleServicio` |
| `AuditStatusModel` | Lectura de resultados y timings persistidos |

## Flujo de Eventos

```text
batch_requested
  -> audit_created
  -> document_registered | document_rejected (mapping)
  -> document_downloaded
  -> document_extracted | document_rejected (contenido)
  -> document_normalized
  -> rules_evaluated
  -> audit_completed | audit_failed | batch_completed | batch_completed_with_errors
```

`document_rejected` salta descarga/extracción/normalización según su origen y
entra directamente a policy. El orquestador solo puede emitir categoría
`DOCUMENT_MAPPING` con `logical_doc_id`, candidatos y una razón de la allowlist
de mapping; el extractor solo puede emitir clase `document_content`. Fallos de
PDO, SQL Server, Drive o transferencia BLOB son técnicos: terminan en DLQ y
nunca se convierten en un hallazgo funcional.

### Concurrencia de Persistencia

`RulesEvaluationWorker` guarda el outcome idempotente y lo entrega a `AuditPersistenceQueue`. La cola permite un solo `rules_evaluated` activo por `job_id`; los restantes quedan ordenados en un ZSET por secuencia global. Jobs distintos sí publican un turno simultáneo en `audit.persistence:{queue}`, por lo que las 3 réplicas de `worker-persistence` procesan hasta 3 jobs en paralelo sin que una factura lenta bloquee a las demás.

El turno avanza únicamente después del cierre exitoso o después de la terminalización DLQ. Una redelivery posterior a `advance` es idempotente. Las dos persistencias exigidas por dominio permanecen dentro de la misma transacción SQL.

### Resiliencia SQL/PDO

Cada callback de modelo recibe un PDO fresco. Las lecturas y la persistencia
dual idempotente admiten hasta cuatro aperturas, separadas por 1, 5 y 30
segundos, ante desconexiones de conexión. `HYT00` se reintenta solo si ocurre al
abrir; un timeout de statement, deadlock o escritura no reproducible no se
repite automáticamente.

Al agotar la política, `AuditEventConsumer` publica DLQ, hace ACK y ejecuta los
hooks terminales en la misma entrega. `AuditPersistenceWorker` aplica una
barrera independiente antes de SQL y rechaza cualquier resultado que contenga
`DOWNLOAD_ERROR` o un contrato de rechazo inválido.

## Evaluacion Multi-Item

La policy no compara un item aislado contra toda la factura. Antes de evaluar, `FieldValueResolver` transforma ambos lados en `ResolvedAuditValue`:

- `TipoCampo=B` + `TipoDato=quantity`: suma cantidades de todos los items de FDV y del documento.
- `TipoDato=trace_token`: compara sets completos de trazabilidad (`Lote`, seriales) y persiste `valoresFuenteVerdad` / `valoresDocumento`.
- Campos no sumables ni set-based con multiples valores distintos quedan `NO_CONCLUYENTE` por ambiguedad.

Caso de regresion cubierto: `D13260500540` con dos items debe producir `Lote={5D03364,5G00989}`, `CantidadEntregada=7` y `CantidadPrescrita=30` como `COINCIDE`.

## Extracción Parcial de Líneas (Item Segmentation)

Cuando el documento tiene múltiples ítems y la IA no logra extraerlos todos de manera limpia (segmentación parcial o incompleta), el extractor no falla con una excepción (que enviaría el evento a DLQ o forzaría un reintento). En cambio:

1. Agrega un warning `ITEM_SEGMENTATION_INCOMPLETE` al payload del evento.
2. La política de auditoría (Policy Engine) detecta este warning y, para evitar sumatorias parciales peligrosas, fuerza el resultado `NO_CONCLUYENTE` para todas las evaluaciones de nivel línea (`TipoCampo = 'B'`).
3. Los campos de cabecera siguen siendo evaluados con normalidad.

## Contrato de Hallazgos

Los hallazgos persistidos en `AudDispEst.Hallazgos` conservan el contrato JSON v1. Cuando un hallazgo configurable falla (`VALOR_DISTINTO`, `NO_ENCONTRADO`, `NO_CONCLUYENTE` o `RECHAZADO` con codigo disponible), el `detalle` inicia con el prefijo textual `-<codigoCampo>- ` tomado de `AudDispCampo.CodigoCampo`, seguido por el detalle funcional del hallazgo. Los hallazgos `COINCIDE` no reciben prefijo.

## Contrato de Identidad

| Campo | Rol |
|---|---|
| `DisId` / `dis_id` | Identidad canónica de auditoría, idempotencia y persistencia (`AudDispEst.FacSec` — columna legacy) |
| `DisDetNro` / `dis_det_nro` | Llave operativa para adjuntos y `FacNro` persistido; `FacNro` es la PK operativa de `AudDispEst` |
| `facNitSec` / `fac_nit_sec` | Cliente/NIT usado para configuración, filtros y métricas |

## Configuración Runtime

| Variable | Default | Uso |
|---|---|---|
| `AUDIT_WORKER_BATCH_REPLICAS` | `2` | Workers que procesan batches |
| `AUDIT_WORKER_ORCHESTRATOR_REPLICAS` | `3` | Workers que resuelven FDV/config/adjuntos |
| `AUDIT_WORKER_EXTRACTION_REPLICAS` | `8` | Workers que consumen Gemini |
| `AUDIT_WORKER_POLICY_REPLICAS` | `2` | Workers de evaluación de reglas |
| `AUDIT_WORKER_PERSISTENCE_REPLICAS` | `3` | Workers SQL globales; la cola limita a uno por job |
| `AUDIT_PERSISTENCE_QUEUE_TTL` | `604800` | Retención de turnos, pendientes y deduplicación |
| `AUDIT_IDEMPOTENCY_KEY_TTL` | `300` | TTL de `X-Idempotency-Key` para `/audit/async` |
| `AUDIT_PENDING_RECLAIM_IDLE_MS` | `600000` | Idle mínimo antes de reclamar mensajes pending |
| `AUDIT_EVENT_MAX_RETRIES` | `3` | Reintentos antes de enviar a DLQ |

## Ejemplos

Auditoría individual:

```powershell
curl.exe -X POST http://localhost:8080/audit/single `
  -H "Content-Type: application/json" `
  -d "{\"disId\":\"87723098\"}"
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
