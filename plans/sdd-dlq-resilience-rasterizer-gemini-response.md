# SDD-DLQ-RESILIENCE-001 — Resiliencia DLQ: Timeout Rasterizador + GeminiResponseException Terminal

**Fecha:** 2026-11-08  
**Autor:** Antigravity (IA)  
**Estado:** APROBADO — listo para implementación  
**Prioridad:** CRÍTICA — 58 entradas DLQ en producción, bucle determinista  
**Archivos afectados:** `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `.env.example`

---

## 1. Contexto y Motivación

### 1.1 Estado de la DLQ en producción (medición 2026-11-08T20:09 UTC-5)

| Categoría | Entradas | Stage | Causa raíz |
|---|---|---|---|
| `gemini_operation_failed` (semantic) | 26 | semantic | Timeout/quota Gemini — reintentos Gateway agotados |
| Timeout rasterización 60s | 11 | extraction | `DEFAULT_TIMEOUT_SECONDS=60` hardcodeado — PDFs complejos |
| `gemini_operation_failed` (extraction) | 8 | extraction | Timeout/quota Gemini — reintentos Gateway agotados |
| `GEMINI_RESPONSE_OUTPUT_TRUNCATED` | 2 | extraction/semantic | `GeminiResponseException` escapa clasificador → 3 reintentos pipeline → DLQ |
| `GEMINI_RESPONSE_PROVIDER_BLOCKED` | 2 | extraction/semantic | Ídem |
| `ATTACHMENTDOWNLOADEXCEPTION` | 1 | download | Transitorio Google Drive — no requiere código |
| **Total** | **58** | | |

### 1.2 Contratos activos impactados

- `document_downloaded` → `document_extracted` | `document_rejected` (extracción)
- `document_extracted` → `audit_completed` | `audit_failed` (semántica)
- Pipeline event-driven Redis Streams (no hay breaking change en este spec)

---

## 2. Análisis de Causa Raíz

### 2.1 CR-1: Timeout rasterizador hardcodeado (11 entradas DLQ)

**Archivo:** [`DocumentPdfRasterizer.php`](../app/Services/Audit/Pipeline/DocumentPdfRasterizer.php)  
**Línea:** 25 — `private const DEFAULT_TIMEOUT_SECONDS = 60;`  
**Instanciación:** [`DocumentExtractionWorker.php`](../app/Services/Audit/Pipeline/DocumentExtractionWorker.php) línea 109 — `new DocumentPdfRasterizer()` sin parámetros

**Mecanismo de fallo:**
```
buildMultimodalParts() → pdfRasterizer->rasterize($rawBytes)
  → executeProcess(gs/pdftoppm)
  → poll loop 10ms
  → (now - start) > 60s
  → proc_terminate(SIGKILL)
  → RuntimeException("Timeout de rasterización (60s) excedido")
  → RasterizationFailureException                   ← capturada ✅
  → handleRejectedDocument()                        ← correcto
  → document_rejected
```

**Diagnóstico:** `RasterizationFailureException` ya está capturada y va a `document_rejected` (no DLQ). El problema es que 60s es **insuficiente** para PDFs complejos (muchas páginas, fuentes no incrustadas, tablas densas). El límite es configurable mediante el constructor de `DocumentPdfRasterizer` pero la instanciación en `DocumentExtractionWorker` usa el default hardcodeado.

**Fallo de diseño:** No existe variable de entorno para ajustar el timeout sin rebuild de imagen.

---

### 2.2 CR-2: `GeminiResponseException` escapa el clasificador de errores de contenido (4 entradas DLQ)

**Archivos:**  
- [`GeminiResponseGuard.php`](../app/Services/Audit/GeminiResponseGuard.php) — lanza `GeminiResponseException($reason, $context)`  
- [`GeminiResponseException.php`](../app/Services/Audit/GeminiResponseException.php) — `parent::__construct($reason->value)` → **código HTTP = 0**  
- [`DocumentExtractionWorker.php`](../app/Services/Audit/Pipeline/DocumentExtractionWorker.php) línea 763 — `isGeminiDocumentContentError()` filtra `$e->getCode() !== 400`

**Mecanismo de fallo:**
```
GeminiGateway::extract() → GeminiResponseGuard::assertUsable()
  → blockReason = "HARM_CATEGORY_..." → GeminiResponseException(PROVIDER_BLOCKED, ctx)
  
DocumentExtractionWorker::resolveExtraction()
  → catch RuntimeException $geminiError
  → isGeminiDocumentContentError($geminiError)
      → $e->getCode() !== 400  ← PROVIDER_BLOCKED tiene code=0 → retorna false
  → NO capturado como content error
  → throw $geminiError
  → AuditEventConsumer (pipeline level) → 3 reintentos → DLQ
```

**`retryable()` ya existe** en `GeminiResponseException` y retorna `false` para `PROVIDER_BLOCKED`, `OUTPUT_TRUNCATED`, `UNSAFE_FINISH_REASON` — pero **nunca se llama** porque el catch genérico no hace instanceof.

**Fallo de diseño:** El clasificador `isGeminiDocumentContentError()` hace pattern matching de texto y código HTTP 400. `GeminiResponseException` es una excepción específica con semántica conocida (`retryable()`), pero el catch no la identifica por tipo.

---

### 2.3 CR-3: `gemini_operation_failed` en semantic/extraction (34 entradas DLQ)

**Diagnóstico:** `MAX_API_RETRIES = 3` con backoff exponencial `1s, 2s, 4s` — diseño correcto. Estos fallos son casos donde los reintentos internos del Gateway **también se agotan** (rate limit 429, timeout de red, Gateway down). Son errores **transitorios de infraestructura** que el pipeline-level retry (3 intentos) tampoco resuelve cuando hay saturación sostenida de quota Gemini.

**Decisión:** No aumentar `MAX_API_RETRIES` en Gateway (aumenta latencia y consumo de quota). En cambio, tratar `gemini_operation_failed` a nivel pipeline como **DLQ esperada** cuando Gemini está saturado — son documentos que necesitan reprocesamiento manual cuando se restaura la quota. Esta categoría no tiene solución de código; es un límite de tasa externo.

> **Nota:** El script de reprocesamiento DLQ ya existe (`/audit/dlq/reprocess`). Estos 34 documentos deben reprocesarse manualmente desde la DLQ una vez restaurada la quota Gemini.

---

## 3. Decisión Clean Rebuild Policy

**Decisión formal: REFACTORIZACIÓN INCREMENTAL — SIN REBUILD**

| Cambio | Estrategia | Justificación |
|---|---|---|
| CR-1: Timeout rasterizador | Parche paramétrico | Constructor ya acepta `$timeoutSeconds`, solo falta leer env var. Contrato del componente inalterado. |
| CR-2: GeminiResponseException → document_rejected | Refactor de catch | Agregar `catch (GeminiResponseException)` antes del `catch (RuntimeException)` existente. Contrato de eventos inalterado. |
| CR-3: gemini_operation_failed | Sin cambio de código | Error externo de quota. Reprocesar desde DLQ cuando se restaure quota. |

**Contratos activos respetados:** `document_downloaded`, `document_extracted`, `document_rejected`, `audit_completed`, `audit_failed` — sin cambios de schema.

---

## 4. Supuestos

| ID | Supuesto | Verificación |
|---|---|---|
| S-1 | `DocumentPdfRasterizer::__construct($logger, $dpi, $timeoutSeconds)` acepta el timeout como tercer parámetro | ✅ Verificado — línea 109 de ExtractionWorker construye con `new DocumentPdfRasterizer()` sin args, el constructor acepta `?int $timeoutSeconds = null` |
| S-2 | `GeminiResponseException` extiende `RuntimeException` | ✅ Verificado — `final class GeminiResponseException extends \RuntimeException` |
| S-3 | `DocumentSemanticWorker` tiene el mismo patrón que `ExtractionWorker` para manejar `GeminiResponseException` | A verificar — si el semantic worker tiene el mismo bug, aplicar el mismo fix |
| S-4 | `DocumentRejectionReason` tiene un valor para OUTPUT_TRUNCATED / PROVIDER_BLOCKED | A verificar — puede usar `GEMINI_DECODE_FAILURE` o requerir un nuevo case |
| S-5 | `PDF_RASTERIZER_TIMEOUT_SECONDS=120` es suficiente para PDFs complejos del dominio | Asumido — valor duplicado del default. Ajustable sin rebuild vía env var. |

---

## 5. Cambios Especificados

### 5.1 Cambio A — `DocumentPdfRasterizer.php`: Timeout configurable vía env var

**Archivo:** `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php`

**Precondición:** El constructor ya tiene `private const DEFAULT_TIMEOUT_SECONDS = 60` y `$timeoutSeconds` como parámetro. El worker lo instancia en línea 109 sin parámetros.

**Qué cambia:** `DocumentExtractionWorker::__construct()` debe leer `PDF_RASTERIZER_TIMEOUT_SECONDS` del entorno y pasarlo al constructor del rasterizador.

**En `DocumentExtractionWorker.php` línea 109:**
```php
// ANTES:
$this->pdfRasterizer  = $pdfRasterizer  ?? new DocumentPdfRasterizer();

// DESPUÉS:
$rasterTimeout = (int) Env::get('PDF_RASTERIZER_TIMEOUT_SECONDS', DocumentPdfRasterizer::DEFAULT_TIMEOUT_SECONDS);
$this->pdfRasterizer  = $pdfRasterizer  ?? new DocumentPdfRasterizer(null, null, $rasterTimeout > 0 ? $rasterTimeout : DocumentPdfRasterizer::DEFAULT_TIMEOUT_SECONDS);
```

**En `DocumentPdfRasterizer.php`:** Cambiar `DEFAULT_TIMEOUT_SECONDS` de `private const` a `public const` para permitir que el caller use el valor default sin acoplamiento de número mágico.

**Resultado:** `PDF_RASTERIZER_TIMEOUT_SECONDS=120` en `.env` → rasterizador usa 120s. Sin cambio de comportamiento si no se define (sigue usando 60s via `DEFAULT_TIMEOUT_SECONDS`).

---

### 5.2 Cambio B — `DocumentExtractionWorker.php`: Capturar `GeminiResponseException` no-retryable

**Archivo:** `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`  
**Ubicación:** Bloque `try/catch` en `handle()`, líneas ~402–446

**Qué cambia:** Agregar un `catch (GeminiResponseException)` **antes** del `catch (RuntimeException)` existente, para capturar las excepciones terminales de Gemini (PROVIDER_BLOCKED, OUTPUT_TRUNCATED, UNSAFE_FINISH_REASON) y convertirlas en `document_rejected` en lugar de reintentos → DLQ.

```php
// AGREGAR antes del catch (RuntimeException $geminiError):
} catch (\App\Services\Audit\GeminiResponseException $geminiResponse) {
    if (!$geminiResponse->retryable()) {
        $reason = $this->mapGeminiResponseToRejectionReason($geminiResponse);
        $integrity = [
            'valid'         => false,
            'reason'        => $reason,
            'declared_mime' => $document['mime'] ?? '',
            'detected_mime' => null,
            'size_bytes'    => strlen(base64_decode($document['data'] ?? '', true) ?: ''),
        ];
        $this->handleRejectedDocument($event, $payload, $document, $integrity);
        $this->documentCache->release($blobKey);
        $this->telemetryPublisher->rejected(
            $event->auditId,
            'extraction',
            self::elapsedMs($extractionStartedAt),
            $event->documentId,
            $disDetNro,
            array_merge($telemetryMeta, ['reason' => $reason, 'gemini_reason' => $geminiResponse->reasonCode()]),
            $event->jobId
        );
        return;
    }
    throw $geminiResponse; // MISSING_CANDIDATE es retryable → pipeline retry normal
}
```

**Nuevo método privado `mapGeminiResponseToRejectionReason()`:**
```php
private function mapGeminiResponseToRejectionReason(\App\Services\Audit\GeminiResponseException $e): string
{
    return match ($e->reason) {
        \App\Services\Audit\GeminiResponseFailureReason::PROVIDER_BLOCKED    => DocumentRejectionReason::GEMINI_PROVIDER_BLOCKED,
        \App\Services\Audit\GeminiResponseFailureReason::OUTPUT_TRUNCATED    => DocumentRejectionReason::GEMINI_OUTPUT_TRUNCATED,
        \App\Services\Audit\GeminiResponseFailureReason::UNSAFE_FINISH_REASON => DocumentRejectionReason::GEMINI_UNSAFE_FINISH,
        default                                                               => DocumentRejectionReason::GEMINI_DECODE_FAILURE,
    };
}
```

**Prerequisito:** Verificar si `DocumentRejectionReason` tiene los cases `GEMINI_PROVIDER_BLOCKED`, `GEMINI_OUTPUT_TRUNCATED`, `GEMINI_UNSAFE_FINISH`. Si no existen, agregarlos.

---

### 5.3 Cambio C — `.env.example`: Documentar nueva variable

```
# Timeout en segundos para rasterización de PDFs complejos (default: 60).
# Aumentar si PDFs del dominio exceden el límite y van a DLQ con "Timeout de rasterización".
PDF_RASTERIZER_TIMEOUT_SECONDS=120
```

---

### 5.4 Cambio D — `DocumentSemanticWorker.php` (si aplica el mismo bug)

Verificar si `DocumentSemanticWorker` tiene el mismo patrón de `catch (RuntimeException)` sin `catch (GeminiResponseException)`. Si aplica, implementar el mismo fix con `telemetryPublisher->rejected(..., 'semantic', ...)`.

---

## 6. Tests Requeridos (TDD)

### 6.1 `DocumentPdfRasterizerTest.php` — Cambio A

| Test | Descripción |
|---|---|
| `testDefaultTimeoutIsUsedWhenEnvNotSet()` | Instanciar con `null` → timeout = `DEFAULT_TIMEOUT_SECONDS` |
| `testCustomTimeoutIsRespected()` | Instanciar con `$timeoutSeconds=120` → timeout = 120 |

### 6.2 `DocumentExtractionWorkerTest.php` — Cambio B

| Test | Descripción |
|---|---|
| `testProviderBlockedBecomesDocumentRejected()` | Gateway lanza `GeminiResponseException(PROVIDER_BLOCKED)` → worker publica `document_rejected`, no relanza |
| `testOutputTruncatedBecomesDocumentRejected()` | Gateway lanza `GeminiResponseException(OUTPUT_TRUNCATED)` → worker publica `document_rejected`, no relanza |
| `testMissingCandidateIsRetryable()` | Gateway lanza `GeminiResponseException(MISSING_CANDIDATE)` → worker relanza (retryable) |
| `testUnsafeFinishBecomesDocumentRejected()` | Gateway lanza `GeminiResponseException(UNSAFE_FINISH_REASON)` → `document_rejected` |
| `testRasterTimeoutFromEnv()` | Env `PDF_RASTERIZER_TIMEOUT_SECONDS=90` → rasterizador instanciado con `90` |

---

## 7. Orden de Implementación

```
Paso 1: Verificar PHPUnit baseline (1023 tests / 0 errores / exit 0)
Paso 2: Verificar DocumentRejectionReason — agregar cases si faltan
Paso 3: Cambio A — DocumentPdfRasterizer: DEFAULT_TIMEOUT_SECONDS → public const
Paso 4: Cambio A — DocumentExtractionWorker: leer PDF_RASTERIZER_TIMEOUT_SECONDS
Paso 5: Cambio B — DocumentExtractionWorker: agregar catch (GeminiResponseException)
Paso 6: Cambio B — DocumentExtractionWorker: nuevo método mapGeminiResponseToRejectionReason()
Paso 7: Verificar DocumentSemanticWorker — aplicar Cambio D si aplica
Paso 8: Cambio C — .env.example: documentar PDF_RASTERIZER_TIMEOUT_SECONDS
Paso 9: Tests PHPUnit (nuevos + regresión)
Paso 10: PHPUnit ≥ 1023 tests / 0 errores / exit 0
Paso 11: git commit + push → CI/CD deploya
Paso 12: gh variable set PDF_RASTERIZER_TIMEOUT_SECONDS --body "120" --env production
Paso 13: Monitorear DLQ post-deploy (meta: 0 nuevas entradas de rasterizador y GeminiResponse)
```

---

## 8. Criterios de Aceptación

| ID | Criterio | Verificación |
|---|---|---|
| CA-1 | PHPUnit: ≥ 1023 tests / 0 errores / 0 fallos post-implementación | `php vendor/bin/phpunit` → exit 0 |
| CA-2 | PDF con 300 páginas rasterizado en menos de 120s → `document_extracted` (no DLQ) | Manual post-deploy |
| CA-3 | Documento bloqueado por Gemini (PROVIDER_BLOCKED) → `document_rejected` (no DLQ) | Test unitario |
| CA-4 | Documento con OUTPUT_TRUNCATED → `document_rejected` (no DLQ) | Test unitario |
| CA-5 | Variable `PDF_RASTERIZER_TIMEOUT_SECONDS` en `.env.example` y GitHub Variables | `gh variable list --env production` |
| CA-6 | DLQ nuevas entradas de tipo rasterizador y GeminiResponse = 0 tras 24h de producción | Monitor DLQ |

---

## 9. Riesgos y Mitigaciones

| Riesgo | Mitigación |
|---|---|
| `DocumentRejectionReason` no tiene cases para los nuevos motivos | Leer el archivo antes de codificar; agregar cases si faltan |
| `DocumentSemanticWorker` tiene el mismo bug pero no fue diagnosticado | Leer el archivo en Paso 7 antes de codificar |
| Timeout 120s sigue siendo insuficiente para algún PDF extremo | Variable configurable — ajustar sin rebuild |
| `MISSING_CANDIDATE` retryable sigue yendo a DLQ tras 3 reintentos | Comportamiento esperado — son errores transitorios de Gemini |

---

## 10. Rollback

**Trigger:** PHPUnit falla tras implementación O `document_rejected` inesperado en producción.

**Procedimiento:**
```bash
git revert HEAD
git push origin main
# CI/CD deploya imagen anterior automáticamente
```

**Tiempo estimado de rollback:** menos de 5 min (CI/CD pipeline existente)

