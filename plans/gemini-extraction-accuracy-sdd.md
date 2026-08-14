# SDD — Precisión de Extracción IA en Documentos Soporte (OCR Gemini)

> **Fecha**: 2026-08-13 · **Nivel**: `A — Implementable` · **Política**: `clean-rebuild-policy`
> **Alcance**: Pipeline de extracción Gemini (`ExtractionPromptBuilder`, `DocumentExtractionContractBuilder`, tests unitarios)

---

## FASE 0 — Descubrimiento

### Inventario de Información

| Elemento | Evidencia |
| --- | --- |
| **Error `DocumentoPaciente`** en `D64260800214` | Soporte `FORMULA MEDICA` contiene `CC1115860646` (10 dígitos). Gemini extrajo `1115580646` (duplicó `5`, omitió `6`) → hallazgo falso positivo `VALOR_DISTINTO`. ([snapshot](file:///c:/Users/USER/Desktop/AudFact/logs/responseIA/D64260800214_success_20260813_205153966784_eba68bbb.json):384-388) |
| **Error `FechaFormula`** en `D64260800214` | Soporte contiene `Fecha de Atención-01/08/2026` y pie de página `01/08/2026`. Gemini extrajo `01/08/2024` → hallazgo falso positivo `VALOR_DISTINTO`. ([snapshot](file:///c:/Users/USER/Desktop/AudFact/logs/responseIA/D64260800214_success_20260813_205153966784_eba68bbb.json):389-393) |
| **Rechazo erróneo** | Ambas discrepancias provocaron `approved: false` en `FORMULA MEDICA`. |
| **Prompt sin directrices numéricas** | `DEFAULT_SYSTEM_PROMPT` carecía de instrucciones de validación dígito a dígito para IDs ni concordancia temporal de año en fechas. ([ExtractionPromptBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/ExtractionPromptBuilder.php):27-68) |
| **Pares ambiguos incompletos** | Faltaban pares puramente numéricos: `6 ↔ 4 ↔ 0 ↔ 8`, `5 ↔ 8 ↔ 6`. |
| **Descripciones de schema débiles** | `identityDocumentNumberDescription` usaba texto breve sin enfatizar transcripción exacta. ([ContractBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php):498-506) |
| **Validación experimental** | Prueba con prompt mejorado sobre PDF real extrajo `1115860646` y `01/08/2026` al 100%. ([test_gemini.php](file:///C:/Users/USER/.gemini/antigravity-ide/brain/17983897-1957-41e9-b7c2-07c6ca294be7/scratch/test_gemini.php)) |
| **Baseline de tests** | PHPUnit: 471 tests, 1531 aserciones, 1 omitido — todo verde. |

### Supuestos

| ID | Supuesto | Riesgo |
| --- | --- | --- |
| S-001 | Los cambios se limitan a prompts y descripciones sin alterar esquemas estructurales de las funciones Gemini (`extract_fields`, `extract_items`, `detect_visual_checks`, `assess_document_quality`). | Cero riesgo de incompatibilidad con consumidores del evento `document_extracted`. |

> **Información faltante**: Ninguna. Causa raíz confirmada, solución reproducida y verificada contra el archivo original. Especificación determinística e implementable de inmediato.

---

## FASE 1 — Especificación

### 1. Objetivo

| Aspecto | Detalle |
| --- | --- |
| **Problema** | Gemini produce errores esporádicos de OCR en documentos escaneados: confunde dígitos en cédulas (`86` → `58`, duplicación de dígitos adyacentes) y confunde el año en fechas (`2026` → `2024`). |
| **Causa raíz** | `DEFAULT_SYSTEM_PROMPT` carece de: (1) transcripción posicional dígito a dígito para IDs numéricos, (2) concordancia de año contra múltiples marcas temporales del documento, (3) pares ambiguos puramente numéricos. |
| **Impacto** | Falsos positivos severidad ALTA (`VALOR_DISTINTO`) → rechazo erróneo de soportes médicos válidos (`approved: false`). |
| **Resultado esperado** | Eliminación de falsos positivos OCR en números de documento y fechas para todas las modalidades documentales. |

### 2. Alcance

**Incluido:**
- Actualización de `DEFAULT_SYSTEM_PROMPT` con directrices de transcripción numérica, concordancia de fechas y pares ambiguos.
- Ajuste de descripciones fallback en `DocumentExtractionContractBuilder`.
- Creación de suite unitaria `ExtractionPromptBuilderTest.php`.
- Validación de no-regresión con PHPUnit (471+ tests) y `GoldenSetReplayTest`.

**Excluido:**
- Sin cambios en esquemas de BD (`AudDispEst`, `AdjuntosDispensacion`).
- Sin cambios en payload ni estructura del evento `document_extracted`.
- Sin cambios en `DocumentNormalizer.php` ni `DocumentPolicyEngine.php`.

### 3. Non Goals

- No se modificarán los umbrales de coincidencia en `DocumentPolicyEngine` (la comparación exacta de números y fechas sigue siendo estricta).
- No se agregará lógica heurística en PHP para "corregir" cédulas mal extraídas; la solución ataca la raíz en Gemini.

### 4. Estado Actual → Estado Objetivo

**Antes** ([ExtractionPromptBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/ExtractionPromptBuilder.php):27-68): El prompt contiene una lista de pares ambiguos alfanuméricos pero carece de instrucciones específicas para identificadores numéricos y verificación de año en fechas.

**Después**: El prompt incorpora secciones dedicadas para:

1. **Identificadores numéricos** — transcripción posicional dígito por dígito con conteo estricto de longitud.
2. **Fechas** — verificación minuciosa del año contrastando múltiples apariciones en el documento.
3. **Pares numéricos ampliados** — `6 ↔ 4 ↔ 0 ↔ 8`, `5 ↔ 8 ↔ 6`, `8 ↔ B ↔ 5 ↔ 3 ↔ 6`.

```diff
     private const DEFAULT_SYSTEM_PROMPT = <<<TEXT
         Eres un extractor documental determinístico.
         Analiza un único documento.
         No inventes valores.
         Si un dato no es visible o no es legible, omítelo o usa el valor nativo null de JSON (sin comillas).
         Para verificaciones visuales usa presente=false cuando el elemento no sea visible.
         Invoca cada función permitida exactamente una vez en el mismo turno.
         No devuelvas texto libre; responde únicamente con function calls.
 
         Extrae el texto **exactamente** como aparece en la imagen. La precisión es prioritaria sobre la rapidez.
 
-        Antes de responder, realiza una segunda verificación de todos los caracteres visualmente ambiguos, especialmente:
+        Antes de responder, realiza una segunda verificación minuciosa:
+        - Identificadores numéricos (cédulas, números de documento, IDs, autorizaciones): transcribe cada dígito individualmente asegurando la longitud y secuencia exacta. No agregues, omitas, fusiones ni dupliques dígitos por confusión visual (ej. 5 vs 6 vs 8).
+        - Fechas (Fecha de Atención, Fecha de Fórmula, Fecha de Entrega): transcribe el año exacto tal como está impreso (ej. 2026 vs 2024 vs 2025). Compara con otras fechas presentes en el documento (datos de impresión, encabezados, firmas) para confirmar el año.
+        - Caracteres visualmente ambiguos, especialmente:
         * 0 ↔ O ↔ D ↔ Q
         * 1 ↔ I ↔ l ↔ 7 ↔ T
         * 2 ↔ Z
         * 3 ↔ E
         * 4 ↔ A ↔ H
         * 5 ↔ S
-        * 6 ↔ G ↔ C
-        * 8 ↔ B
+        * 6 ↔ G ↔ C ↔ 8 ↔ 4 ↔ 0
+        * 8 ↔ B ↔ 5 ↔ 3 ↔ 6
         * 9 ↔ q ↔ g
         ...
 
         No decidas un carácter únicamente por su apariencia. Verifica su forma, el contexto y el patrón esperado (texto, número o código).
         Nunca sustituyas caracteres para formar palabras más "probables" ni completes información mediante suposiciones. En códigos, matrículas, seriales o identificadores, transcribe únicamente lo que sea visible.
 
         Si un carácter sigue siendo ambiguo después de revisarlo, indícalo usando el formato `[0/O]`, `[5/S]` o similar, en lugar de adivinar.
         Entrega la respuesta solo después de confirmar que cada carácter ambiguo ha sido revisado individualmente.
     TEXT;
```

### 5. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| `AD-01` | Reforzar prompt y descripciones de schema. | Fuzzy matching en cédulas (`DocumentPolicyEngine`), corrección Levenshtein en PHP. | Modificar la engine violaría la auditoría determinista. La solución debe asegurar que Gemini extraiga la verdad visible. |
| `AD-02` | Esquemas JSON de funciones inalterados. | Cambiar nombres/tipos en contrato JSON. | Preserva compatibilidad total con `DocumentNormalizer`, `DocumentPolicyEngine` y eventos Redis. |

### 6. Dependencias

| Dependencia | Tipo | Versión |
| --- | --- | --- |
| Google Gemini API | API Externa | v1beta (`gemini-3.5-flash` / `gemini-3.6-flash`) |
| PHPUnit | Testing | 10.5+ |

### 7. Invariantes

| ID | Invariante | Enforcement |
| --- | --- | --- |
| INV-1 | IA solo extrae; PHP decide | `DocumentExtractionWorkerTest` |
| INV-2 | Cero valores esperados de FDV en prompt | `ExtractionPromptBuilderTest` |
| INV-3 | Hashes de prompt y contrato deterministas | `DocumentExtractionWorkerTest` (`hashPayload()`) |

### 8. Contrato de Salida (Inalterado)

```json
{
  "fields": {
    "DocumentoPaciente": {
      "valor": "1115860646",
      "presente": true,
      "estadoExtraccion": "FOUND"
    },
    "FechaFormula": {
      "valor": "01/08/2026",
      "presente": true,
      "estadoExtraccion": "FOUND"
    }
  }
}
```

### 9. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| REQ-01 | Desambiguación de números de cédula e IDs | `ExtractionPromptBuilder` + `ContractBuilder` | Replay sobre `D64260800214` extrae `1115860646` |
| REQ-02 | Verificación de año exacto en fechas | `ExtractionPromptBuilder` | Replay sobre `D64260800214` extrae `01/08/2026` |
| REQ-03 | Suite unitaria para `ExtractionPromptBuilder` | `ExtractionPromptBuilderTest.php` | `php vendor/bin/phpunit tests/.../ExtractionPromptBuilderTest.php` |

### 10. Análisis de Impacto

| Componente | Consumidor | Impacto | Cambio |
| --- | --- | --- | --- |
| `ExtractionPromptBuilder` | `DocumentExtractionWorker` | Positivo | Actualizar `DEFAULT_SYSTEM_PROMPT` |
| `DocumentExtractionContractBuilder` | `DocumentExtractionWorker` | Positivo | Ajustar descripciones fallback |
| `ExtractionPromptBuilderTest` | PHPUnit | Nuevo | Crear suite unitaria |

### 11. Cambios por Archivo

#### [MODIFY] [ExtractionPromptBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/ExtractionPromptBuilder.php)
- **Líneas**: 27–68
- Expandir `DEFAULT_SYSTEM_PROMPT` con directrices de identificadores numéricos, concordancia de fechas y pares numéricos ampliados (ver diff en §4).

#### [MODIFY] [DocumentExtractionContractBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php)
- **Líneas**: 480–515
- Ajustar `identityDocumentNumberDescription` y `fieldValueDescription` para enfatizar transcripción posicional dígito a dígito.

#### [NEW] [ExtractionPromptBuilderTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php)
- `testDefaultSystemPromptContainsNumericAndDateDisambiguationRules()`
- `testBuildSystemPromptPreservesCustomPromptWithDeduplication()`
- `testBuildUserPromptIncludesAllFieldGroupsAndVisualChecks()`

### 12. Plan de Migración

| Paso | Acción |
| --- | --- |
| **Pre** | Suite PHPUnit pasa (471 tests). |
| 1 | Modificar `ExtractionPromptBuilder.php`. |
| 2 | Modificar `DocumentExtractionContractBuilder.php`. |
| 3 | Crear `ExtractionPromptBuilderTest.php`. |
| 4 | Ejecutar `php vendor/bin/phpunit` (472+ tests, todo verde). |
| 5 | Ejecutar `GoldenSetReplayTest` (`--no-coverage`). |
| **Rollback** | `git checkout -- app/Services/Audit/Pipeline/ExtractionPromptBuilder.php app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` + eliminar test. |

### 13. Casos Límite

| Condición | Comportamiento Esperado |
| --- | --- |
| Documento rotado 180° | Gemini orienta y extrae correctamente sin alterar dígitos. Validado vía `assess_document_quality`. |
| Fecha en múltiples formatos (`01/08/2026` vs `01-AGO-2026`) | Gemini extrae la visible; `DocumentNormalizer` canoniza a `2026-08-01`. |
| Cédula con prefijo pegado (`CC1115860646`) | `extract_fields` devuelve solo dígitos `1115860646`. |

### 14. Testing

**Nuevos tests:**
- `testDefaultSystemPromptContainsNumericAndDateDisambiguationRules()`
- `testBuildSystemPromptPreservesCustomPromptWithDeduplication()`
- `testBuildUserPromptIncludesAllFieldGroupsAndVisualChecks()`

**Verificación manual:** Replay de extracción sobre `D64260800214`.

### 15. Riesgos

| Riesgo | Severidad | Mitigación |
| --- | --- | --- |
| Incremento en tokens de prompt (~50 tokens) | Baja | Costo insignificante, latencia idéntica. |

### 16. Criterios de Aceptación

1. `buildSystemPrompt` incluye directrices de validación de identificadores numéricos y concordancia de fechas.
2. `php vendor/bin/phpunit` ejecuta 100% verde sin regresiones.
3. Al procesar `D64260800214`, `DocumentoPaciente` extrae `1115860646` y `FechaFormula` extrae `01/08/2026` (normalizada `2026-08-01`).
4. Ambos campos evalúan `COINCIDE` y la `FORMULA MEDICA` se aprueba (`approved: true`).

---

## FASE 2 — Auditoría de Consistencia

| Verificación | ✓ |
| --- | --- |
| Todas las tablas definidas | ✅ Sin cambios en tablas. |
| Todas las columnas existen | ✅ Sin cambios en columnas. |
| Contratos documentados | ✅ §8. |
| Requisitos trazables | ✅ §9. |
| Consumidores analizados | ✅ `DocumentExtractionWorker`. |
| Migraciones con rollback | ✅ §12. |
| Referencias definidas | ✅ Paths exactos en §11. |
| Compatibilidad evidenciada | ✅ Esquemas JSON inalterados. |
| Criterios verificables | ✅ §16. |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | ✓ |
| --- | --- |
| ¿Decisión arquitectónica implícita? | No |
| ¿Contrato sin documentar? | No |
| ¿Consumidor no analizado? | No |
| ¿Migración sin rollback? | No |
| ¿Dato persistido sin migración? | No |
| ¿Afirmación sin evidencia? | No |
| ¿Referencias huérfanas? | No |
| ¿Dos implementadores producirían soluciones diferentes? | No |

---

## FASE 4 — Resultado Final

**Nivel de Completitud: `A — Implementable`**

La especificación identifica con precisión los archivos, fragmentos exactos antes/después, tests unitarios y verificación empírica contra `D64260800214`, cumpliendo todos los criterios de la política SDD.
