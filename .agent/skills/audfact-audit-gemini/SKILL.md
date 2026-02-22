---
name: audfact-audit-gemini
description: Trabajar en el pipeline de auditoría IA de AudFact. Usar cuando se modifique app/worker/GeminiAuditService.php, app/Services/Audit/*, reglas de prompts/schema, estrategia de reintentos, parseo JSON de Gemini o manejo de archivos adjuntos URL/BLOB.
---

# AudFact Audit Gemini

## Objetivo
Mantener confiable el flujo de auditoría documental y su salida JSON validada.

> [!TIP]
> Consulta la documentación técnica del pipeline en [audit-workflow.md](file:///c:/Users/USER/Desktop/AudFact/plans/features/audit-workflow.md).

## Archivos clave

| Archivo | Tamaño | Rol |
|---|---|---|
| `app/worker/GeminiAuditService.php` | 31.5 KB (817 líneas) | ⭐ Worker principal — orquesta todo el flujo |
| `app/Services/Audit/AuditPromptBuilder.php` | 25.9 KB | Prompt v3.0: 4 capas con axiomas deterministas y motor de 6 dimensiones |
| `app/Services/Audit/AuditResponseSchema.php` | 12.3 KB | Schema JSON esperado de Gemini |
| `app/Services/Audit/AuditFileManager.php` | 11.2 KB | Resuelve archivos: BLOB → memoria (optimizado, sin disco), URL → Drive |
| `app/Services/Audit/AuditResultValidator.php` | 2.4 KB | Valida que la respuesta cumpla el schema |
| `app/Services/Audit/JsonResponseParser.php` | 2.4 KB | Extrae JSON de la respuesta de Gemini |
| `app/Services/Audit/JsonRepairHelper.php` | 2.6 KB | Repara JSON truncado o malformado |
| `app/Services/GoogleDriveAuthService.php` | 9 KB | JWT auth y streaming desde Google Drive |
| `app/Services/GoogleDriveServiceInterface.php` | 1.1 KB | Interfaz Strategy para el servicio de Drive |
| `app/Models/DispensationModel.php` | 3.2 KB | Source of truth (datos de dispensación) |
| `app/Models/AttachmentsModel.php` | 5.1 KB | Resolución de adjuntos BLOB/Drive |
| `app/Models/AuditStatusModel.php` | 5.5 KB | Persistencia de resultados en `AudDispEst` (upsert) |

## Mapa de dependencias del Worker

```
GeminiAuditService
├── DispensationModel (source of truth)
├── AttachmentsModel (adjuntos)
├── AuditStatusModel (persistencia BD)
├── AuditFileManager
│   └── GoogleDriveAuthService (descarga)
├── AuditPromptBuilder (prompts)
├── AuditResultValidator (validación)
├── JsonResponseParser
│   └── JsonRepairHelper (reparación)
├── AuditResponseSchema (schema ref)
├── GuzzleHttp\Client (HTTP)
└── Core\Logger (diagnóstico)
```

## Flujo técnico

### Flujo Normal
1. `auditInvoice()` recibe `invoiceId`, `DisDetNro`, `attachmentId?`.
2. Obtener dispensación → `DispensationModel`.
3. Obtener adjuntos → `AttachmentsModel`.
4. Preparar archivos → `AuditFileManager` (BLOB a memoria | Drive URL a temporal).
5. Construir prompt → `AuditPromptBuilder` (v3.0 con Axiomas A1-A4).
6. Enviar a Gemini → `sendGeminiRequestWithRetry()` (exponential backoff).
7. Parsear respuesta → `JsonResponseParser`.
8. Validar schema → `AuditResultValidator`.
9. Guardar → `saveResponse()` → `responseIA/{DisDetNro}.json`.
10. Persistir → `saveToDatabase()` → `AudDispEst` (upsert via `AuditStatusModel`).

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
1. **No truncar JSON manualmente** — usar `JsonRepairHelper` para reparaciones.
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

## Referencias
1. Ver casos ampliados en `references/examples.md`.
2. Ver plantilla y suite en `references/test-cases.md`.
