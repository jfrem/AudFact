# SDD — Corrección Bug: N:1 Semántico y Purga de `batch_completed` Zombie

**ID del Spec:** SDD-SEMANTIC-MATCH-001  
**Fecha:** 2026-11-08  
**Autor:** Antigravity (IA)  
**Estado:** ✅ Listo para implementación  
**Tipo:** Bug — Corrección  
**Riesgo:** Medio  
**Versión objetivo:** `v2.x` (parche incremental sobre rama `main`)

---

## 0. Clasificación y Perímetro

### 0.1 Triage

| Dimensión                       | Valor                                   |
|---------------------------------|-----------------------------------------|
| Tipo                            | Bug — 2 defectos en 1 spec              |
| Riesgo                          | Medio (afecta pipeline IA en producción)|
| Persistencia afectada           | No (sin cambios de esquema SQL/Redis)   |
| Contrato externo afectado       | No                                      |
| Cambio arquitectónico           | No (refactorización incremental)        |
| Producción afectada             | Sí — causa entradas en DLQ             |
| Requiere migración de datos     | No                                      |

### 0.2 Archivos Involucrados

| # | Archivo | Tipo | Rol |
|---|---------|------|-----|
| A | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | Producción | Eliminar exclusiones 1:1 en fases 1.5, 2 y `rankUnmatchedCandidates()` |
| B | `app/Services/Audit/AuditBatchOrchestrator.php` | Producción | Eliminar llamada zombie a `publisher->publish()` en `publishEmptyBatch()` |
| C | `app/Services/Audit/Pipeline/AuditEvent.php` | Producción | Eliminar constantes `TYPE_BATCH_COMPLETED` y `TYPE_BATCH_COMPLETED_ERR` |
| D | `tests/Services/Audit/AuditBatchOrchestratorTest.php` | Test | Corregir aserciones que esperaban el evento eliminado |
| E | `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | Test | Agregar caso N:1 (2 FDV → 1 línea doc) que previamente explotaba |

### 0.3 Regresiones Conocidas

| Test afectado | Cambio necesario |
|---------------|-----------------|
| `AuditBatchOrchestratorTest::testEnqueueBatchPublishesCompletedEventWhenNoCandidates()` | `assertCount(1,...)` → `assertCount(0,...)` y eliminar aserción de `eventType` |

---

## 1. Contexto y Motivación

### 1.1 Síntoma en Producción

Desde el despliegue de SDD-OPS-025 (que retiró los streams `audit.results.*`) se acumularon miles de entradas en `audit.dlq` con dos patrones de error:

1. **`semantic_result_missing`** — `DomainException` lanzada por `ResolvedSemanticResolver::evaluate()` cuando intenta resolver un par `(fdvIdx, docIdx)` que nunca fue enviado a Gemini.
2. **`event_type sin stream asignado: batch_completed`** — `InvalidArgumentException` en `AuditEventPublisher::streamForEventType()` porque la constante `TYPE_BATCH_COMPLETED` ya no tiene mapping de stream.

### 1.2 Impacto

- Lotes con múltiples artículos FDV que comparten una sola línea de documento terminan en DLQ, nunca se persiste el resultado de auditoría.
- Lotes vacíos (sin candidatos) igualmente terminan en DLQ por el evento zombie, impidiendo el cierre limpio del job.
- Redis acumuló 3 400+ entradas DLQ, causando presión de memoria y bloqueos de procesamiento.

---

## 2. Análisis de Causa Raíz

### 2.1 Bug A — `semantic_result_missing` (N:1 roto)

#### Contrato del docstring (líneas 763–773, `DocumentPolicyEngine.php`)

```
* Estrategia de emparejamiento N:1 (surjective):
*   - Múltiples artículos FDV pueden emparejarse con la misma línea de documento.
*   - Una línea de documento puede servir de respaldo para múltiples FDV.
```

#### Implementación actual (violación del contrato)

**Fase 1.5** (línea 815):
```php
if (in_array($di, $matchedFdv, true)) {
    continue;  // ← excluye docIdx ya asignado → fuerza semántica 1:1
}
```

**Fase 2** (línea 831):
```php
if (in_array($di, $matchedFdv, true)) {
    continue;  // ← mismo problema
}
```

**`rankUnmatchedCandidates()`** (línea 1045):
```php
if (in_array($di, $matchedFdv, true)) {
    continue;  // ← el candidato semantic nunca llega al LLM
}
```

#### Secuencia de fallo (2 FDV, 1 doc)

```
plan():
  SemanticCandidateCollector → pares: [(fdv0,doc0), (fdv1,doc0)]
  Gemini: ambos son MATCH → SemanticEvaluationStore marca ambas filas semantic_match
  plan.state = ready ✓

evaluateResolved():
  evaluateArticleSetField():
    fdv0 → matchedFdv[0] = doc0  ← asignado en Fase 1/1.5/2
    fdv1 → rankUnmatchedCandidates() excluye doc0 → retorna []
         → picks doc1 (nunca evaluado por Gemini)
    ResolvedSemanticResolver::evaluate(fdv1, doc1) → DomainException: semantic_result_missing
```

### 2.2 Bug B — `batch_completed` zombie

`publishEmptyBatch()` fue diseñada para cerrar el job **y** publicar un evento de notificación. SDD-OPS-025 eliminó los streams que consumían ese evento (y su mapping en `streamForEventType()`), pero no eliminó la llamada en `publishEmptyBatch()`.

```
publishEmptyBatch():
  patchJob(...completed...) ← job correctamente cerrado aquí
  publisher->publish(TYPE_BATCH_COMPLETED) ← streamForEventType() lanza InvalidArgumentException
```

No existe ningún consumidor de `batch_completed` ni `batch_completed_with_errors` en todo el repositorio (verificado con `git grep`). El job ya queda en estado `completed` antes de la llamada fallida.

---

## 3. Decisión de Arquitectura

### 3.1 Clean Rebuild Policy

- **Veredicto:** Refactorización incremental (no rebuild).
- **Justificativa:** Ambos cambios son cirugía mínima sobre contratos existentes. No hay cambios de interfaz pública, esquemas, ni flujo de datos entre componentes.
- **Riesgo residual:** Bajo. El único efecto observado será que múltiples FDV ahora pueden compartir un mismo `docIdx` en el resultado — comportamiento documentado en el contrato original y correcto desde el punto de vista del negocio.

### 3.2 Semántica N:1 — Decisión de Negocio Confirmada

El usuario confirmó que la semántica N:1 (múltiples artículos FDV → mismo renglón documental) es el comportamiento correcto y esperado. Una fórmula médica puede listar varios artículos FDV que están todos respaldados por la misma línea del documento adjunto. Eliminar la restricción 1:1 es la corrección, no una regresión.

---

## 4. Especificación de Cambios

### 4.1 Archivo A — `DocumentPolicyEngine.php`

#### Cambio 1: Eliminar exclusión 1:1 en Fase 1.5 (línea ~815)

**ANTES:**
```php
foreach ($fdvArticles as $fi => $fdvOriginal) {
    if (isset($matchedFdv[$fi])) {
        continue;
    }
    foreach ($docArticles as $di => $docOriginal) {
        if (in_array($di, $matchedFdv, true)) {
            continue;
        }
        if (TextNormalization::tokenSortedJaccard($fdvOriginal, $docOriginal) >= 0.5) {
```

**DESPUÉS:**
```php
foreach ($fdvArticles as $fi => $fdvOriginal) {
    if (isset($matchedFdv[$fi])) {
        continue;
    }
    foreach ($docArticles as $di => $docOriginal) {
        if (TextNormalization::tokenSortedJaccard($fdvOriginal, $docOriginal) >= 0.5) {
```

#### Cambio 2: Eliminar exclusión 1:1 en Fase 2 (línea ~831)

**ANTES:**
```php
foreach ($docNorm as $di => $dNorm) {
    if (in_array($di, $matchedFdv, true)) {
        continue;
    }
    if (TextNormalization::similarity($fNorm, $dNorm) >= $threshold) {
```

**DESPUÉS:**
```php
foreach ($docNorm as $di => $dNorm) {
    if (TextNormalization::similarity($fNorm, $dNorm) >= $threshold) {
```

#### Cambio 3: Eliminar exclusión 1:1 en `rankUnmatchedCandidates()` (línea ~1045)

**ANTES:**
```php
private function rankUnmatchedCandidates(string $fdvOriginal, array $docArticles, array $matchedFdv): array
{
    $candidates = [];
    foreach ($docArticles as $di => $docOriginal) {
        if (in_array($di, $matchedFdv, true)) {
            continue;
        }
        $candidates[$di] = TextNormalization::tokenSortedJaccard($fdvOriginal, $docOriginal);
    }
    arsort($candidates);
    return $candidates;
}
```

**DESPUÉS:**
```php
private function rankUnmatchedCandidates(string $fdvOriginal, array $docArticles, array $matchedFdv): array
{
    $candidates = [];
    foreach ($docArticles as $di => $docOriginal) {
        $candidates[$di] = TextNormalization::tokenSortedJaccard($fdvOriginal, $docOriginal);
    }
    arsort($candidates);
    return $candidates;
}
```

> **Nota:** El parámetro `$matchedFdv` en `rankUnmatchedCandidates()` queda inutilizado tras el cambio. Se puede eliminar tanto el parámetro como el argumento en la llamada (línea ~854). Sin embargo, para minimizar el diff y el riesgo de regresión, se conserva el parámetro por ahora y se marca como `@todo` en el docblock.  
> **Decisión:** Eliminar el parámetro en este mismo PR para mantener el código limpio (no hay tests ni llamadas externas que dependan de la firma).

#### Cambio 3b (refinamiento): Eliminar el parámetro `$matchedFdv` de `rankUnmatchedCandidates()`

**ANTES (firma y llamada):**
```php
// Definición:
private function rankUnmatchedCandidates(string $fdvOriginal, array $docArticles, array $matchedFdv): array

// Llamada (línea ~854):
$candidates = $this->rankUnmatchedCandidates($fdvOriginal, $docArticles, $matchedFdv);
```

**DESPUÉS:**
```php
// Definición:
private function rankUnmatchedCandidates(string $fdvOriginal, array $docArticles): array

// Llamada:
$candidates = $this->rankUnmatchedCandidates($fdvOriginal, $docArticles);
```

---

### 4.2 Archivo B — `AuditBatchOrchestrator.php`

#### Cambio: Eliminar publicación zombie en `publishEmptyBatch()`

**ANTES (líneas 591–604):**
```php
$this->publisher->publish(AuditEvent::create(
    eventType: AuditEvent::TYPE_BATCH_COMPLETED,
    auditId: null,
    jobId: $jobId,
    payload: [
        'status' => BatchJobStore::JOB_STATUS_COMPLETED,
        'total' => 0,
        'done' => 0,
        'failed' => 0,
        'accepted' => 0,
        'skipped_locked' => $skippedLocked,
        'skipped_existing' => $skippedExisting,
    ],
));
```

**DESPUÉS:** _(bloque eliminado — sin reemplazo)_

---

### 4.3 Archivo C — `AuditEvent.php`

#### Cambio: Eliminar constantes huérfanas

**ANTES (líneas 22–23):**
```php
public const TYPE_BATCH_COMPLETED      = 'batch_completed';
public const TYPE_BATCH_COMPLETED_ERR  = 'batch_completed_with_errors';
```

**DESPUÉS:** _(ambas líneas eliminadas)_

> **Verificación de seguridad:** `git grep -rn "TYPE_BATCH_COMPLETED\|batch_completed_with_errors" -- app/ tests/` confirma que solo `AuditBatchOrchestrator.php` y `AuditBatchOrchestratorTest.php` las referencian. Ambos se modifican en este spec.

---

### 4.4 Archivo D — `AuditBatchOrchestratorTest.php`

#### Cambio: Corregir test `testEnqueueBatchPublishesCompletedEventWhenNoCandidates()`

**ANTES (líneas 40–41):**
```php
$this->assertCount(1, $publisher->published);
$this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED, $publisher->published[0]->eventType);
```

**DESPUÉS:**
```php
$this->assertCount(0, $publisher->published);
```

> El test ahora verifica que el job vacío se cierra **sin** publicar ningún evento — comportamiento correcto desde que se retiró el stream consumidor.

---

### 4.5 Archivo E — `DocumentPolicyEngineTest.php`

#### Nuevo test: Escenario N:1 — 2 FDV → 1 línea documental

```php
/**
 * Reproduce Bug #semantic_result_missing:
 * Dos artículos FDV apuntan a la misma línea documental.
 * Ambos son validados por el LLM como MATCH.
 * Se espera que evaluateResolved() complete sin excepción.
 */
public function testEvaluateResolvedAllowsMultipleFdvToShareOneDocLine(): void
{
    // Arrange: 2 FDV, 1 artículo doc
    $fdvArticles = ['METFORMINA 850 MG', 'METFORMINA 850 MG TAB'];
    $docArticles = ['METFORMINA 850 MG TABLETA'];

    // SemanticEvaluationStore pre-cargado con ambos pares como semantic_match
    // (fdv0 → doc0) y (fdv1 → doc0)
    $store = $this->buildStoreWithMatches([
        [0, 0, 'semantic_match'],
        [1, 0, 'semantic_match'],
    ]);

    // ... construcción de DocumentPolicyEngine con store inyectado

    // Act
    $result = $engine->evaluateResolved($context);

    // Assert: sin excepción, resultado es concordante para ambos FDV
    $this->assertSame('concordante', $result['resultado'] ?? $result['status'] ?? 'concordante');
}
```

> **Nota de implementación:** El test exacto se adaptará a los fixtures y helpers existentes en `DocumentPolicyEngineTest.php`. El esquema anterior es el contrato mínimo; la implementación concreta usará las clases builder/stub ya presentes en el archivo de tests.

---

## 5. Estrategia de Testing

### 5.1 Línea base pre-implementación (ya confirmada ✅)

```
Tests: 1022, Assertions: 3590, Skipped: 78
Exit code: 0
```

### 5.2 Tests modificados

| Test | Cambio | Resultado esperado |
|------|--------|--------------------|
| `testEnqueueBatchPublishesCompletedEventWhenNoCandidates` | `assertCount(0,...)`, eliminar aserción `eventType` | PASS |

### 5.3 Tests nuevos

| Test | Archivo | Cobertura |
|------|---------|-----------|
| `testEvaluateResolvedAllowsMultipleFdvToShareOneDocLine` | `DocumentPolicyEngineTest.php` | Escenario N:1 que antes causaba `DomainException` |

### 5.4 Meta post-implementación

```
Tests: ≥ 1023, Assertions: ≥ 3590, Skipped: ≤ 78
Exit code: 0
```

---

## 6. Plan de Despliegue

### 6.1 Orden de implementación

```
1. Implementar cambios en DocumentPolicyEngine.php (Archivo A)
2. Implementar cambios en AuditBatchOrchestrator.php (Archivo B)
3. Eliminar constantes en AuditEvent.php (Archivo C)
4. Corregir test en AuditBatchOrchestratorTest.php (Archivo D)
5. Agregar test N:1 en DocumentPolicyEngineTest.php (Archivo E)
6. Ejecutar PHPUnit → confirmar verde
7. Push a main → CI → deploy automático
8. Verificar DLQ en producción = 0 entradas nuevas de los tipos corregidos
```

### 6.2 Rollback

No hay cambios de esquema. Si el deploy falla, el rollback es `git revert` del commit + re-deploy. Los eventos en DLQ producidos antes de la corrección son irrelevantes (ya purgados manualmente).

---

## 7. Documentación a Actualizar

| Archivo | Sección | Cambio |
|---------|---------|--------|
| `plans/changelog.md` | — | Entrada para esta corrección |
| `plans/data-flows.md` | Líneas ~237–238 | Eliminar referencia a `batch_completed` en `audit.results` |
| `opt/DOC-05-SPEC-PIPELINE-STATE-MACHINE.md` | Líneas ~94–95 | Eliminar `batch_completed` como Consumer terminal |

---

## 8. Criterios de Aceptación

- [ ] PHPUnit ≥ 1023 tests, exit code 0, sin regresiones.
- [ ] `git grep "TYPE_BATCH_COMPLETED"` no devuelve resultados en `app/` ni `tests/`.
- [ ] `git grep "batch_completed_with_errors"` no devuelve resultados en `app/` ni `tests/`.
- [ ] DLQ en producción: 0 nuevas entradas de tipo `semantic_result_missing` tras el primer lote post-deploy.
- [ ] DLQ en producción: 0 nuevas entradas de tipo `event_type sin stream asignado: batch_completed` tras el primer lote post-deploy.
- [ ] `plans/changelog.md` actualizado.

