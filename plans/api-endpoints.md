# API Endpoints — AudFact

## Base URL

```
http://localhost:80/api
```

---

## Health Check

### `GET /`

Health check del sistema.

**Parámetros**: Ninguno

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "message": "API is running",
    "data": {
        "version": "1.0",
        "environment": "production"
    }
}
```

---

## Clientes

### `GET /api/clients`

Lista todos los clientes (EPS) activos.

**Parámetros**: Ninguno

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": [
        { "NitSec": 123, "NitCom": "EPS SALUD TOTAL" }
    ]
}
```

---

### `GET /api/clients/{id}`

Obtiene un cliente por su NitSec.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `id` | path | integer | ✅ | NitSec del cliente |

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": { "NitSec": 123, "NitCom": "EPS SALUD TOTAL" }
}
```

**Errores**:
- `404` — Cliente no encontrado

---

## Facturas

### `GET /api/invoices`

Busca facturas por cliente y fecha que no han sido auditadas.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `facNitSec` | query | integer | ✅ | NitSec del cliente |
| `date` | query | string | ✅ | Fecha (YYYY-MM-DD) |
| `limit` | query | integer | ❌ | Máximo de resultados (1-1000, default: 100) |

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": [
        { "FacNitSec": 123, "FacSec": 456, "FacNro": "F-001", "DisId": "D-001" }
    ]
}
```

**Errores**:
- `400` — Parámetros `facNitSec` o `date` faltantes/inválidos

---

## Dispensación

### `GET /api/dispensation/{DisDetNro}`

Obtiene los datos completos de dispensación para una factura.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `DisDetNro` | path | string | ✅ | Número de dispensación (factura) |

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": [
        {
            "FacSec": 456,
            "NumeroFactura": "D-001",
            "Cliente": "EPS SALUD TOTAL",
            "NITCliente": "900123456",
            "NombrePaciente": "JUAN PÉREZ",
            "DocumentoPaciente": "12345678",
            "CodigoArticulo": "ART-001",
            "NombreArticulo": "Medicamento X",
            "CantidadEntregada": 30,
            "CantidadPrescrita": 30
        }
    ]
}
```

**Errores**:
- `404` — No se encontraron datos de dispensación

---

## Documentos Adjuntos

### `GET /api/attachments/{invoiceId}`

Lista los documentos adjuntos de una factura.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `invoiceId` | path | string | ✅ | DisDetNro de la factura |
| `nitSec` | query | string | ✅ | NitSec del cliente |

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": [
        {
            "dispiensa": "D-001",
            "factura": "F-001",
            "cliente": 123,
            "id_documento": "DOC-001",
            "nombre_documento": "Acta de Entrega",
            "almacenamiento_remoto": "https://drive.google.com/...",
            "TipoAlmacenamiento": "URL"
        }
    ]
}
```

---

### `GET /api/attachments/{invoiceId}/document/{attachmentId}`

Descarga un documento adjunto específico.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `invoiceId` | path | string | ✅ | DisDetNro de la factura |
| `attachmentId` | path | string | ✅ | ID del tipo de documento |

**Respuesta exitosa** (`200`):
- Content-Type: `application/pdf` o `image/*`
- Body: Archivo binario (streaming)

**Errores**:
- `404` — Documento no encontrado
- `500` — Error de lectura del BLOB

---

## Auditoría IA

### `POST /api/audit`

Ejecuta auditoría IA para una factura individual.

**Request Body**:
```json
{
    "invoiceId": "D-001",
    "clientId": 123
}
```

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": {
        "auditResult": { "...resultado de Gemini..." },
        "processingTime": 5.2
    }
}
```

---

### `POST /api/audit/batch`

Ejecuta auditoría IA para un lote de facturas.

**Request Body**:
```json
{
    "clientId": 123,
    "date": "2026-01-15",
    "invoices": [
        { "FacSec": 456, "FacNro": "F-001", "DisId": "D-001" }
    ]
}
```

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": {
        "results": [
            {
                "invoiceId": "D-001",
                "status": "completed",
                "auditResult": {}
            }
        ],
        "summary": {
            "total": 1,
            "completed": 1,
            "failed": 0
        }
    }
}
```

**Errores**:
- `400` — Datos de entrada inválidos
- `429` — Rate limit o quota Gemini excedida
- `500` — Error del pipeline de auditoría
- `503` — Modelo Gemini no disponible

---

### `GET /api/audit/status/{batchId}`

Consulta el estado de un lote de auditoría.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `batchId` | path | string | ✅ | ID del batch |

---

### `GET /api/audit/results/{invoiceId}`

Obtiene los resultados de auditoría de una factura.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `invoiceId` | path | string | ✅ | DisDetNro de la factura |

---

## MCP (Model Context Protocol)

### `POST /wrap/webhook.php`

Endpoint MCP para asistentes de IA.

**Request Body** (JSON-RPC 2.0):
```json
{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
        "name": "get_clients",
        "arguments": {}
    },
    "id": 1
}
```

**Tools disponibles**:

| Tool | Descripción | Argumentos |
|---|---|---|
| `get_clients` | Lista clientes activos | Ninguno |
| `get_invoices` | Busca facturas | `{ facNitSec, date, limit? }` |
| `get_dispensation` | Datos de dispensación | `{ DisDetNro }` |
| `get_attachments` | Lista adjuntos | `{ invoiceId, nitSec }` |

**Respuesta exitosa**:
```json
{
    "jsonrpc": "2.0",
    "result": {
        "content": [{ "type": "text", "text": "..." }]
    },
    "id": 1
}
```
