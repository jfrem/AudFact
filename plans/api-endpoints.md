# API Endpoints — AudFact

## Base URL

```
http://localhost:8080
```

> [!IMPORTANT]
> Las rutas **NO** llevan prefijo `/api/`. Se acceden directamente desde la raíz (ej: `/clients`, `/audit/single`).

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

## Configuración

### `GET /config/public`

Devuelve la configuración pública del sistema para uso del frontend.

**Parámetros**: Ninguno

**Respuesta exitosa** (`200`):
```json
{
    "success": true,
    "data": {
        "auditBatchMaxLimit": 10
    }
}
```

---

## Clientes

### `GET /clients`

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

### `POST /clients`

Busca clientes por filtros (lookup).

**Request Body**:
```json
{
    "NitSec": 123
}
```

**Respuesta exitosa** (`200`):
```json
{
    "status": "success",
    "data": { "NitSec": 123, "NitCom": "EPS SALUD TOTAL" }
}
```

---

### `GET /clients/{id}`

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

### `GET /invoices`

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
        { "NitSec": "1165", "FacSec": "90648778", "Dispensa": "T38251201552" }
    ]
}
```

**Errores**:
- `400` — Parámetros `facNitSec` o `date` faltantes/inválidos

---

## Dispensación

### `GET /dispensation/{DisDetNro}`

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

### `GET /dispensation/{invoiceId}/attachments/{nitSec}`

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

### `GET /dispensation/{invoiceId}/attachments/download/{attachmentId}`

Descarga o previsualiza un documento adjunto específico. Soporta dos modos según el header `Accept`.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `invoiceId` | path | string | ✅ | DisDetNro de la factura |
| `attachmentId` | path | string | ✅ | ID del tipo de documento |

**Modo Streaming** (default):
- Content-Type: `application/pdf` o `image/*`
- Body: Archivo binario (streaming)

**Modo JSON** (`Accept: application/json`):
```json
{
    "mime": "application/pdf",
    "data": "JVBERi0xLjQK..."
}
```
- `mime`: Tipo MIME detectado (extensión → magic bytes → `application/octet-stream`)
- `data`: Contenido del archivo codificado en base64

**Detección MIME (magic bytes)**: Cuando el archivo no tiene extensión, se detecta el tipo real por los primeros bytes del contenido: PDF (`%PDF`), JPEG (`FFD8FF`), PNG (`89504E47`), GIF (`47494638`), WEBP (`RIFF..WEBP`), TIFF (`4949`/`4D4D`), ZIP (`504B`).

**Errores**:
- `404` — Documento no encontrado
- `500` — Error de lectura del BLOB

---

## Auditoría IA

### `POST /audit/single`

Ejecuta auditoría IA para una factura individual de forma síncrona.

**Request Body**:
```json
{
    "FacNro": "U88260100225"
}
```

| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `FacNro` | string | ✅ | Número de factura / dispensación |

**Respuesta exitosa** (`200`):
```json
{
    "success": true,
    "message": "Auditoría individual completada",
    "data": {
        "response": "success | warning | error",
        "severity": "ninguna | baja | media | alta",
        "risk_score": 0,
        "message": "Resumen técnico objetivo.",
        "documento": "MULTIPLE",
        "data": { "items": [] },
        "metrics": {
            "TotalCamposEvaluados": 24,
            "TotalCoincidentes": 24,
            "TotalDiscrepancias": 0,
            "Altas": 0, "Medias": 0, "Bajas": 0
        },
        "config_used": { "weights": {}, "thresholds": {}, "max_score": 100 },
        "_meta": {
            "totalTimeMs": 14607,
            "phases": { "dataFetchMs": 621, "filePrepMs": 4816, "geminiApiMs": 8960 },
            "attempts": 1,
            "factura": "U88260100225",
            "documentos": ["ACTA DE ENTREGA", "FORMULA MEDICA", "VALIDADOR DE DERECHOS"],
            "timestamp": "2026-02-27T02:00:21+00:00"
        }
    }
}
```

#### Cómo probar con curl

> [!CAUTION]
> El shell afecta la sintaxis de escape del JSON. Usar la variante correcta.

**PowerShell** (recomendado en Windows):
```powershell
$body = '{"FacNro":"U88260100225"}'
curl.exe -s -X POST http://localhost:8080/audit/single -H "Content-Type: application/json" -d $body
```

**CMD** (Windows):
```cmd
curl.exe -X POST "http://localhost:8080/audit/single" -H "Content-Type: application/json" -d "{\"FacNro\":\"U88260100225\"}"
```

**Bash** (Linux/Mac/WSL):
```bash
curl -X POST http://localhost:8080/audit/single \
  -H "Content-Type: application/json" \
  -d '{"FacNro":"U88260100225"}'
```

---

### `POST /audit`

Ejecuta auditoría IA para un lote de facturas.

**Request Body**:
```json
{
    "facNitSec": 1165,
    "date": "2025-12-30",
    "limit": 5
}
```

| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `facNitSec` | integer | ✅ | NitSec del cliente (EPS) |
| `date` | string | ✅ | Fecha de dispensación (YYYY-MM-DD) |
| `limit` | integer | ✅ | Máximo de facturas a procesar (1–10) |

**Respuesta exitosa** (`200`):
```json
{
    "success": true,
    "message": "Auditoría ejecutada",
    "data": {
        "items": [ { "invoice": {}, "result": {} } ],
        "stoppedEarly": false,
        "totalRequested": 5,
        "totalProcessed": 5
    }
}
```

**Errores**:
- `400` — Datos de entrada inválidos
- `429` — Rate limit o quota Gemini excedida
- `500` — Error del pipeline de auditoría
- `503` — Modelo Gemini no disponible

---

### `GET /audit/results`

Devuelve los resultados persistidos de auditorías IA. Soporta filtros por cliente y fecha.

**Parámetros**:

| Nombre | Ubicación | Tipo | Requerido | Descripción |
|---|---|---|---|---|
| `facNitSec` | query | integer | ❌ | NitSec del cliente |
| `date` | query | string | ❌ | Fecha (YYYY-MM-DD) |

**Respuesta exitosa** (`200`):
```json
{
    "success": true,
    "data": [
        {
            "FacNro": "U88260100225",
            "Estado": "AUD",
            "Resultado": "success",
            "Severidad": "baja",
            "RiskScore": 15,
            "FechaAuditoria": "2026-02-27T02:00:21+00:00"
        }
    ]
}
```

---

## MCP (Model Context Protocol)

### `POST /wrap/webhook.php` (ruta directa, no pasa por Router)

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
