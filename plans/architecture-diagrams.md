# Diagramas de Arquitectura — AudFact

> Estado actual: backend PHP 8.2-FPM + Nginx, frontend Next.js 15.5.15,
> Redis Streams para auditoría asíncrona y SQL Server externo.
> Fuente operativa: `app/Routes/web.php`, `docker-compose.yml` y `app/Services/Audit/Pipeline/*`.

---

## Level 1 — System Context (Contexto del Sistema)

```mermaid
flowchart TD
    User["Auditor / Usuario<br/>(Frontend Web)"]
    AI["Asistente IA<br/>(MCP Agent)"]
    
    subgraph AudFact["Plataforma AudFact"]
        CoreApp["AudFact Core<br/>(API REST + Workers Event-Driven + Next.js)"]
    end
    
    SQL[("SQL Server<br/>(Discolnet / AudDispEst)")]
    Gemini["Google Gemini API<br/>(gemini-3.7-flash)"]
    Drive["Google Drive API<br/>(Adjuntos PDF / Imágenes)"]
    
    User -->|HTTP / JSON| CoreApp
    AI -->|JSON-RPC / MCP| CoreApp
    CoreApp -->|PDO sqlsrv| SQL
    CoreApp -->|HTTPS / Vision API| Gemini
    CoreApp -->|HTTPS / OAuth2 JWT| Drive
```

---

## Level 2 — Container Diagram (Contenedores)

```mermaid
flowchart TD
    Client["Usuario / Navegador Web"]
    
    subgraph FrontendApp["Frontend (Next.js 15.5)"]
        UI["React Server / Client Components"]
        Proxy["Proxy API Server-Side (/api/backend/*)"]
    end
    
    subgraph BackendApp["Backend (Nginx + PHP 8.2-FPM)"]
        Nginx["Nginx Reverse Proxy (:8080)"]
        PHPFPM["PHP-FPM REST API"]
    end
    
    subgraph Broker["Redis 7 (Distributed Broker)"]
        Streams["Redis Streams<br/>(*.priority / *.batch)"]
        StateStore["Redis State Store<br/>(audit:{id}:* / job:{id}:*)"]
        CacheStore["Redis Cache / DLQ"]
    end
    
    subgraph WorkerPool["Pool de Workers CLI PHP"]
        WBatch["BatchRequestedWorker"]
        WOrch["DocumentAuditOrchestrator"]
        WDown["AttachmentDownloadWorker"]
        WExt["DocumentExtractionWorker"]
        WNorm["DocumentNormalizer"]
        WPol["RulesEvaluationWorker"]
        WPers["AuditPersistenceWorker"]
    end
    
    subgraph ExternalServices["Servicios Externos"]
        SQLServer[("SQL Server")]
        GeminiAPI["Google Gemini API"]
        GDriveAPI["Google Drive API"]
    end
    
    Client --> UI
    UI --> Proxy
    Proxy --> Nginx
    Nginx --> PHPFPM
    
    PHPFPM --> Streams
    PHPFPM --> StateStore
    PHPFPM --> SQLServer
    
    Streams --> WorkerPool
    WorkerPool --> StateStore
    WorkerPool --> CacheStore
    
    WBatch --> SQLServer
    WOrch --> SQLServer
    WDown --> GDriveAPI
    WExt --> GeminiAPI
    WPers --> SQLServer
```

---

## Level 3 — Component Diagram (Componentes del Backend PHP)

```mermaid
flowchart LR
    Router["Router + Middleware<br/>(CORS / RateLimit)"]
    Controllers["Controllers<br/>(Audit, Invoices, Clients)"]
    DataServices["Domain & Pipeline Services<br/>(AuditDataService, Orchestrator)"]
    Models["Models PDO sqlsrv<br/>(AuditStatus, Invoices, Clients)"]
    Publisher["AuditEventPublisher<br/>(Priority & Batch Streams)"]
    
    Redis[("Redis 7")]
    SQL[("SQL Server")]
    
    Router --> Controllers
    Controllers --> DataServices
    Controllers --> Models
    DataServices --> Publisher
    Publisher --> Redis
    Models --> SQL
```

---

## Level 4 — Pipeline de Auditoría IA (Secuencia Event-Driven)

```mermaid
sequenceDiagram
    autonumber
    participant API as AuditController
    participant Redis as Redis Streams/State
    participant Batch as BatchRequestedWorker
    participant Orchestrator as DocumentAuditOrchestrator
    participant Downloader as AttachmentDownloadWorker
    participant Extractor as DocumentExtractionWorker
    participant Gemini as Gemini API
    participant Normalizer as DocumentNormalizer
    participant Policy as RulesEvaluationWorker
    participant Persistence as AuditPersistenceQueue / Worker
    participant SQL as SQL Server

    API->>Redis: batch_requested o audit_created
    Batch->>SQL: getInvoicesForAuditBatch()
    Batch->>Redis: audit_created por FacSec reservado
    Orchestrator->>SQL: FDV + audit-config + adjuntos
    Orchestrator->>Redis: document_registered por adjunto
    Downloader->>Redis: guarda BLOB temporal
    alt Descarga fallida
        Downloader->>Redis: document_rejected
    else Descarga exitosa
        Downloader->>Redis: document_downloaded
        Extractor->>Redis: lee BLOB + DocumentIntegrityValidator
        Extractor->>Gemini: POST generateContent (multimodal)
        alt Gemini Function Calling exitoso
            Gemini-->>Extractor: function calls estructurados
            Extractor->>Redis: document_extracted
            Normalizer->>Redis: document_normalized
            Policy->>Policy: DocumentPolicyEngine evalua reglas
            Policy->>Redis: rules_evaluated
            Persistence->>Persistence: AuditPersistenceQueue turno por job
            Persistence->>SQL: transaccion dual idempotente
            Persistence->>Redis: audit_completed
        else Error de decodificacion / PDF corrupto
            Gemini-->>Extractor: HTTP 400 INVALID_ARGUMENT
            Extractor->>Redis: document_rejected (GEMINI_DECODE_FAILURE)
            Policy->>Policy: consolidacion glosa soporte
            Persistence->>SQL: persiste resultado manual_review
            Persistence->>Redis: audit_completed
        end
    end
```

---

## Level 4.1 — Topología y Flujo en 6 Etapas del Pipeline de Auditoría

```mermaid
flowchart TD
    subgraph Trigger ["0. Disparo HTTP o API"]
        Req["HTTP POST /audit/single o /audit/async"] -->|"Publica audit_created"| S_Inbox[("Stream: audit.inbox")]
    end

    subgraph E1 ["Etapa 1: Orquestación"]
        S_Inbox --> W1["DocumentAuditOrchestrator"]
        W1 -->|"1. Consulta FDV"| DB_Fdv[("SQL Server: vw_discolnet_dispensas")]
        W1 -->|"2. Resuelve audit-config"| DB_Cfg[("SQL Server: AudDispCampo")]
        W1 -->|"3. Reconcilia Lógico vs Físico"| Matcher["DocumentAttachmentMatcher"]
        W1 -->|"4. Construye Schemas Gemini"| CBuilder["DocumentExtractionContractBuilder"]
        W1 -->|"Publica N x document_registered"| S_Docs[("Stream: audit.documents")]
    end

    subgraph E2 ["Etapa 2: Descarga"]
        S_Docs --> W2["AttachmentDownloadWorker (8 replicas)"]
        W2 -->|"Descarga BLOB o Drive"| Storage["Google Drive API / SQL Server BLOB"]
        W2 -->|"Guarda temporal en Redis"| R_Blob[("Redis: audit:blob:id")]
        W2 -->|"Publica document_downloaded"| S_Down[("Stream: audit.downloads")]
    end

    subgraph E3 ["Etapa 3: Extracción IA"]
        S_Down --> W3["DocumentExtractionWorker (8 replicas)"]
        W3 -->|"Verifica cache y estructura"| CacheMgr["ExtractionCacheManager"]
        W3 -->|"Parallel Function Calling"| Gemini["Google Gemini API Gateway"]
        W3 -->|"Parser 3 fases y sanitización"| Parser["GeminiResponseParser"]
        W3 -->|"Publica document_extracted"| S_Extr[("Stream: audit.extractions")]
    end

    subgraph E4 ["Etapa 4: Normalización"]
        S_Extr --> W4["DocumentNormalizer"]
        W4 -->|"Fechas ISO, upper, sin tildes, numeros"| TextNorm["TextNormalization e IdentityDocNormalizer"]
        W4 -->|"Publica document_normalized"| S_Norm[("Stream: audit.normalizations")]
    end

    subgraph E5 ["Etapa 5: Evaluación de Reglas"]
        S_Norm --> W5["RulesEvaluationWorker (2 replicas)"]
        W5 --> Engine["DocumentPolicyEngine"]
        Engine -.->|"Fallback semantico farmacos"| Judge["ArticleSemanticMatchJudge"]
        W5 --> Cross1["DocumentDuplicationEvaluator"]
        W5 --> Cross2["DeliveryValidityEvaluator"]
        W5 -->|"Lua: docs_done = docs_total"| S_Eval[("Cola: AuditPersistenceQueue")]
    end

    subgraph E6 ["Etapa 6: Agregación y Persistencia"]
        S_Eval --> W6["AuditPersistenceWorker (3 replicas)"]
        W6 -->|"Transaccion atomica dual con retry"| DB_Persist[("SQL Server: dbo.AudDispEst")]
        W6 -->|"audit_completed / audit_failed"| S_Out[("Telemetria SSE / UI")]
    end

    subgraph ErrorHandling ["Gestión de Fallos y Resiliencia"]
        W1 -.->|"Fallo tras max retries"| DLQ[("Stream: audit.dlq")]
        W2 -.->|"Fallo tecnico o descarga"| DLQ
        W3 -.->|"Fallo de extraccion"| DLQ
        W5 -.->|"Fallo de evaluacion"| DLQ
        W6 -.->|"Fallo transaccional"| DLQ
    end

    classDef stage fill:#0f172a,stroke:#38bdf8,stroke-width:2px,color:#f8fafc,rx:6px,ry:6px;
    classDef stream fill:#1e1b4b,stroke:#818cf8,stroke-width:2px,color:#c7d2fe,rx:8px,ry:8px;
    classDef storage fill:#064e3b,stroke:#34d399,stroke-width:2px,color:#d1fae5,rx:6px,ry:6px;
    classDef dlq fill:#450a0a,stroke:#f87171,stroke-width:2px,color:#fecaca,rx:6px,ry:6px;

    class W1,W2,W3,W4,W5,W6,Engine,Matcher,CBuilder,Parser,TextNorm stage;
    class S_Inbox,S_Docs,S_Down,S_Extr,S_Norm,S_Eval,S_Out stream;
    class DB_Fdv,DB_Cfg,DB_Persist,Storage,R_Blob storage;
    class DLQ dlq;
```

---


## Level 5 — Diagrama de Clases del Pipeline

```mermaid
classDiagram
    class AuditEventConsumer {
        <<abstract>>
        #streams() array
        #handle(event) void
        #processEvent(event) void
    }

    class BatchRequestedWorker {
        +handle(batch_requested) void
    }

    class DocumentAuditOrchestrator {
        +handle(audit_created) void
    }

    class AttachmentDownloadWorker {
        +handle(document_registered) void
    }

    class DocumentExtractionWorker {
        +handle(document_downloaded) void
    }

    class DocumentNormalizer {
        +handle(document_extracted) void
    }

    class RulesEvaluationWorker {
        +handle(document_normalized) void
        +handle(document_rejected) void
    }

    class DocumentPolicyEngine {
        +evaluate(document, fdv, config) array
    }

    class AuditPersistenceQueue {
        +enqueue(rules_evaluated) void
        +advance(event) void
    }

    class AuditPersistenceWorker {
        +handle(rules_evaluated) void
    }

    AuditEventConsumer <|-- BatchRequestedWorker
    AuditEventConsumer <|-- DocumentAuditOrchestrator
    AuditEventConsumer <|-- AttachmentDownloadWorker
    AuditEventConsumer <|-- DocumentExtractionWorker
    AuditEventConsumer <|-- DocumentNormalizer
    AuditEventConsumer <|-- RulesEvaluationWorker
    AuditEventConsumer <|-- AuditPersistenceWorker
    DocumentAuditOrchestrator --> DocumentExtractionContractBuilder
    DocumentExtractionWorker --> DocumentIntegrityValidator
    RulesEvaluationWorker --> DocumentPolicyEngine
    BatchRequestedWorker --> AuditEventPublisher
    DocumentAuditOrchestrator --> AuditEventPublisher
    AttachmentDownloadWorker --> AuditEventPublisher
    DocumentExtractionWorker --> AuditEventPublisher
    DocumentNormalizer --> AuditEventPublisher
    RulesEvaluationWorker --> AuditPersistenceQueue
    AuditPersistenceQueue --> AuditEventPublisher
    AuditPersistenceWorker --> AuditPersistenceQueue
    AuditPersistenceWorker --> AuditEventPublisher
```

---

## Level 6 — Resiliencia en Extracción Documental (Pre-IA y Post-IA)

```mermaid
flowchart TD
    A[Descarga de Documento / BLOB] --> B[Nivel 1: DocumentIntegrityValidator]
    B -->|PDF sin %%EOF o Truncado| C[Rechazo Preventivo: CORRUPTED_DOCUMENT]
    B -->|PDF sin Páginas| D[Rechazo Preventivo: EMPTY_PDF_NO_PAGES]
    B -->|PDF con Password| E[Rechazo Preventivo: ENCRYPTED_DOCUMENT]
    B -->|Estructura Válida| F[Llamada a Google Gemini API]
    
    F -->|200 OK| G[document_extracted]
    F -->|400 INVALID_ARGUMENT / Decode Error| H[Nivel 2: DocumentExtractionWorker]
    H -->|Clasificación Determinista| I[Rechazo: GEMINI_DECODE_FAILURE]
    
    C --> J[Emitir document_rejected]
    D --> J
    E --> J
    I --> J
    J --> K[Incrementar docs_done 3/3]
    G --> K
    K --> L[RulesEvaluationWorker: Sellar Auditoría y Batch al 100%]
```
