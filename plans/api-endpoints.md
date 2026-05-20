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
- `dateTo` opcional, `YYYY-MM-DD`
- `limit` opcional, entero `1..1000`

### `POST /invoices`

Busca facturas por body JSON.

```json
{
  "facNitSec": 1165,
  "dateFrom": "2025-07-01",
  "dateTo": "2025-11-30",
  "limit": 10
}
```

### `GET /dispensation/{DisDetNro}`

Obtiene detalle técnico de una dispensación.

### `POST /dispensation`

Busca una dispensación por body JSON.

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

Encola auditoría individual sobre una sola dispensación.

```json
{
  "DisDetNro": "T38251201552"
}
```

Respuesta exitosa: HTTP `202`.

Campos principales:
- `audit_id`
- `status`
- `dis_det_nro`

### `POST /audit/async`

Encola una auditoría batch asíncrona.

```json
{
  "facNitSec": 1165,
  "date": "2025-07-01",
  "dateTo": "2025-11-30",
  "limit": 10
}
```

Respuesta exitosa: HTTP `202`.

Campos principales:
- `jobId`
- `status`
- `statusUrl`
- `queueDepth`

### `GET /audit/jobs/{jobId}`

Consulta el estado de un job async.

Validación:
- `jobId`: hexadecimal de 32 a 64 caracteres

### `GET /audit/results`

Consulta auditorías persistidas con filtros y paginación.

Parámetros opcionales:
- `facNitSec`
- `facNro`
- `dateFrom`
- `dateTo`
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
            "orden": 1,
            "severity": "alta"
          },
          {
            "campoNombre": "NombreArticulo",
            "tipoCampo": "S",
            "orden": 8,
            "severity": "alta"
          }
        ],
        "visualChecks": [
          {
            "check": "FirmaActaEntrega",
            "description": "Firma o sello de recibido",
            "severity": "alta",
            "orden": 37
          }
        ]
      }
    }
  }
}
```

### `POST /clients/{clientId}/audit-config`

Guarda/reemplaza completamente la configuración de auditoría. La UI envía solo los campos activos; no existe `enabled` ni `rol` en el contrato runtime.

Body:
```json
{
  "systemPrompt": "...",
  "fields": [
    {
      "docId": 1,
      "campoNombre": "NumeroFactura",
      "tipoCampo": "E",
      "orden": 1,
      "severity": "alta"
    },
    {
      "docId": 1,
      "campoNombre": "FirmaActaEntrega",
      "tipoCampo": "V",
      "orden": 37,
      "description": "Firma o sello de recibido",
      "severity": "alta"
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
