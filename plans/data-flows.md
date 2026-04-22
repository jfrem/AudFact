# Flujos de Datos — AudFact

## 1. Consulta REST Simple (Clientes / Facturas / Dispensación)

### Descripción
Flujo estándar para consultas de lectura a la API REST.

### Flujo

```mermaid
sequenceDiagram
    participant U as Usuario/Frontend
    participant N as Nginx (Load Balancer)
    participant P as PHP-FPM (Réplica)
    participant R as Router
    participant MW as Middleware
    participant C as Controller
    participant M as Model
    participant DB as SQL Server

    U->>N: GET /clients
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

## 2. Auditoría IA Principal (Flujo por Lote)

### Descripción
Pipeline completo de auditoría: recibe lote de facturas, obtiene datos de dispensación + documentos adjuntos, envía a Gemini Flash para análisis multimodal, parsea y valida el resultado.

### Flujo

```mermaid
sequenceDiagram
    participant U as Frontend
    participant AC as AuditController
    participant AO as AuditOrchestrator
    participant DM as DispensationModel
    participant AM as AttachmentsModel
    participant AFM as AuditFileManager
    participant GD as Google Drive
    participant EPB as ExtractionPromptBuilder
    participant G as Gemini Flash API
    participant ERS as ExtractionResponseSchema
    participant EG as EmbeddingGateway
    participant SC as SemanticComparator
    participant REG as RuleEngine
    participant DB as SQL Server

    U->>AC: POST /audit {facNitSec, date, limit}
    AC->>AC: Validar input

    loop Para cada factura
        AC->>AO: auditInvoice(invoice)
        AO->>DM: getDispensationData(DisDetNro)
        DM->>DB: SELECT FROM vw_discolnet_dispensas
        DB-->>DM: Datos de dispensación

        AO->>AM: getAttachmentsByInvoiceId(invoiceId, nitSec)
        AM->>DB: SELECT FROM AdjuntosDispensacion...
        DB-->>AM: Lista de adjuntos

        AO->>AFM: resolveFiles(attachments)
        alt TipoAlmacenamiento = URL
            AFM->>GD: Download via JWT
            GD-->>AFM: Binary (temp file)
            AFM->>AFM: base64(file)
        else TipoAlmacenamiento = BLOB
            AFM->>DB: Stream BLOB
            DB-->>AFM: Binary (direct in memory)
            AFM->>AFM: base64(memory)
        end

        AO->>EPB: buildUserPrompt(auditConfig, dispensationData, documents)
        EPB-->>AO: Prompt de extracción

        AO->>G: POST generateContent (Function Calling + archivos)
        G-->>AO: Function call report_extraction

        AO->>ERS: parseExtractionResponse(geminiResponse)
        ERS-->>AO: Campos extraídos por documento

        AO->>SC: compareBatch(pairs, EmbeddingGateway)
        SC->>EG: batchEmbedContents
        EG-->>SC: Vectores semánticos
        SC-->>AO: Similitudes por campo

        AO->>REG: evaluate(fdv, documents, visualChecks, semanticResults)
        REG-->>AO: AuditResult final

        AO-->>AC: AuditResult por factura
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
- Function Calling inválido — se registra y se marca como fallo de extracción
- Validación fallida — Se registra y se marca como `failed`

---

## 3. Auditoría IA (Flujo Individual / Alta Disponibilidad)

### Descripción
Pipeline de evaluación aislado (Single) optimizado para integrarse de forma síncrona visual en Puntos de Dispensación. Utiliza el balanceador de Nginx para distribuir la carga entre las réplicas de FPM en base a `least_conn`.

### Flujo

```mermaid
sequenceDiagram
    participant U as Frontend (Punto Disp.)
    participant N as Nginx Load Balancer
    participant P as PHP-FPM Réplica (1..N)
    participant AO as AuditOrchestrator
    participant G as Gemini Flash API

    U->>N: POST /audit/single {FacNro}
    N->>P: Balanceo (least_conn)
    P->>AO: auditInvoice(DisDetNro)
    AO->>G: generateContent()
    G-->>AO: JSON
    AO-->>P: AuditResult
    P-->>N: 200 JSON
    N-->>U: Respuesta Síncrona
```

### Manejo de Errores
- Nginx distribuye fuera del worker si un contenedor FPM satura sus child processes estáticos.
- Logging segregado mediante nombre de contenedor (`app-{HOSTNAME}...log`).

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
    Tool->>API: GET /clients
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
