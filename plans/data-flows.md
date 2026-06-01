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

## 2. Protocolo MCP

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

---

## 3. Pipeline de Auditoría Asíncrono (Event-Driven) 🚀

### Descripción
Flujo desacoplado basado en Redis Streams. Permite procesamiento paralelo de documentos, reintentos granulares, observabilidad total por fase (Timings) y reserva idempotente por `FacSec` para que batches concurrentes del mismo cliente no dupliquen auditorías.

### Contrato de Identidad

El batch y la auditoría individual transportan dos identificadores con roles distintos:

| Campo | Origen | Rol |
|---|---|---|
| `fac_sec` | `Factura.FacSec` / `vw_discolnet_dispensas.facsecF` | Llave canónica de auditoría y persistencia (`AudDispEst.FacSec`) |
| `dis_det_nro` | `DispensacionDetalleServicio.DisDetNro` / `vw_discolnet_dispensas.Dispensa` | Llave operativa de dispensación y adjuntos (`AudDispEst.FacNro`) |

`DocumentAuditOrchestrator` resuelve la FDV por `fac_sec` y valida que `FDV.header.FacSec`, `payload.dis_det_nro` y `payload.fac_nit_sec` apunten a la misma factura. Si no coinciden, falla con `AUDIT_IDENTITY_MISMATCH`.

Antes de publicar `audit_created`, el API reserva `FacSec` en Redis con un owner token. `DisDetNro` se conserva como llave operativa de adjuntos y como `FacNro` persistido. Si una auditoría cae a DLQ antes del agregador, el consumidor marca la auditoría como `failed`, actualiza el job si existe y libera la reserva por owner token.

### Flujo

```mermaid
sequenceDiagram
    participant API as AuditController
    participant R as Redis (Streams)
    participant BW as BatchRequestedWorker
    participant DB as SQL Server
    participant AO as DocumentAuditOrchestrator
    participant EW as DocumentExtractionWorker
    participant IV as DocumentIntegrityValidator
    participant G as Google Gemini API
    participant NW as DocumentNormalizer
    participant RW as RulesEvaluationWorker
    participant AW as AuditAggregationWorker

    Note over API,R: Fase HTTP Express (< 100ms)
    API->>R: Guarda/Chequea Job en Redis (BatchJobStore)
    API->>R: XADD audit.batch.inbox {batch_requested}
    API-->>API: Retorna 202 Accepted de inmediato

    Note over R,BW: Fase Asíncrona en Background
    R->>BW: Consume batch_requested
    BW->>DB: Consulta pesada de facturas que califican
    DB-->>BW: Lista de Facturas (FacSec, DisDetNro)
    
    loop Por cada Factura en el Lote
        BW->>R: SETNX audit:reservation:facsec:{FacSec} (Reserva de Idempotencia)
        alt Reserva Exitosa
            BW->>R: XADD audit.inbox {audit_created}
        else Reserva Fallida (Ya procesándose/procesada)
            BW-->>BW: Ignorar factura en este job
        end
    end
    BW->>R: Actualiza metadata del job (Total facturas)

    R->>AO: xReadGroup (Consumer: orchestrator)
    AO->>R: XADD audit.documents {document_registered}
    
    par Paralelo por cada Documento
        R->>EW: xReadGroup (Consumer: extractors)
        EW->>IV: valida tamaño, MIME y magic bytes
        alt Documento no procesable
            EW->>R: XADD audit.documents {document_rejected}
            R->>RW: xReadGroup (Consumer: policy-engine)
            RW->>RW: Genera hallazgo RECHAZADO tipo_auditoria=integrity
        else Documento válido
            EW->>G: generateContent (IA Extraction)
            G-->>EW: JSON Result
            EW->>R: XADD audit.documents {document_extracted}
            R->>NW: xReadGroup (Consumer: normalizers)
            NW->>NW: Estandarización de datos (ISO/UTC)
            NW->>R: XADD audit.documents {document_normalized}
        end
    end

    R->>RW: xReadGroup (Consumer: policy-engine)
    RW->>RW: Evaluación de Reglas vs FDV
    RW->>R: XADD audit.results {rules_evaluated}

    R->>AW: xReadGroup (Consumer: aggregator)
    AW->>DB: Persistencia Final (AudDispEst)
    AW->>R: XADD audit.results {audit_completed}
    AW->>R: DEL audit:reservation:facsec:{FacSec} (owner token)
```

### Eventos Clave (Redis Streams)

| Evento | Stream | Origen | Destino | Propósito |
|---|---|---|---|---|
| `batch_requested` | `audit.batch.inbox` | Controller | Batch Worker | Encola la solicitud del lote para consulta de base de datos pesada |
| `audit_created` | `audit.inbox` | Batch Worker / Sync Controller | Orchestrator | Inicia la orquestación de una auditoría individual |
| `document_registered` | `audit.documents` | Orchestrator | Extractor | Registra un adjunto para extracción por IA |
| `document_extracted` | `audit.documents` | Extractor | Normalizer | Transporta datos crudos extraídos de Gemini |
| `document_rejected` | `audit.documents` | Extractor | Rule Engine | Transporta rechazo preventivo de adjuntos vacíos, corruptos, con MIME inconsistente o no soportados, sin consumir Gemini |
| `document_normalized` | `audit.documents` | Normalizer | Rule Engine | Transporta datos estandarizados listos para reglas |
| `rules_evaluated` | `audit.results` | Rule Engine | Aggregator | Transporta veredicto de reglas y auditoría |
| `audit_completed` | `audit.results` | Aggregator | Bus Global / Job Store | Persiste en DB y notifica fin del proceso |
| `dead_letter` | `audit.dlq` | Cualquier Worker | DLQ Controller | Registra fallos fatales para reintento manual administrativamente |
