# Flujos de Datos — AudFact

## 1. Consulta REST Simple (Clientes / Facturas / Dispensación)

### Descripción
Flujo estándar para consultas de lectura a la API REST.

### Flujo

```mermaid
sequenceDiagram
    participant U as Usuario/Frontend
    participant N as Nginx
    participant P as PHP-FPM
    participant R as Router
    participant MW as Middleware
    participant C as Controller
    participant M as Model
    participant DB as SQL Server

    U->>N: GET /api/clients
    N->>P: FastCGI :9000
    P->>R: Despacha ruta
    R->>MW: Rate Limit + CORS
    MW->>C: ClientsController::index()
    C->>M: ClientsModel::getAllClients()
    M->>DB: SELECT NitSec, NitCom FROM NIT...
    DB-->>M: ResultSet
    M-->>C: array
    C-->>U: 200 JSON {status, data}
```

### Entrada
- Método HTTP + URI + Query params (opcionales)

### Salida
- JSON: `{status: "success", data: [...], message: "..."}`

### Manejo de Errores
- `400` — Parámetros inválidos (Validator)
- `404` — Recurso no encontrado
- `429` — Rate limit excedido
- `500` — Error interno (Logger registra detalle)

---

## 2. Auditoría IA (Flujo Principal)

### Descripción
Pipeline completo de auditoría: recibe lote de facturas, obtiene datos de dispensación + documentos adjuntos, envía a Gemini Flash para análisis multimodal, parsea y valida el resultado.

### Flujo

```mermaid
sequenceDiagram
    participant U as Frontend
    participant AC as AuditController
    participant GAS as GeminiAuditService
    participant DM as DispensationModel
    participant AM as AttachmentsModel
    participant AFM as AuditFileManager
    participant GD as Google Drive
    participant APB as AuditPromptBuilder
    participant G as Gemini Flash API
    participant JRP as JsonResponseParser
    participant ARV as AuditResultValidator
    participant DB as SQL Server

    U->>AC: POST /api/audit/batch {clientId, date, invoices[]}
    AC->>AC: Validar input

    loop Para cada factura
        AC->>GAS: auditInvoice(invoice)
        GAS->>DM: getDispensationData(DisDetNro)
        DM->>DB: SELECT FROM vw_discolnet_dispensas
        DB-->>DM: Datos de dispensación

        GAS->>AM: getAttachmentsByInvoiceId(invoiceId, nitSec)
        AM->>DB: SELECT FROM AdjuntosDispensacion...
        DB-->>AM: Lista de adjuntos

        GAS->>AFM: resolveFiles(attachments)
        alt TipoAlmacenamiento = URL
            AFM->>GD: Download via JWT
            GD-->>AFM: Binary (temp file)
            AFM->>AFM: base64(file)
        else TipoAlmacenamiento = BLOB
            AFM->>DB: Stream BLOB
            DB-->>AFM: Binary (direct in memory)
            AFM->>AFM: base64(memory)
        end

        GAS->>APB: buildPrompt(dispensationData, files)
        APB-->>GAS: Prompt estructurado

        GAS->>G: POST generateContent (prompt + archivos)
        G-->>GAS: Respuesta JSON (puede estar truncada)

        GAS->>JRP: parse(rawResponse)
        JRP->>JRP: repair() si JSON malformado
        JRP-->>GAS: Resultado parseado

        GAS->>ARV: validate(result, schema)
        ARV-->>GAS: ValidationResult

        GAS-->>AC: AuditResult por factura
    end

    AC-->>U: 200 JSON {results: [...]}
```

### Entrada
```json
{
    "clientId": 12345,
    "date": "2026-01-15",
    "invoices": [
        {"FacSec": 1001, "FacNro": "F-001", "DisId": "D-001"}
    ]
}
```

### Salida
```json
{
    "status": "success",
    "data": {
        "results": [
            {
                "invoiceId": "D-001",
                "auditResult": { "...schema definido en AuditResponseSchema..." },
                "status": "completed"
            }
        ],
        "summary": { "total": 1, "completed": 1, "failed": 0 }
    }
}
```

### Manejo de Errores
- `429` — Gemini API quota excedida (reintento con backoff)
- `503` — Modelo no disponible
- JSON truncado — `JsonRepairHelper` intenta reparar
- Validación fallida — Se registra y se marca como `failed`

---

## 3. Protocolo MCP

### Descripción
Flujo de comunicación MCP (Model Context Protocol) para asistentes de IA que consultan datos del sistema.

### Flujo

```mermaid
sequenceDiagram
    participant AI as Asistente IA
    participant WH as webhook.php
    participant MCP as MCPServer
    participant Tool as Tool (ej: GetClients)
    participant API as ApiClient
    participant REST as API REST Interna

    AI->>WH: POST /wrap/webhook.php {jsonrpc: "2.0", method: "tools/call", params: {name: "get_clients"}}
    WH->>MCP: handleRequest()
    MCP->>MCP: Identificar tool
    MCP->>Tool: execute(params)
    Tool->>API: GET /api/clients
    API->>REST: HTTP interno
    REST-->>API: JSON response
    API-->>Tool: Datos
    Tool-->>MCP: Resultado formateado
    MCP-->>AI: {jsonrpc: "2.0", result: {content: [{type: "text", text: "..."}]}}
```

### Entrada
- JSON-RPC 2.0: `{jsonrpc, method, params, id}`

### Salida
- JSON-RPC 2.0: `{jsonrpc, result, id}`

### Manejo de Errores
- Tool no encontrada → `{error: {code: -32601, message: "Method not found"}}`
- Error interno → `{error: {code: -32603, message: "Internal error"}}`
