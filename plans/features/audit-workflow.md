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
| `POST` | `/audit/single` | `AuditController::single` | Encola una auditoría individual por `disDetNro` (con `disId` opcional) |
| `POST` | `/audit/async` | `AuditController::async` | Encola un batch por cliente/rango y responde `202` |
| `GET` | `/audit/status/{auditId}` | `AuditController::status` | Estado Redis de una auditoría individual |
| `GET`  | `/audit/jobs` | `AuditController::jobsList` | Listado de jobs batch recientes |
| `GET`  | `/audit/jobs/{jobId}` | `AuditController::jobStatus` | Estado y progreso de un job batch |
| `GET`  | `/audit/results` | `AuditController::results` | Resumen paginado de auditorías persistidas |
| `GET`  | `/audit/results/{facNro}` | `AuditController::resultDetail` | Detalle persistido por `FacNro` |
| `GET`  | `/audit/stats` | `AuditController::stats` | Conteos agregados para dashboard |
| `GET`  | `/audit/stats/monthly` | `AuditController::monthlyPerformance` | Rendimiento mensual agregado por cliente |
| `GET`  | `/audit/documents-history` | `AuditController::documentsHistory` | Historial paginado de documentos auditados |
| `GET`  | `/audit/{facNro}/timings` | `AuditController::timings` | Timings persistidos por factura/dispensa |
| `GET`  | `/audit/{auditId}/flow-stream` | `AuditFlowController::stream` | Telemetría SSE en vivo por auditoría o job |
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
| `DocumentPdfRasterizer` | Pre-rasteriza PDFs a imágenes JPEG (200 DPI nativos) con `pdftoppm` (`poppler-utils`) para ingesta multimodal de alta resolución |
| `ExtractionPromptBuilder` | Construye prompts estructurados y deterministas para Gemini |
| `DocumentNormalizer` | Normaliza evidencia extraída de forma determinística |
| `FieldValueResolver` / `ResolvedAuditValue` | Resuelve FDV y documento con un contrato comun de valores escalares, sets, sumatorias y ambiguedad |
| `DocumentPolicyEngine` | Compara valores resueltos por `TipoCampo`/`TipoDato` y emite hallazgos canonicos |
| `RulesEvaluationWorker` | Evalúa reglas y convierte contratos cerrados de contenido o `DOCUMENT_MAPPING`; mapping produce código `MAP`, severidad alta y resultado `RECHAZADO` |
| `AuditPersistenceQueue` | Deduplica `rules_evaluated` y mantiene un solo turno de persistencia activo por job |
| `AuditPersistenceWorker` | Consume `audit.persistence.priority` y `audit.persistence.batch`, valida, persiste en SQL, cierra Redis, libera el turno y publica eventos terminales |
| `AuditStateStore` | Estado Redis por auditoría: contadores, timings, documentos y outcome |
| `BatchJobStore` | Estado Redis por job, idempotencia/reservas y contadores atómicos `queued/running/completed/failed` basados en transiciones del job |
| `AuditResultPersistenceModel` | Escritura transaccional en `Discolnet.dbo.AudDispEst`, `AdjuntosDispensacion` y `DispensacionDetalleServicio` con emparejamiento determinista por `attachment_id` |
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

### Continuidad del carril de auditoría

`AuditEvent::followUp()` crea eventos de la misma auditoría conservando `audit_id`,
`job_id` y la correlación `parent_event_id`. Copia exclusivamente `source` e
`is_priority` del payload padre sobre el nuevo payload funcional; los resultados
normalizados, agregados o recuperados de Redis no pueden sobreescribirlos. El
documento es un argumento obligatorio: se pasa el ID para una etapa documental
o `documentId: null` para una etapa consolidada, como `rules_evaluated` o
`audit_completed`. Así, olvidar el alcance falla en la llamada en lugar de
producir silenciosamente un evento sin documento.

La factory separa la identidad/correlación de `inheritRoutingMetadata()`, cuyo
único cometido es aplicar la lista cerrada `ROUTING_PAYLOAD_KEYS`. Esa lista
describe claves del transporte activo; no agrega reglas de negocio ni cambia
el clasificador de prioridad. Las pruebas separan identidad, serialización y
enrutamiento; enqueue, reprocess y advance tienen casos explícitos por contrato.

Orquestación, descarga, extracción (incluyendo cache y rechazo), normalización, evaluación,
persistencia y la terminalización técnica de `AuditEventConsumer` usan esta
factory. El orquestador deriva tanto `document_registered` como los rechazos por
mapping del mismo `audit_created`, con IDs documentales distintos y el mismo
padre. `buildDocumentState()` conserva solo el contexto funcional; el transporte
se añade al evento mediante `followUp()`. `AuditEventPublisher::isPriorityEvent()` sigue siendo
el único clasificador: `source === 'single'` o `is_priority === true` seleccionan
prioridad. Un origen `cron` prioritario conserva `cron`; no se infiere `single`
desde prioridad ni desde la ausencia de `job_id`. Metadatos ausentes permanecen
ausentes y no promueven eventos batch.

Cada señal basta por sí sola aunque falte la otra. Un evento publicado directamente
sin `followUp()` también respeta esta regla: `job_id=null` no demuestra origen
interactivo, y valores como `"true"` o `1` no equivalen al booleano `true`.

La serialización JSON, tipos de evento, nombres de streams y contratos HTTP no
cambian. Los metadatos permanecen en el payload por ser un contrato activo de
los consumidores. `rules_evaluated` continúa pasando exclusivamente por
`AuditPersistenceQueue`, incluidos los reprocesos; el transporte se aplica
después de recuperar el outcome canónico de Redis en un reintento.

### Concurrencia de Persistencia

`RulesEvaluationWorker` guarda el outcome idempotente y lo entrega a `AuditPersistenceQueue`. La cola permite un solo `rules_evaluated` activo por `job_id`; los restantes quedan ordenados en un ZSET por secuencia global. Jobs distintos publican turnos simultáneos en `audit.persistence.priority` o `audit.persistence.batch`, hasta la capacidad configurada de `worker-persistence`. Sin job, el turno se delimita por `audit_id`; una auditoría individual no queda detrás del turno reservado a un lote. `audit.persistence:{queue}:*` es el namespace de las claves de scheduling, no un stream.

El turno avanza únicamente después del cierre exitoso o después de la terminalización DLQ. Una redelivery posterior a `advance` es idempotente. Las dos persistencias exigidas por dominio permanecen dentro de la misma transacción SQL.

### Despliegue, diagnóstico y recuperación del desvío a batch

El ajuste del 2026-09-23 es una refactorización incremental aprobada sin
excepciones de compatibilidad. No requiere migración SQL, nuevos secretos ni
variables de entorno. Publicar una imagen PHP inmutable y recrear los servicios
PHP/workers con el mismo SHA; la corrección completa requiere que normalizador,
policy y persistencia hayan adoptado la versión. El rollback consiste en
redeplegar el SHA anterior, conservando Redis y SQL; devuelve también el defecto
de enrutamiento. No borrar grupos, streams, estados ni reservas al desplegar.

Los mensajes ya publicados sin metadatos no se reparan ni se mueven al desplegar.
Para una auditoría afectada:

1. Correlacionar `audit_id`, `event_id`, `parent_event_id` y `stream_id` en los logs
   de publicación; comprobar el SHA real de los workers. Localizar sus eventos
   `document_normalized` y el grupo `policy` en ambos streams documentales.
2. Consultar `XINFO GROUPS` (consumidores, último ID entregado y `lag`, cuando
   esté disponible) y `XPENDING` del grupo `policy`. PEL cuenta entregados sin
   ACK; no mide los mensajes todavía sin entregar. `/metrics/async` suma PEL de
   varios grupos documentales y no permite atribuir todo el valor a policy.
3. Si el evento sigue sin entregar, mantener consumidores batch activos y
   verificar que el grupo avance hasta su ID. Si fue entregado, revisar su
   consumidor, idle, reintentos y logs; un evento abandonado se recupera por el
   mecanismo existente de reclaim. No reclamar uno que aún está ejecutándose.
4. Si terminó en DLQ, corregir primero el fallo técnico y usar el reproceso
   administrativo existente sobre el evento original. Sus metadatos se
   conservan; un evento antiguo sin metadatos seguirá en batch. Si el evento no
   existe, detener el reproceso automático y reconstruir su trazabilidad antes
   de cualquier intervención. No fabricar resultados ni duplicar publicaciones
   para adelantar la cola.
5. Verificar el cierre en Redis, resultado SQL y eventos terminales. Para el
   flujo nuevo, comprobar que una auditoría single bajo carga batch conserve
   `.priority` en documentos, persistencia y resultados.

`docs_done` se actualiza antes de publicar `document_normalized` y
`docs_evaluated` después de guardar una evaluación. Estos contadores aislados
no prueban publicación exitosa ni ausencia de trabajo en curso. SQL y el cierre
Redis preceden a `audit_completed`; su carril terminal no determina el estado
que devuelve el polling. Los workers registran al iniciar identidad, grupo,
carril, streams, bloqueo de lectura, máximo de reintentos e intervalos de reclaim,
sin volcar payloads o configuración sensible. El enrutamiento preservado no
garantiza latencia máxima: policy y persistencia comparten capacidad entre carriles.

Pruebas de regresión: continuidad tras serialización, prioridad con origen
distinto de single, batch y metadatos ausentes, resultados con metadatos
contradictorios, normalización repetida, rechazo documental, outcome canónico en
reintento, enqueue/reprocess/advance de persistencia, éxito SQL y fallo técnico
con evento original conservado en DLQ. Ejecutar `php vendor/bin/phpunit --no-coverage`.

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
- `TipoDato=article_name`: aplica emparejamiento biyectivo (*Greedy Bipartite Matching*) en 3 fases (normalización léxica directa / substring, similitud léxica $\ge 0.82$, y desempate semántico con `SemanticMatchJudge` y caché de 30d en Redis) garantizando asignación 1:1 sin reutilización de ítems y reportando artículos faltantes en entregas multi-ítem ($N \ge 2$).
- Campos escalares de cabecera no sumables ni set-based con múltiples valores distintos quedan `NO_CONCLUYENTE` por ambigüedad.

Caso de regresion cubierto: `D13260500540` con dos items debe producir `Lote={5D03364,5G00989}`, `CantidadEntregada=7` y `CantidadPrescrita=30` como `COINCIDE`.

## Extracción Parcial de Líneas y Reconciliación de Ítems (Item Segmentation & Aggregation)

1. **Agregación Declarativa previa en FDV (`FdvItemAggregator`):** Antes de la extracción, la FDV consolida los ítems de bodega según los campos de ítem requeridos por el documento. Todo campo de ítem no acumulable (`isQuantitySummable() = false`, e.g. `CodigoProducto`, `Lote`, `CUM`) actúa como llave discriminante de agrupación (independientemente de si su `tipoCampo` es `B`, `E` o `S`), mientras que las cantidades se totalizan. Esto alinea `$expectedItemsCount` con documentos consolidados (ej. autorizaciones médicas que unifican entregas multilote).
2. **Advertencia de Segmentación (`ITEM_SEGMENTATION_INCOMPLETE`):** Cuando la cantidad de ítems extraídos del documento físico es menor a los ítems esperados, el extractor agrega un warning `ITEM_SEGMENTATION_INCOMPLETE` al payload del evento.
3. **Resiliencia de Balance en Policy Engine:** Antes de degradar a `NO_CONCLUYENTE`, el motor de políticas evalúa la comparación directa. Si la sumatoria cuantitativa y los códigos de producto coinciden al 100% con la FDV (`COINCIDE`), el hallazgo se resuelve como `COINCIDE` preservando la telemetría de segmentación en `extraction_meta`. Si el balance cuantitativo o identificador no se satisface (faltante real), se preserva el resultado `NO_CONCLUYENTE` para proteger contra extracciones parciales.
4. Los campos de cabecera continúan siendo evaluados con normalidad.

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
| `AUDIT_WORKER_DOWNLOADER_REPLICAS` | `12` | Workers que descargan adjuntos |
| `AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS` | `12` | Workers que consumen Gemini batch |
| `AUDIT_WORKER_EXTRACTION_VIP_REPLICAS` | `2` | Workers que consumen Gemini VIP |
| `AUDIT_WORKER_NORMALIZER_REPLICAS` | `4` | Workers que normalizan documentos |
| `AUDIT_WORKER_POLICY_REPLICAS` | `8` | Workers de evaluación de reglas |
| `AUDIT_WORKER_PERSISTENCE_REPLICAS` | `6` | Workers SQL globales; la cola limita a uno por job |
| `AUDIT_PERSISTENCE_QUEUE_TTL` | `604800` | Retención de turnos, pendientes y deduplicación |
| `AUDIT_IDEMPOTENCY_KEY_TTL` | `300` | TTL de `X-Idempotency-Key` para `/audit/async` |
| `AUDIT_PENDING_RECLAIM_IDLE_MS` | `600000` | Idle mínimo antes de reclamar mensajes pending |
| `AUDIT_EVENT_MAX_RETRIES` | `3` | Reintentos antes de enviar a DLQ |

## Ejemplos

Auditoría individual:

```powershell
curl.exe -X POST http://localhost:8080/audit/single `
  -H "Content-Type: application/json" `
  -d "{\"disDetNro\":\"D03260400856\"}"
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
