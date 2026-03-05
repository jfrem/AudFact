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
| `app/Services/Audit/AuditOrchestrator.php` | ⭐ Orquestador principal — coordina todo el flujo de auditoría |
| `app/Services/Audit/AuditPromptBuilder.php` | Prompt v3.0: 4 capas con axiomas deterministas y motor de 6 dimensiones |
| `app/Services/Audit/AuditResponseSchema.php` | Schema JSON esperado de Gemini |
| `app/Services/Audit/AuditFileManager.php` | Resuelve archivos: BLOB → memoria (optimizado, sin disco), URL → Drive |
| `app/Services/Audit/AuditResultValidator.php` | Valida que la respuesta cumpla el schema |
| `app/Services/Audit/JsonResponseParser.php` | Extrae y repara JSON de la respuesta de Gemini |
| `app/Services/Audit/GeminiGateway.php` | Cliente HTTP para Gemini API con retry, timeout y backoff |
| `app/Services/Audit/AuditPersistenceService.php` | Persistencia de resultados en `AudDispEst` y observaciones detalle |
| `app/Services/Audit/AuditTelemetryService.php` | Métricas y telemetría del pipeline (tiempos, intentos, errores) |
| `app/Services/Audit/AuditPreValidator.php` | Pre-validación de datos y archivos antes de enviar a Gemini |
| `app/Services/GoogleDriveAuthService.php` | JWT auth y streaming desde Google Drive |
| `app/Services/GoogleDriveServiceInterface.php` | Interfaz Strategy para el servicio de Drive |
| `app/Models/DispensationModel.php` | Source of truth (datos de dispensación) |
| `app/Models/AttachmentsModel.php` | Resolución de adjuntos BLOB/Drive |
| `app/Models/AuditStatusModel.php` | Persistencia de resultados en `AudDispEst` (upsert) |

## Mapa de dependencias del Worker

```
AuditOrchestrator
├── DispensationModel (source of truth)
├── AttachmentsModel (adjuntos)
├── AuditPreValidator (pre-validación)
├── AuditFileManager
│   └── GoogleDriveAuthService (descarga)
├── AuditPromptBuilder (prompts)
├── GeminiGateway (HTTP → Gemini API)
├── JsonResponseParser (parseo + reparación)
├── AuditResultValidator (validación schema)
├── AuditPersistenceService (BD: AudDispEst)
├── AuditTelemetryService (métricas)
├── AuditResponseSchema (schema ref)
└── Core\Logger (diagnóstico)
```

## Flujo técnico

### Flujo Normal
1. `orchestrate()` recibe `invoiceId`, `dispensationData`, `attachments`.
2. Pre-validar datos → `AuditPreValidator`.
3. Preparar archivos → `AuditFileManager` (BLOB a memoria | Drive URL a temporal).
4. Construir prompt → `AuditPromptBuilder` (v3.0 con Axiomas A1-A4).
5. Enviar a Gemini → `GeminiGateway` (exponential backoff).
6. Parsear respuesta → `JsonResponseParser`.
7. Validar schema → `AuditResultValidator`.
8. Persistir → `AuditPersistenceService` → `AudDispEst` (upsert via `AuditStatusModel`).
9. Registrar telemetría → `AuditTelemetryService`.

### Flujo Estricto (reintento)
Si la respuesta normal no pasa validación, `executeAuditFlow()` reintenta con parámetros de generación más restrictivos para obtener JSON válido.

## Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `GEMINI_API_KEY` | API key para Google Gemini |
| `GEMINI_MODEL` | Modelo a usar (ej: gemini-2.0-flash) |
| `GEMINI_MAX_RETRIES` | Intentos máximos por request |
| `GEMINI_TEMPERATURE` | Temperatura de generación |
| `GEMINI_TOP_P` | Top-P sampling |
| `GDRIVE_*` | Credenciales JWT de Google Drive |

## Reglas de implementación
1. Mantener respuesta final con campos `response`, `message`, `documento`, `data.items`.
2. **No omitir limpieza de temporales en `finally`**.
3. Tratar errores de API con mensaje corto y código HTTP cuando exista.
4. Limitar cambios de prompt a reglas de negocio verificables siguiendo los **Axiomas (A1-A4)**.
5. **No romper compatibilidad con `AuditResponseSchema`**.
6. Evaluar hallazgos bajo el **Protocolo de 6 Dimensiones** (Identidad, Cuantitativa, Temporal, Descriptiva, Integridad, Forense).
7. Inyección de dependencias: constructor acepta todas como parámetros opcionales.
8. Resultados se persisten dual: disco (`responseIA/`) + BD (`AudDispEst` via `AuditStatusModel`).

## Anti-patterns ⚠️
1. **No truncar JSON manualmente** — `JsonResponseParser` incluye reparación automática.
2. **No omitir safety settings** — documentos médicos requieren `BLOCK_NONE`.
3. **No ignorar HTTP 429** (quota) ni **503** (model unavailable) — el retry con backoff los maneja.
4. **No hardcodear el modelo Gemini** — leer de `GEMINI_MODEL` env var.
5. **No saltarse `AuditResultValidator`** — la respuesta de Gemini puede ser válida JSON pero schema incorrecto.
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
