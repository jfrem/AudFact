# SDD — Optimización de Homologación Semántica, Priorización de Candidatos y Evidencia Documental

- **ID**: `SDD-OPTIM-SEMANTIC-EVIDENCE-2026-11`
- **Fecha**: 2026-11-23
- **Estado**: APROBADO (Ejecución Directa)
- **Alcance**: `TextNormalization.php`, `FieldValueResolver.php`, `DocumentPolicyEngine.php`, `SemanticBatchContract.php`, `SemanticEvaluationWorker.php`, `ExtractionPromptBuilder.php`

---

## 1. Problema y Diagnóstico

A partir del análisis de las 4 dispensas reales en producción (`D03261002256`, `D63261106501`, `U77261101396`, `V14261105835`), se detectaron 6 inconsistencias funcionales y de rendimiento:

1. **Pérdida de razonamiento clínico en `DocumentPolicyEngine::evaluateArticleSetField()`**:
   Cuando `unmatched_articles` contiene ítems, `mergeDocumentArticleNames()` incrementa el set documental a $\ge 2$, evaluando por `evaluateArticleSetField()`. Esta función descartaba el `$judgeResult['reasoning']` y emitía un mensaje estático engañoso: *"En 'Nombre Articulo', el documento soporte no contiene evidencia para X de Y artículos..."*.
2. **Priorización invertida de candidatos en `rankUnmatchedCandidates()`**:
   `unmatched_articles` (artículos no dispensados en la entrega) y candidatos ya asignados en `$matchedFdv` tenían igual o mayor prioridad que los `items` primarios no asignados de la prescripción, provocando hasta 3 olas HTTP secuenciales a Gemini.
3. **Fugas de Capa 1 (Léxica) a Fase 3 (IA) en `TextNormalization::normalizeArticleName()`**:
   La falta de separación de `<número><unidad>` (`600MG` vs `600 MG`, `40MG` vs `40 MG`) y la falta de homologación de abreviaturas farmacéuticas (`TAB`/`TABS`/`TABLETAS` $\to$ `TABLETA`, `CAP`/`CAPS`/`CAPSULAS` $\to$ `CAPSULA`) degradaban el índice Jaccard por debajo de 0.50 en medicamentos exactamente iguales.
4. **Truncamiento masivo en `extraction_meta.valores` (`FieldValueResolver::extractPresentItemValues()`)**:
   Solo tomaba los valores de la fila 0 (`if ($evidenceMeta === []) { $evidenceMeta = $cell->extractMeta(); }`), perdiendo los ítems restantes en `NombreArticulo`, `CodigoProducto`, `CantidadEntregada` y `CantidadPrescrita`.
5. **Inversión direccional en `SemanticBatchContract` y metadatos incompletos en `SemanticEvaluationWorker`**:
   No definía explícitamente los roles (`expected` = FDV dispensado, `actual` = documento soporte) y `SemanticEvaluationWorker` no transfería `dis_det_nro`, `document_type`, `field`, etc. a `evaluateBatch()`.
6. **Cruce Paciente vs Médico en prescripciones tabulares (`ExtractionPromptBuilder.php`)**:
   En formatos tabulares donde las etiquetas colindan verticalmente, faltaba el anclaje de alineación horizontal y verificación con el recuadro de firma.

---

## 2. Invariantes de Calidad y No Regresión

- **INV-1 (Regla D-11 intacta)**: Los artículos de `unmatched_articles` NUNCA deben ingresar a Capa 1 (Fase 1.5/2). Solo son evaluables en Fase 3 (LLM) como último recurso (Tier 3).
- **INV-2 (Criterio Clínico Estricto - Opción B)**: Discrepancias de forma farmacéutica (`CAPSULA` vs `TABLETA`) deben mantener veredicto `NO_CONCLUYENTE` $\to$ `manual_review` con exposición clara del motivo clínico en `detalle`.
- **INV-3 (Prioridad Estricta de 3 Niveles)**:
  - Nivel 1: `items` primarios extraídos no asignados.
  - Nivel 2: `items` primarios extraídos ya asignados en `$matchedFdv` por las Fases 1, 1.5 y 2, es decir, antes de iniciar la Fase 3 (cobertura suryectiva N:1 para multi-lote). Las coincidencias de la propia Fase 3 no reordenan los candidatos de otras filas (SDD-SEMANTIC-RESOLVE-001).
  - Nivel 3: `unmatched_articles` residuales.
- **INV-4 (Completitud de Evidencia)**: `extraction_meta.valores` debe reflejar el 100% de los valores extraídos de las filas del documento.
- **INV-5 (Latencia y Costo Óptimo)**: Si un medicamento es léxicamente idéntico tras normalización estándar, debe resolverse en Capa 1 (`0 ms`, 0 tokens).
