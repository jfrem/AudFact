# Plan: Sincronización de Documentación y Skills (post-refactors)

> Idioma: Español (Latinoamérica)
> Skills aplicadas: `audfact-audit-gemini` → `audfact-project-overview`
> Tipo: **Plan de implementación de cambios documentales** (no de código)

---

## 1. Contexto

Tras estudiar el pipeline event-driven contra el código real y el caso golden `T38250701547` (Positiva, NitSec 2426), se detectó **drift acumulado** en skills y documentación causado por tres refactors recientes (AUDIT-013, AUDIT-014, AUDIT-015) y la limpieza AUDIT-016. La skill `audfact-audit-gemini` referencia archivos que **ya no existen** y describe una regla de negocio que **no está implementada**.

Si no se sincroniza:
- Agentes futuros intentarán abrir 5 binarios `bin/audit-*-worker.php` que se consolidaron en uno solo.
- Las referencias al namespace `app/Services/Audit/Events/` apuntan a una carpeta renombrada hace 1 día.
- La regla "factor de empaque NitSec=2426 ≤ 5 unidades / ACEPTADO_POR_EMPAQUE" se asume implementada cuando **no existe en código** ni en el `audit-config` real de 2426.

Resultado esperado: documentación auto-consistente con el código en `main`, sin promesas falsas, con una nota explícita sobre la regla pendiente.

---

## 2. Drifts confirmados

| Item | Estado documentado | Estado real | Evidencia |
|---|---|---|---|
| `bin/audit-orchestrator-worker.php` …×5 | Listado en skill como 5 archivos | Consolidado en `bin/audit-worker.php <rol>` | `Glob bin/*.php` → 1 archivo; changelog AUDIT-015 |
| `app/Services/Audit/Events/` | Mencionado en `AGENTS.md:440` | Renombrado a `Pipeline/` | `Glob app/Services/Audit/Events/*` → vacío; changelog AUDIT-013 |
| `AuditOrchestrator.php` | Listado en `CATALOG.md:68` y skill overview | No existe | `Glob` → vacío |
| `AuditPromptBuilder.php` | Mencionado en `AGENTS.md:449` | No existe | `Glob` → vacío |
| `DocumentNormalizationWorker.php`, `AuditResultAggregator.php`, `ExtractionCache.php`, `SchemaBuilder.php` | Listados como archivos clave en skill | Fusionados | changelog AUDIT-014 |
| `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md` | Referenciado 2× en skill | Eliminado por cleanup AUDIT-016 | `Glob` → vacío |
| TipoCampo `D` (datos exactos) | Skill regla 2 | El enum mapea código `E` → `EXACT` (default); `D` no existe | `AuditComparisonType.php:23-31` |
| Regla "factor de empaque NitSec=2426 ≤ 5 unidades / ACEPTADO_POR_EMPAQUE" | Skill regla 7 | **No implementada en ningún archivo PHP** | `Grep "empaque\|ACEPTADO_POR_EMPAQUE"` → 0 hits en `app/` |
| Conteo "8 controladores" | Overview skill | 11 controllers reales | `Glob app/Controllers/*.php` |
| Mecanismo `omitirSi` (`fdv_has`, `fdv_missing`, `doc_quality`) | No documentado | Activo y visible en `audit-config` 2426 | `GET /clients/2426/audit-config` |
| Agregación de items en reglas `B` | No documentada | Confirmada (FDV 20+30=50 → hallazgo `valorFuenteVerdad: "50"`) | Caso golden T38250701547 |

---

## 3. Alcance

### Archivos a modificar
1. `.agent/skills/audfact-audit-gemini/SKILL.md` — la skill más afectada.
2. `.agent/skills/audfact-project-overview/SKILL.md` — flujo monolítico obsoleto.
3. `.agent/skills/CATALOG.md` — fila de archivo inexistente y descripciones desactualizadas.
4. `AGENTS.md` — namespace `Events/→Pipeline/` y referencia a `AuditPromptBuilder`.
5. `plans/changelog.md` — registrar la sincronización (DOCS-SYNC).

### Archivos NO tocados (verificados alineados o fuera de alcance)
- `CLAUDE.md` — es enrutador puro, ya alineado.
- Código PHP — no se modifica nada en `app/`, `core/`, `bin/`, `tests/`.
- `tests/Services/Audit/Events/` — la carpeta de tests sigue con nombre viejo (drift menor); se anota como TODO pero no se renombra aquí.

---

## 4. Cambios concretos por archivo

### 4.1 `.agent/skills/audfact-audit-gemini/SKILL.md`

**Eliminar:**
- Bloque TIP (línea 11-12) que apunta a `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md`.
- Filas de "Archivos clave" para `DocumentNormalizationWorker.php`, `AuditResultAggregator.php`, `ExtractionCache.php`, `SchemaBuilder.php` (todas fusionadas en sus consumidores).
- Regla 7 completa: "Factor de empaque: sólo `NitSec = 2426` admite exceso `<= 5` unidades con warning `ACEPTADO_POR_EMPAQUE`." → **eliminar** (no implementada). Renumerar reglas siguientes.
- Sección "Referencias" → línea que apunta al `PLANNING_*.md` eliminado.

**Reemplazar:**
- Tabla "Workers bootstrap" (5 filas) → 1 sola fila:
  ```
  | bin/audit-worker.php <rol> | El rol determina el stream/group: orchestrator | extraction | normalizer | policy | aggregator |
  ```
- Regla 2 ("TipoCampo gobierna el schema"): cambiar `D → Datos exactos` por `E (default) → EXACT`. Documentar el mapeo real desde `AuditComparisonType::fromTipoCampo()`:
  - `E` (cualquier código no-S/B/V) → `EXACT`
  - `S` → `SEMANTIC` (umbral 0.82, fallback `SemanticMatchJudge`)
  - `B` → `BUSINESS` (sumatoria de items + comparación numérica)
  - `V` → `VISUAL` (también puede vivir en `visualChecks[]` aparte, según el config)
- Bloque "Levantar workers localmente": cambiar los 5 comandos por:
  ```bash
  php bin/audit-worker.php orchestrator &
  php bin/audit-worker.php extraction &
  php bin/audit-worker.php normalizer &
  php bin/audit-worker.php policy &
  php bin/audit-worker.php aggregator &
  ```

**Agregar 3 secciones nuevas** (justo antes de "Anti-patterns"):

a) **Mecanismo `omitirSi`** — selectores soportados por `DocumentPolicyEngine`:
- `fdv_has: ["FieldA", ...]` → omitir si la FDV trae esos campos.
- `fdv_missing: ["FieldA", ...]` → omitir si la FDV NO trae esos campos.
- `doc_quality: ["ilegible", ...]` → omitir según calidad declarada por Gemini.
- Ejemplo real (audit-config 2426, FORMULA MEDICA): `CantidadPrescrita.omitirSi = {"fdv_has":["NumeroAutorizacion"]}` — la cantidad prescrita en la fórmula se omite cuando hay número de autorización (manda la AUTORIZACION).

b) **Agregación de items en reglas B**:
- La policy **suma items de la FDV** antes de comparar contra `valorDocumento`. Caso real T38250701547: FDV con 2 items (CantidadEntregada=20 + 30) produce hallazgo `valorFuenteVerdad: "50"`, `valorDocumento: "50"`, `tipo_auditoria: "business"`, `resultado: COINCIDE`.
- Implicación: nunca documentar reglas B como "campo a campo" — son agregadas.

c) **Contrato real de hallazgo**:
```json
{
  "severidad": "alta|media|baja",
  "rol": "AUTORITATIVO|INFORMATIVO",
  "campo": "<nombre>",
  "documento": "DISPENSA|AUTORIZACION|FORMULA MEDICA|...",
  "valorDocumento": "<valor extraído por Gemini>",
  "valorFuenteVerdad": "<valor de la FDV>",
  "resultado": "COINCIDE|VALOR_DISTINTO|NO_ENCONTRADO|OMITIDO|NO_CONCLUYENTE",
  "detalle": "<string|null>",
  "tipo_auditoria": "exact|semantic|business"
}
```
Nota: hallazgos de visual checks omiten `rol` y `tipo_auditoria`.

**Nota técnica adicional** (en sección "Variables de entorno relevantes"):
- En Gemini 3.x los thinking tokens pueden superar 4× los output tokens (caso T38250701547: 5 594 thinking vs 1 177 output). Considerar al ajustar `GEMINI_*_MAX_OUTPUT_TOKENS` y `GEMINI_*_THINKING_BUDGET`.

### 4.2 `.agent/skills/audfact-project-overview/SKILL.md`

**Estructura** (línea 28-46):
- Cambiar "Controllers/ — 8 controladores HTTP (incluye base)" → "Controllers/ — 11 controladores HTTP (incluye base)".
- Verificar conteos de Models (6) y Services tras consolidación; ajustar si difieren.

**Flujo principal — Auditoría IA** (líneas 71-84): reemplazar todo el bloque por el event-driven real:
```
1. POST /audit/single → AuditController::single → publica audit_created en audit.inbox
2. DocumentAuditOrchestrator → resuelve FDV + audit-config + adjuntos → publica N document_registered
3. DocumentExtractionWorker → sha256(file) → cache → Gemini function calling extract_document_data
4. DocumentNormalizer → fechas ISO, upper sin tildes, numéricos canónicos
5. RulesEvaluationWorker → DocumentPolicyEngine + SemanticMatchJudge fallback
6. AuditAggregationWorker → MERGE Discolnet.dbo.AudDispEst + AdjuntosDispensacionDetalle
7. Publica audit_completed | audit_failed | batch_completed[_with_errors]
```

**Patrones de diseño** (línea 97-104): eliminar mención a `ExtractionPromptBuilder` (no existe). El "Builder" actual es el armado dinámico del function declaration en `DocumentAuditOrchestrator`.

### 4.3 `.agent/skills/CATALOG.md`

- Línea 11 (descripción `audfact-audit-gemini`): `"Pipeline con Gemini y servicios (AuditOrchestrator)"` → `"Pipeline event-driven con Gemini y workers (DocumentAuditOrchestrator + 4 workers)"`.
- Línea 36 (triggers): `"AuditOrchestrator"` → `"DocumentAuditOrchestrator, workers, Pipeline event-driven, DLQ"`.
- Línea 68 (mapeo archivo→skill): **eliminar fila** `app/Services/Audit/AuditOrchestrator.php` (archivo no existe).

### 4.4 `AGENTS.md`

- Línea 440: `"app/Services/Audit/Events/DocumentPolicyEngine.php"` y `"app/Services/Audit/Events/AuditAggregationWorker.php"` → reemplazar `Events/` por `Pipeline/` (post AUDIT-013).
- Línea 449: `"Prompts: definidos en `app/Services/Audit/AuditPromptBuilder.php`..."` → `"Schema y prompts: construidos dinámicamente en `DocumentAuditOrchestrator` (function declaration `extract_document_data`, parametrizado por audit-config) y en `DocumentExtractionWorker` (user prompt por documento)"`.

### 4.5 `plans/changelog.md`

Agregar al inicio:
```markdown
## [2026-04-28] — Docs Sync: Pipeline event-driven & TipoCampo

### 📚 Documentation / Skills
- **DOCS-SYNC-002**: Sincronización tras detectar drift acumulado contra refactors AUDIT-013/014/015 y validación con caso golden T38250701547.
  - **Skill `audfact-audit-gemini`**: bootstrap unificado a `bin/audit-worker.php <rol>`, eliminadas referencias a archivos consolidados (DocumentNormalizationWorker, AuditResultAggregator, ExtractionCache, SchemaBuilder), corregido naming TipoCampo (E es default, no D), eliminada regla "factor de empaque NitSec=2426" (no implementada), agregadas secciones para `omitirSi`, agregación B y contrato real de hallazgo, nota sobre thinking tokens Gemini 3, removida referencia a PLANNING_*.md eliminado.
  - **Skill `audfact-project-overview`**: reemplazado flujo monolítico (AuditOrchestrator.auditInvoice + EmbeddingGateway + RuleEngine) por flujo event-driven actual; conteos actualizados.
  - **CATALOG.md**: eliminada fila `AuditOrchestrator.php`; descripciones y triggers de `audfact-audit-gemini` actualizados.
  - **AGENTS.md**: corregido namespace `Events/ → Pipeline/`; reemplazada referencia a `AuditPromptBuilder.php` (eliminado) por construcción dinámica en orchestrator/extractor.
  - **TODO de negocio**: la regla "factor de empaque ≤ 5 unidades para NitSec=2426 con warning ACEPTADO_POR_EMPAQUE" estaba documentada pero no implementada. Si el negocio aún la requiere, debe registrarse como nuevo ticket de implementación (vive en DocumentPolicyEngine y/o como `omitirSi` en audit-config).
```

---

## 5. Criterios de Aceptación

1. ✅ `grep -rni` para los siguientes términos en `.agent/`, `AGENTS.md`, `CLAUDE.md`, `plans/` debe volver vacío:
   - `bin/audit-orchestrator-worker.php`, `bin/audit-extraction-worker.php`, `bin/audit-normalizer-worker.php`, `bin/audit-policy-worker.php`, `bin/audit-aggregator-worker.php`
   - `AuditOrchestrator.php` (sin "Document" delante)
   - `DocumentNormalizationWorker.php`, `AuditResultAggregator.php`, `ExtractionCache.php`, `SchemaBuilder.php` (referenciados como archivos vivos)
   - `app/Services/Audit/Events/`
   - `AuditPromptBuilder.php`
   - `PLANNING_AudFact_AuditPipelineCleanRebuild`
   - `ACEPTADO_POR_EMPAQUE`
2. ✅ La skill `audfact-audit-gemini` documenta correctamente `E/S/B/V → exact/semantic/business/visual` (alineado con `AuditComparisonType::fromTipoCampo()`).
3. ✅ La skill incluye las 3 secciones nuevas (`omitirSi`, agregación B, contrato hallazgo).
4. ✅ El changelog tiene la entrada DOCS-SYNC-002 con la nota TODO sobre factor de empaque.
5. ✅ El "Flujo principal" del overview describe el pipeline event-driven (no el monolítico).

---

## 6. Tareas técnicas (orden de ejecución)

1. Editar `.agent/skills/audfact-audit-gemini/SKILL.md` (mayor volumen).
2. Editar `.agent/skills/audfact-project-overview/SKILL.md`.
3. Editar `.agent/skills/CATALOG.md`.
4. Editar `AGENTS.md`.
5. Editar `plans/changelog.md`.
6. Verificar con grep los términos del CA1.
7. Reportar diff resumido al usuario.

---

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| Editar texto que sí se usa (rompe enlaces internos) | Cada edit es atómico; verifico con grep tras cada archivo |
| Eliminar la regla "factor de empaque" sin avisar al negocio | Se anota como TODO explícito en el changelog |
| Conteos del overview imprecisos por refactors no detectados | Ajusto contra `Glob app/Controllers/*.php`, `app/Models/*.php`, `app/Services/Audit/**/*.php` antes de escribir |
| Marcado de namespace en `AGENTS.md` afecta a otra herramienta | Solo cambia 2 ocurrencias muy localizadas; bajo impacto |

---

## 8. Estimación

- **Complejidad**: Media (5 archivos markdown, sin código).
- **Tests**: N/A (es documentación).
- **Reversibilidad**: alta — un solo commit revertible.
- **Tiempo**: 1 sesión.

---

## 9. Verificación end-to-end

1. Tras los edits, ejecutar:
   ```bash
   grep -rni "Events/DocumentPolicyEngine\|AuditOrchestrator\.php\|AuditPromptBuilder\|ACEPTADO_POR_EMPAQUE\|PLANNING_AudFact_AuditPipelineCleanRebuild\|bin/audit-orchestrator-worker\|bin/audit-extraction-worker\|bin/audit-normalizer-worker\|bin/audit-policy-worker\|bin/audit-aggregator-worker" .agent/ AGENTS.md CLAUDE.md plans/
   ```
   Debe retornar vacío.
2. Releer la skill `audfact-audit-gemini` y confirmar que el pipeline descrito coincide con el flujo real (5 etapas, 1 binario por rol).
3. Revisar diff con el usuario antes de cualquier commit.
4. Si el usuario aprueba, **no commitear** sin instrucción explícita (regla AGENTS.md).

---

## 10. Hallazgos relacionados

- **TODO de negocio**: regla "factor de empaque NitSec=2426 ≤ 5 unidades con warning ACEPTADO_POR_EMPAQUE" — quedará anotada en el changelog como pendiente de implementación si aún se requiere.
- **Drift menor fuera de alcance**: la carpeta `tests/Services/Audit/Events/` no fue renombrada cuando el código prod pasó a `Pipeline/`. Sugerencia: tarea separada para renombrar y ajustar namespaces de los tests (no se hace en este plan).
