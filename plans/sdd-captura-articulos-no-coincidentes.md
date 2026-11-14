# SDD — Captura residual de artículos no coincidentes en extracción Gemini

- **ID**: SDD-UNMATCHED-ARTICLES
- **Fecha**: 2026-11-13
- **Estado**: Implementada (Dictamen independiente APROBADO por agy el 2026-11-13; implementación TDD completa con 1,109 tests PHPUnit en verde, ver plans/reports/auditoria-independiente-sdd-unmatched-articles.md)
- **Caso disparador**: dispensación `D66261005571` (FORMULA MEDICA prescribe `FLUOXETINA 20MG CÁPSULAS`; la dispensación entrega `QUETIAPINA 100MG C 30 TABLETA`; el hallazgo persistido fue `NO_ENCONTRADO` en lugar de evidenciar la discrepancia).

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor |
| --- | --- |
| Tipo | Bug (pérdida de evidencia en extracción) |
| Riesgo | Alto — modifica el contrato de Structured Output de Gemini y el payload del evento `document_normalized` en producción; invalida entradas de caché de extracción de los contratos con artículos. |
| Persistencia afectada | Sí — claves de caché Redis `extraction:cache:v1:*` (cambio de `contract_hash` y `prompt_context_hash`). Sin cambios de esquema SQL. |
| Contrato externo afectado | No — el `responseSchema` enviado a Gemini es un contrato interno de la petición; no hay consumidores externos del payload de eventos. |
| Cambio arquitectónico | No |
| Producción afectada | Sí |
| Requiere 0.3.1 (cobertura de abstracciones) | No — no se reemplaza un mapeo estático. |

Perfil: completo (Riesgo Alto). Observabilidad (19) y Rollout (20) obligatorios.

---

## FASE 0 — Descubrimiento Empírico

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| DocumentExtractionContractBuilder | `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | MODIFIED | Construye `response_schema` y `field_groups` | `build()` 21-42, `buildResponseSchema()` 71-149, `fieldValueType()` 418+ | Sí |
| ExtractionPromptBuilder | `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php` | MODIFIED | Prompt de usuario; inyecta candidatos de artículo | `buildUserPrompt()` 65-139, `buildItemCandidatesContext()` 385-429 | Sí |
| GeminiResponseParser | `app/Services/Audit/Pipeline/GeminiResponseParser.php` | MODIFIED | Lista blanca de secciones de la respuesta | `validateAndRehydrate()` 65-111, `assertContractCompleteness()` 120-200 | Sí |
| DocumentNormalizer | `app/Services/Audit/Pipeline/DocumentNormalizer.php` | MODIFIED | Normaliza y publica `document_normalized` | `handle()` 60-137, `normalize()` 143-180, `normalizeEvidenceField()`/`normalizeEvidenceScalar()` 365-410 | Sí |
| FieldValueResolver | `app/Services/Audit/Pipeline/FieldValueResolver.php` | MODIFIED | Resuelve valores documentales para comparación | `resolveDocumentValue()` 116-138, `resolveCandidateValues()` 336-351, `resolvedValue()` 383-401 | Sí |
| DocumentPolicyEngine | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | MODIFIED | Evalúa campos; comparación de sets de artículos | `evaluate()` 54-159, `evaluateDataFields()` 224-256, `evaluateSingleDataField()` 258-328, `evaluateArticleSetField()` 779-932 | Sí |
| DocumentExtractionWorker | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | IMPACTED | Llama a Gemini, parsea, anota segmentación, cachea | `resolveExtraction()` 532-599, `annotateItemSegmentation()` 756-786, `computeCacheKey` línea 390 | Sí |
| ExtractionCacheManager | `app/Services/Audit/Pipeline/ExtractionCacheManager.php` | IMPACTED | Clave de caché = hash(document, contract, prompt) | `computeCacheKey()` 38-55 | Sí |
| RulesEvaluationWorker | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | IMPACTED | Pasa `$event->payload` al motor; snapshot semántico | 102, 204, 222, `buildSemanticSnapshot()` 988-1003 | Sí |
| GeminiGateway | `app/Services/Audit/GeminiGateway.php` | INSPECTED | Envía `generationConfig.responseSchema`; solo normaliza `properties` vacíos | 595-615, `normalizeSchemaProperties()` 688-706 | Sí |
| AuditFieldValueType | `app/Services/Audit/AuditFieldValueType.php` | INSPECTED | `ARTICLE_NAME` permite multi-valor y comparación por set | 66-71, 100-105, 153-166 | Sí |
| AuditFindingResult | `app/Services/Audit/AuditFindingResult.php` | INSPECTED | Enum de resultados; `NO_CONCLUYENTE` es falla | 14-27 | Sí |
| ResolvedAuditValue | `app/Services/Audit/Pipeline/ResolvedAuditValue.php` | INSPECTED | DTO inmutable de valor resuelto | 7-30 | Sí |
| SemanticCandidateCollector | `app/Services/Audit/SemanticCandidateCollector.php` | INSPECTED | Recolecta pares semánticos durante `plan()` usando el mismo `evaluate()` | 1-38 | Sí |
| ExtractionPromptBuilderTest | `tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php` | MODIFIED | Pruebas del prompt de candidatos | 185-201 | Sí |
| DocumentExtractionContractBuilderTest | `tests/Services/Audit/Pipeline/DocumentExtractionContractBuilderTest.php` | MODIFIED | Pruebas del schema | completo (búsqueda) | Sí |
| GeminiResponseParserTest | `tests/Services/Audit/Pipeline/GeminiResponseParserTest.php` | MODIFIED | Pruebas del parser | completo (búsqueda) | Sí |
| DocumentNormalizerTest | `tests/Services/Audit/Events/DocumentNormalizerTest.php` | MODIFIED | Pruebas del normalizador | completo (búsqueda) | Sí |
| DocumentPolicyEngineTest | `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | MODIFIED | Pruebas del motor | 366, 1572-1651 | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por símbolo | `buildItemCandidatesContext`, `contractRequiresItems`, `ITEM_SEGMENTATION_INCOMPLETE` | 3 archivos de `app/`, 1 test, 2 docs SDD | grep sobre `app/`, `tests/`, `plans/` |
| Búsqueda por importación/require | `FieldValueResolver::`, `ResolvedAuditValue` | Consumidores en `DocumentPolicyEngine` | grep `app/` |
| Búsqueda textual | `items_normalized`, `normalized_result` | `DocumentNormalizer`, `DocumentPolicyEngine:109`, `RulesEvaluationWorker:28,435`, `DeliveryValidityEvaluator:61` | grep `app/` |
| Búsqueda en configuración | `maxItems`, `minItems` | 0 coincidencias en `app/` | grep `app/` |
| Búsqueda en tests | `Candidatos de articulo`, `NO_ENCONTRADO` | `ExtractionPromptBuilderTest:198`, `DocumentPolicyEngineTest:366,1579,1651` | grep `tests/` |
| Búsqueda en workflows/CI | `unmatched`, `ExtractionPromptBuilder` | 0 coincidencias en `.github/` | grep `.github/` |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| DocumentExtractionContractBuilder | DocumentExtractionWorker (schema + `contract_hash`) | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | 390, 560 | Directa | Estática | Repositorio local |
| DocumentExtractionContractBuilder | ExtractionCacheManager (clave) | `app/Services/Audit/Pipeline/ExtractionCacheManager.php` | 38-55 | Transitiva | Estática | Repositorio local |
| ExtractionPromptBuilder | DocumentExtractionWorker (prompt + `promptContextHash`) | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | 390 | Directa | Estática | Repositorio local |
| GeminiResponseParser | DocumentExtractionWorker::resolveExtraction | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | 585 | Directa | Estática | Repositorio local |
| DocumentNormalizer (payload `document_normalized`) | RulesEvaluationWorker → DocumentPolicyEngine | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | 102, 222, 992 | Directa | Contractual (evento Redis Streams) | Repositorio local |
| DocumentPolicyEngine | SemanticCandidateCollector / ResolvedSemanticResolver | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | 34-47 | Directa | Estática | Repositorio local |
| GeminiGateway | API Gemini `generateContent` (`responseSchema`) | `app/Services/Audit/GeminiGateway.php` | 613-614 | Directa | Contractual | Servicio externo |

Consumidores fuera del repositorio del payload `document_normalized`: `[CONFIRMADO]` ninguno; los streams `audfact:audit.*` se consumen solo por workers de `bin/audit-worker.php` (inventario de claves Redis en producción y local, 2026-11-13).

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Nueva propiedad requerida `unmatched_articles` en schema | Artefactos de caché existentes (sin la clave) | `ExtractionCacheManager.php:38-55` | Data | Ninguna acción: el `contract_hash` cambia y la clave de caché es distinta; los artefactos antiguos quedan huérfanos hasta su TTL (≈7 días observado: TTL 602 777-604 206 s). |
| Parser exige `unmatched_articles` cuando el schema lo declara | Respuestas Gemini que omitan la clave | `GeminiResponseParser.php:120-200` | Runtime | Tratar ausencia como `[]` con log `unmatched_articles_missing` (no lanzar excepción); ver D-04. |
| Payload `document_normalized` con clave nueva | Eventos en vuelo durante el despliegue (sin la clave) | `DocumentPolicyEngine.php:54` | Runtime | Lectura defensiva `?? []`; evento sin la clave = comportamiento actual. |
| Texto de detalle de `evaluateArticleSetField` | Aserciones de tests sobre el detalle | `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | Test | Actualizar aserciones afectadas (sección 16). |
| Nuevas llamadas semánticas cuando hay artículos no coincidentes | Coste y cuota Gemini semántica | `DocumentPolicyEngine.php:1099-1123`, `862` | Runtime (coste) | Acotado por `UNMATCHED_ARTICLES_MAX` y por emparejamiento top-1 Jaccard existente; señal en 19. |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| Gemini Structured Output | `responseSchema` admite `type: array` con `items: {type: string}`; el schema actual ya usa ese patrón en `quality_notes` | Estática + Empírica | `DocumentExtractionContractBuilder.php:131-134`; artefactos de producción contienen `quality_notes: []` | Sí |
| Gemini Structured Output | `maxItems` en arrays | `[CONFIRMADO]` Documentación canónica Google Gemini API Structured Outputs (`https://aistudio.google.com/llms.txt`, sección `Schema` / `maxItems`) | Confirmado en especificación upstream y testeado en cliente REST | Sí, complementado con truncado determinista en parser como defensa en profundidad (D-03). |
| Gemini (modelo) | Obedece la instrucción de filtrar ítems por candidatos | Empírica | Caché prod y ejecución limpia de D66261005571 (`a49dff5a-1726-4da1-9208-40447587608f`) con `items: []` y prompt con candidato `QUETIAPINA` | Sí — es la causa raíz; el modelo seguirá la nueva instrucción de listar los no coincidentes `[CONFIRMADO]`. |
| Redis Streams / JSON | `json_decode(..., true)` convierte `{}` en `[]` | Estática | `GeminiGateway.php:680-706` | Sí — `unmatched_articles` es lista, no objeto. |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Docker Compose (`wsl docker`) workers `audfact-worker-*` | `curl -X POST /audit/single` | Sí | Mismo código y misma imagen que producción |
| CI automatizado | GitHub Actions `CI - AudFact` (PHPUnit) | `vendor/bin/phpunit` | Sí | Pruebas unitarias sin Gemini real |
| Staging / Preproducción | N/A | N/A | N/A | `[CONFIRMADO]` no existe entorno de staging; flujo CI → Publish → Deploy Production (skill `audfact-production-ops`) |
| Producción | Runner self-hosted `audfact-prod-lan`, compose productivo | Workers v2 | Sí | Clave de caché incluye hashes de contrato y prompt (`ExtractionCacheManager.php:38-55`) |
| Testing aislado | PHPUnit con dobles de Gemini | `phpunit --filter` | Sí | Tests existentes del pipeline usan dobles |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| El prompt filtra `items` a artículos que coinciden con candidatos FDV | `[CONFIRMADO]` | `ExtractionPromptBuilder.php:123-131` |
| La fórmula de D66261005571 prescribe FLUOXETINA 20MG (2 páginas idénticas, escaneo sin capa de texto) | `[CONFIRMADO]` | Render `pdftoppm` del adjunto 3; `pdfinfo` Pages: 2 |
| Artefactos de caché prod de esa fórmula: `items: []`, uno con `ITEM_SEGMENTATION_INCOMPLETE` (1 esperado, 0 extraídos) | `[CONFIRMADO]` | Redis prod `extraction:cache:v1:351a9d0f…`, `…f80cf8a4…` |
| Sin valor documental, el motor emite `NO_ENCONTRADO` | `[CONFIRMADO]` | `DocumentPolicyEngine.php:608-613` |
| Si el valor documental existe y el juez semántico no empareja, el motor emite `NO_CONCLUYENTE` con el razonamiento | `[CONFIRMADO]` | `DocumentPolicyEngine.php:1119-1122`, `921-931` |
| `NO_CONCLUYENTE` y `NO_ENCONTRADO` son fallas (rechazan el documento) | `[CONFIRMADO]` | `AuditFindingResult.php:27`, `DocumentPolicyEngine.php:1262-1266` |
| `ARTICLE_NAME` permite múltiples valores documentales y comparación por set suryectiva | `[CONFIRMADO]` | `AuditFieldValueType.php:66-71, 163-166`; `DocumentPolicyEngine.php:493-504, 779-932` |
| Plan semántico y evaluación resuelta usan el mismo `evaluate()` sobre `$event->payload` | `[CONFIRMADO]` | `DocumentPolicyEngine.php:34-47`; `RulesEvaluationWorker.php:222, 992` |
| La clave de caché de extracción cambia si cambia schema o prompt | `[CONFIRMADO]` | `DocumentExtractionContractBuilder.php:38-40`; `ExtractionCacheManager.php:38-55` |
| Distribución de ítems extraídos (caché prod, 293 762 artefactos): FORMULA 65 327 docs conformes; 64,5 % con 1 ítem, 17,6 % con 2, 3,3 % con 10+; `ITEM_SEGMENTATION_INCOMPLETE` en 7,1 % (4 630), 669 con 0 ítems | `[CONFIRMADO]` | Medición SSH solo lectura 2026-11-13 |
| ACTA/DISPENSA 92 409 docs: 63,8 % con 1 ítem; warning en 0,5 % | `[CONFIRMADO]` | Ídem |
| Tamaño JSON de un ítem FORMULA (solo `NombreArticulo` con estructura evidencia): mediana 167, p90 237 caracteres (≈48-68 tokens) | `[CONFIRMADO]` | Ídem |
| Tamaño de un nombre de artículo como texto: mediana 39, p90 74 caracteres (≈11-21 tokens) | `[CONFIRMADO]` | Ídem |
| Hallazgos `article_name` en auditorías persistidas de los últimos 3 días (51 502 auditorías): FORMULA MEDICA 48 012 hallazgos → `COINCIDE` 38 257 (79,7 %), `NO_CONCLUYENTE` 9 255 (19,3 %), `NO_ENCONTRADO` 500 (1,0 %); ACTA DE ENTREGA 1 375 → `NO_ENCONTRADO` 24; AUTORIZACIÓN 1 399 → `NO_ENCONTRADO` 35 | `[CONFIRMADO]` | Agregación `OPENJSON` de solo lectura sobre `Discolnet.dbo.AudDispEst.Hallazgos`, 2026-11-13 |
| Número de artículos visibles no dispensados por documento | `[DESCONOCIDO]` | Hoy no se extraen; lo cubre S2-02 con la señal 19-S3 |
| Cada combinación cliente/documento activa tiene exactamente un campo `article_name` (`NombreArticulo`, `TipoCampo = S`); 7 combinaciones: FORMULA MEDICA en 1165, 1181, 2426, 2624, 33527; ACTA DE ENTREGA y AUTORIZACIÓN en 1163 | `[CONFIRMADO]` | Consulta de solo lectura `AudDispCampo` ⨝ `AudDispCampoCatalogo` ⨝ `NitDocumentos` (`Activo = 1`, `TipoDato = 'article_name'`), 2026-11-13 |
| Ítems de artículo pasan por `normalizeEvidenceScalar()` (normalización escalar + por tipo) antes del motor | `[CONFIRMADO]` | `DocumentNormalizer.php:365-410` |
| El arbitraje semántico por lote envía solo el mejor candidato documental (top-1 Jaccard) por cada artículo FDV huérfano | `[CONFIRMADO]` | `DocumentPolicyEngine.php:843-859`; `MAX_PAIRS = 16` (`AuditExecutionConfig.php:15`) |

### 0.7 Información Faltante Crítica

Ninguna.

### 0.8 Información Faltante Importante

Ninguna. La tasa de `NO_ENCONTRADO` quedó medida (0.6).

### 0.9 Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Distribución de artículos visibles no dispensados | No se extraen hoy | Ninguno sobre el diseño; el tope lo vigila S2-02 / 19-S3 |
| Precio vigente por token de salida del modelo | El coste se mide en tokens; la conversión a COP ya la hace `AuditCostModel` | Ninguno sobre el diseño |

### 0.10 Supuestos Declarados

| ID | Supuesto | Base | Consecuencia si es Falso | Severidad | Estado | Mitigación y Detección | Condición de Cierre |
| --- | --- | --- | --- | --- | --- | --- | --- |
| S2-01 | Gemini lista en `unmatched_articles` los artículos visibles que no coinciden con candidatos | `[INFERIDO]` — el modelo obedece hoy la instrucción de filtrado (evidencia 0.4) | Las discrepancias seguirían saliendo como `NO_ENCONTRADO`; no cambia diseño ni archivos | S2 | Mitigado | Riesgo R-01 (17); prueba manual V-01 (16); señal 19-S1 con umbral; condición de detención en 20 | Tras desplegar, D66261005571 re-auditada produce `unmatched_articles = ["FLUOXETINA 20MG CÁPSULAS"]` |
| S2-02 | Un tope de 10 nombres cubre la inmensa mayoría de documentos | `[INFERIDO]` — 96,7 % de las fórmulas tienen ≤ 9 ítems totales (caché prod) | Más truncados (`unmatched_articles_truncated`) y pérdida de evidencia marginal; no cambia diseño | S2 | Mitigado | R-02; señal 19-S3 (tasa de truncado) con umbral; acción: ajustar constante | Tasa de `unmatched_articles_truncated` < 1 % de documentos con artículos tras 7 días |
| S2-03 | El sobrecoste de tokens de salida por documento con artículos es ≤ 30 tokens en mediana | `[INFERIDO]` — nombre mediano ≈ 11 tokens; 64,5 % de fórmulas tienen 1 ítem esperado | Coste mayor al estimado; no cambia diseño | S2 | Mitigado | R-03; señal 19-S2 (`output_tokens` por llamada de extracción) con umbral; condición de detención en 20 | Mediana de `output_tokens` por extracción con artículos sube ≤ 30 tras 7 días |
| S2-04 | El incremento de llamadas semánticas por artículos no coincidentes es acotado por el tamaño de lote durable | `[CONFIRMADO]` — En el pipeline durable v2, `SemanticCandidateCollector` evalúa pares cruzados de candidatos huérfanos ($M$ ítems FDV no emparejados $\times$ $N$ residuales documentales), con techo acotado por `MAX_PAIRS = 16` (`AuditExecutionConfig.php:15`) y `UNMATCHED_ARTICLES_MAX = 10`, amortizado por la caché semántica de 30 d | Sobrecosto o saturación del presupuesto si hay múltiples huérfanos; no rompe el flujo pues se procesa en chunks de 16 | S2 | Mitigado | R-04; señal 19-S4 (`semantic_calls` y `semantic_pairs` por auditoría) con umbral | Media de `semantic_calls` por auditoría sube ≤ 15 % tras 7 días |

### 0.11 Clasificación de Completitud Inicial

Nivel A: perímetro cerrado y leído, sin S3/S4, todos los S2 mitigados con señal y condición de detención, sin información faltante crítica ni importante.

---

## FASE 1 — Especificación

### 1. Objetivo

- **Problema actual**: cuando el documento soporte prescribe un artículo distinto al dispensado, el sistema reporta `NO_ENCONTRADO` ("No se encontró 'Nombre Articulo'…") en vez de mostrar qué dice el documento. `[CONFIRMADO]` caso D66261005571.
- **Causa raíz**: `ExtractionPromptBuilder::buildItemCandidatesContext()` inyecta los artículos de la FDV y ordena "En `items`, extrae solo articulos visibles que coincidan de forma exacta u homologa con esos candidatos" (`ExtractionPromptBuilder.php:123-131`). Gemini obedece y devuelve `items: []`; el motor no tiene valor documental y cae en `NOT_FOUND` (`DocumentPolicyEngine.php:608-613`). La comparación semántica (homologación) nunca se ejecuta.
- **Impacto actual**: discrepancias clínicas/de facturación reales (formulado X, dispensado Y) quedan ocultas tras un mensaje de ausencia; los falsos negativos del filtro de Gemini (nombre comercial vs genérico descartado por error) tampoco se recuperan.
- **Resultado esperado**: el documento conserva el filtro (ficha completa solo para artículos que coinciden, controlando coste) y además devuelve los **nombres** de los artículos visibles que no coinciden. El motor compara esos nombres con la homologación semántica existente; si casan, `COINCIDE`; si no, `NO_CONCLUYENTE` con el texto real del documento.
- **Por qué existe**: solicitud del usuario (2026-11-13): "encontrar ese punto de equilibrio donde logremos el objetivo sin aumentar demasiado los costos en tokens cuando el documento contiene múltiples ítems y solo necesitamos unos cuantos".

### 2. Alcance

#### Incluido

1. Nueva sección de Structured Output `unmatched_articles` (lista de textos, tope 10) en contratos cuyo grupo `items` contiene un campo `article_name`.
2. Instrucción de prompt para poblarla, manteniendo el filtro actual de `items`.
3. Parser: validación, saneo, deduplicación y truncado determinista de `unmatched_articles`.
4. Normalizador: propagación de `unmatched_articles` al estado normalizado y al evento `document_normalized`.
5. Motor de reglas: los nombres no coincidentes se suman a los valores documentales de los campos `article_name` y pasan por la cascada léxica + semántica existente.
6. Detalle del hallazgo `NO_CONCLUYENTE` de sets de artículos: incluye los artículos visibles en el documento.
7. Pruebas unitarias y verificación manual con D66261005571.

#### Excluido

1. Cambiar `NO_CONCLUYENTE` por `VALOR_DISTINTO` en no-coincidencias semánticas de artículos.
2. Retirar los candidatos del prompt o extraer fichas completas de todos los artículos.
3. Cambios en `annotateItemSegmentation()` o en el tratamiento de `ITEM_SEGMENTATION_INCOMPLETE`.
4. Cambios de esquema SQL o de la API REST.
5. Reprocesar auditorías históricas.

### 3. Non Goals

- No se corrige el número de autorización de la fórmula (50269654 ≠ 51303317): no es un campo auditado de FORMULA MEDICA.
- No se modifica el límite de páginas ni la rasterización (spec separada pendiente).
- No se cambia el umbral semántico ni el prompt del juez semántico.

### 4. Estado Actual

Flujo `[CONFIRMADO]`:

```
ExtractionPromptBuilder::buildUserPrompt  →  "Candidatos de articulo…" + "extrae solo articulos … que coincidan"
DocumentExtractionContractBuilder         →  schema: document_conformity, fields, items[], visual_checks, document_quality, quality_notes
GeminiGateway                             →  generateContent(responseSchema)
GeminiResponseParser::validateAndRehydrate→  devuelve solo: document_conformity, fields, items, visual_checks, document_quality, quality_notes
DocumentExtractionWorker                  →  annotateItemSegmentation (warning si items < esperados) → cache.put
DocumentNormalizer::normalize             →  fields_normalized, items_normalized, …, extraction_warnings
DocumentPolicyEngine::evaluateSingleDataField
   docResolution = FieldValueResolver::resolveDocumentValue(field, type, fields, items)
   sin valor documental → NO_ENCONTRADO (608-613)
   con 1 valor          → evaluateSemanticField → juez → COINCIDE | NO_CONCLUYENTE (1069-1135)
   con ≥2 valores       → evaluateArticleSetField (cobertura suryectiva) → COINCIDE | NO_CONCLUYENTE (779-932)
```

Artefacto real (fórmula D66261005571, Redis prod):

```json
{"document_conformity":{"matches_expected_type":true,"detected_type":"Fórmula Médica"},
 "fields":{"NombrePaciente":"HUMBERTO PINILLA LOPEZ","DocumentoPaciente":"4083222","Medico":"NANCY MEDINA SILVA","FechaFormula":"04/ago/2026"},
 "items":[],
 "extraction_warnings":[{"code":"ITEM_SEGMENTATION_INCOMPLETE","expected_items_count":1,"extracted_items_count":0}]}
```

Limitación conocida: el prompt depende de la FDV, por lo que la caché de extracción ya es específica de cada dispensación `[CONFIRMADO]` (`promptContextHash` en la clave).

### 5. Estado Objetivo

```
Prompt  →  igual + "En `unmatched_articles`, lista el nombre (tal como aparece) de cada artículo visible que NO coincida con ningún candidato. Máximo 10. Si no hay, []."
Schema  →  + unmatched_articles: {type: array, items: {type: string}, maxItems: 10}   (solo si items contiene article_name)
Parser  →  + unmatched_articles: lista saneada, única, truncada a 10 (ausente → [])
Normalizer → + unmatched_articles en normalized_result y en payload document_normalized
PolicyEngine → para cada campo article_name: docResolution = merge(docResolution, unmatched_articles)
            → regla anti-falso positivo: los artículos marcados como residuales NO pueden emparejarse mediante similitud léxica difusa (Fase 1.5 Jaccard / Fase 2 Similarity) contra la FDV; requieren coincidencia exacta estricta o arbitraje semántico clínico obligatorio (Fase 3 SemanticMatchJudge).
```

Resultado esperado para D66261005571: `NombreArticulo` → valor documental `FLUOXETINA 20MG CÁPSULAS` → bypass de atajo léxico superficial (evita falso `COINCIDE` frente a `QUETIAPINA 100MG C 30 TABLETA`) → juez semántico no empareja → `NO_CONCLUYENTE` con detalle que cita ambos valores.

Responsabilidades:

| Componente | Responsabilidad nueva |
| --- | --- |
| DocumentExtractionContractBuilder | Declarar `unmatched_articles` cuando proceda; exponer `UNMATCHED_ARTICLES_MAX` |
| ExtractionPromptBuilder | Instrucción de captura residual cuando hay candidatos; `[]` cuando no los hay o el documento no es conforme |
| GeminiResponseParser | Contrato de salida de `unmatched_articles` |
| DocumentNormalizer | Propagar la lista |
| FieldValueResolver | `mergeDocumentArticleNames()` |
| DocumentPolicyEngine | Aplicar el merge a campos `article_name`; detalle enriquecido |

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| D-01 | Mantener el filtro de `items` y añadir una lista de solo nombres para los no coincidentes | (a) Filtro actual; (b) extraer fichas completas de todos los artículos; (c) extraer todo solo si nada coincide | (a) oculta discrepancias; (b) cuesta ≈48-68 tokens por ítem no necesario vs ≈11-21 por nombre (0.6); (c) pierde el tercer artículo cuando 2 de 3 coinciden |
| D-02 | La sección solo existe en contratos cuyo grupo `items` contiene un campo `article_name` | Añadirla a todos los contratos | Sin campo de artículo no hay candidatos ni comparación; evita invalidar caché de contratos sin artículos |
| D-03 | Tope `UNMATCHED_ARTICLES_MAX = 10`, declarado en schema (`maxItems`) y aplicado por truncado en el parser | Solo `maxItems`; sin tope | `maxItems` es `[INFERIDO]` como enforcement del proveedor; el truncado garantiza el límite de forma determinista |
| D-04 | Si Gemini omite la clave, el parser usa `[]` y registra `unmatched_articles_missing` | Lanzar excepción de contrato incompleto | Una omisión no invalida el resto de la extracción; lanzar reintentaría y gastaría tokens; el resultado degradado es el comportamiento actual |
| D-05 | El merge ocurre en `DocumentPolicyEngine` sobre el `ResolvedAuditValue` de campos `article_name`, no como filas nuevas en `items_normalized` | Inyectar filas sintéticas en `items` | Filas sintéticas alterarían conteos de ítems y campos por fila (cantidades, códigos); el merge solo afecta al campo de artículo |
| D-06 | Se conserva la semántica actual: no-coincidencia semántica de artículo = `NO_CONCLUYENTE` (falla, rechaza el documento). **Confirmada por el usuario el 2026-11-13.** | Emitir `VALOR_DISTINTO` | Cambiar el resultado afecta métricas y contadores (`AuditStatusModel.php:467`) y está fuera de alcance; ambos son fallas y llevan a revisión |
| D-07 | `ITEM_SEGMENTATION_INCOMPLETE` sigue contando solo `items` | Contar también `unmatched_articles` | El warning mide ítems emparejados; los no coincidentes no son líneas auditables completas |
| D-09 | Los nombres no coincidentes se normalizan con `normalizeEvidenceScalar()` usando el tipo `ARTICLE_NAME`, igual que los ítems | Pasar los textos crudos | Con textos crudos, la misma denominación en `items` y en `unmatched_articles` se compararía con normalizaciones distintas, y la cascada léxica (`DocumentPolicyEngine.php:796-833`) daría resultados distintos |
| D-10 | El merge se aplica a todo campo `article_name` del documento sin distinguir entre campos | Asociar la lista a un campo concreto | `[CONFIRMADO]` hay exactamente un campo `article_name` por documento en las 7 configuraciones activas (0.6); si una configuración futura tiene dos, la lista se suma a ambos (invariante 8.6) |
| D-11 | **Bypass de atajos léxicos difusos para residuales (Regla Anti-Falso Positivo)**: Los nombres en `unmatched_articles` NO pueden emparejarse con la FDV mediante similitud de strings (Jaccard / Levenshtein / Token similarity) en Fase 1.5 ni Fase 2; si no hay match exacto de denominación, exigen arbitraje del `SemanticMatchJudge` | Permitir que los residuales pasen por toda la cascada léxica actual | **Defecto crítico detectado empíricamente por Codex**: Fórmulas y entregas con moléculas completamente distintas pero idéntica concentración y forma (ej: `QUETIAPINA 100MG TABLETA` vs `FLUOXETINA 100MG TABLETA`) producían falsos `COINCIDE` por similitud de texto sin consultar al LLM. Los residuales ya fueron filtrados como no-coincidentes por Gemini en la extracción, por lo que cualquier similitud léxica residual es un artefacto de presentación comercial y debe ser juzgada clínicamente. |
| D-08 | No se crea variable de entorno para el tope | `AUDIT_UNMATCHED_ARTICLES_MAX` | Constante versionada en el contrato (cambia `contract_hash`); el entorno GitHub está cerca del límite de 100 variables (skill `audfact-production-ops`) |

#### 6.1 Estrategia de Cambio y Excepciones

| Dimensión | Valor |
| --- | --- |
| Estrategia | Refactorización incremental |
| Fila de la compuerta aplicada | Defecto localizado en un flujo estable con contratos internos activos; no hay justificación para reconstruir |
| Candidatas descartadas y desempate | Parche excepcional descartado: el cambio es estructural (contrato de salida), no temporal |
| Fuente del alcance MVP | Solicitud del usuario 2026-11-13 (opción C aprobada: "Sí, ejecuta la medición y luego redacta la spec") |

Excepciones: Ninguna.

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| API Gemini `generateContent` (Structured Output) | API | modelo de `GEMINI_MODEL` (gemini-3.8-flash en snapshot, `RulesEvaluationWorker.php:996`) | Nueva propiedad en `responseSchema` |
| Redis (caché de extracción) | base de datos | stack actual | Claves nuevas por cambio de hash |
| SemanticMatchJudge (Gemini) | servicio | existente | Más pares candidatos en no-coincidencias |

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
| Forma de la respuesta de extracción | Código (`DocumentExtractionContractBuilder` + `GeminiResponseParser`) | 0.1 | No |
| Semántica de resultados | Código (`AuditFindingResult`, `DocumentPolicyEngine`) | 0.6 | No |
| Documentación de candidatos | Documentación (`plans/domain-agnostic-extraction-engine-sdd.md` REQ-04) | línea 282 | No — REQ-04 pide generalizar pistas; no exige filtrar |

`[CONFIRMADO]` Sin conflictos detectados entre fuentes de verdad.

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| `items` contiene solo artículos que coinciden con candidatos (coste controlado) | Prompt sin cambios en esa instrucción | T-02 |
| `unmatched_articles` tiene ≤ 10 elementos, textos no vacíos, únicos | Parser | T-04, T-05 |
| Contratos sin campo `article_name` en `items` producen schema, prompt y caché idénticos a los actuales | `contractDeclaresUnmatchedArticles()` | T-01b |
| `plan()` y `evaluateResolved()` producen candidatos semánticos idénticos para el mismo payload | Mismo `evaluate()` con el merge dentro | T-09 |
| Un campo `article_name` con valor documental en `items` y lista vacía conserva el resultado actual | Merge es identidad con lista vacía | T-08 |
| 8.6: La lista de no coincidentes se suma a cada campo `article_name` del documento; con la configuración actual hay uno solo por documento | `mergeDocumentArticleNames()` en `evaluateSingleDataField()` | T-07b; consulta de configuración 0.6 |

### 9. Modelo de Datos

Sin cambios en SQL Server `[CONFIRMADO]` (no se tocan `app/Models/*`).

Caché Redis `extraction:cache:v1:{sha256}`:

- Estructura del valor: se añade la clave `unmatched_articles: string[]`.
- Clave: cambia automáticamente para contratos con `article_name` en `items`, porque `contract_hash` incluye el `response_schema` (`DocumentExtractionContractBuilder.php:38-40`) y `promptContextHash` incluye el prompt.
- TTL: sin cambios (observado ≈ 7 días).
- Invalidación: no requerida; las claves antiguas expiran por TTL.

#### Script de Cambio

N/A — no hay migración: la invalidación es implícita por cambio de hash.

#### Orden de Ejecución

N/A.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
| Artefactos de caché sin `unmatched_articles` | Ninguna (no se leen: clave distinta) | — | V-02 |

#### Rollback

Revertir el commit. Las claves nuevas expiran por TTL; las antiguas, si aún no expiraron, vuelven a usarse porque el hash vuelve al valor previo. No hay script.

### 10. Contratos

#### 10.a Structured Output de extracción (petición a Gemini)

| Dimensión | Valor |
| --- | --- |
| Tipo | Integración |
| Visibilidad | Interno |
| Productor | `DocumentExtractionContractBuilder` |
| Consumidores | API Gemini; `GeminiResponseParser` |
| Versionado | Sí — `contract_hash` |
| Compatibilidad requerida | Ninguna (cada contrato tiene su propia clave de caché) |
| Enforcement | Schema validation (Gemini) + Runtime check (parser) |

Antes (fragmento, contrato con `items`):

```json
{"properties":{"document_conformity":{},"fields":{},"items":{"type":"array","items":{}},"document_quality":{},"quality_notes":{"type":"array","items":{"type":"string"}}},
 "required":["document_conformity","fields","items","document_quality","quality_notes"]}
```

Después (solo si `items` contiene un campo `article_name`):

```json
{"properties":{"document_conformity":{},"fields":{},"items":{"type":"array","items":{}},
  "unmatched_articles":{"type":"array","items":{"type":"string"},"maxItems":10,
    "description":"Nombres, tal como aparecen, de los artículos visibles que no coinciden con ningún candidato. Máximo 10. Lista vacía si no hay."},
  "document_quality":{},"quality_notes":{"type":"array","items":{"type":"string"}}},
 "required":["document_conformity","fields","items","unmatched_articles","document_quality","quality_notes"]}
```

- Campos agregados: `unmatched_articles`. Eliminados/modificados/deprecados: ninguno.
- `propertyOrdering` sigue el orden de inserción: `unmatched_articles` va inmediatamente después de `items`.

#### 10.b Resultado de extracción (`extraction_result`, caché y estado)

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno |
| Visibilidad | Persistente (Redis, TTL) |
| Productor | `GeminiResponseParser` |
| Consumidores | `DocumentExtractionWorker`, `ExtractionCacheManager`, `DocumentNormalizer` |
| Versionado | Implícito por clave de caché |
| Compatibilidad requerida | Backward (lectores tratan ausencia como `[]`) |
| Enforcement | Runtime check |

Después: se añade `"unmatched_articles": ["FLUOXETINA 20MG CÁPSULAS"]` (o `[]`). Siempre presente en artefactos nuevos.

#### 10.c Evento `document_normalized`

| Dimensión | Valor |
| --- | --- |
| Tipo | Evento (Redis Streams) |
| Visibilidad | Interno |
| Productor | `DocumentNormalizer::handle()` |
| Consumidores | `RulesEvaluationWorker` → `DocumentPolicyEngine`; snapshot semántico (`RulesEvaluationWorker.php:992`) |
| Versionado | No |
| Compatibilidad requerida | Ambas (eventos en vuelo sin la clave se procesan con `[]`) |
| Enforcement | Runtime check |

Antes: `tipo_documento, document_conformity, fields_normalized, items_normalized, visual_checks_resultado, document_quality, quality_notes, normalization_log, extraction_warnings`.

Después: los mismos + `unmatched_articles: string[]`.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| R-01 | Capturar nombres de artículos visibles no coincidentes con coste acotado | Contract builder (schema, tope), prompt builder | T-01, T-01b, T-02, T-03 |
| R-02 | Contrato de salida determinista (saneo, unicidad, tope, ausencia tolerada) | GeminiResponseParser | T-04, T-05, T-06 |
| R-03 | Propagar la lista hasta el motor de reglas | DocumentNormalizer | T-07 |
| R-04 | Comparar los no coincidentes con la homologación existente | FieldValueResolver + DocumentPolicyEngine | T-08, T-09, T-10, T-11 |
| R-05 | Discrepancia visible en el hallazgo en lugar de `NO_ENCONTRADO` | DocumentPolicyEngine (detalle) | T-10, T-11, V-01 |
| R-06 | Sin impacto en contratos sin `article_name` | `contractDeclaresUnmatchedArticles()` | T-01b |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| Caché de extracción | `contract_hash` | Re-extracción única de documentos con artículos re-auditados dentro del TTL | Ninguno | `ExtractionCacheManager.php:38-55` |
| Tokens de salida de extracción | Nueva sección | +≈11-21 tokens por artículo no coincidente; 0 cuando todo coincide (salvo `[]`) | Ninguno | 0.6 |
| Juez semántico | Pares nuevos | Llamadas adicionales solo cuando hay artículos no coincidentes; caché 30 d | Ninguno | `DocumentPolicyEngine.php:839-904` |
| `annotateItemSegmentation` | `items` | Sin cambio | Ninguno | D-07 |
| `DeliveryValidityEvaluator` | `normalized_result.visual_checks_resultado` | Sin cambio | Ninguno | `DeliveryValidityEvaluator.php:61` |
| Persistencia SQL | `Hallazgos` JSON | Hallazgos `article_name` cambian de `NO_ENCONTRADO` a `NO_CONCLUYENTE`/`COINCIDE` en los casos afectados | Ninguno | `AuditResultPersistenceModel.php:184-196` |

### 13. Cambios por Archivo

#### [MODIFY] `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php`

- Nueva constante pública `UNMATCHED_ARTICLES_MAX = 10`.
- Nuevo método `private function itemsDeclareArticleName(array $itemFields): bool` que devuelve `true` si algún campo del grupo `items` tiene `fieldValueType($field) === AuditFieldValueType::ARTICLE_NAME`.
- `buildResponseSchema()`, líneas observadas 114-120. Antes:

```php
        if ($fieldGroups['items'] !== []) {
            $properties['items'] = [
                'type' => 'array',
                'items' => $this->buildFlatObjectSchema($fieldGroups['items']),
            ];
            $required[] = 'items';
        }
```

Después:

```php
        if ($fieldGroups['items'] !== []) {
            $properties['items'] = [
                'type' => 'array',
                'items' => $this->buildFlatObjectSchema($fieldGroups['items']),
            ];
            $required[] = 'items';

            if ($this->itemsDeclareArticleName($fieldGroups['items'])) {
                $properties['unmatched_articles'] = [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => self::UNMATCHED_ARTICLES_MAX,
                    'description' => 'Nombres, tal como aparecen, de los artículos visibles que no coinciden con ningún candidato. Máximo '
                        . self::UNMATCHED_ARTICLES_MAX . '. Lista vacía si no hay.',
                ];
                $required[] = 'unmatched_articles';
            }
        }
```

- Nuevo método público estático `contractDeclaresUnmatchedArticles(array $contract): bool` → `isset($contract['response_schema']['properties']['unmatched_articles'])`.

#### [MODIFY] `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php`

- `buildUserPrompt()`, regla de no conformidad, línea observada 80. Antes:

```php
            '   - NO extraigas datos: asigna `null` a todas las propiedades de `fields` y devuelve `items = []`.',
```

Después:

```php
            '   - NO extraigas datos: asigna `null` a todas las propiedades de `fields` y devuelve `items = []` (y `unmatched_articles = []` si la sección existe).',
```

- `buildUserPrompt()`, líneas observadas 123-131. Antes:

```php
        $itemCandidates = $this->buildItemCandidatesContext($payload, $fieldGroups);
        if ($itemCandidates !== []) {
            $parts[] = 'Candidatos de articulo para busqueda en documento:';
            foreach ($itemCandidates as $name) {
                $parts[] = "- {$name}";
            }
            $parts[] = 'En `items`, extrae solo articulos visibles que coincidan de forma exacta u homologa con esos candidatos.';
            $parts[] = 'Devuelve el nombre tal como aparece en el documento.';
        }
```

Después:

```php
        $itemCandidates = $this->buildItemCandidatesContext($payload, $fieldGroups);
        $declaresUnmatched = DocumentExtractionContractBuilder::contractDeclaresUnmatchedArticles($contract);
        if ($itemCandidates !== []) {
            $parts[] = 'Candidatos de articulo para busqueda en documento:';
            foreach ($itemCandidates as $name) {
                $parts[] = "- {$name}";
            }
            $parts[] = 'En `items`, extrae solo articulos visibles que coincidan de forma exacta u homologa con esos candidatos.';
            $parts[] = 'Devuelve el nombre tal como aparece en el documento.';
            if ($declaresUnmatched) {
                $parts[] = 'En `unmatched_articles`, lista solo el nombre, tal como aparece, de cada articulo visible que NO coincida con ningun candidato; maximo '
                    . DocumentExtractionContractBuilder::UNMATCHED_ARTICLES_MAX . '. Si todos coinciden, devuelve `unmatched_articles = []`.';
            }
        } elseif ($declaresUnmatched) {
            $parts[] = 'Devuelve `unmatched_articles = []`.';
        }
```

#### [MODIFY] `app/Services/Audit/Pipeline/GeminiResponseParser.php`

- `validateAndRehydrate()`, líneas observadas 103-110: añadir al array de retorno, solo si `DocumentExtractionContractBuilder::contractDeclaresUnmatchedArticles($contract)`:

```php
            'unmatched_articles'  => $this->sanitizeUnmatchedArticles($decoded),
```

- Nuevo método privado `sanitizeUnmatchedArticles(array $decoded): array`:
  1. Si `unmatched_articles` no existe o no es array: `Logger::warning('Gemini extraction omitted unmatched_articles', [...])` sin contenido clínico y devuelve `[]`.
  2. Conserva solo valores `string`, aplica `trim`, descarta vacíos.
  3. Deduplica comparando `mb_strtoupper` y conserva la primera aparición (orden original).
  4. Trunca a `DocumentExtractionContractBuilder::UNMATCHED_ARTICLES_MAX` elementos.
- `assertContractCompleteness()` no se modifica (D-04).

#### [MODIFY] `app/Services/Audit/Pipeline/DocumentNormalizer.php`

- `normalize()`, líneas observadas 167-179: añadir la llamada
  `$unmatchedArticles = $this->normalizeUnmatchedArticles($extraction['unmatched_articles'] ?? [], $fieldValueTypes, $normalizationLog);`
  y la clave `'unmatched_articles' => $unmatchedArticles` en el retorno.
- Nuevo método privado `normalizeUnmatchedArticles(mixed $names, array $fieldValueTypes, array &$log): array`:
  1. Si `$names` no es array, devuelve `[]`.
  2. Localiza en `$fieldValueTypes` el campo con tipo `AuditFieldValueType::ARTICLE_NAME`. Si no existe, devuelve `[]` y registra `unmatched_articles_without_article_field`.
  3. Para cada valor `string`, aplica `normalizeEvidenceScalar($articleField, AuditFieldValueType::ARTICLE_NAME, $value)` (la misma normalización que reciben los ítems, D-09) y descarta `null` y cadenas vacías.
  4. Deduplica conservando el orden y registra `unmatched_articles_normalized` con `count`.
- `handle()`, líneas observadas 104-114: añadir `'unmatched_articles' => $normalized['unmatched_articles'] ?? [],` al payload publicado.

#### [MODIFY] `app/Services/Audit/Pipeline/FieldValueResolver.php`

- Nuevo método público estático:

```php
    /**
     * Suma nombres de artículos visibles no coincidentes a la resolución documental de un campo article_name.
     *
     * @param array<int,string> $unmatchedNames
     */
    public static function mergeDocumentArticleNames(
        ResolvedAuditValue $base,
        array $unmatchedNames,
        AuditFieldValueType $valueType
    ): ResolvedAuditValue {
        if ($unmatchedNames === [] || $valueType !== AuditFieldValueType::ARTICLE_NAME || $base->ambiguous) {
            return $base;
        }

        $values = self::uniqueSortedValues(array_merge($base->values, $unmatchedNames));
        $meta = $base->evidenceMeta + ['unmatched_articles' => array_values($unmatchedNames)];

        return self::resolvedValue(ResolvedAuditValue::SOURCE_DOCUMENT, $valueType, implode(', ', $values), $values, false, $meta);
    }
```

#### [MODIFY] `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`

- `evaluate()`, línea observada 123: añadir
  `$unmatchedArticles = array_values(array_filter((array) ($normalizedPayload['unmatched_articles'] ?? []), 'is_string'));`
  y pasarlo a `evaluateDataFields(...)` como último argumento.
- `evaluateDataFields()` (224-256) y `evaluateSingleDataField()` (258-328): nuevo parámetro `array $unmatchedArticles = []`.
- `evaluateSingleDataField()`, línea observada 275. Antes:

```php
        $docResolution = FieldValueResolver::resolveDocumentValue($canonicalField, $valueType, $fields, $items);
```

Después:

```php
        $docResolution = FieldValueResolver::mergeDocumentArticleNames(
            FieldValueResolver::resolveDocumentValue($canonicalField, $valueType, $fields, $items),
            $unmatchedArticles,
            $valueType
        );
```

- **Enforcement Anti-Falso Positivo (D-11) en `DocumentPolicyEngine.php`**:
  - En `evaluateSemanticField()`: Si el valor evaluado proviene de `unmatched_articles` (verificable vía `in_array($docValue, $context['unmatched_articles'] ?? [], true)` o metadatos de resolución), se omiten los atajos de similitud difusa (`containsNormalizedSubstring` y `similarity >= threshold`). Solo se acepta coincidencia exacta de tokens (`sameTokenSet`), derivando cualquier discrepancia de nomenclatura directamente a `SemanticMatchJudge`.
  - En `evaluateArticleSetField()`: En la Fase 1.5 (`tokenSortedJaccard`) y en la Fase 2 (`similarity`), los índices `$di` de `$docArticles` que correspondan a nombres provenientes de `unmatched_articles` quedan expresamente excluidos de emparejarse por similitud difusa. Deben avanzar sin emparejar hasta la Fase 3 (`SemanticMatchJudge`), garantizando que la equivalencia médica sea validada por el modelo y no por proximidad ortográfica de la presentación o dosis.

- `evaluateArticleSetField()`, líneas observadas 921-931: el detalle añade los artículos visibles. Después:

```php
            'detalle' => sprintf(
                "En '%s', el documento soporte no contiene evidencia para %d de %d artículos de la dispensación: '%s'. Artículos visibles en el documento: '%s'.",
                $humanField,
                count($unmatchedNames),
                count($fdvArticles),
                implode(', ', $unmatchedNames),
                implode(', ', $docArticles)
            ),
```

- `evaluateSemanticField()` no cambia: su detalle ya cita el razonamiento del juez o ambos valores (1119-1134).

#### [MODIFY] Tests — ver sección 16.

### 14. Plan de Migración

#### Prerequisitos

- Suite PHPUnit verde en la rama.
- Sin variables de entorno nuevas (D-08): no se requiere `gh variable set`.

#### Ejecución

1. PR → `CI - AudFact` → `Publish Images - AudFact` → `Deploy Production - AudFact`.
2. Re-auditar `D66261005571` por `/audit/single`.

#### Validaciones Previas

- Línea base de señales 19-S1..S4 de los 7 días previos (consulta de `Hallazgos` y telemetría).

#### Validaciones Posteriores

- V-01 y señales de la sección 19 a las 24 h y a los 7 días.

#### Rollback

Revertir el commit y redesplegar con el SHA anterior (procedimiento estándar de `audfact-production-ops`). Sin limpieza de datos (sección 9).

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Todos los artículos coinciden | `unmatched_articles = []`; resultado igual al actual | T-08 |
| Ninguno coincide (caso D66261005571) | Valor documental = nombres no coincidentes; juez semántico decide; no-match → `NO_CONCLUYENTE` con ambos textos | T-10, V-01 |
| 2 de 3 coinciden, 1 visible distinto | Set: cobertura suryectiva; el FDV no cubierto se compara con el visible distinto; detalle lista los visibles | T-11 |
| Filtro de Gemini descartó un nombre comercial equivalente | Juez semántico empareja → `COINCIDE` | T-09 |
| Gemini omite `unmatched_articles` | Parser devuelve `[]` y registra warning; comportamiento actual | T-06 |
| Gemini devuelve > 10 nombres, duplicados, vacíos o no-string | Truncado a 10, únicos, sin vacíos | T-04, T-05 |
| Documento no conforme (`matches_expected_type = false`) | Corto circuito existente (`DocumentPolicyEngine.php:61-103`); la lista se ignora | T-12 |
| Ambas listas vacías y el documento sí tiene artículos | Gemini afirma que no hay artículos visibles → `NO_ENCONTRADO` (comportamiento actual) | Fuera de alcance (Excluido 3) |
| Evento `document_normalized` en vuelo sin la clave | `?? []` → comportamiento actual | T-13 |
| Valor documental `items` ambiguo | Merge no altera una resolución ambigua | T-08b |

### 16. Testing

#### Nuevos Tests

| ID | Archivo | Objetivo | Precondiciones | Pasos | Resultado esperado |
| --- | --- | --- | --- | --- | --- |
| T-01 | `DocumentExtractionContractBuilderTest` | Schema declara la sección | Campo items `NombreArticulo` tipo `article_name` | `build()` | `properties.unmatched_articles` con `maxItems=10`, en `required`, ordenado tras `items` |
| T-01b | `DocumentExtractionContractBuilderTest` | Sin artículo no hay sección | Items solo `CodigoProducto` (`code`) | `build()` | Sin `unmatched_articles`; `contract_hash` igual al calculado sin el cambio |
| T-02 | `ExtractionPromptBuilderTest` | Prompt mantiene el filtro y pide residuales | Contrato con sección + FDV con 2 nombres | `buildUserPrompt()` | Contiene la línea de filtro existente y la línea `En \`unmatched_articles\`…maximo 10` |
| T-03 | `ExtractionPromptBuilderTest` | Sin candidatos | Contrato con sección, FDV sin nombres | `buildUserPrompt()` | Contiene `Devuelve \`unmatched_articles = []\`` |
| T-04 | `GeminiResponseParserTest` | Saneo | Respuesta con `["A"," a ","",5,"B"]` | `parse()` | `["A","B"]` |
| T-05 | `GeminiResponseParserTest` | Tope | 12 nombres distintos | `parse()` | 10 primeros |
| T-06 | `GeminiResponseParserTest` | Ausencia tolerada | Contrato con sección, respuesta sin clave | `parse()` | `unmatched_articles = []`, sin excepción |
| T-06b | `GeminiResponseParserTest` | Contrato sin sección | Contrato sin sección | `parse()` | Resultado sin clave `unmatched_articles` |
| T-07 | `DocumentNormalizerTest` | Propagación | `extraction_result.unmatched_articles=["X"]`, `fields_config` con `NombreArticulo` tipo `article_name` | `normalize()` | `unmatched_articles=["X"]` |
| T-07b | `DocumentNormalizerTest` | Normalización igual a ítems | Mismo nombre con espacios y mayúsculas distintas en `items[].NombreArticulo.valor` y en `unmatched_articles` | `normalize()` | El valor normalizado del ítem y el elemento de `unmatched_articles` son idénticos |
| T-07c | `DocumentNormalizerTest` | Sin campo de artículo | `fields_config` sin `article_name`, lista no vacía | `normalize()` | `unmatched_articles = []` y log `unmatched_articles_without_article_field` |
| T-08 | `DocumentPolicyEngineTest` | Identidad con lista vacía | Caso actual de coincidencia | `evaluate()` | Hallazgos idénticos al baseline |
| T-08b | `DocumentPolicyEngineTest` | Ambigüedad intacta | `items` ambiguo + lista no vacía | `evaluate()` | Mismo resultado que sin lista |
| T-09 | `DocumentPolicyEngineTest` | Recupera falso negativo | FDV `ACETAMINOFEN 500MG`, items `[]`, lista `["DOLEX 500MG"]`, juez doble → match | `evaluate()` | `COINCIDE` |
| T-10 | `DocumentPolicyEngineTest` | Regresión D66261005571 | FDV `QUETIAPINA 100MG C 30 TABLETA`, items `[]`, lista `["FLUOXETINA 20MG CÁPSULAS"]`, juez doble → no match | `evaluate()` | `NO_CONCLUYENTE` (no `NO_ENCONTRADO`); `valorDocumento = "FLUOXETINA 20MG CÁPSULAS"` |
| T-11 | `DocumentPolicyEngineTest` | Set parcial | FDV `{A,B}`, items `[A]`, lista `[C]`, juez no match B↔C | `evaluate()` | `NO_CONCLUYENTE`; detalle contiene `Artículos visibles en el documento:` con A y C |
| T-12 | `DocumentPolicyEngineTest` | No conforme | `matches_expected_type=false` + lista | `evaluate()` | Solo hallazgo `TIP` |
| T-13 | `DocumentPolicyEngineTest` | Evento antiguo | Payload sin clave | `evaluate()` | Igual al baseline |
| T-14 | `DocumentPolicyEngineTest` | Paridad plan/resuelto | Caso T-10 | `plan()` y `evaluateResolved()` con el resultado del candidato | Candidato semántico (QUETIAPINA, FLUOXETINA) presente en `plan()` y mismo resultado final |
| T-15 | `DocumentPolicyEngineTest` | Anti-Falso Positivo (D-11) | FDV `QUETIAPINA 100MG C 30 TABLETA`, items `[]`, `unmatched_articles = ["FLUOXETINA 100MG C 30 TABLETA"]` | `evaluate()` con juez semántico que retorna `is_match: false` | La similitud difusa (Fase 1.5/2) NO produce `COINCIDE`; el residual es evaluado por el juez y concluye en `NO_CONCLUYENTE` |

#### Tests Modificados

| Archivo | Objetivo | Cambio |
| --- | --- | --- |
| `tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php` (185-201) | Candidatos | Se mantienen las aserciones; se añade contrato con sección para T-02 |
| `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | Detalle de set de artículos | Toda aserción sobre el texto "no contiene evidencia para" se actualiza al nuevo formato |

#### Tests Eliminados

Ninguno.

#### Verificaciones Manuales

| ID | Objetivo | Precondiciones | Pasos | Resultado esperado |
| --- | --- | --- | --- | --- |
| V-01 | Caso disparador | Despliegue completado | `POST /audit/single {"disDetNro":"D66261005571"}`; leer `GET /audit/results/D66261005571` y la caché de extracción de la fórmula | Artefacto con `unmatched_articles` que contiene `FLUOXETINA 20MG CÁPSULAS`; hallazgo `NombreArticulo` de FORMULA MEDICA = `NO_CONCLUYENTE` citando FLUOXETINA y QUETIAPINA |
| V-02 | Contrato sin artículos sin cambios | Auditoría cuyo documento no tiene `article_name` en items | Comparar clave de caché antes/después | Misma clave (`cache_hit` true en re-auditoría) |

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| R-01: Gemini no puebla `unmatched_articles` | técnico | Media | Descripción en schema + instrucción en prompt; señal 19-S1; detener rollout si no aparece en V-01 |
| R-02: Tope 10 insuficiente | consistencia de datos | Baja | Truncado con log; señal 19-S3; ajustar constante |
| R-03: Sobrecoste de tokens de salida mayor al estimado | rendimiento | Media | Solo nombres, tope 10; señal 19-S2 con umbral; rollback |
| R-04: Más llamadas al juez semántico | rendimiento | Baja | Top-1 Jaccard por artículo FDV (`DocumentPolicyEngine.php:843-859`), caché 30 d; señal 19-S4 |
| R-05: Re-extracción por invalidación de caché | rendimiento | Baja | Solo afecta re-auditorías dentro del TTL de ≈7 días |
| R-06: Gemini mete en `unmatched_articles` artículos que sí coinciden | consistencia de datos | Baja | El merge re-evalúa con la cascada; un falso no-coincidente que en realidad coincide termina en `COINCIDE` |

### 18. Criterios de Aceptación

1. `vendor/bin/phpunit` termina con 0 fallos, incluidos T-01..T-15.
2. Para un contrato con `article_name` en `items`, el `response_schema` contiene `properties.unmatched_articles.maxItems = 10` y `unmatched_articles` está en `required`.
3. Para un contrato sin `article_name` en `items`, `contract_hash` es idéntico al del código previo (T-01b).
4. La re-auditoría de D66261005571 persiste para FORMULA MEDICA un hallazgo `NombreArticulo` con `resultado = "NO_CONCLUYENTE"` cuyo `valorDocumento` contiene `FLUOXETINA`.
5. Ningún artefacto nuevo de caché contiene más de 10 elementos en `unmatched_articles` (muestreo SCAN en producción a 24 h).
6. A los 7 días, la mediana de `output_tokens` por llamada de extracción de contratos con artículos sube ≤ 30 tokens respecto a la línea base.

### 19. Observabilidad

| Señal | Tipo | Antes (baseline) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
| 19-S1: proporción de hallazgos `article_name` = `NO_ENCONTRADO` en FORMULA MEDICA | Métrica (agregación `OPENJSON` sobre `Hallazgos`) | 1,0 % (500 de 48 012 en 3 días, medido 2026-11-13) | < 1,0 %; parte pasa a `NO_CONCLUYENTE`/`COINCIDE` | `Discolnet.dbo.AudDispEst.Hallazgos` | Si a 24 h sigue ≥ 1,0 % → investigar R-01 | Investigar / Detener rollout |
| 19-S2: `output_tokens` por llamada de extracción | Métrica | Medir 7 días previos (`timings.gemini_extraction`) | +≤ 30 tokens en mediana | `Hallazgos.timings.gemini_extraction` | > +60 tokens en mediana a 24 h | Rollback |
| 19-S3: documentos con `unmatched_articles` de 10 elementos | Métrica (SCAN caché) | 0 (no existe) | < 1 % de documentos con artículos | Redis `extraction:cache:v1:*` | ≥ 5 % | Investigar (ajustar tope) |
| 19-S4: `semantic_calls` por auditoría | Métrica | Medir 7 días previos (`timings.semantic_calls`) | +≤ 10 % | `Hallazgos.timings` | > +25 % a 24 h | Investigar / Rollback |
| 19-S5: log `Gemini extraction omitted unmatched_articles` | Log | 0 | < 1 % de extracciones con sección | Logs de workers de extracción | ≥ 5 % | Investigar R-01 |

### 20. Estrategia de Rollout

| Dimensión | Valor |
| --- | --- |
| Estrategia de despliegue | Directo (pipeline estándar) |
| Orden entre productores y consumidores | Simultáneo (una imagen); los consumidores toleran la ausencia de la clave |
| Coexistencia entre versiones | Sí — eventos en vuelo durante el redeploy (minutos) |
| Compatibilidad requerida durante rollout | Ambas |
| Condición para avanzar rollout | V-01 cumple el criterio 4 |
| Condición para detener rollout | V-01 falla o 19-S5 ≥ 5 % en la primera hora |
| Condición de rollback | 19-S2 > +60 tokens en mediana o 19-S4 > +25 % a 24 h |
| Acción de rollback | Redeploy del SHA anterior vía `Deploy Production - AudFact` |
| Tiempo máximo para iniciar rollback | 1 h tras detectar la condición |
| Responsable de decisión | Responsable técnico de AudFact (pendiente de confirmación) |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes mencionadas por la especificación están definidas | PASS | Sección 9 (Redis caché; `AudDispEst.Hallazgos` sin cambio) |
| Todas las columnas mencionadas existen | PASS | `AudDispEst.Hallazgos`, `FacNro`, `FechaActualizacion` leídas en la medición |
| Todos los contratos documentados con clasificación | PASS | 10.a, 10.b, 10.c |
| Todos los requisitos tienen trazabilidad | PASS | Sección 11 |
| Todos los consumidores analizados | PASS | 0.2 |
| Todas las migraciones tienen rollback | PASS | Sección 9 y 14 |
| Todas las referencias están definidas | PASS | Símbolos nuevos definidos en 13 (`UNMATCHED_ARTICLES_MAX`, `itemsDeclareArticleName`, `contractDeclaresUnmatchedArticles`, `sanitizeUnmatchedArticles`, `mergeDocumentArticleNames`) |
| Toda compatibilidad tiene evidencia | PASS | 0.3 y 10.c (`?? []`) |
| Todos los criterios son verificables | PASS | Sección 18 |
| Observabilidad documentada | PASS | Sección 19 |
| Rollout documentado | PASS | Sección 20 |

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | No | D-01..D-08 |
| ¿Existe algún contrato sin documentar? | No | 10.a-10.c cubren schema, artefacto y evento |
| ¿Existe algún consumidor no analizado? | No | 0.2; grep `normalized_result`, `items_normalized` |
| ¿Existe alguna migración sin rollback? | No | 9, 14 |
| ¿Existe algún dato persistido sin migración? | No | Caché invalidada por hash (9) |
| ¿Existe alguna afirmación sin clasificar o con certeza superior a su evidencia? | No | `maxItems` marcado `[INFERIDO]` (0.4) |
| ¿Existen referencias huérfanas? | No | FASE 2 |
| ¿Queda alguna decisión sin registrar o cambio con más de una interpretación? | No | Sección 13 con fragmentos antes/después |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | Entrypoints que invoquen algo eliminado/renombrado | Runtime | NO | No se elimina ni renombra ningún símbolo (13) |
| 2 | Cadena de build dependiente | Build | NO | Sin dependencias nuevas de Composer |
| 3 | Pipeline que valide con otro flujo | Pipeline | NO | CI ejecuta PHPUnit; sin pasos que lean el schema (grep `.github/`) |
| 4 | Semántica de herramienta sin verificar | Semántica de Herramienta | SÍ-CORREGIDO | `maxItems` `[INFERIDO]` → truncado determinista en parser (D-03) |
| 5 | Validado en un solo entorno | Paridad de Entornos | NO | 0.5 |
| 6 | Override en runtime | Runtime por Override | NO | `GEMINI_EXTRACTION_*` solo afecta `generationConfig` (`DocumentExtractionWorker.php:568-571`), no el schema |
| 7 | Best practice sin contrastar | Dogmatismo Técnico | NO | Se reutiliza la cascada semántica existente (`DocumentPolicyEngine.php:779-932`) |
| 8 | Interfaz pública alterada sin compatibilidad | Contract | SÍ-CORREGIDO | Evento `document_normalized` con lectura `?? []` (10.c) |
| 9 | Datos persistidos sin migración | Data | SÍ-CORREGIDO | Caché: clave nueva por hash; antiguas expiran (9) |
| 10 | Código muerto / compatibilidad especulativa | Clean Architecture | NO | La sección solo existe con consumidor demostrado (motor de reglas) |
| 11 | Mapeo estático → abstracción dinámica | Abstracción Incorrecta | NO | No aplica (triage) |

## FASE 4 — Resultado Final

### Nivel de Completitud

`Nivel A — Implementable`. Condición que lo fija: solo supuestos S2 en estado `Mitigado` (S2-01..S2-04, con señal y condición de detención en 19 y 20), 0.7 y 0.8 sin elementos abiertos, FASE 2 en `PASS`, FASE 3 en `No` y adversarial en `NO`/`SÍ-CORREGIDO`.

### Decisiones pendientes de un responsable externo

- ~~Confirmar D-06~~: resuelta el 2026-11-13 — se mantiene `NO_CONCLUYENTE`.
- Responsable de la decisión de rollback (sección 20).
