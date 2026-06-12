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

Health check funcional del backend. Devuelve estado global y detalle de base de datos, disco y memoria.

### `GET /metrics/async`

Métricas operativas del pipeline async en Redis: profundidad de cola, DLQ, jobs por estado y fallos terminales. Si Redis no está disponible, responde ceros para no romper la UI; `/health` expone el estado real.

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

### `GET /dispensation/{DisDetNro}/attachments/download/{attachmentId}`

Descarga o previsualiza un adjunto.

Comportamiento:
- con `Accept: application/json`, responde `{ mime, data }` con `data` base64
- en cualquier otro caso hace streaming binario

### `POST /audit/single`

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

Encola una auditoría batch asíncrona mediante un pipeline 100% no bloqueante, delegando el procesamiento pesado a workers y garantizando alta concurrencia e idempotencia absoluta.

#### Cabeceras
- `X-Idempotency-Key` (opcional): clave para evitar doble encolamiento por reintentos rápidos del cliente. Si no se proporciona, el backend genera un UUID temporal y lo devuelve en la respuesta.

#### Parámetros (Body JSON)
```json
{
  "facNitSec": 1165,
  "date": "2025-07-01",
  "dateTo": "2025-11-30",
  "limit": 10
}
```
*Nota: `dateTo` es opcional, si se omite, se iguala automáticamente a `date`. `date` también puede enviarse como `dateFrom`.*

#### Respuestas

##### HTTP 202 Accepted (nuevo job creado)
Se retorna si el lote fue encolado con éxito.

```json
{
  "success": true,
  "message": "Auditoría batch encolada con éxito",
  "data": {
    "job_id": "e8d6411d-872f-4886-905c-e58f0ee2b453",
    "status": "pending",
    "idempotency_key": "4a74a67c-f7e4-4d17-8e2c-227508ce4e9b"
  }
}
```

`idempotency_key` solo se incluye cuando el backend tuvo que autogenerar la llave.

##### HTTP 409 Conflict (solicitud duplicada)
Se retorna si la misma `X-Idempotency-Key` ya fue reclamada por un job vigente.

```json
{
  "success": true,
  "message": "Solicitud ya registrada",
  "data": {
    "job_id": "e8d6411d-872f-4886-905c-e58f0ee2b453"
  }
}
```

##### 🔴 HTTP 400 Bad Request
Se retorna si hay errores de validación de campos.

---

### `GET /audit/jobs/{job_id}`

Consulta el estado de un job async.

#### Parámetros
- `job_id`: Path parameter, UUID v4.


Campos principales de la respuesta:
- `job_id`, `status`, `total`, `done`, `failed`, `pending`
- `avg_duration_ms`: duración activa promedio de las auditorías terminales
- `accumulated_duration_ms`: duración activa acumulada del lote
- `throughput_per_sec`: auditorías terminales por segundo activo acumulado
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

### `GET /audit/{facNro}/timings`

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

Reprocesa un evento de DLQ republicando su evento original al stream canónico.

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

Obtiene la configuración completa de auditoría para un cliente, incluyendo el prompt del sistema, campos de datos por documento y visual checks separados.

Respuesta:
```json
{
  "success": true,
  "data": {
    "nitSec": "2426",
    "activo": true,
    "systemPrompt": "...",
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
            "codigoCampo": "FACN"
          },
          {
            "campoNombre": "NombreArticulo",
            "tipoCampo": "S",
            "tipoDato": "article_name",
            "orden": 8,
            "severity": "alta",
            "codigoCampo": "NAM"
          }
        ],
        "visualChecks": [
          {
            "check": "FirmaActaEntrega",
            "description": "Firma o sello de recibido",
            "severity": "alta",
            "orden": 37,
            "codigoCampo": "FIR"
          }
        ]
      }
    }
  }
}
```

### `POST /clients/{clientId}/audit-config`

Guarda/reemplaza completamente la configuración de auditoría. La UI envía solo los campos activos; no existe `enabled` ni `rol` en el contrato runtime.
**NOTA:** El campo `systemPrompt` es de envío **obligatorio** (`string` o `null`); omitirlo resulta en un error HTTP 422 para prevenir borrados accidentales del prompt.

Body:
```json
{
  "systemPrompt": "...",
  "fields": [
    {
      "docId": 1,
      "campoNombre": "NumeroFactura",
      "tipoCampo": "E",
      "tipoDato": "text",
      "orden": 1,
      "severity": "alta",
      "codigoCampo": "FACN"
    },
    {
      "docId": 1,
      "campoNombre": "FirmaActaEntrega",
      
      "orden": 37,
      "description": "Firma o sello de recibido",
      "severity": "alta",
      "codigoCampo": "FIR"
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
