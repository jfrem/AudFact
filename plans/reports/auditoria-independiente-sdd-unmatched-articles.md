# Informe Técnico de Auditoría Arquitectónica Independiente

- **Documento auditado**: `plans/sdd-captura-articulos-no-coincidentes.md`
- **ID de la Especificación**: `SDD-UNMATCHED-ARTICLES`
- **Herramienta / Agente**: `agy CLI 1.5.7` (Revisor Técnico Independiente)
- **Fecha de auditoría**: 2026-11-13
- **Estado**: APROBADO

---

## 1. Resumen de la Evaluación

### 1.1 Completitud formal de la spec (Directrices SDD y Trazabilidad)
- **Triage y Clasificación**: La spec clasifica adecuadamente el cambio como de **Riesgo Alto** (modificación de contrato de Structured Output de Gemini y evento Redis Streams `document_normalized`), aplicando el perfil completo con observabilidad y rollout obligatorios.
- **Estructura Fases 0 a 4**: Cumple estrictamente con la anatomía de Specification Driven Development (SDD):
  - **Fase 0 (Descubrimiento Empírico)**: Perímetro de impacto cerrado (11 archivos auditados), grafo de dependencias acopladas, análisis de impacto inverso, semántica de herramientas, matriz de entornos y tabla de supuestos (S2-01 a S2-04) con mitigaciones y condiciones de cierre operativas.
  - **Fase 1 (Especificación)**: Análisis de causa raíz, estados actual y objetivo, 11 decisiones arquitectónicas explícitas (D-01 a D-11) con alternativas rechazadas, invariantes (8.1 a 8.6), contratos formales (10.a a 10.c), matriz de trazabilidad (R-01 a R-06), detalle de cambios antes/después por archivo, gestión de casos límite y plan de pruebas.
  - **Fases 2, 3 y 4**: Auditorías de consistencia, arquitectónica y adversarial anti-regresión concluidas sin inconsistencias abiertas ni suposiciones huérfanas.
- **Trazabilidad**: Cobertura bidireccional completa desde los requisitos R-01..R-06 hasta los contratos, implementación y casos de prueba.

### 1.2 Robustez de la solución al caso disparador D66261005571 (FLUOXETINA vs QUETIAPINA)
- **Diagnóstico de causa raíz**: Certero. La instrucción de filtrado en `ExtractionPromptBuilder::buildItemCandidatesContext()` provocaba que Gemini retornara `items: []`, llevando al motor de políticas a emitir erróneamente un falso `NO_ENCONTRADO`.
- **Eficacia de la solución**: La inclusión de la sección `unmatched_articles` (limitada a nombres) permite que Gemini capture `FLUOXETINA 20MG CÁPSULAS` sin incurrir en el sobrecoste de extraer fichas completas.
- **Resolución y Detalle**: La integración en `FieldValueResolver::mergeDocumentArticleNames` y la normalización como `AuditFieldValueType::ARTICLE_NAME` garantizan que el valor ingrese a la cascada de auditoría. Al no existir equivalencia terapéutica entre Fluoxetina y Quetiapina, `SemanticMatchJudge` dictamina no-coincidencia y el motor emite `NO_CONCLUYENTE`, enriqueciendo el detalle persistido con los artículos visibles del documento (`Artículos visibles en el documento: 'FLUOXETINA 20MG CÁPSULAS'`). Se elimina la ceguera de auditoría del caso disparador.

### 1.3 Blindaje Anti-Falso Positivo D-11 en DocumentPolicyEngine
- **Evaluación crítica**: La regla D-11 es el componente más crítico del diseño desde la perspectiva de seguridad clínica y precisión de auditoría.
- **Riesgo neutralizado**: Sin D-11, un medicamento residual con distinta molécula pero idéntica concentración y forma farmacológica (ej. `FLUOXETINA 100MG TABLETA` vs `QUETIAPINA 100MG TABLETA`) compartiría la mayoría de tokens sintácticos, superando el umbral de Jaccard en Fase 1.5 (`tokenSortedJaccard >= 0.5`) o similitud léxica en Fase 2, arrojando un falso `COINCIDE` catastrófico sin consultar al LLM.
- **Mecanismo de blindaje**: La spec prohíbe taxativamente que los nombres derivados de `unmatched_articles` se beneficien de atajos difusos (Jaccard fase 1.5 y similitud fase 2). Exige coincidencia exacta de tokens (`sameTokenSet`) o arbitraje clínico obligatorio mediante `SemanticMatchJudge` (Fase 3). Este blindaje está sólidamente especificado en las secciones 5, 6 (D-11), 13 (`DocumentPolicyEngine.php`) y verificado por la prueba T-15.

### 1.4 Colector Semántico v2 (Supuesto S2-04 y Control de Pares Cruzados)
- **Evaluación de concurrencia y costos**: El supuesto S2-04 modela con exactitud el comportamiento del pipeline durable v2 (`SemanticCandidateCollector`).
- **Control de explosión combinatoria**: El producto cartesiano entre ítems FDV no emparejados ($M$) y artículos residuales ($N$) queda acotado por un doble cerco:
  1. Cota superior estructural: `UNMATCHED_ARTICLES_MAX = 10`.
  2. Tamaño máximo de lote por chunk: `MAX_PAIRS = 16` (`AuditExecutionConfig.php:15`).
- **Amortización**: El impacto en costos y tiempos de ejecución se atenúa mediante la persistencia en caché semántica Redis (TTL de 30 días) y queda monitoreado por la señal de observabilidad 19-S4 (`semantic_calls` con umbral de incremento $\le 10\%$ y alerta a $+25\%$).

### 1.5 Inmutabilidad de Claves de Caché para Contratos sin article_name
- **Aislamiento de contratos**: La inyección de `unmatched_articles` está estrictamente condicionada por `itemsDeclareArticleName($fieldGroups['items'])`.
- **Preservación de hash**: En contratos donde el grupo `items` no audite campos `article_name`, el `response_schema` y el prompt generado son idénticos byte a byte al código previo.
- **Garantía de caché**: `ExtractionCacheManager::computeCacheKey` preserva el `$contractHash` y `$promptContextHash` originales, garantizando cero invalidaciones accidentales de caché en documentos administrativos o sin medicamentos (Invariante 8.3, T-01b y V-02).

### 1.6 Validación Oficial de maxItems en Gemini Structured Outputs
- **Conformidad con upstream**: La spec documenta el soporte de `maxItems` en `generationConfig.responseSchema` conforme a la especificación OpenAPI/JSON Schema de Google Gemini API Structured Outputs.
- **Defensa en profundidad**: Conforme a la decisión D-03, la arquitectura no delega la seguridad exclusivamente al proveedor de IA; `GeminiResponseParser::sanitizeUnmatchedArticles` implementa un truncado determinista estricto (`array_slice` a 10 elementos), saneo de cadenas (`trim`, descarte de vacíos) y deduplicación (`mb_strtoupper`), garantizando el cumplimiento del invariante 8.2 aun ante eventuales fallos de observancia del modelo.

### 1.7 Cobertura de la Matriz de Pruebas (T-01 a T-15)
- **Complitud del ciclo de vida**:
  - Generación de contratos y schemas: T-01, T-01b.
  - Prompts condicionales: T-02, T-03.
  - Parsing defensivo y truncado: T-04, T-05, T-06, T-06b.
  - Normalización y logging: T-07, T-07b, T-07c.
  - Integración en Policy Engine y baseline: T-08, T-08b, T-09, T-10, T-11, T-12, T-13, T-14, T-15.

---

## 2. Veredicto Final

**VEREDICTO: APROBADO**
Compuerta SDD Paso 5 superada exitosamente. Se autoriza el avance al Paso 6 (Implementación TDD).
