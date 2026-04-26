# PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md

> Version de documento: v1.0 | Fecha: 2026-04-23
> **Proyecto:** `AudFact`
> **Modo:** `STRICT`
> **Cambio:** Clean rebuild del pipeline de auditoria documental IA event-driven con Redis Streams.

---

## 0. Modo del documento

### 0.1 Regla de seleccion

- `STRICT` es obligatorio.
- No aplica `COMPACT`: el cambio afecta contratos publicos, workers, Redis, Gemini, persistencia y arquitectura.

### 0.2 Regla de enforcement

- No se permite compatibilidad legacy.
- No se permite Redis Lists.
- No se permite flujo sincronico de auditoria IA.
- No se permite codigo muerto, adaptadores, shims ni wrappers para preservar el pipeline anterior.

### 0.3 Regla de placeholders

- No quedan placeholders vivos.
- Items no confirmados quedan como `BLOCKED`.

---

## 1. Objetivo del documento

Definir la implementacion verificable del nuevo pipeline de auditoria documental IA de AudFact, basado en eventos Redis Streams, procesamiento por documento, extraccion estructurada con Gemini, normalizacion deterministica, reglas PHP auditables y persistencia final en SQL Server.

---

## 2. Problema a resolver

El pipeline actual concentra demasiadas responsabilidades en controladores, orquestador monolitico, Redis Lists y worker batch. Esto impide escalar por documento, controlar reintentos por etapa, auditar contratos intermedios y separar extraccion IA de decision de negocio.

**Problema tecnico concreto:**

- `POST /audit/single` ejecuta auditoria sincronica.
- `POST /audit/async` depende de un servicio legacy inconsistente.
- El worker batch actual procesa facturas completas, no documentos como unidad minima.
- No existe DLQ.
- No existe cache de extraccion por hash documental.
- No existe contrato event-driven para `audit_created`, `document_registered`, `document_extracted`, `document_normalized`, `rules_evaluated`, `audit_completed`.

---

## 3. Estado normativo y nivel de certeza

### 3.1 Etiquetas obligatorias

- `CONFIRMED`: validado por codigo, curl local o requisito explicito.
- `INFERRED`: deducido del diseno aprobado.
- `PROVISIONAL`: requiere aprobacion antes de implementar.
- `BLOCKED`: no implementable hasta resolver decision externa.

### 3.2 Regla de autonomia

- Se puede implementar `CONFIRMED`.
- `INFERRED` requiere test y ADR.
- `PROVISIONAL` y `BLOCKED` requieren aprobacion explicita.

---

## 4. Alcance por release

### v1.0 - MVP operativo clean rebuild

- [ ] Reemplazar pipeline legacy por Redis Streams.
- [ ] Reescribir `POST /audit/single` para responder `202` con `audit_id`.
- [ ] Reescribir `POST /audit/async` para responder `202` con `job_id`.
- [ ] Procesar cada documento como unidad minima.
- [ ] Construir schema Gemini desde `audit-config`.
- [ ] Aplicar regla estricta: `TipoCampo = D` a `fields`, `TipoCampo = V` a `visual_checks`.
- [ ] Extraer con Gemini function calling.
- [ ] Cachear extraccion por `document_hash`.
- [ ] Normalizar campos en PHP.
- [ ] Evaluar reglas en PHP deterministico.
- [ ] Persistir resultado final con decisiones documentales.
- [ ] Implementar DLQ.
- [ ] Eliminar `AuditQueueService`, `AuditOrchestrator`, `AuditOrchestratorFactory` y `bin/audit-worker.php`.

### v1.1 - Hardening operativo

- [ ] Recuperacion de mensajes pendientes con `XPENDING` y `XCLAIM`.
- [ ] Metricas por etapa.
- [ ] Endpoint administrativo de reproceso DLQ.
- [ ] Control de concurrencia por cliente.

### v1.2 - Optimizacion

- [ ] Circuit breaker por tasa de error en ventana temporal.
- [ ] Limites diarios por cliente.
- [ ] Particion avanzada para documentos grandes.

---

## 5. Priorizacion estricta

| Prioridad | Significado | Regla |
|---|---|---|
| `P0` | Critico | Sin esto no hay MVP funcional |
| `P1` | Alto | Mejora resiliencia y operacion |
| `P2` | Medio | Optimizacion y gobierno |
| `P3` | Bajo | Expansion futura |

---

## 6. Principios de diseno

1. **Clean rebuild:** reemplazar modulos deficientes, no envolverlos.
2. **Event-driven:** toda etapa avanza por eventos versionados.
3. **Documento como unidad minima:** la factura agrupa documentos, pero el procesamiento inicia por documento.
4. **IA solo extrae:** Gemini no toma decisiones de negocio.
5. **Reglas deterministicas:** normalizacion y policy engine viven en PHP.
6. **Contract-first:** cada evento tiene entrada, salida y errores esperados.
7. **Configuracion estricta:** `TipoCampo` define comportamiento; no se infiere por nombre.
8. **Idempotencia:** `audit_id`, `document_id` y `document_hash` controlan reprocesamiento.
9. **Sin codigo muerto:** todo legacy reemplazado se elimina en el mismo release.

---

## 7. Criterios de autonomia del agente

### 7.1 Puede decidir sin preguntar cuando

- El item este `CONFIRMED`.
- No cambie auth ni secretos.
- Respete los contratos de este documento.
- El cambio elimine legacy obsoleto ya identificado.

### 7.2 Debe detenerse cuando

- Cambie persistencia fisica de SQL Server.
- Requiera nueva tabla de equivalencias CUM.
- Cambie autenticacion/autorizacion.
- Necesite redefinir reglas de negocio por cliente no presentes en `audit-config`.

---

## 8. Protocolo de comunicacion y registro de operaciones

### 8.1 Transportes soportados

| Transporte | Protocolo | Canal | Auth | Estado |
|---|---|---|---|---|
| API REST | HTTP JSON | `/audit/single`, `/audit/async`, `/audit/jobs/{jobId}` | Sin auth general actual | `CONFIRMED` |
| Fuente de verdad | HTTP JSON interno | `/dispensation/{DisDetNro}` | Backend interno | `CONFIRMED` |
| Adjuntos | HTTP JSON interno | `/dispensation/{invoiceId}/attachments/{nitSec}` | Backend interno | `CONFIRMED` |
| Descarga documento | HTTP JSON interno | `/dispensation/{invoiceId}/attachments/download/{attachmentId}` | Backend interno | `CONFIRMED` |
| Config cliente | HTTP JSON interno | `/clients/{clientId}/audit-config` | Backend interno | `CONFIRMED` |
| Redis Streams | Redis | `audit.inbox`, `audit.documents`, `audit.results`, `audit.dlq` | Redis interno | `CONFIRMED` |
| Gemini | HTTPS REST | `generateContent` | `GEMINI_API_KEY` | `CONFIRMED` |

### 8.2 Contrato `POST /audit/single`

- **Estado:** `CONFIRMED`
- **Content-Type requerido:** `application/json`
- **HTTP esperado:** `202 Accepted`
- **Idempotencia:** no aplica por request; cada llamada valida crea un `audit_id` nuevo.
- **Validacion de entrada:**
  - `DisDetNro`: string requerido, trim, longitud `1..255`.
- **Payload de entrada:**

```json
{
  "DisDetNro": "T38250701547"
}
```

- **Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Auditoria encolada",
  "data": {
    "audit_id": "uuid-v4",
    "status": "pending",
    "dis_det_nro": "T38250701547"
  }
}
```

- **Errores expuestos:**

| HTTP | Condicion | Mensaje |
|---|---|---|
| `400` | JSON invalido o content type invalido | `Payload JSON invalido` |
| `422` | `DisDetNro` ausente o invalido | `DisDetNro es requerido` |
| `503` | Redis no disponible | `No se pudo encolar la auditoria` |

### 8.3 Contrato `POST /audit/async`

- **Estado:** `CONFIRMED`
- **Content-Type requerido:** `application/json`
- **HTTP esperado:** `202 Accepted`
- **Idempotencia:** un cliente no puede tener mas de un batch activo para el mismo `facNitSec` y rango de fechas.
- **Validacion de entrada:**
  - `facNitSec`: integer requerido, `>= 1`.
  - `date`: fecha requerida formato `YYYY-MM-DD`.
  - `dateTo`: fecha opcional formato `YYYY-MM-DD`, debe ser `>= date`.
  - `limit`: integer opcional, rango `1..100`, default `100`.
- **Payload de entrada:**

```json
{
  "facNitSec": 2426,
  "date": "2025-07-29",
  "dateTo": "2025-07-29",
  "limit": 10
}
```

- **Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Batch de auditoria encolado",
  "data": {
    "job_id": "uuid-v4",
    "status": "pending",
    "total": 10
  }
}
```

- **Resolucion de facturas:**
  - Fuente primaria: `POST /invoices`.
  - Payload enviado: `facNitSec`, `dateFrom`, `dateTo`, `limit`.
  - `dateFrom` recibe el valor de entrada `date`.
  - Cada item de respuesta debe contener `NitSec`, `FacSec`, `Dispensa`.
  - `Dispensa` se usa como `DisDetNro` del evento `audit_created`.
  - `FacSec` se conserva en metadata de auditoria para persistencia/idempotencia.
  - `GET /invoices` no participa en el flujo del pipeline; queda solo como endpoint de consulta/manual/debug.
  - Aunque `GET /invoices` y `POST /invoices` hoy compartan logica funcional, el pipeline **debe** estandarizarse unicamente sobre `POST /invoices`.

- **Errores expuestos:**

| HTTP | Condicion | Mensaje |
|---|---|---|
| `400` | JSON invalido o content type invalido | `Payload JSON invalido` |
| `409` | Batch activo duplicado para la misma llave logica | `Ya existe un batch activo para el cliente y rango solicitado` |
| `422` | Validacion de entrada falla | Mensaje especifico del campo |
| `503` | Redis no disponible | `No se pudo encolar el batch` |

### 8.4 Contrato `GET /audit/jobs/{jobId}`

- **Estado:** `CONFIRMED`
- **HTTP esperado:** `200 OK`
- **Path param:** `jobId`: UUID v4 string requerido.
- **Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Estado del job",
  "data": {
    "job_id": "uuid-v4",
    "status": "processing",
    "total": 10,
    "done": 3,
    "failed": 0,
    "pending": 7,
    "created_at": "2026-04-23T10:00:00Z",
    "updated_at": "2026-04-23T10:01:30Z",
    "audits": [
      {
        "audit_id": "uuid-v4",
        "dis_det_nro": "T38250701547",
        "status": "completed"
      }
    ]
  }
}
```

- **Estados validos de job:** `pending`, `processing`, `completed`, `completed_with_errors`, `failed`.
- **Errores expuestos:**

| HTTP | Condicion | Mensaje |
|---|---|---|
| `404` | Job no existe o expiro | `No se encontro el job solicitado` |
| `422` | `jobId` invalido | `jobId invalido` |
| `503` | Redis no disponible | `No se pudo consultar el estado del job` |

### 8.5 Registro de operaciones

| Operacion | Release | Prioridad | Dependencia | Tipo | Estado |
|---|---|---|---|---|---|
| `create_audit_event` | v1.0 | P0 | Redis | Write | `CONFIRMED` |
| `resolve_audit_context` | v1.0 | P0 | `/dispensation/{DisDetNro}` | Read | `CONFIRMED` |
| `resolve_client_documents` | v1.0 | P0 | `/clients/{clientId}/documents` | Read | `CONFIRMED` |
| `resolve_client_audit_config` | v1.0 | P0 | `/clients/{clientId}/audit-config` | Read | `CONFIRMED` |
| `register_documents` | v1.0 | P0 | Redis Streams | Write | `CONFIRMED` |
| `extract_document_data` | v1.0 | P0 | Gemini | Read | `CONFIRMED` |
| `normalize_document_data` | v1.0 | P0 | PHP | Read/Write event | `CONFIRMED` |
| `evaluate_document_policy` | v1.0 | P0 | PHP | Read/Write event | `CONFIRMED` |
| `aggregate_audit_result` | v1.0 | P0 | Redis + SQL Server | Write | `CONFIRMED` |
| `reprocess_dlq_event` | v1.1 | P1 | Redis DLQ | Write | `PROVISIONAL` |

### 8.6 Streams y consumer groups

| Stream | Eventos permitidos | Consumer group | Productor | Consumidor |
|---|---|---|---|---|
| `audit.inbox` | `audit_created`, `batch_created` | `orchestrator` | `AuditController` | `DocumentAuditOrchestrator` |
| `audit.documents` | `document_registered`, `document_extracted`, `document_normalized`, `extraction_failed` | `extractors`, `normalizers`, `policy` | Orchestrator, extractor, normalizer | Extractor, normalizer, policy |
| `audit.results` | `rules_evaluated`, `audit_completed`, `audit_failed`, `batch_completed`, `batch_completed_with_errors` | `aggregator` | Policy, aggregator | Aggregator |
| `audit.dlq` | `dead_letter` | `dlq-admin` | Cualquier consumer | Reproceso administrativo v1.1 |

### 8.7 Reglas Redis Streams

- `XGROUP CREATE` debe ejecutarse de forma idempotente al arrancar cada worker.
- `XREADGROUP` debe usar `COUNT 1` en MVP para simplificar ack y trazabilidad.
- `XACK` solo se ejecuta despues de publicar exitosamente el evento siguiente o persistir el resultado final.
- Si una etapa falla con error recuperable, el evento se reintenta hasta `AUDIT_EVENT_MAX_RETRIES`.
- Si supera `AUDIT_EVENT_MAX_RETRIES`, se publica `dead_letter` en `audit.dlq` y se marca el documento o auditoria como `failed`.
- No se permite borrar mensajes de streams en v1.0.

---

## 9. Comportamiento detallado por operacion

### 9.1 `create_audit_event`

- **Estado:** `CONFIRMED`
- **Entrada single:** `DisDetNro`, `job_id = null`
- **Entrada batch:** `DisDetNro`, `facNitSec`, `job_id`
- **Salida:** evento `audit_created`
- **Errores:** payload invalido, Redis no disponible
- **Regla:** si `audit:{audit_id}:status` existe, no duplicar.
- **Nota:** en modo single, `facNitSec` se resuelve desde FDV durante `resolve_audit_context`; no se recibe desde el cliente.

### 9.2 `resolve_audit_context`

- **Estado:** `CONFIRMED`
- **Entrada:** `DisDetNro`
- **Salida:** `header`, `items`
- **Fuente:** `/dispensation/{DisDetNro}`
- **Errores:** 404, payload sin header, payload sin `NitSec`
- **Contrato minimo de salida:**

```json
{
  "header": {
    "FacSec": "87723098",
    "NumeroFactura": "T38250701547",
    "NitSec": "2426"
  },
  "items": []
}
```
- **Regla:** `header.FacSec`, `header.NumeroFactura` y `header.NitSec` son obligatorios para continuar.

### 9.3 `resolve_client_audit_config`

- **Estado:** `CONFIRMED`
- **Entrada:** `NitSec`
- **Salida:** documentos, campos `D`, checks `V`, `systemPrompt`
- **Regla critica:** no inferir visual checks por nombre.
- **Ejemplo 1165:** `FirmaActaEntrega` como `D` queda en `fields`.
- **Ejemplo 2426:** `FirmaActaEntrega` y `FirmaPrescriptor` como `V` quedan en `visual_checks`.
- **Contrato minimo normalizado:**

```json
{
  "nitSec": "2426",
  "activo": true,
  "systemPrompt": null,
  "documents": {
    "DISPENSA": {
      "docId": 1,
      "fields": ["NombrePaciente"],
      "visualChecks": [
        {
          "check": "FirmaActaEntrega",
          "description": "Firma o sello de recibido del paciente/acudiente",
          "severity": "CRITICO"
        }
      ]
    }
  }
}
```
- **Errores:** configuracion ausente, `activo=false`, documento sin `docId`, campos no-array, visual check sin `check`.

### 9.4 `register_documents`

- **Estado:** `CONFIRMED`
- **Entrada:** audit context, config cliente, adjuntos
- **Salida:** N eventos `document_registered`
- **Errores:** adjuntos faltantes, documento sin config, schema invalido
- **Matching documento-config:**
  - Primero por `id_documento == docId`.
  - Si no hay match por id, se intenta match exacto normalizado por `nombre_documento`.
  - Si no hay match, el documento queda `failed` con error `DOCUMENT_CONFIG_NOT_FOUND`.
- **Documentos requeridos:**
  - Todo documento presente en `audit-config.documents` activo se considera requerido para MVP.
  - Si no existe adjunto para un documento requerido, la auditoria pasa a `failed` con error `REQUIRED_ATTACHMENT_MISSING`.
- **Orden:** publicar documentos en orden ascendente por `docId`.

### 9.5 `extract_document_data`

- **Estado:** `CONFIRMED`
- **Entrada:** `document_registered`
- **Salida:** `document_extracted`
- **Cache:** `extraction:cache:{document_hash}`, TTL `AUDIT_CACHE_TTL`
- **Errores:** descarga fallida, Gemini 429/503, function call invalido
- **Retry:** 3 intentos con backoff exponencial
- **DLQ:** al agotar intentos definitivos
- **Descarga:** el worker usa `Accept: application/json`; la respuesta debe contener `mime` y `data` base64.
- **Hash:** `document_hash = sha256(base64_data)`.
- **Cache hit:** si existe `extraction:cache:{document_hash}`, publicar `document_extracted` con `cache_hit=true` y no llamar Gemini.
- **Function calling:** la funcion unica del MVP se llama `extract_document_data`.
- **Output invalido:** si Gemini no invoca la funcion o omite `fields`, se reintenta; al agotar intentos, DLQ.

### 9.6 `normalize_document_data`

- **Estado:** `CONFIRMED`
- **Entrada:** `document_extracted`
- **Salida:** `document_normalized`
- **Reglas:** fechas ISO, texto uppercase sin tildes, cantidades numericas, nombres normalizados
- **CUM:** equivalencia farmacologica queda `BLOCKED` hasta confirmar tabla
- **Reglas exactas MVP:**
  - Fechas validas se convierten a `YYYY-MM-DD`.
  - Cantidades eliminan caracteres no numericos excepto punto decimal y se convierten a string canonico.
  - Texto general aplica `trim`, mayusculas y remocion de tildes.
  - Null, string vacio y valores no visibles se normalizan como `null`.
- **Log obligatorio:** todo campo transformado agrega entrada en `normalization_log`.

### 9.7 `evaluate_document_policy`

- **Estado:** `CONFIRMED`
- **Entrada:** todos los `document_normalized` del `audit_id`
- **Salida:** `rules_evaluated`
- **Resultados validos:** `COINCIDE`, `VALOR_DISTINTO`, `NO_ENCONTRADO`, `OMITIDO`, `NO_CONCLUYENTE`
- **Regla:** policy engine espera contador `docs:done == docs:total`.
- **Comparacion MVP:**
  - Campos numericos, codigos, IDs y fechas usan comparacion exacta normalizada.
  - Nombres de persona usan similitud con umbral `0.85`.
  - Nombres de articulo usan similitud con umbral `0.82`.
  - Texto general usa similitud con umbral `0.90`.
  - `OMITIDO`: FDV y documento son `null` o vacios.
  - `NO_ENCONTRADO`: FDV tiene valor y documento no tiene valor.
  - `NO_CONCLUYENTE`: el documento existe pero la calidad visual, segmentacion o matching no permiten afirmar `COINCIDE` ni `VALOR_DISTINTO` con confianza suficiente.
- **Severidad:**
  - `CRITICO` en visual checks equivale a `alta`.
  - Campos sin severidad configurada usan `media`.
- **DocumentoFallido:**
  - Primero el documento con mas discrepancias `alta`.
  - En empate, menor `docId`.
  - Si no hay `alta`, usar mayor severidad disponible.
- **Regla de cadena documental:**
  - La formula medica es el origen clinico de la prescripcion.
  - La autorizacion aplica cuando el pagador/EPS/aseguradora aprueba entrega total o parcial.
  - La dispensa prueba lo efectivamente entregado por el gestor farmaceutico.
  - No todos los documentos tienen que repetir los mismos valores; cada campo se evalua segun autoridad documental.
- **Regla de cantidad base:**
  - Si existe autorizacion: `cantidad_entregada_total <= cantidad_autorizada`.
  - Si no existe autorizacion: `cantidad_entregada_total <= cantidad_prescrita`.
  - Una entrega parcial frente a formula o autorizacion no es discrepancia.
  - Una formula con productos adicionales no entregados no es discrepancia.
- **Consolidacion de cantidades desde `items`:**
  - Si un documento contiene `items[]`, la cantidad del documento para comparacion se obtiene sumando las lineas matcheadas del mismo producto.
  - `cantidad_entregada_total` se calcula sobre la suma de `CantidadEntregada` de todos los `items` consolidados por producto.
  - `cantidad_prescrita_total` se calcula sobre la suma de `CantidadPrescrita` de todos los `items` consolidados por producto cuando la formula venga segmentada.
  - Si el documento no tiene `items[]`, el policy engine puede usar `fields` solo para comparar campos escalares del documento.
  - Si el documento no tiene `items[]`, el policy engine no puede construir lineas, filas ni cantidades por item a partir de `fields`.
  - Toda comparacion por producto, linea o cantidad consolidada requiere `items[]`; si esa estructura no existe, la comparacion itemizada se omite o se clasifica como `NO_CONCLUYENTE` segun criticidad y legibilidad.
  - En el golden case `T38250701547`, la dispensa debe consolidar `20 + 30 = 50` antes de comparar contra la autorizacion.
- **Criterio deterministico para `NO_CONCLUYENTE`:**
  - `NO_CONCLUYENTE` solo aplica si `document_quality != legible` o si el matching de producto produce multiples candidatos plausibles sin resolucion por codigo/CUM.
  - `VALOR_DISTINTO` aplica cuando la comparacion es posible y los valores difieren respecto al umbral o exactitud esperada.
  - `NO_ENCONTRADO` aplica cuando el campo esperado no aparece en el documento aun siendo legible.
  - Un campo critico con `NO_CONCLUYENTE` fuerza `manual_review`.
- **Regla especial por factor de empaque:**
  - Para clientes especificos por `NitSec`, se permite que `cantidad_entregada_total` supere la cantidad autorizada/prescrita hasta `5` unidades cuando la diferencia se justifique por factor de empaque.
  - Esta regla solo aplica a los clientes listados explicitamente en `23.5`.
  - Si el `NitSec` no esta listado, no se permite exceso.
  - Si el exceso es `> 5`, siempre es discrepancia `alta`.

### 9.8 `aggregate_audit_result`

- **Estado:** `CONFIRMED`
- **Entrada:** `rules_evaluated`
- **Salida:** persistencia en `AudDispEst` y evento `audit_completed`
- **Errores:** persistencia fallida, decisiones documentales invalidas
- **Regla:** batch actualiza `job:{job_id}:done` y `job:{job_id}:failed`.
- **EstadoDetallado:**
  - `completed`: sin discrepancias `alta` y sin documentos fallidos.
  - `manual_review`: procesamiento completo con visual check critico fallido o campo critico fallido.
  - `error`: procesamiento completo con discrepancias `alta` no criticas.
  - `failed`: fallo tecnico que impidio completar evaluacion.
- **RequiereRevisionHumana:** `1` solo para `manual_review` o `failed`; `0` para `completed` y `error`.
- **Evento final:** publicar `audit_completed` si persistencia fue exitosa; publicar `audit_failed` si persistencia falla.

---

## 10. Arquitectura tecnica

### 10.1 Stack

| Capa | Tecnologia |
|---|---|
| Lenguaje | PHP 8.2 |
| API | MVC custom |
| Eventos | Redis Streams |
| IA | Gemini function calling |
| Persistencia | SQL Server PDO sqlsrv |
| Documentos | Google Drive URL + BLOB |
| Tests | PHPUnit |

### 10.2 Estructura objetivo

```text
app/Services/Audit/
├── Events/
│   ├── AuditEvent.php
│   ├── AuditEventPublisher.php
│   ├── AuditEventConsumer.php
│   └── AuditStateStore.php
├── Schema/
│   └── SchemaBuilder.php
├── Orchestration/
│   └── DocumentAuditOrchestrator.php
├── Extraction/
│   ├── DocumentExtractionWorker.php
│   └── ExtractionCache.php
├── Normalization/
│   └── DocumentNormalizer.php
├── Policy/
│   └── DocumentPolicyEngine.php
├── Aggregation/
│   └── AuditResultAggregator.php
└── GeminiGateway.php

bin/
├── audit-orchestrator-worker.php
├── audit-extraction-worker.php
├── audit-normalizer-worker.php
├── audit-policy-worker.php
└── audit-aggregator-worker.php
```

### 10.3 Flujo de datos

```text
POST /audit/single
  -> audit_created
    -> DocumentAuditOrchestrator
      -> document_registered
        -> DocumentExtractionWorker
          -> document_extracted
            -> DocumentNormalizer
              -> document_normalized
                -> DocumentPolicyEngine
                  -> rules_evaluated
                    -> AuditResultAggregator
                      -> audit_completed
```

---

## 11. Contratos de salida

### 11.1 Evento base

```json
{
  "event_id": "uuid-v4",
  "parent_event_id": "uuid-v4|null",
  "event_type": "audit_created",
  "audit_id": "uuid-v4",
  "job_id": "uuid-v4|null",
  "document_id": "string|null",
  "timestamp": "2026-04-23T10:00:00Z",
  "version_extractor": "gemini-3.x",
  "version_normalizer": "1.0.0",
  "version_rules": "1.0.0",
  "payload": {}
}
```

- **Regla:** todos los eventos se serializan como JSON UTF-8 sin datos binarios ni base64.
- **Regla:** `timestamp` usa UTC en formato ISO 8601.
- **Regla:** `event_id`, `audit_id`, `job_id` y `parent_event_id` usan UUID v4 cuando no son `null`.
- **Regla:** `document_id` usa el identificador del adjunto (`id_documento`) como string.
- **Regla:** `payload` nunca incluye API keys, tokens, contrasenas, datos base64 ni contenido completo de documentos.
- **Regla:** la FDV completa puede vivir en Redis por `24h` cuando sea necesaria para orquestacion, normalizacion y policy, pero nunca como parte del payload de eventos entre etapas; se guarda en claves de estado.

### 11.1.1 `audit_created`

```json
{
  "event_type": "audit_created",
  "audit_id": "uuid-v4",
  "job_id": null,
  "document_id": null,
  "payload": {
    "dis_det_nro": "T38250701547",
    "fac_nit_sec": null,
    "source": "single"
  }
}
```

### 11.1.2 `batch_created`

```json
{
  "event_type": "batch_created",
  "audit_id": null,
  "job_id": "uuid-v4",
  "document_id": null,
  "payload": {
    "fac_nit_sec": "2426",
    "date_from": "2025-07-29",
    "date_to": "2025-07-29",
    "limit": 10,
    "total": 10
  }
}
```

### 11.2 `document_registered`

```json
{
  "event_type": "document_registered",
  "audit_id": "uuid-v4",
  "job_id": "uuid-v4|null",
  "document_id": "1",
  "payload": {
    "tipo_documento": "DISPENSA",
    "nombre_alternativo": "ANE",
    "download_url": "/dispensation/T38250701547/attachments/download/1",
    "tipo_almacenamiento": "URL",
    "extraction_schema": {},
    "visual_checks": [],
    "system_prompt": null,
    "fuente_verdad": {
      "header": {},
      "items": []
    }
  }
}
```

### 11.3 `document_extracted`

```json
{
  "event_type": "document_extracted",
  "audit_id": "uuid-v4",
  "document_id": "1",
  "payload": {
    "tipo_documento": "DISPENSA",
    "fields": {},
    "visual_checks_resultado": [],
    "document_hash": "sha256",
    "cache_hit": false,
    "gemini_attempts": 1
  }
}
```

### 11.4 `document_normalized`

```json
{
  "event_type": "document_normalized",
  "audit_id": "uuid-v4",
  "document_id": "1",
  "payload": {
    "tipo_documento": "DISPENSA",
    "fields_normalized": {},
    "visual_checks_resultado": [],
    "normalization_log": []
  }
}
```

### 11.5 `rules_evaluated`

```json
{
  "event_type": "rules_evaluated",
  "audit_id": "uuid-v4",
  "payload": {
    "hallazgos": {
      "items": [],
      "metrics": {
        "total_campos": 0,
        "coincidencias": 0,
        "discrepancias": 0,
        "omitidos": 0,
        "risk_score": 0
      }
    },
    "document_decisions": []
  }
}
```

### 11.6 `dead_letter`

```json
{
  "event_type": "dead_letter",
  "audit_id": "uuid-v4",
  "job_id": "uuid-v4|null",
  "document_id": "1|null",
  "payload": {
    "failed_event_type": "document_registered",
    "failed_stream": "audit.documents",
    "failed_stage": "extractor",
    "attempts": 3,
    "last_error_code": "GEMINI_SCHEMA_INVALID",
    "last_error_message": "Gemini no invoco extract_document_data",
    "original_event": {}
  }
}
```

### 11.7 Codigos de error internos

| Codigo | Etapa | Significado | Recuperable |
|---|---|---|---|
| `REDIS_UNAVAILABLE` | controller/worker | Redis no disponible | Si |
| `FDV_NOT_FOUND` | orchestrator | `/dispensation/{DisDetNro}` no retorno datos | No |
| `FDV_INVALID` | orchestrator | FDV sin `FacSec`, `NumeroFactura` o `NitSec` | No |
| `AUDIT_CONFIG_NOT_FOUND` | orchestrator | Cliente sin audit-config activo | No |
| `REQUIRED_ATTACHMENT_MISSING` | orchestrator | Falta adjunto requerido por config | No |
| `DOCUMENT_CONFIG_NOT_FOUND` | orchestrator | Adjunto sin configuracion de documento | No |
| `ATTACHMENT_DOWNLOAD_FAILED` | extractor | No se pudo descargar adjunto | Si |
| `GEMINI_HTTP_RETRYABLE` | extractor | Gemini 429/500/502/503/504 | Si |
| `GEMINI_SCHEMA_INVALID` | extractor | Function call ausente o payload invalido | Si |
| `NORMALIZATION_FAILED` | normalizer | Error transformando fields | No |
| `POLICY_INCOMPLETE_INPUT` | policy | Faltan documentos normalizados | Si |
| `PERSISTENCE_FAILED` | aggregator | Error SQL o decisiones invalidas | Si |
| `GEMINI_CREDENTIALS_MISSING` | extractor | `GEMINI_API_KEY` ausente | No |

### 11.8 Contrato Gemini `extract_document_data`

```json
{
  "name": "extract_document_data",
  "description": "Extrae datos estructurados y verificaciones visuales de un documento de auditoria farmaceutica.",
  "parameters": {
    "type": "object",
    "properties": {
      "fields": {
        "type": "object",
        "additionalProperties": {
          "type": ["string", "number", "boolean", "null"]
        }
      },
      "items": {
        "type": "array",
        "items": {
          "type": "object",
          "additionalProperties": {
            "type": ["string", "number", "boolean", "null"]
          }
        }
      },
      "visual_checks": {
        "type": "array",
        "items": {
          "type": "object",
          "properties": {
            "check": {"type": "string"},
            "presente": {"type": "boolean"},
            "detalle": {"type": "string"},
            "severidad": {"type": "string"}
          },
          "required": ["check", "presente"]
        }
      },
      "document_quality": {
        "type": "string",
        "enum": ["legible", "parcialmente_legible", "ilegible"]
      },
      "quality_notes": {
        "type": "array",
        "items": {
          "type": "string"
        }
      }
    },
    "required": ["fields", "visual_checks", "document_quality"]
  }
}
```

- **Regla:** `fields` solo contiene campos `TipoCampo = D`.
- **Regla:** `visual_checks` solo contiene checks `TipoCampo = V`.
- **Regla:** si el documento no tiene checks `V`, Gemini debe retornar `visual_checks: []`.
- **Regla:** `items` es opcional; su ausencia no autoriza derivar lineas o filas desde `fields`.
- **Regla dura sobre `items`:**
  - `items` solo puede usarse cuando el documento presenta lineas o filas claramente segmentables.
  - En documentos narrativos o mixtos, no se permite inventar `items` a partir de `fields`.
  - El normalizador no puede derivar `items` desde `fields`.
  - Si el documento es narrativo, la salida valida es `items: []` o ausencia de `items`.
- **Regla de calidad:** `document_quality` es obligatorio y debe reflejar la legibilidad global del documento para uso del policy engine.

---

## 12. Variables de entorno

```bash
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_PREFIX=audfact:

AUDIT_CACHE_TTL=86400
AUDIT_STREAM_BLOCK_MS=5000
AUDIT_EVENT_MAX_RETRIES=3
AUDIT_DLQ_STREAM=audit.dlq
AUDIT_INTERNAL_API_BASE=http://nginx

GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.1-pro-preview
GEMINI_TIMEOUT=300
GEMINI_MAX_OUTPUT_TOKENS=8192
GEMINI_TEMPERATURE=0.0
```

### 12.1 Variables nuevas obligatorias en `.env.example`

| Variable | Default | Requerida | Estado | Uso |
|---|---|---|---|---|
| `AUDIT_STREAM_BLOCK_MS` | `5000` | No | `CONFIRMED` | Bloqueo de `XREADGROUP` |
| `AUDIT_EVENT_MAX_RETRIES` | `3` | No | `CONFIRMED` | Reintentos por evento antes de DLQ |
| `AUDIT_DLQ_STREAM` | `audit.dlq` | No | `CONFIRMED` | Nombre del stream DLQ |
| `AUDIT_CACHE_TTL` | `86400` | No | `CONFIRMED` | TTL cache de extraccion |
| `AUDIT_INTERNAL_API_BASE` | `http://nginx` | Si | `CONFIRMED` | Base URL usada por workers para consumir API interna |
| `AUDIT_FDV_TTL` | `86400` | No | `CONFIRMED` | TTL de la fuente de verdad completa en Redis |

### 12.2 Reglas de configuracion

- Si una variable opcional falta, se usa el default documentado.
- Si `AUDIT_INTERNAL_API_BASE` falta o esta vacia, los workers deben terminar con codigo de salida `1`.
- Si `GEMINI_API_KEY` falta, el extractor falla con `GEMINI_CREDENTIALS_MISSING` y envia el evento a DLQ sin reintentos.
- Si Redis falta en controller, la API responde `503`.
- Si Redis falta en worker, el worker debe terminar con codigo de salida `1`.

### 12.3 Cliente HTTP interno para workers

- Base URL: `AUDIT_INTERNAL_API_BASE`.
- Timeout por request interno: `REQUEST_TIMEOUT_MS`, default `60000`.
- Headers obligatorios:
  - `Accept: application/json`
  - `Content-Type: application/json` solo para requests con body.
- No se envia `X-API-KEY` en v1.0 porque los endpoints internos actuales no tienen auth general.
- Si en el futuro se agrega auth interna, este documento debe actualizar contratos antes de implementar.

### 12.4 Politica de datos en Redis

- Redis puede contener la FDV completa por `24h`.
- TTL por defecto: `AUDIT_FDV_TTL = 86400`.
- La FDV completa se guarda solo en claves de estado, no dentro del payload de eventos.
- La FDV almacenada puede incluir datos personales del paciente presentes en `/dispensation/{DisDetNro}`.
- Redis no puede almacenar:
  - base64 de documentos,
  - binarios,
  - API keys,
  - tokens,
  - contrasenas.
- La clave canonica es:

```text
audit:{audit_id}:fuente_verdad
```

- El formato almacenado es el JSON completo devuelto por `resolve_audit_context`.

---

## 13. Rate limiting y resiliencia

| Componente | Estrategia |
|---|---|
| Gemini | retry exponencial, circuit breaker, DLQ |
| Redis Streams | ack explicito, retry count, DLQ |
| Descarga adjuntos | error accionable por documento |
| Persistencia | transaccion SQL |
| Batch | `completed`, `completed_with_errors`, `failed` |

### 13.1 Backoff por etapa

| Etapa | Intentos | Esperas | DLQ |
|---|---:|---|---|
| Orquestador | 1 | No aplica | Si |
| Extractor descarga | 3 | `2s`, `4s`, `8s` | Si |
| Extractor Gemini | 3 | `2s`, `4s`, `8s` | Si |
| Normalizador | 1 | No aplica | Si |
| Policy | 3 | `2s`, `4s`, `8s` si faltan docs | Si |
| Agregador persistencia | 3 | `2s`, `4s`, `8s` | Si |

### 13.2 Idempotencia

| Nivel | Llave | Regla |
|---|---|---|
| Auditoria | `audit:{audit_id}:status` | Si existe en `processing/completed/manual_review/failed`, no recrear estado |
| Documento registrado | `audit:{audit_id}:docs:{document_id}:registered` | No publicar dos veces el mismo documento |
| Extraccion | `extraction:cache:{document_hash}` | Cache hit evita llamada Gemini |
| Normalizacion | `audit:{audit_id}:docs:{document_id}` | Sobrescritura permitida solo con mismo `document_hash` |
| Persistencia | `FacSec` | Upsert/MERGE |
| Batch | `job:active:{facNitSec}:{dateFrom}:{dateTo}` | Impide duplicados activos |

### 13.3 TTL de estado

| Key | TTL |
|---|---:|
| `audit:{audit_id}:*` | `86400` segundos |
| `job:{job_id}:*` | `86400` segundos |
| `job:active:{facNitSec}:{dateFrom}:{dateTo}` | `86400` segundos |
| `extraction:cache:{document_hash}` | `AUDIT_CACHE_TTL` |
| `audit:{audit_id}:fuente_verdad` | `AUDIT_FDV_TTL` |

---

## 14. Testing

### 14.1 Cobertura minima v1.0

| Area | Minimo |
|---|---|
| Eventos Redis | 80% |
| SchemaBuilder | 90% |
| Normalizer | 80% |
| PolicyEngine | 80% |
| Aggregator | 80% |
| Controllers audit | 75% |

### 14.2 Tests obligatorios

```text
tests/Services/Audit/
├── Events/
├── Schema/SchemaBuilderTest.php
├── Extraction/ExtractionCacheTest.php
├── Normalization/DocumentNormalizerTest.php
├── Policy/DocumentPolicyEngineTest.php
├── Aggregation/AuditResultAggregatorTest.php
└── Fixtures/
    ├── client_1165_config.php
    ├── client_2426_config.php
    └── client_3080_config.php
```

### 14.3 Casos negativos obligatorios

| Test | Esperado |
|---|---|
| `POST /audit/single` sin `DisDetNro` | `422` |
| Redis caido al encolar | `503` |
| Cliente sin audit-config activo | `audit_failed` + codigo `AUDIT_CONFIG_NOT_FOUND` |
| Documento requerido sin adjunto | `audit_failed` + codigo `REQUIRED_ATTACHMENT_MISSING` |
| Adjunto sin config | `audit_failed` + codigo `DOCUMENT_CONFIG_NOT_FOUND` |
| Gemini sin function call | retry y luego DLQ |
| Config 1165 con `FirmaActaEntrega` tipo `D` | schema lo incluye en `fields`, no en `visual_checks` |
| Config 2426 con checks `V` | schema los incluye en `visual_checks` |
| Persistencia falla | `audit_failed` + codigo `PERSISTENCE_FAILED` |

---

## 15. Gates de release

| Gate | Criterio | Evidencia |
|---|---|---|
| Tests | PHPUnit pasa | Log local/CI |
| Redis Lists eliminado | 0 usos en pipeline audit | `rg "lpush|brpop|audit:queue"` |
| Legacy eliminado | 0 referencias a clases obsoletas | `rg "AuditOrchestrator|AuditQueueService"` |
| Schema estricto | fixtures 1165/2426/3080 pasan | PHPUnit |
| DLQ | evento fallido llega a `audit.dlq` | Test integracion |
| Persistencia | registro final en `AudDispEst` | Test/model integration |
| Docs | README, CHANGELOG, skills actualizados | Diff |

### 15.1 Definicion de Done v1.0

El release v1.0 solo se considera `Done` si:

- Los endpoints `POST /audit/single`, `POST /audit/async` y `GET /audit/jobs/{jobId}` cumplen los contratos de este documento.
- Al menos un caso real de cliente `2426` completa el flujo E2E hasta persistencia.
- Los fixtures `1165`, `2426` y `3080` validan el `SchemaBuilder`.
- `rg "audit:queue|lpush|brpop"` no encuentra uso dentro del pipeline de auditoria.
- `rg "AuditOrchestrator|AuditQueueService|audit-worker.php"` no encuentra referencias vivas fuera de changelog/documentacion historica.
- PHPUnit pasa.
- `CHANGELOG.md`, `.env.example`, README y skill `audfact-audit-gemini` estan actualizados.
- La auditoria real del caso `T38250701547` ejecutada por terminal humana retorna el golden result esperado documentado en `23.9`.

### 15.2 Gate de aceptacion humana obligatoria

La implementacion del nuevo pipeline solo se considera **exitosa** si un auditor humano ejecuta exactamente este comando en terminal:

```powershell
Invoke-RestMethod -Uri "http://localhost:8080/audit/single" -Method POST -ContentType "application/json" -Body '{"DisDetNro":"T38250701547"}' | ConvertTo-Json -Depth 20
```

y el resultado funcional de la auditoria coincide con el golden case definido en `23.9`.

Reglas:

- Si el resultado difiere materialmente del golden case esperado, la implementacion se considera **fracaso**.
- No se acepta como exito una respuesta "parecida" ni semanticamente aproximada.
- Los siguientes elementos deben coincidir:
  - estado global esperado,
  - necesidad de revision humana,
  - motivo principal de revision,
  - no penalizacion de entrega parcial,
  - no auditoria de `NumeroAutorizacion` en formula medica,
  - presencia valida de `FirmaActaEntrega`,
  - presencia valida de `FirmaPrescriptor`.

### 15.3 Regla de commit y autorizacion final

- Durante la implementacion, los agentes pueden editar archivos, ejecutar pruebas, validar contratos y preparar cambios locales.
- Durante la implementacion, los agentes **no pueden** ejecutar `git commit`, `git tag`, `git push` ni acciones equivalentes de cierre de versionado.
- El commit solo puede realizarse al finalizar la implementacion y unicamente si se cumplen simultaneamente estas condiciones:
  1. El flujo implementado cumple los criterios de `15.1`.
  2. El gate humano de `15.2` fue ejecutado y el resultado coincide materialmente con el golden case `T38250701547`.
  3. El usuario autoriza explicitamente el commit en esta conversacion.
- Si el golden case falla o queda materialmente distinto, el estado del trabajo vuelve a `In Dev` o `QA`; no puede pasar a `Done` ni habilitar commit.
- La ausencia de autorizacion explicita del usuario bloquea cualquier accion de commit aun cuando la implementacion y el golden case ya esten correctos.

---

## 16. Flujos E2E esperados

### 16.1 Flujo single

`POST /audit/single` recibe `DisDetNro`, publica `audit_created`, retorna `audit_id`, workers procesan documentos, agregador persiste resultado, estado final `completed`, `manual_review` o `failed`.

### 16.2 Flujo batch

`POST /audit/async` resuelve facturas no auditadas, crea `job_id`, publica un `audit_created` por factura, actualiza progreso y termina como `completed`, `completed_with_errors` o `failed`.

### 16.3 Flujo DLQ

Si una etapa agota reintentos, publica en `audit.dlq` con payload original, errores acumulados y etapa fallida.

---

## 17. Riesgos y mitigaciones

| Riesgo | Severidad | Mitigacion | Owner |
|---|---|---|---|
| Config visual mal cargada como `D` | Alta | Respetar `TipoCampo`; no inferir | Backend |
| Redis Streams mal ack | Alta | Consumer comun + tests | Backend |
| Ruptura frontend por `202` | Media | Actualizar frontend en mismo release | Frontend/Backend |
| Gemini devuelve schema invalido | Alta | Validacion + retry + DLQ | Backend |
| Persistencia parcial | Alta | Transaccion SQL | Backend |
| Codigo legacy residual | Alta | Gate `rg` obligatorio | Tech Lead |
| Regla de factor de empaque extrapolada a clientes no autorizados | Alta | Limitar implementacion exclusivamente a `NitSec 2426` y agregar test negativo para otros clientes | Product Owner + Auditoria |

---

## 18. Definiciones operativas

| Termino | Definicion |
|---|---|
| Auditoria | Proceso completo asociado a `audit_id` |
| Batch | Grupo de auditorias asociado a `job_id` |
| Documento | Unidad minima de extraccion |
| FDV | Fuente de verdad desde `/dispensation/{DisDetNro}` |
| Campo D | Campo documental extraible |
| Campo V | Verificacion visual |
| DLQ | Stream de eventos fallidos definitivos |
| Clean rebuild | Reemplazo sin compatibilidad legacy |

---

## 19. ADR

| # | Decision | Alternativas descartadas | Justificacion | Estado |
|---|---|---|---|---|
| ADR-01 | Usar Redis Streams | Redis Lists | Necesario para consumer groups, ack y DLQ | `ACTIVA` |
| ADR-02 | Eliminar pipeline legacy | Compatibilidad temporal | Fase temprana exige clean rebuild | `ACTIVA` |
| ADR-03 | `TipoCampo` gobierna schema | Inferir por nombre | Evita decisiones ocultas y drift | `ACTIVA` |
| ADR-04 | Gemini solo extrae | IA decide reglas | Reglas deben ser auditables | `ACTIVA` |
| ADR-05 | Documento es unidad minima | Factura como unidad minima | Mejora paralelismo e idempotencia | `ACTIVA` |
| ADR-06 | FDV por API REST interna | Acceso directo a vistas en workers | Contrato ya validado por curl local | `ACTIVA` |
| ADR-07 | Equivalencia CUM diferida | Crear tabla sin confirmacion | Falta confirmar existencia/fuente | `BLOCKED` |
| ADR-08 | La evaluacion sigue cadena Formula -> Autorizacion -> Dispensa | Comparar todos los campos en todos los documentos | Cada documento tiene autoridad distinta en el proceso de negocio | `ACTIVA` |
| ADR-09 | Entregas parciales son validas | Exigir igualdad exacta entre prescrito/autorizado/entregado | Autorizacion y dispensa pueden ser parciales | `ACTIVA` |
| ADR-10 | Exceso por factor de empaque hasta 5 unidades solo aplica por NitSec configurado | Permitir exceso global | Regla de negocio excepcional por cliente | `ACTIVA` |

---

## 19.1 Matriz de gaps cerrados y pendientes

| Gap | Estado | Resolucion | Impacto implementacion |
|---|---|---|---|
| Frontera de FDV | `CERRADO` | Usar API REST interna `/dispensation/{DisDetNro}` | Workers no consultan vistas SQL directamente para FDV |
| Frontera de adjuntos | `CERRADO` | Usar endpoints de adjuntos y descarga JSON base64 | Extractor no usa `AttachmentsModel` directamente |
| Fuente de config | `CERRADO` | Usar `/clients/{clientId}/audit-config` | `SchemaBuilder` recibe contrato normalizado |
| Visual checks | `CERRADO` | Solo `TipoCampo = V` | No inferir por nombre |
| `FirmaActaEntrega` cliente 1165 | `CERRADO` | Tratar como `D` mientras BD lo tenga como `D` | Test fixture obligatorio |
| Cola | `CERRADO` | Redis Streams, no Redis Lists | Eliminar `AuditQueueService` |
| Unidad minima | `CERRADO` | Documento | Workers por etapa documental |
| DLQ | `CERRADO` | `audit.dlq` con evento `dead_letter` | Implementar desde v1.0 |
| Equivalencia CUM | `BLOCKED` | No existe fuente confirmada | Normalizador usa passthrough |
| Clientes con factor de empaque | `CERRADO` | Lista confirmada: solo `2426` | Regla implementable en v1.0 |
| Reproceso DLQ API | `PROVISIONAL` | Diferido v1.1 | No implementar en v1.0 |
| Control de concurrencia avanzado | `PROVISIONAL` | Diferido v1.1 | Solo idempotencia batch por llave logica |
| Limites diarios por cliente | `PROVISIONAL` | Diferido v1.2 | No bloquear MVP |

---

## 20. Roadmap visual

```text
Sprint 1
├── Redis Streams
├── AuditEvent
├── StateStore
└── Controllers 202

Sprint 2
├── SchemaBuilder
├── Orquestador documental
└── document_registered

Sprint 3
├── Extractor
├── Gemini FC
├── Cache hash
└── DLQ

Sprint 4
├── Normalizador
├── PolicyEngine
└── rules_evaluated

Sprint 5
├── Aggregator
├── Persistencia
├── Batch completion
└── Limpieza legacy + docs + QA
```

### 20.1 Sprint 1 - Contrato de ejecucion

**Objetivo:** dejar lista la base event-driven y eliminar el bloqueo actual de `POST /audit/async`.

**Implementar:**

- `RedisClient` con operaciones Streams.
- `AuditEvent`.
- `AuditEventPublisher`.
- `AuditEventConsumer`.
- `AuditStateStore`.
- `AuditController::single()` event-driven.
- `AuditController::async()` event-driven.
- `AuditController::jobStatus()` desde `AuditStateStore`.

**Eliminar:**

- `AuditQueueService`.
- Referencias a Redis Lists en auditoria.

**Regla de orden de retiro legacy:**

- El codigo legacy no se elimina al inicio del sprint.
- Primero debe existir el nuevo flujo funcional de `POST /audit/single`, `POST /audit/async` y `GET /audit/jobs/{jobId}` sobre Redis Streams.
- Solo despues de verificar los criterios de cierre de Sprint 1 se elimina `AuditQueueService` y cualquier referencia operativa a Redis Lists.
- No se permite una ventana intermedia en la que los endpoints queden sin flujo operativo.

**Criterio de cierre:**

- `POST /audit/single` retorna `202` y existe evento en `audit.inbox`.
- `POST /audit/async` retorna `202`, crea `job_id` y publica N eventos `audit_created`.
- `GET /audit/jobs/{jobId}` retorna estado desde Redis.

### 20.2 Sprint 2 - Schema y orquestacion documental

**Objetivo:** convertir auditorias en documentos registrados con schema cerrado.

**Implementar:**

- `SchemaBuilder`.
- `DocumentAuditOrchestrator`.
- Matching config/adjuntos.
- Contadores `docs:total` y documentos registrados.

**Criterio de cierre:**

- Para cliente `2426`, se publican 3 `document_registered`.
- Para fixture `1165`, `FirmaActaEntrega` queda en `fields`.
- Para fixture `2426`, `FirmaActaEntrega` y `FirmaPrescriptor` quedan en `visual_checks`.

### 20.3 Sprint 3 - Extraccion

**Objetivo:** extraer documentos individualmente con cache y DLQ.

**Implementar:**

- `DocumentExtractionWorker`.
- `ExtractionCache`.
- Contrato Gemini `extract_document_data`.
- Hash documental.
- Retry y DLQ.

**Criterio de cierre:**

- Cache hit evita llamada Gemini.
- Gemini invalido produce DLQ tras 3 intentos.
- Evento valido produce `document_extracted`.

### 20.4 Sprint 4 - Normalizacion y policy

**Objetivo:** transformar extraccion en hallazgos auditables.

**Implementar:**

- `DocumentNormalizer`.
- `DocumentPolicyEngine`.
- Comparacion por campo.
- Calculo de severidad, risk score y documento fallido.

**Criterio de cierre:**

- `docs:done == docs:total` dispara `rules_evaluated`.
- Hallazgos usan solo resultados permitidos.
- No hay decisiones de negocio en Gemini.

### 20.5 Sprint 5 - Agregacion, persistencia y limpieza

**Objetivo:** cerrar auditorias y dejar una sola arquitectura vigente.

**Implementar:**

- `AuditResultAggregator`.
- Persistencia final.
- Eventos `audit_completed`, `audit_failed`, `batch_completed`, `batch_completed_with_errors`.
- Limpieza de legacy, tests y docs.

**Criterio de cierre:**

- Caso `T38250701547` / `2426` persiste resultado.
- No quedan referencias vivas a pipeline legacy.
- PHPUnit pasa.

---

## 21. Gobernanza de sprints

| Sprint | Owner | Reviewer | Approver |
|---|---|---|---|
| Sprint 1 | Backend | Tech Lead | Engineering Owner |
| Sprint 2 | Backend | Arquitectura IA | Engineering Owner |
| Sprint 3 | Backend | Seguridad/IA | Engineering Owner |
| Sprint 4 | Backend | Auditoria Funcional | Engineering Owner |
| Sprint 5 | Backend | QA/Tech Lead | Product/Engineering Owner |

---

## 22. Referencias y fuentes de verificacion

| Fuente | Ubicacion | Evidencia |
|---|---|---|
| Repo AudFact | `C:\Users\USER\Desktop\AudFact` | Codigo actual |
| Rutas REST | `app/Routes/web.php` | Endpoints existentes |
| Curl FDV | `/dispensation/T38250701547` | Fuente de verdad operativa |
| Curl adjuntos | `/dispensation/T38250701547/attachments/2426` | 3 documentos URL |
| Curl docs 1165 | `/clients/1165/documents` | 4 documentos cliente |
| Curl config 2426 | `/clients/2426/audit-config` | Checks `V` confirmados |
| Input usuario | Conversacion actual | Clean rebuild, no legacy |
| Input usuario | Conversacion actual | Flujo negocio Formula -> Autorizacion -> Dispensa |
| Input usuario | Conversacion actual | Excepcion por factor de empaque hasta 5 unidades para clientes especificos |
| Input usuario | Conversacion actual | Redis puede contener FDV completa por 24h |

---

## 23. Reglas documentales derivadas de inspeccion visual y flujo de negocio

### 23.1 Cadena de autoridad documental

| Documento | Rol de negocio | Autoridad principal |
|---|---|---|
| Formula medica | Origen clinico de la necesidad | Paciente, medico, fecha formula, diagnostico, productos prescritos, firma/sello prescriptor |
| Autorizacion | Aprobacion del pagador/EPS/aseguradora | Numero autorizacion, fecha autorizacion, producto autorizado, cantidad autorizada, vigencia, pagador |
| Dispensa / acta de entrega | Evidencia de entrega real | Cantidad entregada, lote, vencimiento, fecha entrega, firma de recibido |

### 23.2 Reglas de coherencia entre documentos

- Lo entregado debe estar prescrito en formula medica.
- Si el producto requiere autorizacion, lo entregado debe estar autorizado.
- Lo entregado puede ser menor que lo autorizado.
- Lo autorizado puede ser menor que lo prescrito.
- Productos prescritos pero no entregados no generan hallazgo.
- Productos autorizados pero no entregados no generan hallazgo.
- Productos entregados sin soporte en formula ni autorizacion generan discrepancia `alta`.
- Identidad del paciente debe coincidir en documentos criticos.

### 23.3 Autoridad por campo MVP

| Campo | Documento autoridad | Regla |
|---|---|---|
| `NombrePaciente` | Todos | Debe coincidir normalizado en formula, autorizacion y dispensa cuando exista |
| `DocumentoPaciente` | Todos | Comparacion exacta normalizada |
| `CodigoDiagnostico` | Todos | Debe coincidir en formula medica, autorizacion y dispensa; si falta, no es legible o difiere en cualquiera, genera hallazgo |
| `FechaFormula` | Formula medica | Dispensa puede repetirla; formula manda |
| `FechaAutorizacion` | Autorizacion | Dispensa puede repetirla; autorizacion manda |
| `NumeroAutorizacion` | Autorizacion | No se audita contra formula medica; formula queda fuera de evaluacion para este campo |
| `NombreArticulo` | Cadena completa | Matching por codigo/CUM/nombre normalizado |
| `CUM` | Dispensa o autorizacion si visible | Comparacion exacta cuando existe |
| `CantidadPrescrita` | Formula medica | Se usa como limite si no hay autorizacion |
| `CantidadAutorizada` | Autorizacion | Se usa como limite cuando existe autorizacion |
| `CantidadEntregada` | Dispensa | Se suma por producto para validar contra autorizacion/prescripcion |
| `Lote` | Dispensa | Solo se valida contra dispensa/FDV, no contra formula |
| `FechaVencimiento` | Dispensa | Solo se valida contra dispensa/FDV |
| `FirmaActaEntrega` | Dispensa | Check visual si `TipoCampo = V` |
| `FirmaPrescriptor` | Formula medica | Check visual si `TipoCampo = V` |
| `SelloPrescriptor` | Formula medica | No auditable en v1.0 salvo que exista como `TipoCampo = V` |

### 23.4 Matching de productos

Orden obligatorio de matching:

1. `CUM` o codigo producto exacto si ambos documentos lo exponen.
2. Codigo interno o codigo autorizado si existe equivalencia explicita.
3. Nombre normalizado con similitud semantica.
4. Alias/equivalencia farmacologica solo si existe fuente confirmada.

Reglas:

- Diferencias entre descripcion comercial y descripcion interna no son discrepancia si el matching por codigo/CUM o similitud supera umbral.
- Formula medica puede contener muchos productos no entregados; el policy engine solo debe buscar soporte para productos entregados.
- Si un producto entregado no aparece en formula ni autorizacion, el resultado es `VALOR_DISTINTO` con severidad `alta`.

### 23.4.2 Regla explicita sobre `CodigoDiagnostico`

- `CodigoDiagnostico` **si es auditable en los tres documentos**:
  - `FORMULA MEDICA`
  - `AUTORIZACION`
  - `DISPENSA`
- El campo debe evaluarse en los tres cuando el documento exista dentro de la auditoria.
- Si en cualquiera de los tres no aparece, no es legible o no puede extraerse con confianza, el resultado del campo no se omite: genera hallazgo.
- Clasificacion:
  - visible y coincidente: `COINCIDE`
  - visible y distinto: `VALOR_DISTINTO`
  - ausente: `NO_ENCONTRADO`
  - presente pero ilegible o visualmente insuficiente: `NO_CONCLUYENTE`
- Un `CodigoDiagnostico` `NO_CONCLUYENTE` o `NO_ENCONTRADO` en uno de los tres documentos impide clasificar el caso como `completed`.

### 23.4.1 Regla explicita sobre `NumeroAutorizacion`

- `NumeroAutorizacion` **no es auditable en formula medica**.
- La formula medica queda excluida de cualquier comparacion, warning u observacion sobre ese campo.
- Autoridad del campo:
  1. Autorizacion
  2. Dispensa
- Si formula medica contiene un numero distinto, el pipeline debe ignorarlo completamente.

### 23.5 Regla excepcional por factor de empaque

- **Estado:** `CONFIRMED`
- **Descripcion:** algunos clientes identificados por `NitSec` permiten entregas superiores hasta `5` unidades cuando el exceso se explica por factor de empaque o blister.
- **Limite maximo:** `5` unidades sobre cantidad autorizada o prescrita.
- **Aplicacion:** solo para `NitSec` explicitamente listados.
- **Lista de NitSec autorizados:** `2426` unicamente.
- **Criterio de aceptacion:** para `NitSec = 2426`, una entrega con exceso `<= 5` se clasifica `COINCIDE` con warning `ACEPTADO_POR_EMPAQUE`; una entrega con exceso `> 5` se clasifica `VALOR_DISTINTO` severidad `alta`.
- **Regla de no propagacion:** ningun otro `NitSec` hereda esta tolerancia por similitud, nombre comercial, pagador o tipo de cliente.
- **Regla de calculo:** la tolerancia se evalua sobre la suma entregada por producto consolidado tras matching documental.

### 23.6 Calidad visual

| Estado | Criterio visual | Comportamiento |
|---|---|---|
| `legible` | Campos principales claros | Procesar normal |
| `parcialmente_legible` | Campos clave visibles pero hay ruido, perspectiva o contraste bajo | Procesar y agregar warning |
| `ilegible` | No permite extraer identidad o producto | Documento `failed`, auditoria `manual_review` o `failed` segun criticidad |

Reglas:

- `legible` no altera el resultado por si solo.
- `parcialmente_legible` puede producir `NO_CONCLUYENTE` para campos afectados.
- `ilegible` obliga `NO_CONCLUYENTE` en campos afectados.
- Si un campo critico queda `NO_CONCLUYENTE`, la auditoria debe terminar en `manual_review`.
- `NO_CONCLUYENTE` no debe degradarse automaticamente a `NO_ENCONTRADO`.

### 23.7 Datos observados en fixtures visuales locales

| Archivo | Documento | Observaciones |
|---|---|---|
| `tmp/Dispensa.jpg` | Dispensa | Tabla clara, dos items, firma de recibido visible, huella no visible |
| `tmp/AUTORIZACION.jpg` | Autorizacion | Numero autorizacion y cantidad visibles, perspectiva/ruido moderado, servicio autorizado con descripcion comercial |
| `tmp/FORMULA_MEDICA.jpg` | Formula medica | Lista extensa multilinea, firma y sello prescriptor visibles, numero autorizacion visualmente distinto al de dispensa/autorizacion |

### 23.8 Reglas sobre visual checks no configurados

- Un elemento visual detectado pero no configurado como `TipoCampo = V` no genera hallazgo.
- El extractor puede reportarlo en metadata interna, pero el policy engine lo ignora.
- Para auditarlo formalmente debe existir en `audit-config` como `TipoCampo = V`.

### 23.9 Golden case obligatorio - `T38250701547`

- **Estado:** `CONFIRMED`
- **Objetivo:** este caso es la referencia obligatoria para validar que la implementacion del pipeline produce el comportamiento esperado.
- **Metodo de validacion humana:** ejecucion manual del endpoint `POST /audit/single` desde terminal PowerShell.

#### 23.9.1 Comando oficial de validacion

```powershell
Invoke-RestMethod -Uri "http://localhost:8080/audit/single" -Method POST -ContentType "application/json" -Body '{"DisDetNro":"T38250701547"}' | ConvertTo-Json -Depth 20
```

#### 23.9.2 Resultado funcional esperado

- **Estado global esperado:** `manual_review`
- **RequiereRevisionHumana:** `1`
- **FacSec esperado:** `87723098`
- **FacNro esperado:** `T38250701547`
- **FacNitSec esperado:** `2426`

#### 23.9.3 Reglas del caso que deben cumplirse

- `NombrePaciente` y `DocumentoPaciente` deben coincidir.
- `NumeroAutorizacion` debe auditarse solo contra `AUTORIZACION` y `DISPENSA`.
- `NumeroAutorizacion` en `FORMULA MEDICA` no se audita.
- `CantidadEntregada total = 50` frente a `CantidadAutorizada = 100` debe aceptarse como entrega parcial valida.
- `FirmaActaEntrega` debe detectarse como presente.
- `FirmaPrescriptor` debe detectarse como presente.
- `CodigoDiagnostico` debe ser auditable en los tres documentos.
- En `FORMULA MEDICA`, `CodigoDiagnostico` debe producir `NO_CONCLUYENTE` o `NO_ENCONTRADO` si no puede validarse con confianza.
- El producto autorizado vs entregado puede quedar `NO_CONCLUYENTE` si el matching documental no es concluyente.

#### 23.9.4 Motivos esperados de revision humana

Al menos uno de estos motivos debe estar presente en el resultado funcional:

- `CodigoDiagnostico` no concluyente o no encontrado en `FORMULA MEDICA`.
- Matching no concluyente del producto entre `AUTORIZACION` y `DISPENSA`.

#### 23.9.5 Motivos que no deben disparar fracaso

Los siguientes hechos **no** deben causar `error` o `failed`:

- entrega parcial `50 <= 100`,
- numero de autorizacion visible en formula medica,
- productos adicionales en formula medica que no fueron entregados,
- descripcion comercial distinta en autorizacion si no alcanza para mismatch concluyente,
- presencia de huella ausente en acta si no esta configurada como check visual.

#### 23.9.6 Condicion de fracaso de implementacion

La implementacion se considera **fracaso** si el comando de `23.9.1` retorna un resultado que difiere materialmente del comportamiento esperado arriba descrito, incluyendo cualquiera de estos casos:

- `completed` en lugar de `manual_review`,
- `error` o `failed` sin base en las reglas definidas,
- rechazo por `NumeroAutorizacion` en formula medica,
- rechazo por entrega parcial valida,
- ausencia de deteccion de `FirmaActaEntrega`,
- ausencia de deteccion de `FirmaPrescriptor`,
- omision de la evaluacion de `CodigoDiagnostico` en cualquiera de los tres documentos.

#### 23.9.7 `rules_evaluated` esperado

```json
{
  "event_type": "rules_evaluated",
  "audit_id": "SIM-T38250701547",
  "payload": {
    "hallazgos": {
      "items": [
        {
          "campo": "NombrePaciente",
          "valorFuenteVerdad": "GARCIA ABSALON",
          "valorDocumento": "ABSALON GARCIA",
          "resultado": "COINCIDE",
          "severidad": "media",
          "documento": "FORMULA MEDICA"
        },
        {
          "campo": "DocumentoPaciente",
          "valorFuenteVerdad": "12132213",
          "valorDocumento": "12132213",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "AUTORIZACION"
        },
        {
          "campo": "DocumentoPaciente",
          "valorFuenteVerdad": "12132213",
          "valorDocumento": "12132213",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "DISPENSA"
        },
        {
          "campo": "DocumentoPaciente",
          "valorFuenteVerdad": "12132213",
          "valorDocumento": "12132213",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "FORMULA MEDICA"
        },
        {
          "campo": "CodigoDiagnostico",
          "valorFuenteVerdad": "S127",
          "valorDocumento": "S127",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "AUTORIZACION"
        },
        {
          "campo": "CodigoDiagnostico",
          "valorFuenteVerdad": "S127",
          "valorDocumento": "S127",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "DISPENSA"
        },
        {
          "campo": "CodigoDiagnostico",
          "valorFuenteVerdad": "S127",
          "valorDocumento": null,
          "resultado": "NO_CONCLUYENTE",
          "severidad": "alta",
          "documento": "FORMULA MEDICA",
          "detalle": "El documento existe pero el codigo diagnostico no es legible con confianza suficiente."
        },
        {
          "campo": "NumeroAutorizacion",
          "valorFuenteVerdad": "46338218",
          "valorDocumento": "46338218",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "AUTORIZACION"
        },
        {
          "campo": "NumeroAutorizacion",
          "valorFuenteVerdad": "46338218",
          "valorDocumento": "46338218",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "DISPENSA"
        },
        {
          "campo": "FechaAutorizacion",
          "valorFuenteVerdad": "2025-07-27",
          "valorDocumento": "2025-07-27",
          "resultado": "COINCIDE",
          "severidad": "media",
          "documento": "AUTORIZACION"
        },
        {
          "campo": "FechaEntrega",
          "valorFuenteVerdad": "2025-07-29",
          "valorDocumento": "2025-07-29",
          "resultado": "COINCIDE",
          "severidad": "media",
          "documento": "DISPENSA"
        },
        {
          "campo": "FechaFormula",
          "valorFuenteVerdad": "2025-05-20",
          "valorDocumento": "2025-05-20",
          "resultado": "COINCIDE",
          "severidad": "media",
          "documento": "FORMULA MEDICA"
        },
        {
          "campo": "NombreArticulo",
          "valorFuenteVerdad": "GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5",
          "valorDocumento": "20012566-23 - GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "DISPENSA"
        },
        {
          "campo": "NombreArticulo",
          "valorFuenteVerdad": "GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5",
          "valorDocumento": "CUREBAND PREMIUM GASA ANTIADHERENTE ESTERIL 7.5CM X 7.5CM",
          "resultado": "NO_CONCLUYENTE",
          "severidad": "alta",
          "documento": "AUTORIZACION",
          "detalle": "La descripcion comercial parece corresponder al mismo insumo, pero el matching no es concluyente solo con evidencia visual."
        },
        {
          "campo": "CantidadAutorizada",
          "valorFuenteVerdad": "100",
          "valorDocumento": "100",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "AUTORIZACION"
        },
        {
          "campo": "CantidadEntregada",
          "valorFuenteVerdad": "50",
          "valorDocumento": "50",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "DISPENSA"
        },
        {
          "campo": "FirmaActaEntrega",
          "valorFuenteVerdad": "Obligatorio",
          "valorDocumento": "PRESENTE",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "DISPENSA",
          "detalle": "Firma manuscrita visible en el area de recibido."
        },
        {
          "campo": "FirmaPrescriptor",
          "valorFuenteVerdad": "Obligatorio",
          "valorDocumento": "PRESENTE",
          "resultado": "COINCIDE",
          "severidad": "alta",
          "documento": "FORMULA MEDICA",
          "detalle": "Firma y sello visibles del profesional de salud."
        }
      ],
      "metrics": {
        "total_campos": 18,
        "coincidencias": 16,
        "discrepancias": 0,
        "omitidos": 0,
        "no_concluyentes": 2,
        "risk_score": 20
      }
    }
  }
}
```

#### 23.9.8 `document_decisions` esperado

```json
[
  {
    "documentName": "DISPENSA",
    "approved": true,
    "observation": null
  },
  {
    "documentName": "AUTORIZACION",
    "approved": false,
    "observation": "El producto autorizado no puede mapearse de forma concluyente solo con evidencia visual al item entregado."
  },
  {
    "documentName": "FORMULA MEDICA",
    "approved": false,
    "observation": "El codigo diagnostico no es legible con confianza suficiente."
  }
]
```

#### 23.9.9 `auditResultData` esperado

```php
[
    'FacSec'                  => '87723098',
    'FacNro'                  => 'T38250701547',
    'EstAud'                  => 1,
    'EstadoDetallado'         => 'manual_review',
    'RequiereRevisionHumana'  => 1,
    'Severidad'               => 'alta',
    'Hallazgos'               => '{"items":[...],"metrics":{"total_campos":18,"coincidencias":16,"discrepancias":0,"omitidos":0,"no_concluyentes":2,"risk_score":20}}',
    'DetalleError'            => 'Auditoria completada con incertidumbre documental: 2 campos no concluyentes requieren revision humana.',
    'DocumentosProcesados'    => 3,
    'DocumentoFallido'        => 'FORMULA MEDICA',
    'DuracionProcesamientoMs' => 42000,
    'FacNitSec'               => '2426',
]
```

#### 23.9.10 Regla de equivalencia material

Para aceptar la implementacion como correcta, no basta con coincidir solo en el estado final. Debe existir equivalencia material contra `23.9.7`, `23.9.8` y `23.9.9` en:

- hallazgos principales,
- decisiones por documento,
- estado final persistido,
- documento fallido principal,
- necesidad de revision humana.

---

## Apendice A - Contratos detallados

### A.1 `SchemaBuilder`

```php
/**
 * Entrada:
 * - nitSec: string
 * - documentConfig: array{
 *     docId:int,
 *     fields:list<string>,
 *     visualChecks:list<array{check:string, description:string, severity:string}>
 *   }
 *
 * Salida:
 * - functionDeclaration: array
 * - visualChecks: list<array>
 *
 * Regla:
 * - Solo `visualChecks` provenientes de TipoCampo V entran como checks visuales.
 */
```

### A.2 `AuditStateStore`

```php
/**
 * Keys:
 * - audit:{audit_id}:status
 * - audit:{audit_id}:docs:total
 * - audit:{audit_id}:docs:done
 * - audit:{audit_id}:docs:{document_id}
 * - job:{job_id}:status
 * - job:{job_id}:total
 * - job:{job_id}:done
 * - job:{job_id}:failed
 */
```

### A.3 `AuditResultAggregator`

```php
/**
 * Entrada:
 * - rules_evaluated event
 *
 * Salida:
 * - auditResultData para AudDispEst
 * - documentDecisions para AdjuntosDispensacion
 *
 * Estados:
 * - completed
 * - error
 * - failed
 * - manual_review
 */
```

---

## Apendice B - Onboarding para agentes

### Lectura obligatoria

- [ ] Este documento completo.
- [ ] `.agent/skills/audfact-audit-gemini/SKILL.md`
- [ ] `.agent/skills/audfact-api-rest/SKILL.md`
- [ ] `.agent/skills/audfact-sqlsrv-models/SKILL.md`
- [ ] `.agent/skills/audfact-runtime-docker/SKILL.md`

### Reglas

- [ ] No preservar legacy.
- [ ] No inferir checks visuales.
- [ ] No acceder a Gemini sin schema validado.
- [ ] No cerrar sprint sin tests.
- [ ] No dejar clases o tests huerfanos.
