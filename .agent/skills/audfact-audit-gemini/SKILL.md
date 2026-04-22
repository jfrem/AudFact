---
name: audfact-audit-gemini
description: Trabajar en el pipeline de auditoría IA de AudFact. Usar cuando se modifique app/Services/Audit/AuditOrchestrator.php, app/Services/Audit/*, reglas de prompts/schema, estrategia de reintentos, parseo JSON de Gemini o manejo de archivos adjuntos URL/BLOB.
---

# AudFact Audit Gemini

## Objetivo
Mantener confiable el flujo de auditoría documental y su salida JSON validada.

> [!TIP]
> Consulta la documentación técnica del pipeline en [audit-workflow.md](file:///c:/Users/USER/Desktop/AudFact/plans/features/audit-workflow.md).

## Archivos clave

| Archivo | Rol |
|---|---|
| `app/Services/Audit/AuditOrchestrator.php` | ⭐ Orquestador principal — coordina las 3 fases del pipeline v4 |
| `app/Services/Audit/ExtractionPromptBuilder.php` | Prompt de extracción v4: minimalista, solo pide campos y visual checks. Sin lógica de negocio (delegada a RuleEngine) |
| `app/Services/Audit/ExtractionResponseSchema.php` | Schema de Function Calling para Gemini: define `report_extraction` tool |
| `app/Services/Audit/AuditResponseSchema.php` | Schema JSON de respuesta final del pipeline (métricas, config, items) |
| `app/Services/Audit/AuditFileManager.php` | Resuelve archivos: BLOB → memoria (optimizado, sin disco), URL → Drive |
| `app/Services/Audit/GeminiGateway.php` | Cliente HTTP para Gemini API con retry, timeout, backoff y Function Calling |
| `app/Services/Audit/EmbeddingGateway.php` | Cliente HTTP para Gemini Embedding API (Fase 2) |
| `app/Services/Audit/SemanticComparator.php` | Compara campos FDV vs extraídos usando embeddings (Fase 2) |
| `app/Services/Audit/FieldClassifier.php` | Clasifica campos por tipo (exact, semantic, visual, date, numeric) y doc autoritativo |
| `app/Services/Audit/RuleEngine.php` | ⭐ Motor determinista PHP puro — evalúa discrepancias con pesos de riesgo (Fase 3) |
| `app/Services/Audit/AuditPersistenceService.php` | Persistencia de resultados con **Transacciones PDO**: `AudDispEst` (upsert) + `AdjuntosDispensacion` (UPDATE) — revierte completo si algo falla |
| `app/Services/Audit/AuditTelemetryService.php` | Métricas y telemetría del pipeline (tiempos, intentos, errores) |
| `app/Services/Audit/AuditPreValidator.php` | Pre-validación de datos y archivos antes de enviar a Gemini |
| `app/Services/Audit/AuditQueueService.php` | Orquesta colas de auditoría Redis, encolamiento y estados (Jobs) — Resiliente a reinicios (`NOSCRIPT` fallback) |
| `app/Services/Audit/AuditOrchestratorFactory.php` | Patrón Factory para orquestar la construcción y reutilización de todos los servicios v4 |
| `bin/audit-worker.php` | Consumidor CLI de cola Redis que orquesta las llamadas al pipeline AI de manera concurrente |
| `app/Services/GoogleDriveAuthService.php` | JWT auth y streaming desde Google Drive |
| `app/Services/GoogleDriveServiceInterface.php` | Interfaz Strategy para el servicio de Drive |
| `app/Models/DispensationModel.php` | Source of truth (datos de dispensación) |
| `app/Models/AttachmentsModel.php` | Resolución de adjuntos BLOB/Drive |
| `app/Models/AuditStatusModel.php` | Persistencia de resultados en `AudDispEst` (upsert) |

## Mapa de dependencias del Orquestador

```
AuditOrchestrator (v4)
├── DispensationModel (source of truth)
├── AttachmentsModel (adjuntos)
├── AuditPreValidator (pre-validación)
├── AuditFileManager
│   └── GoogleDriveAuthService (descarga)
├── ExtractionPromptBuilder (prompt de extracción, Fase 1)
├── ExtractionResponseSchema (Function Calling schema, Fase 1)
├── GeminiGateway (HTTP → Gemini API, Fase 1 + FC)
├── EmbeddingGateway (HTTP → Gemini Embedding API, Fase 2)
├── SemanticComparator (comparación semántica, Fase 2)
├── FieldClassifier (clasificación de campos)
├── RuleEngine (evaluación determinista, Fase 3)
├── AuditPersistenceService (BD: AudDispEst + AdjuntosDispensacion)
├── AuditTelemetryService (métricas)
├── AuditResponseSchema (schema ref)
└── Core\Logger (diagnóstico)
```

## Flujo técnico

### Flujo Normal (Síncrono `POST /audit/single`)
1. `auditInvoice()` recibe `invoiceId`, `disDetNro`, `attachmentId`.
2. Pre-validar datos → `AuditPreValidator` (incluye consulta de adjuntos requeridos con prefiltrado SQL `AdjDisOpc='N'`).
3. Preparar archivos → `AuditFileManager` (BLOB a memoria | Drive URL a temporal).
4. **Fase 1 — Extracción**: `ExtractionPromptBuilder` + `GeminiGateway::sendWithFunctionCalling()` → `ExtractionResponseSchema::parseExtractionResponse()`. Gemini invoca `report_extraction` con JSON tipado.
5. **Fase 2 — Embedding**: `SemanticComparator` + `EmbeddingGateway` → cosine similarity para campos tipo `semantic`.
6. **Fase 3 — Reglas**: `RuleEngine::evaluate()` — lógica PHP determinista, pesos de riesgo, clasificación de hallazgos.
7. Persistir → `AuditPersistenceService` → `AudDispEst` (upsert via `AuditStatusModel`).
8. Registrar telemetría → `AuditTelemetryService`.

### Flujo Asíncrono (Colas `POST /audit/async` + `bin/audit-worker.php`)
1. `POST /audit/async` -> Valida límites y llama a `AuditQueueService::enqueue()`.
2. Encola ID de factura en Redis Lists y crea llave Hash de seguimiento.
3. El proceso CLI long-running `bin/audit-worker.php` detiene la cola via `brpop`.
4. El worker desempaqueta, llama a `AuditOrchestrator::auditInvoice()` internamente.
5. El worker intercepta éxito/error y actualiza los hashes de seguimiento.

## Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `GEMINI_API_KEY` | API key para Google Gemini |
| `GEMINI_MODEL` | Modelo a usar (ej: gemini-2.0-flash) |
| `GEMINI_MAX_RETRIES` | Intentos máximos por request |
| `GEMINI_TEMPERATURE` | Temperatura de generación (0.0 = determinista) |
| `GEMINI_TOP_P` | Top-P sampling (1.0 = greedy con temp 0) |
| `GEMINI_TOP_K` | Top-K sampling (1 = solo token más probable) |
| `GEMINI_SEED` | Seed para reproducibilidad (dev: 42, prod: opcional) |
| `GDRIVE_*` | Credenciales JWT de Google Drive |

## Reglas de implementación
1. Mantener respuesta final con campos `response`, `message`, `documento`, `data.items`.
2. **No omitir limpieza de temporales en `finally`**.
3. Tratar errores de API con mensaje corto y código HTTP cuando exista.
4. El prompt de extracción (Fase 1) NO debe contener lógica de negocio — solo extracción de campos y visual checks.
5. **No romper compatibilidad con `AuditResponseSchema`**. El schema de salida final debe ser consistente con el frontend.
6. La evaluación de hallazgos (Fase 3) es **exclusivamente PHP determinista** en `RuleEngine` — nunca en el prompt de Gemini.
7. Inyección de dependencias: constructor acepta 10 servicios tipados (sin nullable legacy).
8. Resultados se persisten doble: disco (`responseIA/`) + BD estado (`AudDispEst` via upsert + `AdjuntosDispensacion` via `updateAuditResult`).
9. **Exclusión de Régimen**: Para clientes que no suministran datos fiables de régimen (ej. NitSec `1045`, `80455`, `2426`), `DispensationModel` inyecta `NULL` en la consulta, transformándose en `N/D` por el prompt builder. El `RuleEngine` **omite la evaluación** si la Fuente de Verdad para `Cliente.Regimen` es `N/D` o `ARL`.

## Anti-patterns ⚠️
1. **No inyectar lógica de negocio en el prompt de extracción** — eso es responsabilidad exclusiva del `RuleEngine` (Fase 3).
2. **No omitir safety settings** — documentos médicos requieren `BLOCK_NONE`.
3. **No ignorar HTTP 429** (quota) ni **503** (model unavailable) — el retry con backoff los maneja.
4. **No hardcodear el modelo Gemini** — leer de `GEMINI_MODEL` env var.
5. **No parsear texto libre de Gemini** — usar Function Calling (`report_extraction`) para obtener JSON tipado directamente.
6. **No guardar archivos temporales sin cleanup** — siempre usar `try/finally`.

## Cross-references
- **`audfact-sqlsrv-models`**: `DispensationModel` y `AttachmentsModel` proveen datos.
- **`audfact-security-guardrails`**: Sanitización de archivos descargados.

## Ejemplos

### Ejemplo 1: invocación de auditoría por API
```bash
curl -X POST http://localhost:8080/audit ^
  -H "Content-Type: application/json" ^
  -d "{\"facNitSec\":1165,\"date\":\"2025-12-30\",\"limit\":1}"
```

### Ejemplo 2: shape de error consistente
```json
{
  "response": "error",
  "message": "Dispensación no encontrada",
  "documento": "MULTIPLE",
  "data": {
    "items": [],
    "details": null
  }
}
```

### Ejemplo 3: limpieza garantizada
```php
try {
    [$result, $attempt] = $this->executeAuditFlow($dispensationData, $files);
} finally {
    foreach ($files as $file) {
        $this->fileManager->cleanup($file);
    }
}
```

## Checklist rápido
1. Flujo normal y estricto siguen funcionando.
2. Casos de error retornan JSON consistente.
3. Adjuntos URL/BLOB siguen soportados.
4. Respuesta se persiste en disco (`responseIA/`).
5. Resultado se persiste en BD (`AudDispEst` via upsert).
6. Logs de diagnóstico cubren fases clave.
7. Temporales limpiados en `finally`.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

**Después de implementar cualquier cambio en los archivos gobernados por esta skill, DEBES:**

1. **Verificar si este SKILL.md sigue siendo preciso**:
   - ¿Los servicios listados en "Archivos clave" siguen existiendo? ¿Hay nuevos?
   - ¿El mapa de dependencias del Orquestador sigue vigente?
   - ¿El flujo técnico (Normal y Estricto) refleja el código actual?
   - ¿Las variables de entorno listadas están actualizadas?
2. **Si detectas una desviación**: corregirla ANTES de ejecutar `audfact-docs-sync`.
3. **Ejecutar `audfact-docs-sync`**: esto es la segunda capa de validación.

> [!CAUTION]
> Ignorar este paso y dejar la skill desactualizada generará drift
> acumulativo que confundirá a futuros agentes.

## Referencias
1. Ver casos ampliados en `references/examples.md`.
2. Ver plantilla y suite en `references/test-cases.md`.
