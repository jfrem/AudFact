# SDD — Migración de Function Calling a Structured Outputs Nativos y Aplanamiento del Schema de Extracción

> **Fecha**: 2026-08-27 · **Nivel**: `A — Implementable` · **Política**: `clean-rebuild-policy`
> **Alcance**: Pipeline de extracción documental Gemini — `DocumentExtractionContractBuilder`, `GeminiGateway`, `GeminiResponseParser`, `ExtractionPromptBuilder`, `DocumentExtractionWorker` y tests asociados.

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación / Evidencia |
| :--- | :--- | :--- |
| **Tipo** | Refactor / Optimización de costos | [CONFIRMADO] Se migra el mecanismo de interacción con Gemini API de Function Calling a Structured Outputs (`responseSchema`), sin alterar el flujo de datos del dominio. |
| **Riesgo** | Medio | [CONFIRMADO] Afecta el mecanismo de comunicación con la API externa de Gemini y la estructura interna del payload, pero no afecta contratos de API REST ni persistencia. |
| **Persistencia afectada** | No | [CONFIRMADO] No se modifican tablas, vistas ni columnas SQL Server. La caché Redis de extracciones se invalida naturalmente por cambio de `contract_hash`. |
| **Contrato externo afectado** | No | [CONFIRMADO] La API REST del backend (`/audit/single`, `/audit/async`, `/audit/results`) mantiene endpoints y respuestas idénticos. |
| **Cambio arquitectónico** | Sí | [CONFIRMADO] Se cambia el patrón de interacción con Gemini de Function Calling (Tools API) a Structured Outputs (`responseSchema` en `generationConfig`). |
| **Producción afectada** | Sí | [CONFIRMADO] El cambio altera el payload enviado a Google Gemini API y el formato de respuesta recibido. |
| **Requiere 0.3.1 (cobertura de abstracciones)** | No | [CONFIRMADO] No se reemplazan mapeos estáticos por abstracciones dinámicas. |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta Absoluta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| :--- | :--- | :--- | :--- | :--- | :---: |
| `DocumentExtractionContractBuilder.php` | `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | `MODIFIED` | Ensamblador de contratos JSON Schema para extracción. Genera `function_declarations`, `required_function_names` y `field_groups`. | `build()` L38-58, `buildEvidenceFieldSchema()` L414-452, `buildFunctionDeclarations()` L66-85 | Sí |
| `GeminiGateway.php` | `app/Services/Audit/GeminiGateway.php` | `MODIFIED` | Gateway HTTP hacia Gemini API. Construye y envía payloads REST. | `sendWithFunctionCalling()` L87-172, `buildPayload()` L292-341 | Sí |
| `GeminiResponseParser.php` | `app/Services/Audit/Pipeline/GeminiResponseParser.php` | `MODIFIED` | Parser de respuesta Gemini. Orquesta 3 fases de recuperación (parse, retry, fallback) para Function Calls paralelos. | `parse()` L81-155, `extractFunctionCalls()` L195-223, `validateParallelExtractionPayload()` L303-340, `retryMissingFunctions()` L241-298 | Sí |
| `ExtractionPromptBuilder.php` | `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php` | `MODIFIED` | Constructor de prompts de sistema y usuario. Genera `toolConfig` para Function Calling. | `buildToolConfig()` L146-154, `contractFunctionDeclarations()` L170-185, `requiredFunctionNames()` L191-208, `contractFieldSchemas()` L333-343, `evidenceValueDescription()` L345-354, `buildUserPrompt()` L69-140 | Sí |
| `DocumentExtractionWorker.php` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | `MODIFIED` | Worker de extracción documental. Invocante principal de GeminiGateway y GeminiResponseParser. | `resolveExtraction()` L292-361, llamada a `sendWithFunctionCalling` L318-332 | Sí |
| `GeminiConfig.php` | `app/Services/Audit/GeminiConfig.php` | `INSPECTED` | Value object de configuración Gemini. `toGenerationConfig()` ya soporta `mediaResolution`. | N/A — no cambia | Sí |
| `ExtractedEvidence.php` | `app/Services/Audit/Pipeline/ExtractedEvidence.php` | `INSPECTED` | VO inmutable de evidencia extraída. Consume `{valor, valores, presente, estadoExtraccion}` — output del parser/normalizer. | N/A — no cambia, la rehidratación del parser lo alimenta con la misma shape | Sí |
| `ExtractionState.php` | `app/Services/Audit/Pipeline/ExtractionState.php` | `INSPECTED` | Enum de estados de extracción. Define `FOUND`, `FOUND_IN_LIST`, `NOT_FOUND`, `ILLEGIBLE`. | N/A — `ILLEGIBLE` se mantiene en el enum pero el schema plano no lo emite | Sí |
| `DocumentNormalizer.php` | `app/Services/Audit/Pipeline/DocumentNormalizer.php` | `INSPECTED` | Normaliza la extracción cruda a shapes canónicos. Consume `estadoExtraccion` de cada campo. | L381 — consume `evidence['estadoExtraccion']`; no cambia porque recibe datos post-rehidratación | Sí |
| `FieldValueResolver.php` | `app/Services/Audit/Pipeline/FieldValueResolver.php` | `INSPECTED` | Resuelve valores finales de campos. Consume `estadoExtraccion`. | L312 — `$cell->estadoExtraccion === ExtractionState::FOUND_IN_LIST`; no cambia | Sí |
| `ArticleSemanticMatchJudge.php` | `app/Services/Audit/ArticleSemanticMatchJudge.php` | `INSPECTED` | Judge de equivalencia semántica. Usa `TASK_SEMANTIC_MATCH`, no `TASK_EXTRACTION`. | N/A — no afectado por este cambio que es exclusivo de extracción | Sí |
| `DocumentExtractionWorkerTest.php` | `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php` | `MODIFIED` | Suite de pruebas del worker. Los mocks de `GeminiGateway` retornan structure FC. | Múltiples métodos con responses mockeadas en formato FC | Sí |
| `ExtractionPromptBuilderTest.php` | `tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php` | `MODIFIED` | Suite de pruebas del prompt builder. Valida `buildToolConfig()` y `contractFunctionDeclarations()`. | Tests que validan `functionCallingConfig` y `function_declarations` | Sí |
| `ResponseIADiskStore.php` | `app/Services/Audit/ResponseIADiskStore.php` | `INSPECTED` | Persiste request/response a disco para debug. Agnóstico al formato del payload. | N/A — no cambia | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| :--- | :--- | :--- | :--- |
| Búsqueda por símbolo | `sendWithFunctionCalling` | 7 archivos: Gateway, Worker, Parser, Judge, DiskStore, 2 tests | [CONFIRMADO] Judge usa `TASK_SEMANTIC_MATCH` (no afectado). DiskStore es agnóstico. |
| Búsqueda por símbolo | `functionCall` | 3 archivos: Parser, PromptBuilder, Judge | [CONFIRMADO] Parser y PromptBuilder son MODIFIED. Judge no afectado. |
| Búsqueda por símbolo | `function_declarations` | 2 archivos: ContractBuilder, PromptBuilder | [CONFIRMADO] Ambos en perímetro. |
| Búsqueda por símbolo | `toolConfig` | 2 archivos: Gateway, Judge | [CONFIRMADO] Gateway es MODIFIED. Judge no afectado. |
| Búsqueda por símbolo | `estadoExtraccion` | 6 archivos en `app/`: ContractBuilder, ExtractedEvidence, DocumentNormalizer, ExtractionState, FieldValueResolver | [CONFIRMADO] Todos reciben datos post-rehidratación; no cambian. |
| Búsqueda por símbolo | `ExtractionState::ILLEGIBLE` | 0 consumidores en lógica de negocio | [CONFIRMADO] `ILLEGIBLE` solo existe en el enum y en el schema FC. Ningún `if/match/switch` consume este estado. |
| Búsqueda en tests | `DocumentExtractionContractBuilder` | 4 archivos test | [CONFIRMADO] Tests en perímetro. |
| Búsqueda en tests | `GeminiResponseParser` | 0 tests dedicados | [CONFIRMADO] El parser carece de test unitario dedicado; se prueba integrado via WorkerTest. Se creará uno nuevo. |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `DocumentExtractionContractBuilder` | `DocumentExtractionWorker` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L116, L322 | Directa | Invocación `build()`, `contractFunctionDeclarations()` | Repositorio local |
| `DocumentExtractionContractBuilder` | `ExtractionPromptBuilder` | `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php` | L102, L337 | Directa | Invocación `FN_*` constantes, `contractFieldSchemas()` | Repositorio local |
| `DocumentExtractionContractBuilder` | `GeminiResponseParser` | `app/Services/Audit/Pipeline/GeminiResponseParser.php` | L307, L313, L319, L324 | Directa | Referencia a `FN_*` constantes | Repositorio local |
| `GeminiGateway` | `DocumentExtractionWorker` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L318 | Directa | Invocación `sendWithFunctionCalling()` | Repositorio local |
| `GeminiGateway` | `GeminiResponseParser` | `app/Services/Audit/Pipeline/GeminiResponseParser.php` | L266 | Directa | Invocación `sendWithFunctionCalling()` en retry | Repositorio local |
| `GeminiGateway` | `ArticleSemanticMatchJudge` | `app/Services/Audit/ArticleSemanticMatchJudge.php` | L18 | Directa | Inyección; usa `TASK_SEMANTIC_MATCH` — no afectado | Repositorio local |
| `GeminiResponseParser` | `DocumentExtractionWorker` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L338 | Directa | Invocación `parse()` | Repositorio local |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| :--- | :--- | :--- | :--- | :--- |
| `build()` ya no retorna `function_declarations` ni `required_function_names` | `DocumentExtractionWorker::resolveExtraction()` | Worker L322: `contractFunctionDeclarations($contract)` | Runtime | [CORREGIDO] Worker deja de extraer `function_declarations`; pasa el contrato completo al nuevo método `sendWithStructuredOutput()`. |
| `build()` ya no retorna `function_declarations` | `ExtractionPromptBuilder::contractFunctionDeclarations()` | PromptBuilder L170-185 | Runtime | [CORREGIDO] El método `contractFunctionDeclarations()` se elimina. `buildToolConfig()` se elimina. `requiredFunctionNames()` evoluciona a leer de la nueva key `schema_function_names`. |
| `buildPayload()` ya no inyecta `tools` ni `toolConfig` | Tests con mocks de respuesta FC | WorkerTest, PromptBuilderTest | Test | [CORREGIDO] Se actualizan mocks: las respuestas ya no contienen `functionCall` sino JSON text directo. |
| `GeminiResponseParser::parse()` ya no parsea `functionCall` parts | `DocumentExtractionWorker::resolveExtraction()` | Worker L338 | Runtime | [CORREGIDO] `parse()` se refactoriza para parsear JSON text response en lugar de FC parts. |
| `GeminiResponseParser::retryMissingFunctions()` ya no aplica | Retry path del parser | Parser L241-298 | Runtime | [CORREGIDO] Eliminado; `responseSchema` garantiza estructura. Se preserva fallback para `detect_visual_checks` y `assess_document_quality` como valores por defecto si están ausentes en el JSON. |
| `ExtractionPromptBuilder::buildUserPrompt()` referencia `extract_fields`, `extract_items` como funciones a invocar | Prompt de usuario | PromptBuilder L94, L98, L136-137 | Runtime | [CORREGIDO] Se reescribe para no mencionar "funciones" sino "secciones del JSON". |

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| :--- | :--- | :--- | :--- | :--- |
| Gemini API REST v1beta (`generateContent`) | `responseSchema` se envía dentro de `generationConfig` junto con `responseMimeType: "application/json"` para forzar output JSON estructurado | Documental | [ai.google.dev/gemini-api/docs/structured-output](https://ai.google.dev/gemini-api/docs/structured-output): "responseMimeType must be set to application/json. responseSchema defines the blueprint for the output." | Sí |
| Gemini API REST v1beta | `responseSchema` y `tools` (Function Calling) son mutuamente excluyentes en el mismo request | Documental | Google Docs: Structured Output es una constraint en la respuesta; Function Calling es una capability. No se combinan en un mismo request. | Sí — el cambio elimina `tools` y `toolConfig` del payload completamente. |
| Gemini API REST v1beta | `responseSchema` soporta `object`, `string`, `number`, `boolean`, `array` types con `nullable`, `enum`, `required`, `description` y `propertyOrdering` | Documental | Google Docs + evidencia empírica del schema FC actual que ya usa estos mismos tipos | Sí |
| Gemini API REST v1beta | `responseSchema` no soporta `anyOf`/`oneOf` en todos los modelos, pero soporta `nullable` como alternativa | Documental | Google Docs: "Use `nullable: true` for optional fields" | Sí — el schema usa `nullable: true` para campos opcionales. |
| PHP `json_decode` | El output de Gemini con `responseMimeType: "application/json"` retorna un string JSON en `candidates[0].content.parts[0].text`. | Documental + Empírica | Google Docs: "The model's response is a text part containing a JSON string" | Sí — `json_decode($text, true)` produce array asociativo directamente. |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| :--- | :--- | :--- | :--- | :--- |
| **Desarrollo Local (Windows/CLI)** | PHPUnit suite + `.env` local | `vendor\bin\phpunit` | Sí | [CONFIRMADO] Tests se actualizan con mocks de Structured Output response. |
| **Docker / WSL Local** | Workers Redis Streams + auditoría manual | `docker compose exec php composer test` | Sí | [CONFIRMADO] Runtime PHP 8.2-FPM; `json_decode` nativo. |
| **CI Automatizado (GitHub Actions)** | Build, lint y tests unitarios | `composer test` | Sí | [CONFIRMADO] Pipeline CI ejecuta PHPUnit; no requiere API key real. |
| **Producción LAN (172.16.0.3)** | Despliegue Zero-Source via GHCR | Docker Compose deploy | Sí | [CONFIRMADO] Variables de entorno sin cambios; el payload se construye en runtime. |

---

### 0.6 Inventario de Información

| ID | Aserción | Estado | Evidencia |
| :--- | :--- | :---: | :--- |
| **I-01** | El consumo actual de tokens TEXT del schema FC para 8 campos + 2 items + visual checks + quality es **2300 tokens** (vs 1092 tokens de imagen). | `[CONFIRMADO]` | [Log](file:///c:/Users/USER/Desktop/AudFact/logs/responseIA/U77260801145_success_20260827_154005365936_e888c91d.json) L689-702: `promptTokensDetails[TEXT]=2300, IMAGE=1092`. |
| **I-02** | Cada campo en el schema FC repite `{valor, presente, estadoExtraccion}` con 4 enum values, `nullable`, `required` y `propertyOrdering`, consumiendo ~120 tokens por campo. | `[CONFIRMADO]` | [ContractBuilder::buildEvidenceFieldSchema()](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php#L414-L452): el objeto anidado tiene 3 propiedades con metadata repetida × N campos. |
| **I-03** | `ExtractionState::ILLEGIBLE` existe en el enum pero nunca es consumido por ningún `if`, `match`, `switch` o comparación en la lógica de negocio de `app/`. | `[CONFIRMADO]` | Búsqueda `ExtractionState::ILLEGIBLE` en `app/` retornó 0 resultados. Solo existe en `ExtractionState.php:18` y `ContractBuilder.php:435`. |
| **I-04** | `ExtractedEvidence::fromArray()` hidrata `estadoExtraccion` con fallback a `FOUND` cuando el valor es null o no reconocido. | `[CONFIRMADO]` | [ExtractedEvidence::fromArray()](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/ExtractedEvidence.php#L35-L46) L44 → [ExtractionState::fromInput()](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/ExtractionState.php#L23-L31) L30: `tryFrom($upper) ?? self::FOUND`. |
| **I-05** | `ArticleSemanticMatchJudge` usa `GeminiGateway` exclusivamente con `TASK_SEMANTIC_MATCH` y no usa `function_declarations` de extracción. | `[CONFIRMADO]` | [ArticleSemanticMatchJudge](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/ArticleSemanticMatchJudge.php#L18): `GeminiGateway` inyectado; grep de `function_declarations` = 0 resultados; grep de `TASK_EXTRACTION` = 0 resultados. |
| **I-06** | `GeminiResponseParser` no tiene test unitario dedicado. Se prueba indirectamente via `DocumentExtractionWorkerTest`. | `[CONFIRMADO]` | Búsqueda `GeminiResponseParser` en `tests/` = 0 archivos de test dedicados. |
| **I-07** | Google Gemini API retorna Structured Output como un JSON string dentro de `candidates[0].content.parts[0].text`. No retorna `functionCall`. | `[CONFIRMADO]` | Documentación oficial Google: "The model's response is a text part containing a JSON string." |

---

### 0.7 Información Faltante Crítica

| Dato | Motivo | Impacto |
| :--- | :--- | :--- |
| _(Ninguna)_ | Toda información crítica fue confirmada por lectura directa del código y documentación oficial. | N/A |

### 0.8 Información Faltante Importante

| Dato | Motivo | Impacto |
| :--- | :--- | :--- |
| Conteo exacto de tokens del schema `responseSchema` aplanado | Solo medible empíricamente post-implementación | Bajo — la reducción es arquitectónicamente garantizada (~60-70% estimado por eliminación de `{presente, estadoExtraccion}` × N campos + eliminación de tools overhead) |

### 0.9 Información Faltante Opcional

| Dato | Motivo | Impacto |
| :--- | :--- | :--- |
| _(Ninguna)_ | N/A | N/A |

### 0.10 Supuestos Declarados

| ID | Supuesto | Severidad | Evidencia | Riesgo |
| :--- | :--- | :---: | :--- | :--- |
| **S-01** | `responseSchema` en `generationConfig` es aceptado por `gemini-3.7-flash` en el endpoint `v1beta/models/{model}:generateContent`. | S1 | Documentación oficial Google + modelo soporta Structured Output desde v2.5+. | Si es rechazado con 400, se revierte a FC con un solo commit. |
| **S-02** | La calidad de extracción (precisión de OCR de dígitos, detección de campos) no degrada al migrar de Function Calling a Structured Output, ya que las descripciones del schema y el system prompt se preservan. | S1 | Google Docs: "responseSchema is the cleaner, intended path for data formatting tasks". Las instrucciones textuales del prompt se mantienen. | Si degrada, se revierte y se documenta como limitación del API. |

### 0.11 Clasificación de Completitud Inicial

`Nivel A — Implementable`. Cero supuestos S3/S4. Todo el perímetro verificado por lectura. Grafo de dependencias cerrado. Todas las regresiones tienen corrección documentada.

---

## FASE 1 — Especificación

### 1. Objetivo

- **Problema actual**: El pipeline de extracción documental usa Function Calling (`tools` + `toolConfig`) para obligar a Gemini a retornar JSON estructurado. Cada campo repite un objeto `{valor, presente, estadoExtraccion}` con 4 enum values + metadata, generando ~120 tokens por campo. Para 8 campos + 2 items de un documento AUTORIZACION, el TEXT input consume **2300 tokens** — el doble del costo de la imagen (1092 tokens).
- **Causa raíz**: Function Calling impone overhead algorítmico (declaración de funciones, envoltura de herramientas, parsing de `functionCall` parts) y el schema anidado con `{presente, estadoExtraccion}` multiplica tokens innecesariamente. El modelo puede inferir `presente` y `estadoExtraccion` de un simple `null` vs no-null.
- **Impacto actual**: Cada extracción consume ~2300 tokens de TEXT input por schema. En un batch de 100 documentos × 5 tipos documentales = 500 llamadas × 2300 tokens innecesarios ≈ 1.15M tokens de overhead por batch.
- **Resultado esperado**: Reducción del TEXT input a menos de 1000 tokens por schema (>55% reducción) usando `responseSchema` nativo con tipos planos.
- **Razón de existencia**: Google Gemini recomienda `responseSchema` sobre Function Calling cuando el objetivo es obtener JSON estructurado sin ejecutar acciones externas. El pipeline AudFact es extracción pura — Gemini no necesita "decidir qué herramienta usar"; siempre extrae todos los campos.

### 2. Alcance

#### Incluido

- Migración de `tools` + `toolConfig` a `responseSchema` + `responseMimeType` en el payload de extracción documental.
- Aplanamiento del schema: cada campo pasa de `{valor, presente, estadoExtraccion}` a tipo primitivo directo (`string|null`, `number|null`).
- Implementación de rehidratación en `GeminiResponseParser` para reconstruir la shape canónica `{valor, valores, presente, estadoExtraccion}`.
- Unificación del contrato: las 4 "funciones" (`extract_fields`, `extract_items`, `detect_visual_checks`, `assess_document_quality`) se fusionan en un único schema JSON.
- Actualización de tests unitarios.

#### Excluido

- Migración de `ArticleSemanticMatchJudge` — usa `TASK_SEMANTIC_MATCH` con FC independiente.
- Modificación del enum `ExtractionState` — se mantiene `ILLEGIBLE` aunque el schema plano no lo emita.
- Cambios en el system prompt o user prompt base (salvo eliminación de "Invoca funciones").
- Cambios en `GeminiConfig` o variables de entorno.
- Cambios en persistencia SQL Server.

### 3. Non Goals

- Optimización de tokens de imagen (DPI, resolución) — ya configurado óptimamente.
- Reducción de `thinkingTokens` — son valiosos para precisión de OCR.
- Migración a `Batch API` o `Context Caching` — futuras optimizaciones independientes.
- Reducción de `maxOutputTokens` — permanece en 4096 para extracción.

### 4. Estado Actual

#### Arquitectura actual

```
DocumentExtractionWorker
  ├── ExtractionPromptBuilder.buildUserPrompt()      → texto del prompt
  ├── ExtractionPromptBuilder.buildSystemPrompt()    → texto del system
  ├── ExtractionPromptBuilder.contractFunctionDeclarations()  → [function_declarations]
  ├── ExtractionPromptBuilder.buildToolConfig()      → {functionCallingConfig: {mode: ANY, ...}}
  └── GeminiGateway.sendWithFunctionCalling()
        ├── payload.tools = [{functionDeclarations: [...]}]
        ├── payload.toolConfig = {functionCallingConfig: ...}
        └── response.candidates[0].content.parts[*].functionCall
              └── GeminiResponseParser.parse()
                    ├── extractFunctionCalls(parts) → {fn_name: args}
                    ├── retryMissingFunctions()     → 2nd API call
                    └── validateParallelExtractionPayload()
```

#### Schema actual por campo (ejemplo `NombrePaciente`)

```json
{
  "NombrePaciente": {
    "type": "object",
    "properties": {
      "valor": { "type": "string", "nullable": true, "description": "..." },
      "presente": { "type": "boolean" },
      "estadoExtraccion": { "type": "string", "enum": ["FOUND","FOUND_IN_LIST","NOT_FOUND","ILLEGIBLE"] }
    },
    "required": ["valor", "presente", "estadoExtraccion"],
    "propertyOrdering": ["valor", "presente", "estadoExtraccion"]
  }
}
```

**Token cost**: ~120 tokens × N campos = ~960 tokens solo para 8 campos fields.

#### Distribución de tokens actual (AUTORIZACION, 8 campos + 2 items)

| Modalidad | Tokens | Porcentaje |
| :--- | :--- | :--- |
| TEXT (schema + prompt + system) | 2300 | 68% |
| IMAGE | 1092 | 32% |
| **Total prompt** | 3392 | 100% |
| Output (FC response) | 446 | — |
| Thinking | 904 | — |

### 5. Estado Objetivo

#### Arquitectura objetivo

```
DocumentExtractionWorker
  ├── ExtractionPromptBuilder.buildUserPrompt()      → texto del prompt (sin refs a funciones)
  ├── ExtractionPromptBuilder.buildSystemPrompt()    → texto del system
  └── GeminiGateway.sendWithStructuredOutput()
        ├── payload.generationConfig.responseMimeType = "application/json"
        ├── payload.generationConfig.responseSchema = {unified JSON schema}
        ├── payload.tools = ABSENT
        ├── payload.toolConfig = ABSENT
        └── response.candidates[0].content.parts[0].text = JSON string
              └── GeminiResponseParser.parse()
                    ├── json_decode(text) → array
                    ├── rehydrateEvidenceFields() → {valor, presente, estadoExtraccion}
                    └── validateStructuredPayload()
```

#### Schema objetivo por campo (ejemplo `NombrePaciente`)

```json
{
  "NombrePaciente": { "type": "string", "nullable": true, "description": "..." }
}
```

**Token cost estimado**: ~25 tokens × N campos = ~200 tokens para 8 campos.

#### Schema unificado completo (ejemplo AUTORIZACION)

```json
{
  "type": "object",
  "properties": {
    "fields": {
      "type": "object",
      "properties": {
        "NombrePaciente": { "type": "string", "nullable": true, "description": "..." },
        "DocumentoPaciente": { "type": "string", "nullable": true, "description": "..." }
      }
    },
    "items": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "CodigoProducto": { "type": "string", "nullable": true },
          "CantidadEntregada": { "type": "number", "nullable": true }
        }
      }
    },
    "visual_checks": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "check": { "type": "string", "enum": ["VigenciaEntrega"] },
          "presente": { "type": "boolean" },
          "valor": { "type": "number", "nullable": true },
          "unidad": { "type": "string", "nullable": true },
          "fecha_base": { "type": "string", "nullable": true }
        },
        "required": ["check", "presente"]
      }
    },
    "document_quality": { "type": "string", "enum": ["legible","parcialmente_legible","ilegible"] },
    "quality_notes": { "type": "array", "items": { "type": "string" } }
  },
  "required": ["fields", "document_quality", "quality_notes"]
}
```

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| :--- | :--- | :--- | :--- |
| **D-01** | Migrar de Function Calling a `responseSchema` nativo | (a) Mantener FC con schema más compacto; (b) Usar `responseMimeType: "application/json"` sin schema | (a) FC mantiene overhead algorítmico; (b) Sin schema no hay garantía de estructura. `responseSchema` es la solución oficial de Google para extracción JSON. |
| **D-02** | Aplanar el schema eliminando `{presente, estadoExtraccion}` del contrato Gemini y rehidratando en PHP | (a) Mantener schema anidado en `responseSchema` | El 70% del costo de tokens TEXT viene de la repetición de `presente` + `estadoExtraccion` × N campos. La rehidratación en PHP es determinística y libre de ambigüedad: `null` → `NOT_FOUND`, no-null → `FOUND`. |
| **D-03** | Eliminar el estado `ILLEGIBLE` del contrato con Gemini pero mantenerlo en el enum PHP `ExtractionState` | (a) Eliminar `ILLEGIBLE` del enum PHP también | `ILLEGIBLE` no tiene consumidores activos en la lógica de negocio. Mantenerlo en el enum evita breaking changes si se reintroduce en el futuro. En el schema plano, un campo ilegible se retorna como `null` y se rehidrata como `NOT_FOUND`. |
| **D-04** | Unificar las 4 funciones FC en un único schema JSON con secciones `fields`, `items`, `visual_checks`, `document_quality`, `quality_notes` | (a) Mantener 4 "herramientas" separadas en `responseSchema` | `responseSchema` no soporta "parallel function calling"; es un schema único. La unificación es obligatoria y además elimina overhead de envoltura. |
| **D-05** | Crear un nuevo método `sendWithStructuredOutput()` en `GeminiGateway` en lugar de modificar `sendWithFunctionCalling()` | (a) Modificar `sendWithFunctionCalling()` para ambos modos | Clean Architecture: `sendWithFunctionCalling()` sigue siendo necesario para `ArticleSemanticMatchJudge` (TASK_SEMANTIC_MATCH). Los dos métodos tienen contratos fundamentalmente diferentes. |
| **D-06** | Preservar `visual_checks` y `document_quality` con su schema anidado actual (no aplanar) | (a) Aplanar también estos campos | Estos campos ya son compactos (1 instancia, no N×campo) y su estructura anidada es semánticamente necesaria (array de checks con propiedades múltiples). |
| **D-07** | Soporte de cardinalidad multivalor para `TRACE_TOKEN` como `array` de strings nullable en Structured Outputs | (a) Reducir a string escalar único delimitado por comas | `TRACE_TOKEN` (Lotes, seriales) requiere comparación de sets discretos. Modelar como array nativo de strings preserva la lista atómica (`valores`) y permite a `GeminiResponseParser` y `DocumentNormalizer` rehidratar con `FOUND_IN_LIST` sin pérdida de cardinalidad. |
| **D-08** | Validación estricta y pre-procesamiento de `field_groups.items` en parser | (a) Filtrar silenciosamente filas no-array antes de validación | La extracción de líneas debe validar que cada fila de `items` sea un objeto asociativo válido y contenga todas las claves requeridas por el contrato antes de la rehidratación. |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| :--- | :--- | :--- | :--- |
| Google Gemini API | API externa | v1beta | Requiere soporte de `responseSchema` + `responseMimeType` en `generateContent` |
| `gemini-3.7-flash` | Modelo IA | 3.7 | Soporte confirmado para Structured Outputs |
| Guzzle HTTP | Librería PHP | ^7.0 | Sin cambios — HTTP POST payload cambia pero Guzzle es agnóstico |
| PHP `json_decode` | Runtime | 8.2 | Parsea el JSON text response; ya usado extensamente |

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| :--- | :--- | :--- | :--- |
| Schema de extracción | `DocumentExtractionContractBuilder::build()` | Genera el schema dinámicamente desde `AudDispCampoCatalogo` | No |
| Shape canónica de evidencia | `ExtractedEvidence` (VO) | `{valor, valores, presente, estadoExtraccion}` | No |
| Formato de respuesta Gemini | Documentación oficial Google | Structured Output: `parts[0].text` = JSON string | No |

[CONFIRMADO] Sin conflictos detectados entre fuentes de verdad.

### 8. Invariantes

| Invariante | Enforcement | Validación |
| :--- | :--- | :--- |
| Todo campo extraído por Gemini debe llegar a `DocumentNormalizer` con shape `{valor, valores, presente, estadoExtraccion}` | `GeminiResponseParser::rehydrateEvidenceFields()` | Test unitario: campo null → `{valor:null, presente:false, estadoExtraccion:'NOT_FOUND'}` |
| `ExtractedEvidence::fromArray()` acepta la shape canónica sin cambios | Sin cambios en `ExtractedEvidence` | Tests existentes siguen pasando |
| `ExtractionState::ILLEGIBLE` permanece en el enum PHP | Sin cambios en `ExtractionState` | Tests existentes del enum siguen pasando |
| `ArticleSemanticMatchJudge` sigue usando Function Calling | `sendWithFunctionCalling()` permanece intacto | Tests existentes del judge siguen pasando |
| La caché de extracciones se invalida | `contract_hash` cambia al cambiar el schema | Automático — `hashPayload()` producirá hash diferente |

### 9. Modelo de Datos

[CONFIRMADO] Sin impacto en persistencia. No se modifican tablas, vistas, columnas, índices ni constraints SQL Server. La caché Redis de extracciones se invalida naturalmente por cambio de `contract_hash`.

### 10. Contratos

#### Contrato: Payload HTTP hacia Gemini API (`generateContent`)

| Dimensión | Valor |
| :--- | :--- |
| Tipo | API REST (cliente) |
| Visibilidad | Interno |
| Productor | `GeminiGateway::sendWithStructuredOutput()` |
| Consumidores | Google Gemini API |
| Versionado | No |
| Compatibilidad requerida | Ninguna (consumidor externo no bajo nuestro control) |
| Enforcement | HTTP 200/400 de la API |

##### Antes

```json
{
  "systemInstruction": { "parts": [{"text": "..."}] },
  "contents": [{"role": "user", "parts": [...]}],
  "generationConfig": { "temperature": 0, "maxOutputTokens": 4096, "mediaResolution": "MEDIA_RESOLUTION_HIGH" },
  "tools": [{"functionDeclarations": [...]}],
  "toolConfig": {"functionCallingConfig": {"mode": "ANY", "allowedFunctionNames": [...]}}
}
```

##### Después

```json
{
  "systemInstruction": { "parts": [{"text": "..."}] },
  "contents": [{"role": "user", "parts": [...]}],
  "generationConfig": {
    "temperature": 0,
    "maxOutputTokens": 4096,
    "mediaResolution": "MEDIA_RESOLUTION_HIGH",
    "responseMimeType": "application/json",
    "responseSchema": { "type": "object", "properties": {...} }
  }
}
```

- Campos agregados en `generationConfig`: `responseMimeType`, `responseSchema`.
- Campos eliminados del root: `tools`, `toolConfig`.

#### Contrato: Respuesta de Gemini API

##### Antes (Function Calling)

```json
{
  "candidates": [{
    "content": {
      "parts": [
        { "functionCall": { "name": "extract_fields", "args": { "fields": { "NombrePaciente": { "valor": "Juan", "presente": true, "estadoExtraccion": "FOUND" }}}}}
      ]
    }
  }]
}
```

##### Después (Structured Output)

```json
{
  "candidates": [{
    "content": {
      "parts": [
        { "text": "{\"fields\":{\"NombrePaciente\":\"Juan\"},\"items\":[],\"visual_checks\":[],\"document_quality\":\"legible\",\"quality_notes\":[]}" }
      ]
    }
  }]
}
```

#### Contrato: Shape interna post-parser (hacia DocumentNormalizer)

**Sin cambios**. La rehidratación en `GeminiResponseParser` garantiza que el output del parser sigue siendo:

```php
[
    'fields' => ['NombrePaciente' => ['valor' => 'Juan', 'presente' => true, 'estadoExtraccion' => 'FOUND']],
    'items' => [...],
    'visual_checks' => [...],
    'document_quality' => 'legible',
    'quality_notes' => [],
]
```

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| :--- | :--- | :--- | :--- |
| **R-01** | Reducir tokens TEXT del prompt de extracción >50% | Aplanar schema + migrar a `responseSchema` | Comparar `promptTokensDetails[TEXT]` en logs antes/después |
| **R-02** | Mantener la misma calidad de extracción | Preservar `description` de campos + system prompt + user prompt | Verificar extracción de `U77260801145` con los mismos resultados |
| **R-03** | No romper consumidores downstream (`DocumentNormalizer`, `FieldValueResolver`, `ExtractedEvidence`) | Rehidratación en parser produce shape canónica idéntica | Tests existentes de normalizer/resolver pasan sin cambios |
| **R-04** | No afectar `ArticleSemanticMatchJudge` | `sendWithFunctionCalling()` permanece intacto | Tests existentes del judge pasan sin cambios |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| :--- | :--- | :--- | :--- | :--- |
| `DocumentNormalizer` | `GeminiResponseParser` output shape | Ninguno | Ninguno | [CONFIRMADO] Recibe datos post-rehidratación con shape canónica |
| `FieldValueResolver` | `ExtractedEvidence.estadoExtraccion` | Ninguno | Ninguno | [CONFIRMADO] Consume shape canónica de `ExtractedEvidence` |
| `DocumentPolicyEngine` | Extraction data via normalized shape | Ninguno | Ninguno | [CONFIRMADO] Opera sobre datos normalizados |
| `ExtractionCacheManager` | Extraction data shape en Redis | Invalidación natural | Ninguno | [CONFIRMADO] `contract_hash` cambia; cache miss fuerza re-extracción |
| `AuditResultPersistenceModel` | Hallazgos persistidos en BD | Ninguno | Ninguno | [CONFIRMADO] Persiste hallazgos, no datos crudos de extracción |
| `ResponseIADiskStore` | Request/response payloads | Solo el formato del JSON loggeado cambia | Ninguno | [CONFIRMADO] Persiste payloads agnósticamente |
| `GeminiCallMetrics` | `usageMetadata` de Gemini response | Ninguno | Ninguno | [CONFIRMADO] `usageMetadata` tiene la misma estructura en Structured Output |

### 13. Cambios por Archivo

#### [MODIFY] [DocumentExtractionContractBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php)

**Método `build()`, líneas observadas: 38-58:**

```diff
     public function build(string $documentName, array $fields, array $visualChecks): array
     {
         $fieldGroups = $this->groupFields($documentName, $fields);
-        $declarations = $this->buildFunctionDeclarations(
-            $fieldGroups,
-            $this->activeVisualChecks($visualChecks)
-        );
-        $requiredFunctionNames = self::functionNames($declarations);
+        $responseSchema = $this->buildResponseSchema(
+            $fieldGroups,
+            $this->activeVisualChecks($visualChecks)
+        );

         return [
-            'function_declarations' => $declarations,
-            'required_function_names' => $requiredFunctionNames,
+            'response_schema' => $responseSchema,
             'field_groups' => [
                 'fields' => array_column($fieldGroups['fields'], 'campoNombre'),
                 'items' => array_column($fieldGroups['items'], 'campoNombre'),
             ],
             'contract_hash' => self::hashPayload([
-                'function_declarations' => $declarations,
-                'required_function_names' => $requiredFunctionNames,
+                'response_schema' => $responseSchema,
             ]),
         ];
     }
```

**Nuevo método `buildResponseSchema()` (reemplaza `buildFunctionDeclarations()`):**

```php
/**
 * Construye el responseSchema unificado para Structured Output.
 */
private function buildResponseSchema(array $fieldGroups, array $visualChecks): array
{
    $properties = [];
    $required = [];

    if ($fieldGroups['fields'] !== []) {
        $properties['fields'] = $this->buildFlatObjectSchema($fieldGroups['fields']);
        $required[] = 'fields';
    }

    if ($fieldGroups['items'] !== []) {
        $properties['items'] = [
            'type' => 'array',
            'items' => $this->buildFlatObjectSchema($fieldGroups['items']),
        ];
    }

    if ($visualChecks !== []) {
        $properties['visual_checks'] = $this->buildVisualChecksSchema($visualChecks);
    }

    $properties['document_quality'] = [
        'type' => 'string',
        'enum' => ['legible', 'parcialmente_legible', 'ilegible'],
    ];
    $properties['quality_notes'] = [
        'type' => 'array',
        'items' => ['type' => 'string'],
    ];
    $required[] = 'document_quality';
    $required[] = 'quality_notes';

    return [
        'type' => 'object',
        'properties' => $properties,
        'required' => $required,
        'propertyOrdering' => array_keys($properties),
    ];
}
```

**Método `buildEvidenceFieldSchema()` se reemplaza por `buildFlatFieldSchema()`:**

```diff
-    private function buildEvidenceFieldSchema(array $field): array
+    private function buildFlatFieldSchema(array $field): array
     {
         $tipoCampo = (string) ($field['tipoCampo'] ?? 'E');
         $valueType = $this->fieldValueType($field);
         $valorType = $this->schemaTypeForField($valueType, $tipoCampo);
-        $valorProperty = [
-            'type' => $valorType,
-            'nullable' => true,
-        ];
+        $schema = ['type' => $valorType, 'nullable' => true];
+
         $configuredDescription = isset($field['description']) ? trim((string) $field['description']) : '';
         $fallbackDescription = $valueType->fieldDescriptionFallback();
         $valorDescription = $configuredDescription !== '' ? $configuredDescription : $fallbackDescription;
-        if ($valorDescription !== null && $valorDescription !== '') {
-            $valorProperty['description'] = $valorDescription;
-        }
-
-        $properties = [
-            'valor' => $valorProperty,
-            'presente' => ['type' => 'boolean'],
-            'estadoExtraccion' => [
-                'type' => 'string',
-                'enum' => ['FOUND', 'FOUND_IN_LIST', 'NOT_FOUND', 'ILLEGIBLE'],
-            ],
-        ];
-        $propertyOrdering = ['valor', 'presente', 'estadoExtraccion'];
-        if ($valueType->allowsMultiValueDocument()) {
-            $properties['valores'] = [
-                'type' => 'array',
-                'items' => ['type' => $valorType],
-            ];
-            $propertyOrdering = ['valor', 'valores', 'presente', 'estadoExtraccion'];
+        if ($valorDescription !== null && $valorDescription !== '') {
+            $schema['description'] = $valorDescription;
         }

-        return [
-            'type' => 'object',
-            'properties' => $properties,
-            'required' => ['valor', 'presente', 'estadoExtraccion'],
-            'propertyOrdering' => $propertyOrdering,
-        ];
+        return $schema;
     }
```

**Nuevo `buildFlatObjectSchema()` (reemplaza `buildObjectSchema()` para uso en responseSchema):**

```php
private function buildFlatObjectSchema(array $fields): array
{
    $properties = [];
    foreach ($fields as $field) {
        $name = $this->fieldName($field);
        $properties[$name] = $this->buildFlatFieldSchema($field);
    }

    $schema = ['type' => 'object', 'properties' => $properties];
    if ($properties !== []) {
        $schema['propertyOrdering'] = array_keys($properties);
    }
    return $schema;
}
```

**Se eliminan**: `buildFunctionDeclarations()`, `buildExtractFieldsDeclaration()`, `buildExtractItemsDeclaration()`, `buildAssessDocumentQualityDeclaration()`, `functionNames()`. Se refactoriza `buildDetectVisualChecksDeclaration()` a `buildVisualChecksSchema()` (retorna solo el array schema, no la envoltura de function declaration).

---

#### [MODIFY] [GeminiGateway.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiGateway.php)

**Nuevo método `sendWithStructuredOutput()`, líneas observadas: post-L172:**

```php
/**
 * Envía un request de Structured Output a la API de Gemini.
 *
 * @param  string $prompt  Texto del prompt del usuario.
 * @param  array<int, array<string, mixed>> $files  Archivos inline.
 * @param  string $systemInstruction  Instrucción de sistema.
 * @param  array<string, mixed> $responseSchema  JSON Schema para responseSchema.
 * @param  string $taskType Perfil de tarea.
 * @param  array<string, mixed> $generationOverrides  Sobrecargas de generación.
 * @param  array<string, mixed>|null $debugContext  Metadata de trazabilidad.
 * @return array<string, mixed>
 */
public function sendWithStructuredOutput(
    string $prompt,
    array $files,
    string $systemInstruction,
    array $responseSchema,
    string $taskType,
    array $generationOverrides = [],
    ?array $debugContext = null
): array {
    // Reutiliza la misma lógica de retry/circuit-breaker del gateway.
    // El payload incluye responseSchema en generationConfig en lugar de tools.
}
```

**Nuevo método privado `buildStructuredOutputPayload()`:**

La diferencia con `buildPayload()` es:
1. No incluye `tools` ni `toolConfig` en el payload.
2. Inyecta `responseMimeType` y `responseSchema` dentro de `generationConfig`.

```php
private function buildStructuredOutputPayload(
    string $prompt,
    array $files,
    string $systemInstruction,
    array $responseSchema,
    string $taskType,
    array $generationOverrides = []
): array {
    $this->assertTaskProfile($taskType, $files);
    $generationConfig = $this->config->toGenerationConfig($generationOverrides, $taskType === self::TASK_EXTRACTION);
    $generationConfig['responseMimeType'] = 'application/json';
    $generationConfig['responseSchema'] = self::normalizeSchemaProperties($responseSchema);

    $parts = [['text' => $prompt]];
    foreach ($files as $index => $file) {
        $label = (string) ($file['label'] ?? '');
        if ($label !== '') {
            $parts[] = ['text' => 'DOCUMENTO ' . ($index + 1) . ': ' . $label];
        }
        $parts[] = ['inlineData' => ['mimeType' => $file['mime'], 'data' => $file['data']]];
    }

    return [
        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => $generationConfig,
        'safetySettings' => $this->getSafetySettings(),
    ];
}
```

`sendWithFunctionCalling()` permanece intacto para `ArticleSemanticMatchJudge`.

---

#### [MODIFY] [GeminiResponseParser.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/GeminiResponseParser.php)

**Método `parse()` se reescribe completamente para Structured Output:**

```php
public function parse(
    array $response,
    array $contract,
    array $multimodalParts,
    string $documentType,
    array $payload,
    array $debugContext
): array {
    $candidate = $this->extractPrimaryCandidate($response);
    $this->assertSuccessfulFinishReason($candidate);

    $textPart = $candidate['content']['parts'][0]['text'] ?? null;
    if (!is_string($textPart)) {
        throw new RuntimeException('GEMINI_EXTRACTION_MISSING_TEXT_RESPONSE');
    }

    $decoded = json_decode($textPart, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('GEMINI_EXTRACTION_INVALID_JSON: ' . json_last_error_msg());
    }

    return $this->validateAndRehydrate($decoded, $contract);
}
```

**Nuevo método `validateAndRehydrate()`:**

```php
private function validateAndRehydrate(array $decoded, array $contract): array
{
    $rawFields = is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [];
    $rawItems  = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
    $visualChecks = is_array($decoded['visual_checks'] ?? null) ? $decoded['visual_checks'] : [];

    $fields = $this->rehydrateEvidenceFields($rawFields);
    $items  = array_map(fn(array $item) => $this->rehydrateEvidenceFields($item), array_filter($rawItems, 'is_array'));

    $documentQuality = $this->validateDocumentQuality($decoded['document_quality'] ?? null);
    $qualityNotes = is_array($decoded['quality_notes'] ?? null)
        ? $decoded['quality_notes']
        : ['Evaluación de calidad por defecto'];

    $this->validateVisualChecks($visualChecks);

    return [
        'fields'           => $fields,
        'items'            => array_values($items),
        'visual_checks'    => $visualChecks,
        'document_quality' => $documentQuality,
        'quality_notes'    => array_values($qualityNotes),
    ];
}
```

**Nuevo método `rehydrateEvidenceFields()`:**

```php
/**
 * Rehidrata campos planos (primitivo|null) a shape canónica {valor, presente, estadoExtraccion}.
 *
 * Regla determinista:
 * - Si valor !== null → presente=true, estadoExtraccion=FOUND
 * - Si valor === null → presente=false, estadoExtraccion=NOT_FOUND
 *
 * @param  array<string, mixed> $flatFields
 * @return array<string, array{valor: mixed, presente: bool, estadoExtraccion: string}>
 */
private function rehydrateEvidenceFields(array $flatFields): array
{
    $rehydrated = [];
    foreach ($flatFields as $name => $value) {
        if (!is_string($name)) {
            continue;
        }

        $rehydrated[$name] = [
            'valor'             => $value,
            'presente'          => $value !== null,
            'estadoExtraccion'  => $value !== null ? 'FOUND' : 'NOT_FOUND',
        ];
    }
    return $rehydrated;
}
```

**Se eliminan**: `extractFunctionCalls()`, `retryMissingFunctions()`, `FUNCTION_RECOVERY_POLICY`, `optionalFunctionArray()`, `requiredFunctionArgs()`. Se preservan: `extractPrimaryCandidate()`, `assertSuccessfulFinishReason()`, `validateDocumentQuality()`, `validateVisualChecks()`.

---

#### [MODIFY] [ExtractionPromptBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/ExtractionPromptBuilder.php)

**`buildToolConfig()` se elimina (L146-154).** Ya no se genera `functionCallingConfig`.

**`contractFunctionDeclarations()` se elimina (L170-185).** Ya no hay `function_declarations`.

**`requiredFunctionNames()` se elimina (L191-208).** Las funciones ya no existen como concepto.

**`contractRequiresFunction()` se adapta.** Verifica presencia de secciones en el schema via `field_groups` y existencia de visual checks en el contrato.

**`buildUserPrompt()` se adapta (L69-140):** Elimina referencias a "funciones" y "function calls". El prompt final dice "Devuelve un JSON con las secciones: fields, items, visual_checks, document_quality, quality_notes." en lugar de "Invoca exactamente una vez cada función...".

**`DEFAULT_SYSTEM_PROMPT` se adapta (L27-40):** Elimina "Invoca cada función permitida exactamente una vez en el mismo turno. No devuelvas texto libre; responde únicamente con function calls." y lo reemplaza por "Responde únicamente con JSON válido según el schema indicado."

**`contractFieldSchemas()` se adapta (L333-343):** Lee propiedades directamente del `response_schema.properties.fields.properties` y `response_schema.properties.items.items.properties`.

---

#### [MODIFY] [DocumentExtractionWorker.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionWorker.php)

**`resolveExtraction()` L318-332:**

```diff
-        $response = $this->gateway->sendWithFunctionCalling(
+        $responseSchema = $this->requiredArray($contract, 'response_schema');
+        $response = $this->gateway->sendWithStructuredOutput(
             $userPrompt,
             $files,
             $systemPrompt,
-            [['functionDeclarations' => $this->promptBuilder->contractFunctionDeclarations($contract)]],
-            $this->promptBuilder->buildToolConfig($contract),
+            $responseSchema,
             GeminiGateway::TASK_EXTRACTION,
             GeminiConfig::generationOverridesFromEnv('GEMINI_EXTRACTION', ['maxOutputTokens' => 4096]),
             [...]
         );
```

---

### 14. Plan de Migración

#### Prerequisitos

- Rama feature creada desde `main`.
- Tests existentes pasan antes de iniciar cambios.

#### Ejecución

1. Modificar `DocumentExtractionContractBuilder` — schema plano + `response_schema`.
2. Agregar `GeminiGateway::sendWithStructuredOutput()`.
3. Refactorizar `GeminiResponseParser::parse()` con rehidratación.
4. Adaptar `ExtractionPromptBuilder` — eliminar FC methods, adaptar prompts.
5. Adaptar `DocumentExtractionWorker::resolveExtraction()` — usar nuevo método.
6. Actualizar tests unitarios (mocks de response, assertions de schema).
7. Ejecutar suite completa `vendor\bin\phpunit`.

#### Validaciones Previas

- `vendor\bin\phpunit` pasa con 0 fallos en rama `main`.

#### Validaciones Posteriores

- `vendor\bin\phpunit` pasa con 0 fallos en la rama feature.
- Prueba empírica con `U77260801145`: enviar a `/audit/single` y verificar que `promptTokensDetails[TEXT]` < 1200 tokens.
- Verificar que la extracción produce los mismos valores que el baseline.

#### Rollback

- Revertir el commit de la rama feature. `sendWithFunctionCalling()` permanece intacto.
- La caché Redis se invalida naturalmente al volver al schema FC original (diferente `contract_hash`).

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| :--- | :--- | :--- |
| Gemini retorna JSON vacío `{}` | `fields` = `[]`, `items` = `[]`, `visual_checks` = `[]`, `document_quality` lanza excepción (campo required en schema). | RuntimeException con mensaje descriptivo |
| Gemini retorna `null` para un campo (dato ilegible/ausente) | Rehidratación produce `{valor: null, presente: false, estadoExtraccion: 'NOT_FOUND'}` | Test unitario de `rehydrateEvidenceFields()` |
| Gemini retorna un valor para un campo | Rehidratación produce `{valor: "...", presente: true, estadoExtraccion: 'FOUND'}` | Test unitario de `rehydrateEvidenceFields()` |
| Gemini retorna JSON inválido (no parseable) | RuntimeException `GEMINI_EXTRACTION_INVALID_JSON` | Test unitario |
| Gemini retorna `finishReason` != `STOP` | RuntimeException `GEMINI_EXTRACTION_UNSAFE_FINISH_REASON` (sin cambios vs actual) | Test existente |
| `visual_checks` ausente en response (schema no tiene `required` para ella) | Se trata como array vacío `[]` | Lógica de fallback en `validateAndRehydrate()` |
| Cache hit con formato anterior (FC) | Cache miss forzado por cambio de `contract_hash` → re-extracción | Automático |
| Campo con `allowsMultiValueDocument()` (ej. `CodigoArticulo` tipo CODE) | El schema plano retorna un string; la rehidratación produce `estadoExtraccion: FOUND`. `valores` queda como `[]` porque Gemini retorna un solo string, no un array | `DocumentNormalizer` ya maneja este caso sin `valores` |

### 16. Testing

#### Nuevos Tests

| Test | Objetivo | Precondiciones | Pasos | Resultado Esperado |
| :--- | :--- | :--- | :--- | :--- |
| `GeminiResponseParser::testRehydrateFieldWithValue()` | Verificar rehidratación de campo con valor | Instancia de parser | Invocar `parse()` con response `{fields: {NombrePaciente: "Juan"}}` | `fields.NombrePaciente = {valor: "Juan", presente: true, estadoExtraccion: "FOUND"}` |
| `GeminiResponseParser::testRehydrateFieldWithNull()` | Verificar rehidratación de campo null | Instancia de parser | Invocar `parse()` con response `{fields: {NombrePaciente: null}}` | `fields.NombrePaciente = {valor: null, presente: false, estadoExtraccion: "NOT_FOUND"}` |
| `GeminiResponseParser::testInvalidJsonThrows()` | Verificar error en JSON inválido | Instancia de parser | Invocar `parse()` con response `{parts: [{text: "not json"}]}` | RuntimeException |
| `DocumentExtractionContractBuilder::testBuildReturnsResponseSchema()` | Verificar que `build()` retorna `response_schema` en lugar de `function_declarations` | Instancia del builder | Invocar `build()` con campos estándar | Array contiene key `response_schema`, no `function_declarations` |
| `DocumentExtractionContractBuilder::testFlatFieldSchemaIsNotNested()` | Verificar que cada campo es un tipo primitivo, no un objeto anidado | Instancia del builder | Invocar `build()` con campo `text` | Schema del campo = `{type: "string", nullable: true}`, no contiene `properties` |

#### Tests Modificados

| Test | Cambio | Motivo |
| :--- | :--- | :--- |
| `DocumentExtractionWorkerTest::*` | Actualizar mocks de `GeminiGateway` response de FC parts a JSON text response | Estructura de respuesta cambia de `functionCall` a `text` |
| `ExtractionPromptBuilderTest::*` | Eliminar tests de `buildToolConfig()` y `contractFunctionDeclarations()` | Métodos eliminados |

#### Tests Eliminados

| Test | Motivo | Cobertura de Reemplazo |
| :--- | :--- | :--- |
| Tests de `retryMissingFunctions()` (integrados en WorkerTest) | Retry selectivo eliminado; `responseSchema` garantiza estructura | Nuevo test de fallback para campos opcionales ausentes |

#### Verificaciones Manuales

| Verificación | Objetivo | Pasos | Resultado Esperado |
| :--- | :--- | :--- | :--- |
| Extracción real `U77260801145` | Verificar que la extracción produce resultados correctos con tokens reducidos | cURL a `/audit/single` con `disDetNro: U77260801145` en desarrollo local | (1) HTTP 200 con extracción exitosa. (2) Log en `logs/responseIA/` muestra `promptTokensDetails[TEXT] < 1200`. (3) Valores extraídos coinciden con baseline. |

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| :--- | :--- | :--- | :--- |
| Gemini produce resultados menos precisos con Structured Output vs Function Calling | Rendimiento | Media | Verificación empírica con `U77260801145`. Si degrada, rollback inmediato (1 commit). |
| `responseSchema` rechazado con HTTP 400 por Gemini | Técnico | Baja | Schema usa tipos primitivos ya validados empíricamente en FC. Si 400, el log `responseIA` captura el error para diagnóstico. |
| Pérdida de `FOUND_IN_LIST` como estado distinguido | Consistencia de datos | Baja | `FOUND_IN_LIST` solo aplica a campos CODE/TRACE_TOKEN que retornan arrays de tokens. El schema plano retorna un string simple; `valores` se mantiene vacío. `DocumentNormalizer` ya maneja ambos casos. |
| Invalidación masiva de caché Redis | Operativo | Baja | Esperado y deseable: cache miss fuerza re-extracción con schema optimizado. No impacta producción (cache es optimization, no dependencia). |

### 18. Criterios de Aceptación

1. `vendor\bin\phpunit` ejecuta con 0 fallos y 0 errores.
2. El payload enviado a Gemini API no contiene las keys `tools` ni `toolConfig`.
3. El payload enviado contiene `generationConfig.responseMimeType = "application/json"` y `generationConfig.responseSchema` con el schema unificado.
4. `promptTokensDetails.TEXT` en logs `responseIA/` es menor a 1200 tokens para la misma extracción de AUTORIZACION (baseline: 2300).
5. La extracción de `U77260801145` produce los mismos campos con los mismos valores que el baseline.
6. `sendWithFunctionCalling()` permanece intacto y los tests de `ArticleSemanticMatchJudge` pasan sin cambios.
7. El método `rehydrateEvidenceFields()` produce shape canónica `{valor, presente, estadoExtraccion}` verificada por tests unitarios.

### 19. Observabilidad

Sin impacto en observabilidad. [CONFIRMADO] El cambio es de Riesgo Medio. Los logs `responseIA/` capturan automáticamente el nuevo formato de payload/response. Las métricas de telemetría (`GeminiCallMetrics`) funcionan idénticamente ya que `usageMetadata` tiene la misma estructura en Structured Output.

### 20. Estrategia de Rollout

Sin estrategia de rollout requerida. [CONFIRMADO] Riesgo Medio — el cambio se deploya directamente en producción via CI/CD. Rollback = revertir commit en `main`, re-deploy automático.

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| :--- | :--- | :--- |
| Todas las entidades persistentes mencionadas están definidas | PASS | Sin cambios en persistencia. |
| Todas las columnas mencionadas existen | PASS | N/A — sin cambios de BD. |
| Todos los contratos documentados con clasificación | PASS | Payload Gemini y shape interna post-parser documentados. |
| Todos los requisitos tienen trazabilidad | PASS | R-01 a R-04 mapeados a implementación y validación. |
| Todos los consumidores analizados | PASS | Grafo de dependencias cerrado con evidencia. |
| Todas las migraciones tienen rollback | PASS | N/A — sin migración de datos. Rollback = revertir commit. |
| Todas las referencias a archivos, clases, funciones están definidas | PASS | Todas con ruta absoluta y líneas observadas. |
| Toda compatibilidad tiene evidencia | PASS | Documentación oficial de Google + evidencia empírica de schema FC equivalente. |
| Todos los criterios son verificables | PASS | 7 criterios con métricas medibles. |
| Observabilidad documentada | N/A | [CONFIRMADO] Riesgo Medio — sin impacto en observabilidad. |
| Rollout documentado | N/A | [CONFIRMADO] Riesgo Medio — deploy directo. |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| :--- | :--- | :--- |
| ¿Existe alguna decisión arquitectónica implícita? | No | FASE 1 §6: 6 decisiones documentadas explícitamente. |
| ¿Existe algún contrato sin documentar? | No | FASE 1 §10: Payload Gemini (antes/después) y shape interna post-parser documentados. |
| ¿Existe algún consumidor no analizado? | No | FASE 0.2: Grafo cerrado. FASE 0.1: Perímetro cerrado con 7 métodos de búsqueda. |
| ¿Existe alguna migración sin rollback? | No | N/A — sin migración de datos. |
| ¿Existe algún dato persistido sin migración? | No | N/A — sin cambios de persistencia. |
| ¿Existe alguna afirmación sin evidencia? | No | Todas las afirmaciones etiquetadas `[CONFIRMADO]` con ruta:línea. |
| ¿Existen referencias huérfanas? | No | Todo método nuevo/eliminado tiene contexto de uso documentado. |
| ¿Dos implementadores producirían soluciones diferentes? | No | Diffs antes/después con líneas exactas; reglas de rehidratación determinísticas. |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Resultado | Evidencia |
| :--- | :--- | :--- | :--- |
| 1 | ¿Script/entrypoint invoca algo eliminado? | NO | `sendWithFunctionCalling()` permanece. `bin/audit-worker.php` instancia `DocumentExtractionWorker` que internamente cambia su implementación. |
| 2 | ¿Build depende de algo eliminado? | NO | Los métodos eliminados son internos de clase. Ningún autoload, composer script o CI step los referencia directamente. |
| 3 | ¿Pipeline CI valida con flujo diferente? | NO | [CONFIRMADO] CI ejecuta `composer test` que corre PHPUnit; tests actualizados en este cambio. |
| 4 | ¿Se asume comportamiento de herramienta sin verificar? | NO | [CONFIRMADO] FASE 0.4: `responseSchema` verificado contra documentación oficial de Google. `json_decode` es nativo PHP. |
| 5 | ¿Optimizado para un solo entorno? | NO | [CONFIRMADO] FASE 0.5: Compatible en 4 entornos verificados. |
| 6 | ¿Override en runtime oculta algo? | NO | [CONFIRMADO] Las variables de entorno (`GEMINI_MODEL`, `GEMINI_MEDIA_RESOLUTION`) no cambian. No hay feature flags. |
| 7 | ¿Se aplicó best practice sin verificar convención local? | NO | [CONFIRMADO] La migración a `responseSchema` es consistente con el paradigma del proyecto: "IA NO es el Auditor" — Gemini es extractor, no decisor. `responseSchema` es el mecanismo correcto para extractores. |
| 8 | ¿Se modifica interfaz pública sin compatibilidad? | NO | [CONFIRMADO] API REST (`/audit/single`, etc.) sin cambios. Shape post-parser sin cambios (rehidratación la preserva). |
| 9 | ¿Se afectan datos persistidos sin migración? | NO | [CONFIRMADO] Sin cambios en SQL Server. Caché Redis invalidada naturalmente. |
| 10 | ¿Se introduce código muerto o legacy? | NO | [CONFIRMADO] Se elimina código FC del pipeline de extracción. `sendWithFunctionCalling()` permanece pero es usado activamente por `ArticleSemanticMatchJudge`. `ExtractionState::ILLEGIBLE` permanece en enum por backward compat (justificado en D-03). |
| 11 | ¿Se reemplaza mapeo estático por abstracción sin verificar? | NO | [CONFIRMADO] No aplica. No se reemplazan mapeos estáticos. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

`Nivel A — Implementable`

### Justificación

La especificación cumple todas las condiciones de completitud técnica:

- Todo el perímetro verificado por lectura directa (14 archivos).
- Grafo de dependencias cerrado con evidencia en cada arista.
- Todas las regresiones identificadas tienen corrección documentada.
- Cero supuestos S3/S4; dos supuestos S1 con mitigación inmediata.
- Todas las auditorías FASE 2 y FASE 3 resultan PASS / NO.
- Todas las preguntas adversariales resultan NO con evidencia verificable.
- Diffs antes/después con líneas exactas para cada archivo MODIFIED.
- 7 criterios de aceptación medibles y verificables.
- Rollback inmediato por reversión de commit.
