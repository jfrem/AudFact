# API Endpoints — AudFact

## Base URL

```text
http://localhost:8080
```

Las rutas no usan prefijo `/api`.

## Convención de respuesta

Las respuestas HTTP siguen la forma emitida por `Core\Response`:

```json
{
  "success": true,
  "message": "Operación exitosa",
  "data": {}
}
```

En error:

```json
{
  "success": false,
  "message": "Errores de validación",
  "errors": {
    "campo": ["detalle"]
  }
}
```

## Endpoints

### `GET /`

Estado base del API.

### `GET /health`

Health check funcional del backend. Devuelve estado global y detalle de base de datos (escritura y lectura DB2), Redis, disco, memoria y latencia (`request_duration_ms`).

### `GET /metrics/async`

Campo aditivo `executionVersion` (1/2). V2 agrega `executionScheduler` por
stage/lane (queued/active/delayed/oldest_wait_ms), `geminiBudget` (cuota usada,
tokens reservados e inflight; modelo/scope_hash) y `executionMaintenance`
(commitsPending/outboxPending/semanticPlans/heartbeatAgeMs). semanticPlans es
objeto JSON aun vacío. Lectura inválida: 503 sin datos parciales; heartbeat
viejo se informa y bloquea nuevas admisiones.
queueDepth incluye tareas activas v2; backlogDepth agrega queued+active+delayed
a PEL+lag conocido+pendientes SQL. No representa documentos únicos.

Métricas operativas del pipeline async en Redis. `queueDepth` y `streamDepths` conservan la suma de PEL por compatibilidad. `streamBacklogs` agrega por stream/grupo `pending`, `lag` y `lagKnown`; ambos conteos provienen de XINFO GROUPS, sin el fallback a cero de xPending. `persistenceScheduler` muestra `active`, `pending` y `scopes`, incluyendo colas legacy reconciliadas antes de la primera lectura o después de perder el índice. `backlogDepth` suma PEL, lag conocido y pendientes internos, sin duplicar turnos activos. Cuenta trabajo de grupos, no documentos únicos. Un lag desconocido permanece `null`. Incluye DLQ, jobs y fallos terminales. Errores parciales de Redis, respuestas malformadas o reconciliación incompleta se registran y producen 503.

### `GET /config/public`

Configuración pública para el frontend.

Respuesta típica:

```json
{
  "success": true,
  "message": "Operación exitosa",
  "data": {
    "auditBatchMaxLimit": 100,
    "auditBatchTimeoutMs": 3600000
  }
}
```

### `GET /clients`

Lista clientes activos.

### `GET /clients/{clientId}`

Consulta un cliente por identificador.

Validación:
- `clientId`: entero `>= 1`

### `GET /clients/{clientId}/documents`

Lista el catálogo documental configurado para un cliente.

Validación:
- `clientId`: entero `>= 1`

### `POST /clients`

Busca un cliente por body JSON.

```json
{
  "clientId": 1165
}
```

### `GET /invoices`

Busca facturas por query params.

Parámetros:
- `facNitSec` requerido
- `dateFrom` requerido, `YYYY-MM-DD`
- `dateTo` opcional, `YYYY-MM-DD` (si se omite, se iguala a `dateFrom`)
- `page` opcional, entero `>= 1` (default `1`)
- `pageSize` opcional, entero `1..100` (default `20`)

Respuesta:

```json
{
  "success": true,
  "message": "Facturas encontradas",
  "data": {
    "items": [
      {
        "NitSec": 1165,
        "DisId": 87172329,
        "Dispensa": "X24250700021"
      }
    ],
    "total": 125,
    "page": 1,
    "pageSize": 20,
    "totalPages": 7,
    "filters": {
      "facNitSec": 1165,
      "dateFrom": "2025-07-01",
      "dateTo": "2025-07-01"
    }
  }
}
```

### `POST /invoices`

Busca facturas por body JSON.

```json
{
  "facNitSec": 1165,
  "dateFrom": "2025-07-01",
  "dateTo": "2025-11-30",
  "page": 1,
  "pageSize": 20
}
```

Nota: `dateTo` es opcional, si se omite, se iguala automáticamente a `dateFrom`. La respuesta usa el mismo contrato paginado de `GET /invoices`.

### `GET /dispensation/{DisId}/{DisDetNro}`

Obtiene detalle técnico de una dispensación.

### `POST /dispensation`

Busca una dispensación por body JSON.

```json
{
  "DisDetNro": "X24250700021"
}
```

*Nota: `DisId` es opcional. Si se omite, el backend derivará automáticamente el identificador canónico interno a partir de `DisDetNro` para satisfacer las consultas a las vistas estandarizadas.*

### `GET /dispensation/{DisDetNro}/attachments/{nitSec}`

Lista metadatos de adjuntos para una dispensación.

Campos de identidad en la respuesta:
- `dispensacion_id`: `AdjuntosDispensacion.DisId`
- `dis_det_nro`: `DispensacionDetalleServicio.DisDetNro`
- `cliente`, `id_documento`, `nombre_documento`, `nombre_alternativo`
- `almacenamiento_remoto`, `TipoAlmacenamiento`

Este contrato público no expone los nombres internos `physical_*` usados por el
matcher del pipeline.

### `GET /dispensation/{DisDetNro}/attachments/download/{attachmentId}`

Descarga o previsualiza un adjunto.

Comportamiento:
- con `Accept: application/json`, responde `{ mime, data }` con `data` base64
- en cualquier otro caso hace streaming binario

### `POST /audit/single`

Pausa de admisión/ejecución, cuota v2 sin configurar, mantenimiento no saludable
o 80% de capacidad de etapa/memoria: HTTP 503 y `Retry-After: 5`, antes de
crear auditoría/reserva. Mensaje público: «La auditoría está temporalmente al
límite de capacidad». `/audit/async` usa la misma compuerta antes de crear job.

Encola auditoría individual sobre una sola dispensación usando la llave compuesta `DisId` + `DisDetNro` como identidad canónica.

```json
{
  "disDetNro": "87723098"
}
```

*Nota: `disId` es opcional. Si se omite, el backend derivará automáticamente la identidad canónica interna a partir de `disDetNro` para resolver correctamente la persistencia.*

Respuesta exitosa: HTTP `202`.

Campos principales:
- `audit_id`
- `status`
- `dis_id`
- `dis_det_nro`

### `POST /audit/async`

Encola un lote de auditorías de dispensaciones existentes, seleccionado por cliente y fechas. El controlador publica `batch_requested` y los workers realizan el procesamiento. La respuesta HTTP confirma la admisión del job; el avance se consulta mediante `GET /audit/jobs/{jobId}`.

#### Cabeceras
- `Content-Type: application/json`.
- `X-Idempotency-Key` (obligatoria): clave suministrada por el consumidor para evitar otra admisión mientras la barrera sigue vigente. Ausente o vacía produce HTTP 400. El TTL se configura con `AUDIT_IDEMPOTENCY_KEY_TTL`, por defecto 300 segundos.

#### Parámetros (Body JSON)
```json
{
  "facNitSec": 1165,
  "date": "2025-07-01",
  "dateTo": "2025-11-30",
  "limit": 10
}
```
Validación del controlador actual:

- `facNitSec`: requerido, entero `>= 1`.
- `date`: requerido, fecha `YYYY-MM-DD`; `dateFrom` no sustituye este campo.
- `dateTo`: opcional; si se omite, se utiliza `date`. Debe ser mayor o igual a `date`.
- `limit`: opcional o `null`, entero `1..100` cuando se informa; por defecto `100`.

El máximo HTTP de 100 está fijado en `AuditController::async()`. `AUDIT_BATCH_MAX_LIMIT` se expone en la configuración pública del frontend, pero este controlador no lo consulta para validar el lote.

#### Respuestas

##### HTTP 202 Accepted (nuevo job creado)
Se retorna si el lote fue encolado con éxito.

```json
{
  "success": true,
  "message": "Batch de auditoría encolado",
  "data": {
    "job_id": "e8d6411d-872f-4886-905c-e58f0ee2b453",
    "status": "pending"
  }
}
```

##### HTTP 409 Conflict (solicitud duplicada)
Se retorna si la misma `X-Idempotency-Key` ya está reclamada durante el TTL de la barrera. Devuelve el `job_id` existente. La expiración de la barrera no depende de que el job haya terminado.

```json
{
  "success": true,
  "message": "Solicitud ya registrada",
  "data": {
    "job_id": "e8d6411d-872f-4886-905c-e58f0ee2b453"
  }
}
```

##### Errores de admisión

- HTTP `400`: cabecera `X-Idempotency-Key` ausente/vacía o JSON inválido.
- HTTP `422`: campos inválidos, `date` ausente o `dateTo` anterior a `date`.
- HTTP `503`: error al reclamar la clave, inicializar el job o publicar el evento.

Referencia del contrato actual: `app/Controllers/AuditController.php::async()` y `app/Controllers/Controller.php::getBody()/validate()`. El portal consume esta operación mediante `frontend/lib/api/audfact.ts::enqueueAuditBatch()`.

---

### `GET /audit/jobs`

Lista los jobs de auditoría batch recientes con enriquecimiento de nombres de clientes.

#### Query Parameters
- `limit`: entero opcional `1..100` (default: `50`)

Respuesta:
```json
{
  "success": true,
  "message": "Lista de jobs de auditoría",
  "data": [
    {
      "job_id": "e8d6411d-872f-4886-905c-e58f0ee2b453",
      "status": "completed",
      "total": 10,
      "done": 10,
      "failed": 0,
      "fac_nit_sec": 1165,
      "client_name": "NUEVA EPS",
      "created_at": "2026-06-01T13:00:00Z"
    }
  ]
}
```

### `GET /audit/jobs/{job_id}`

Consulta el estado de un job async.

#### Parámetros
- `job_id`: Path parameter, UUID v4.


Campos principales de la respuesta:
- `job_id`, `status`, `total`, `done`, `failed`, `pending`
- `avg_duration_ms`: duración activa promedio de las auditorías terminales
- `accumulated_duration_ms`: duración activa acumulada del lote
- `throughput_per_sec`: `(done + failed) / segundos de pared desde created_at`; incluye preparación y espera. En jobs terminales se congela en completed_at (fallback updated_at para estados antiguos).
- `elapsed_ms`: tiempo de pared usado para calcular throughput; compartido por lista y detalle.
- `audits`: resumen por auditoría en el job

### `GET /audit/status/{audit_id}`

Consulta el estado transitorio de una auditoría individual en Redis.

#### Parámetros
- `audit_id`: Path parameter, UUID v4.

Respuesta:

```json
{
  "success": true,
  "message": "Estado de la auditoría",
  "data": {
    "audit_id": "8e4efc63-2e14-4d91-a8f0-85efdd0491bf",
    "status": "processing",
    "dis_det_nro": "X24250700021",
    "dis_id": "87172329",
    "docs_total": 4,
    "docs_done": 2,
    "docs_extracted": 2,
    "docs_evaluated": 1,
    "is_terminal": false,
    "error_message": null,
    "created_at": "2026-06-01T13:00:00Z",
    "updated_at": "2026-06-01T13:00:10Z"
  }
}
```

#### Valores válidos de `status`

| Valor | Descripción |
|---|---|
| `pending` | Auditoría encolada, aún no procesada por el orchestrator |
| `processing` | Al menos un documento está siendo procesado activamente |
| `completed` | Auditoría completada sin hallazgos de alta severidad |
| `manual_review` | Completada con hallazgos que requieren revisión humana |
| `error` | Completada con discrepancias documentales no críticas — requiere análisis posterior pero no bloquea |
| `failed` | Error fatal de pipeline (timeout, Gemini caído, excepción no recuperable) — el análisis no se completó |

### `GET /audit/{auditId}/flow-stream`

Stream SSE de telemetría live para la vista de trazabilidad, soportando tanto auditorías individuales como lotes agregados.

Validación:
- `auditId`: path parameter, UUID v4 generado por `POST /audit/single` (`audit_id`) o por `POST /audit/async` (`job_id`).
- `DisDetNro` / `FacNro` no son identificadores validos para este stream live; se usan solo para historial persistido.
- Se hace dual-routing: verifica en Redis si existe `audit:{id}:state` o `job:{id}:state`. Si no existe ninguno, responde `404`.

Eventos SSE:
- `connected`: confirma conexion con `{ "id": "<uuid>", "type": "audit|job" }`.
- `telemetry`: evento de fase live desde `audit.telemetry` filtrado por el ID.
- `timeout`: cierre controlado despues de 30s con `reconnect_ms`.

Autenticacion:
- No aplica en esta fase de desarrollo.
- Antes de exponerlo fuera de una red controlada debe agregarse autenticacion/autorizacion del usuario auditor.

### `GET /audit/results`

Consulta auditorías persistidas con filtros y paginación.

Parámetros opcionales:
- `facNitSec`
- `facNro`
- `dateFrom`
- `dateTo` (si se omite, se iguala a `dateFrom`)
- `page`
- `pageSize`

Respuesta:

```json
{
  "success": true,
  "message": "Resultados de auditorías",
  "data": {
    "items": [],
    "total": 0,
    "page": 1,
    "pageSize": 20,
    "totalPages": 0,
    "filters": {}
  }
}
```

### `GET /audit/results/{facNro}`

Consulta el detalle persistido de una auditoría por `FacNro` (`DisDetNro`/`Dispensa`), llave primaria operativa en `AudDispEst`.

Validación:
- `facNro`: string no vacío en ruta.

Respuesta:

```json
{
  "success": true,
  "message": "Detalle de auditoría",
  "data": {
    "DisId": "87723098",
    "FacNro": "T38250701547",
    "findings": [],
    "fieldDecisions": [],
    "documentDecisions": [],
    "timings": {}
  }
}
```

### `GET /audit/stats`

Consulta resumen agregado de estados de auditoría para dashboard.

### `GET /audit/stats/monthly`

Consulta estadísticas agregadas de rendimiento mensual por cliente/EPS para análisis de conformidad histórica.

#### Query Parameters
- `year`: entero opcional `2000..2100` (default: año actual)

Respuesta:
```json
{
  "success": true,
  "message": "Rendimiento mensual de auditoría",
  "data": {
    "year": 2026,
    "summary": {
      "total_facturas": 120,
      "total_conformes": 110,
      "total_rechazadas": 10,
      "global_rate_conf": 91.7,
      "total_documentos": 360,
      "total_doc_conformes": 348,
      "total_doc_rechazados": 12
    },
    "items": []
  }
}
```

### `GET /audit/documents-history`

Consulta historial documental auditado con paginación.

Parámetros opcionales:
- `facNitSec`
- `facNro`
- `page`
- `pageSize`

Respuesta:

```json
{
  "success": true,
  "message": "Historial de auditorías de documentos",
  "data": {
    "items": [],
    "total": 0,
    "page": 1,
    "pageSize": 20,
    "totalPages": 0,
    "filters": {}
  }
}
```

### `GET /audit/costs`

Reporte paginado de costos IA por intento individual con liquidación en USD y COP según la TRM oficial aplicada.

Query Parameters opcionales:
- `page`: entero (default: 1)
- `pageSize`: entero (default: 20, max: 100)
- `dateFrom`: fecha `YYYY-MM-DD`
- `dateTo`: fecha `YYYY-MM-DD`
- `facNitSec`: identificador del cliente
- `facNro`: número de dispensación/factura

Respuesta:
```json
{
  "success": true,
  "message": "Operación exitosa",
  "data": {
    "trm_vigente_hoy": 3194.44,
    "trm_fuente": "datos_gov_co",
    "pagination": {
      "page": 1,
      "pageSize": 20,
      "totalRecords": 1,
      "totalPages": 1
    },
    "totales_periodo": {
      "gran_total_tokens": 10036,
      "costo_total_usd": 0.016716,
      "costo_total_cop": 53.4
    },
    "items": []
  }
}
```

### `GET /audit/costs/clients`

Consolidado del gasto acumulado de auditoría IA agrupado por Cliente / EPS con sumatorias nativas `SUM(CostoUSD)` y `SUM(CostoCOP)`.

### `GET /audit/costs/trm`

Inspección en vivo de la Tasa Representativa del Mercado (TRM) vigente, origen activo (`datos_gov_co`, `dolarapi_fallback`, `redis_cache`, `static_fallback`) y vigencia legal.

### `GET /audit/costs/{facNro}`

Historial cronológico de todos los intentos de auditoría registrados para una dispensación (`FacNro`), detallando tokens por categoría, tarifa aplicada, costo liquidado en USD y COP, y porcentaje de acierto en caché.

### `GET /audit/{facNro}/timings`

`timing_schema_version=1` conserva el significado histórico. V2 usa 2:
policy mide ejecución PHP, `semantic_wait` mide espera de homologación,
`semantic_pairs` cuenta pares; semantic_calls conserva generaciones exitosas
deduplicadas por request_id. Contadores aparte: semantic_http_attempts,
semantic_count_tokens_attempts, semantic_request_failures y
semantic_unknown_attempts. Tokens/duración del request se agregan una vez,
sin multiplicarlos por cada par. El ledger conserva null para uso desconocido.

Consulta métricas persistidas por fase para una auditoría.

Validación:
- `facNro`: string no vacío en la ruta

Campos de latencia:
- `processing_duration_ms`: tiempo activo desde `started_at` hasta cierre de reglas
- `queue_wait_ms`: espera entre encolamiento (`created_at`) e inicio activo (`started_at`)
- `total_elapsed_ms`: tiempo total desde encolamiento hasta cierre de reglas

### `GET /audit/dlq`

Lista eventos recientes de la Dead Letter Queue del pipeline async.

Parámetros opcionales:
- `limit`: entero `1..100`, default `20`

### `POST /audit/dlq/reprocess`

Recupera una auditoría fallida mediante una nueva raíz `audit_created`,
independiente e idempotente por auditoría original durante `AUDIT_STATE_TTL`.
También reconstruye desde raíz los fallos de persistencia; al terminar policy,
usa el scheduler SQL habitual. No reutiliza event_id ni modifica el job histórico.
Repetir distintas entradas DLQ de una misma auditoría devuelve el mismo intento.

HTTP 200 conserva `stream_id`, `reprocessed_event_id`, `event_type` y `audit_id`,
con la identidad nueva y `event_type=audit_created`; agrega `original_audit_id`
y `original_job_id`. Consultar progreso en `/audit/status/{auditId}` usando el
nuevo ID. Conserva el carril de prioridad original. HTTP 409 indica estado
activo/exitoso/ausente, identidad incompleta o evento sin auditoría; 422, streamId
o evento original inválidos; 404, entrada DLQ ausente; 503, fallo Redis registrado.

Ver [límites, recuperación del histórico y rollout](ajustes-alto-volumen-2026-10-08.md).

```json
{
  "streamId": "1700000000000-0"
}
```

### `GET /audit/field-catalog`

Obtiene el catálogo maestro de campos auditables desde la base de datos (fuente única de verdad).

Respuesta:
```json
{
  "success": true,
  "data": [
    {
      "campoNombre": "NumeroFactura",
      "codigoCampo": "FACN",
      "tipoCampo": "E",
      "tipoDato": "text",
      "descripcion": "Verifica el número de factura",
      "severidad": "alta",
      "esVisual": false
    }
  ]
}
```

### `GET /clients/{clientId}/audit-config`

Obtiene la configuración completa de auditoría para un cliente, incluyendo el prompt del sistema, campos de datos por documento y visual checks separados con su respectivo `aplicaServicio` (`TODOS`, `POS`, `MIPRES`, etc.).

`diasVigencia` conserva el plazo configurado o `null` si no existe. El fallback
de 60 días se aplica al evaluar y mostrar la vigencia; no se presenta como una
configuración persistida. La evidencia visual completa del documento tiene prioridad.

Respuesta:
```json
{
  "success": true,
  "data": {
    "nitSec": "2426",
    "activo": true,
    "systemPrompt": "...",
    "factorConv": false,
    "diasVigencia": 30,
    "documents": {
      "DISPENSA": {
        "docId": 1,
        "fields": [
          {
            "campoNombre": "NumeroFactura",
            "tipoCampo": "E",
            "tipoDato": "text",
            "orden": 1,
            "severity": "alta",
            "codigoCampo": "FACN",
            "description": "",
            "aplicaServicio": "TODOS"
          },
          {
            "campoNombre": "NombreArticulo",
            "tipoCampo": "S",
            "tipoDato": "article_name",
            "orden": 8,
            "severity": "alta",
            "codigoCampo": "NAM",
            "description": "",
            "aplicaServicio": "TODOS"
          }
        ],
        "visualChecks": [
          {
            "check": "FirmaActaEntrega",
            "description": "Firma o sello de recibido",
            "severity": "alta",
            "orden": 37,
            "codigoCampo": "FIR",
            "aplicaServicio": "TODOS"
          }
        ]
      }
    }
  }
}
```

### `POST /clients/{clientId}/audit-config`

Guarda/reemplaza completamente la configuración de auditoría. La UI envía solo los campos activos; no existe `enabled` ni `rol` en el contrato runtime. Se puede especificar opcionalmente `aplicaServicio` (`TODOS` por defecto, o modalidades específicas como `POS` o `MIPRES`).
**NOTA:** El campo `systemPrompt` es de envío **obligatorio** (`string` o `null`); omitirlo resulta en un error HTTP 422 para prevenir borrados accidentales del prompt.

`diasVigencia` es opcional: acepta enteros entre 1 y 365, incluyendo strings
enteros normalizados por el controlador. Booleanos, floats y valores fuera del
rango producen HTTP 422 antes de consultar el catálogo o guardar. Omitirlo o
enviar `null` conserva el valor existente; al crear una cabecera guarda `NULL`.
`null` no es una orden para borrar un plazo ya configurado.

Body:
```json
{
  "systemPrompt": "...",
  "factorConv": false,
  "diasVigencia": 30,
  "fields": [
    {
      "docId": 1,
      "campoNombre": "NumeroFactura",
      "orden": 1,
      "severity": "alta",
      "aplicaServicio": "TODOS"
    },
    {
      "docId": 1,
      "campoNombre": "FirmaActaEntrega",
      "orden": 37,
      "description": "Firma o sello de recibido",
      "severity": "alta",
      "aplicaServicio": "POS"
    }
  ]
}
```

Respuesta:
```json
{
  "success": true,
  "data": {
    "fieldCount": 2
  }
}
```

## MCP

### `POST /app/wrap/webhook.php`

Webhook MCP autenticado por `X-API-KEY` contra `MCP_WEBHOOK_SECRET`.

Tools publicadas por `capabilities.php`:
- `get_clients`
- `get_invoices`
- `get_dispensation`
- `get_attachments`
