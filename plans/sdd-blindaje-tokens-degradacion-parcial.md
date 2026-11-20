# SDD-TOKEN-BUDGET-001 — Blindaje Presupuestal de Tokens, Degradación Parcial Resiliente y Caché de Extracción

- **Identificador**: `SDD-TOKEN-BUDGET-001`
- **Fecha**: 2026-11-20
- **Estado**: PROPUESTO / LISTO PARA IMPLEMENTACIÓN
- **Prioridad**: CRÍTICA (Impacto directo en costo operativo, presupuesto IA y tolerancia a fallos)
- **Autor / Origen**: Diagnóstico Forense RCA Producción (Incidente Job `685ebaf6` — Lote 2,100 Nueva EPS)
- **Nivel de Rigor**: Nivel A (Implementable, Determinista y Verificable)

---

## 0. Resumen Ejecutivo y Triage

### 0.1 Problema y Justificación Económica
Durante el procesamiento del lote masivo `685ebaf6-cc23-404e-92d1-ddf950712929` (2,100 dispensaciones de NUEVA EPS en producción LAN `172.16.0.3`), el pipeline de auditoría completó 2,089 facturas pero dejó **11 auditorías en estado terminal `failed`** (0.52% del lote).

La investigación forense en la memoria de Redis (`audit:{auditId}:state`) demostró que:
1. **24 documentos de esas 11 facturas ya habían sido descargados y extraídos exitosamente por Gemini** (consumiendo entre 12,000 y 18,000 tokens por factura).
2. En 9 de las facturas, **2 de sus 3 documentos** estaban 100% procesados; solo 1 documento falló tras agotar los 3 reintentos.
3. En 2 de las facturas (`D86260800847` y `X30260701000`), **los 3 documentos (100%) estaban extraídos** y lo único que falló fue la comparación semántica final.
4. Debido a una política de fallo de *"Todo o Nada"*, el pipeline abortó las 11 auditorías completas, emitió eventos a `audit.dlq`, **no persistió nada en SQL Server** y dejó huérfana la inversión de **más de 160,000 tokens**.
5. Si estas dispensaciones se reintentan desde cero, el sistema vuelve a descargar y vuelve a pagar a Gemini por los 24 documentos que ya había analizado.

### 0.2 Objetivos de la Especificación
1. **Degradación Parcial Resiliente (Partial Extraction Salvage)**: Si 1 documento de la dispensa agota sus reintentos, el pipeline NO aborta la auditoría. Marca el documento faltante como rechazado por indisponibilidad, evalúa los documentos hermanos válidos y persiste la auditoría en SQL Server como `manual_review` (`EstAud = 0`, `RequiereRevisionHumana = 1`), salvando el 100% de los tokens ya invertidos.
2. **Fallback Semántico No Destructivo**: Si la evaluación semántica agota reintentos por contención de tokens o timeout de Gemini, no mata la auditoría. Marca los campos evaluados como `NO_CONCLUYENTE` y persiste la auditoría en `manual_review`.
3. **Caché Deduplicada de Extracción por Hash SHA-256 (`DocumentExtractionCache`)**: Si un documento con idéntico contenido binario ya fue extraído previamente, se reutiliza su extracción estructurada en Redis con costo 0 en tokens.
4. **Métricas de Presupuesto**: Registrar y exponer en `/metrics/async` los tokens ahorrados por caché y las auditorías rescatadas por degradación parcial.

---

## 1. Perímetro de Impacto (Archivos Afectados)

| Archivo | Tipo de Cambio | Responsabilidad |
| :--- | :---: | :--- |
| `app/Services/Audit/Pipeline/DocumentExtractionCache.php` | **NUEVO** | Servicio de persistencia y lectura de extracciones estructuradas por hash SHA-256 del contenido binario. |
| `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | **MODIFICADO** | Consulta de caché antes de Gemini; salvaguarda de documento no disponible sin abortar auditoría raíz. |
| `app/Services/Audit/Pipeline/SemanticEvaluationWorker.php` | **MODIFICADO** | Fallback a hallazgo `NO_CONCLUYENTE` ante agotamiento de reintentos semánticos (evita `fail_audit`). |
| `app/Services/Audit/Pipeline/AuditTaskRunner.php` | **MODIFICADO** | Manejo de agotamiento de intentos por etapa: no marcar auditoría terminal `failed` si hay documentos rescatables. |
| `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | **MODIFICADO** | Permitir persistir auditorías que contengan documentos con extracción no disponible, marcando `manual_review`. |
| `app/Services/Audit/Pipeline/BatchJobStore.php` | **MODIFICADO** | Telemetría de auditorías rescatadas y tokens ahorrados. |
| `tests/Unit/Services/Audit/Pipeline/DocumentExtractionCacheTest.php` | **NUEVO** | Suite unitaria que certifica el almacenamiento y recuperación por hash SHA-256. |
| `tests/Unit/Services/Audit/Pipeline/PartialDegradationResilienceTest.php` | **NUEVO** | Suite unitaria que certifica la degradación a `manual_review` ante fallos parciales de extracción y semántica. |

---

## 2. Especificación Técnica de los Componentes

### 2.1 Componente 1: Caché Deduplicada de Extracción (`DocumentExtractionCache`)

#### Responsabilidad:
Evitar llamadas repetidas a Gemini cuando un archivo binario ya fue analizado y extraído en el pasado (mismo contenido exacto).

#### Algoritmo:
1. Al recibir un documento binario en `DocumentExtractionWorker`:
   $$\text{docHash} = \text{hash}('sha256', \$binaryContent)$$
2. Consultar la clave Redis:
   $$\text{cacheKey} = \text{"audit:cache:extraction:"} . \text{docHash}$$
3. Si existe:
   - Decodificar el JSON de extracción estructurada.
   - Registrar métrica `tokens_saved_by_cache` con el promedio de tokens del modelo.
   - Publicar directamente el evento `document_extracted` sin invocar a `GeminiGateway`.
4. Si no existe:
   - Invocar a `GeminiGateway::extractDocument()`.
   - Si la extracción es exitosa, guardar en Redis con TTL de 7 días (`604800` segundos):
     $$\text{SETEX } \text{cacheKey } 604800 \text{ json\_encode}(\text{extractionData})$$

---

### 2.2 Componente 2: Degradación Parcial en Extracción (`DocumentExtractionWorker`)

#### Comportamiento Actual (Falla Presupuestal):
Cuando un documento falla repetidamente hasta agotar los reintentos:
```php
// Comportamiento anterior destructivo:
$effects['state_transitions'][] = AuditTaskEffects::transition($task, 'fail_audit', null, $payload);
$event = AuditTaskEffects::followUp($task, AuditEvent::TYPE_AUDIT_FAILED, ...);
```
Esto cancela toda la dispensa, descarta los documentos hermanos ya extraídos y aborta el flujo.

#### Comportamiento Resiliente Propuesto:
Cuando un documento agota reintentos (`attempt >= maxAttempts`):
1. **NO** abortar la auditoría raíz.
2. Marcar el documento en el estado de Redis con:
   ```php
   [
       'extraction_status' => 'UNAVAILABLE',
       'rejection_reason'  => 'DOCUMENT_EXTRACTION_UNAVAILABLE',
       'requires_manual_review' => true,
       'detail' => 'No fue posible extraer automáticamente el documento tras los reintentos permitidos.'
   ]
   ```
3. Emitir evento `document_extracted` con payload degradado (`status = 'UNAVAILABLE'`) para que el contador `docs_extracted` de la auditoría avance.
4. Cuando todos los documentos de la dispensa terminen (ej. 2 extraídos exitosamente y 1 degradado), la auditoría transiciona a `RulesEvaluationWorker`.
5. El motor de reglas evalúa las reglas para los 2 documentos válidos y, al detectar que 1 documento está en `UNAVAILABLE`, dictamina automáticamente:
   - `EstAud = 0` (No aprobada automáticamente).
   - `RequiereRevisionHumana = 1` (Revisión manual).
   - `DetalleError = 'Auditoría completada con extracción parcial: 1 documento requiere inspección visual; 2 documentos auditados exitosamente.'`
6. `AuditPersistenceWorker` persiste el resultado en `dbo.AudDispEst` con todos los hallazgos de los documentos que sí se extrajeron.
7. **Resultado Económico**: Los tokens invertidos en los 2 documentos válidos quedan 100% aprovechados y documentados en la base de datos.

---

### 2.3 Componente 3: Fallback Semántico No Destructivo (`SemanticEvaluationWorker`)

#### Comportamiento Actual:
Si la evaluación semántica de principios activos o diagnósticos falla tras 3 reintentos (por contención, 429 o timeout de Gemini):
```php
// Comportamiento anterior destructivo:
$effects['outbox'][] = AuditTaskEffects::event($task, $event, 'failed');
```
La auditoría se marca como `failed` y se bota a la basura la extracción de los 3 documentos.

#### Comportamiento Resiliente Propuesto:
Ante agotamiento de reintentos en `SemanticEvaluationWorker`:
1. Capturar el fallo semántico sin re-lanzar excepción destructiva.
2. Para cada par de campos sometido a evaluación semántica que no obtuvo respuesta de Gemini:
   ```php
   [
       'resultado' => 'NO_CONCLUYENTE',
       'severidad' => 'media',
       'detalle'   => 'Evaluación semántica no concluyente por indisponibilidad transitoria del servicio de inferencia; requiere verificación por el auditor humano.'
   ]
   ```
3. Publicar el evento `rules_evaluated` con estado final `manual_review`.
4. La auditoría fluye hacia persistencia y queda guardada en SQL Server con `EstAud = 0` y `RequiereRevisionHumana = 1`.
5. **Resultado Económico**: Los 3 documentos extraídos y todos los hallazgos exactos quedan persistidos. Cero tokens desperdiciados.

---

## 3. Matriz de Trazabilidad y Casos de Prueba

| ID Requisito | Escenario de Prueba | Comportamiento Esperado | Verificación |
| :--- | :--- | :--- | :--- |
| **REQ-CACHE-01** | Envío de documento con hash SHA-256 ya existente en Redis. | No invoca a Gemini. Recupera JSON de caché. Tiempo < 5ms. Tokens consumidos = 0. | `DocumentExtractionCacheTest::testReusesCachedExtractionBySha256` |
| **REQ-DEGRAD-01** | Dispensa con 3 documentos donde 1 agota reintentos de extracción. | La auditoría NO falla. Se persisten los 2 documentos válidos. Estado final = `manual_review`. | `PartialDegradationResilienceTest::testPartialExtractionDegradesToManualReview` |
| **REQ-SEM-01** | Dispensa con 3 documentos extraídos donde la llamada semántica agota reintentos. | La auditoría NO falla. Hallazgos semánticos quedan en `NO_CONCLUYENTE`. Estado final = `manual_review`. | `PartialDegradationResilienceTest::testSemanticFailureFallbackToInconclusive` |
| **REQ-PERSIST-01** | Persistencia transaccional de auditoría con degradación parcial en SQL Server. | Inserta registro en `dbo.AudDispEst` con `EstAud = 0`, `RequiereRevisionHumana = 1` y detalle explicativo. | `PartialDegradationResilienceTest::testPersistsPartialAuditToDatabase` |

---

## 4. Plan de Rollback y Mitigación de Riesgos

- **Riesgo 1**: ¿Puede una extracción degradada aprobar erróneamente una factura?
  - **Mitigación**: **IMPOSIBLE**. Cualquier documento no disponible o con fallo semántico asigna `EstAud = 0` y `RequiereRevisionHumana = 1`. Ninguna factura puede aprobarse (`EstAud = 1`) si falta un documento requerido.
- **Riesgo 2**: Crecimiento de memoria en Redis por la caché de extracciones.
  - **Mitigación**: Las claves `audit:cache:extraction:{sha256}` tienen TTL estricto de 7 días (`604800s`) y un tamaño máximo promedio de 2 KB por documento extraído. 100,000 documentos ocupan ~200 MB, muy por debajo de los límites de memoria.
- **Rollback**: Si se requiere desactivar la caché o la degradación parcial, se puede apagar mediante las variables de configuración `.env`:
  - `AUDIT_EXTRACTION_CACHE_ENABLED=false`
  - `AUDIT_PARTIAL_DEGRADATION_ENABLED=false`
