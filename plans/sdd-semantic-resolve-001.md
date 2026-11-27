# SDD — Orden determinista de candidatos en la resolución semántica (fix de `semantic_result_missing`)

- **ID:** SDD-SEMANTIC-RESOLVE-001
- **Versión:** 3 (2026-11-25). Incorpora la evaluación independiente de la versión 2 (hallazgos EV2-01 a EV2-13) y sustituye a las versiones 1 y 2.
- **Estado:** implementada y verificada de forma independiente en la rama `fix/sdd-semantic-resolve-001`; aceptada por el usuario el 2026-11-25. Integrada en `staging` local sin push. Pendiente Q-1 (consumidores externos de `detalle`), V-1, V-3 y V-4 antes del despliegue.
- **Rama sugerida:** `fix/sdd-semantic-resolve-001`, desde `staging` (ff41b74).
- **Spec hermana:** `plans/sdd-identidad-molecular-cobertura-articulos.md` (SDD-ARTICLE-MOLECULE-001, creada en la misma entrega). Esta spec no depende de ella.
- **Nivel de completitud:** Nivel C — Diseño Parcial (FASE 4).

## Clasificación del Cambio (Triage)

| Dimensión | Valor |
| --- | --- |
| Tipo | Bug |
| Riesgo | Alto — cambia el orden de consulta de pares y, en un caso acotado, el motivo (`detalle`) de un hallazgo persistido `[CONFIRMADO]` (sección 10) |
| Persistencia afectada | El plan v2 no cambia. El texto `detalle` de un hallazgo persistido puede cambiar en la condición de la sección 10 |
| Contrato externo afectado | No en formato; sí en contenido de `detalle` bajo la condición de la sección 10 (consumidores externos `[DESCONOCIDO]`) |
| Cambio arquitectónico | No |
| Producción afectada | Sí — servicio `worker-policy` (`docker-compose.yml`, líneas observadas 276-291) |
| Requiere 0.3.1 | No |

Perfil reducido: no elegible (Producción afectada = Sí y Riesgo Alto).

---

## FASE 0 — Descubrimiento

### 0.1 Perímetro de impacto

| Archivo | Ruta | Categoría | Propósito | Líneas leídas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| Motor de política | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | MODIFIED | Fase 3 de `evaluateArticleSetField` (método en 785-957; bloque de la Fase 3 en 852-925), `plan`, `evaluateResolved`, `rankUnmatchedCandidates` | 34-47, 785-957, 1089-1138 | Sí |
| Resolutor resuelto | `app/Services/Audit/ResolvedSemanticResolver.php` | IMPACTED | Origen de `semantic_result_missing` (línea 37) | 1-98 | Sí |
| Recolector | `app/Services/Audit/SemanticCandidateCollector.php` | IMPACTED | Plan; expone `identity()`, usada por el resolutor (línea 31) | 1-38 | Sí |
| Store del plan | `app/Services/Audit/Pipeline/SemanticEvaluationStore.php` | IMPACTED | `buildPlan`, `advance`, `waves`, `results` | 45-206 | Sí |
| Worker de reglas | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | IMPACTED | Pasa `SemanticEvaluationStore::results` al motor (línea 204); parche `semantic_pairs` (línea 255) | 185-300 | Sí |
| Runner de tareas | `app/Services/Audit/Pipeline/AuditTaskRunner.php` | INSPECTED | `DomainException` es falla permanente con DLQ (65-104); log `Audit task operation failed` (101-104) | 55-110 | Sí |
| Committer | `app/Services/Audit/Pipeline/AuditTaskCommitter.php` | INSPECTED | Borra el plan al consumirlo | 162-175 | Sí |
| Mantenimiento | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php` | INSPECTED | GC de planes de auditorías terminales | 583-603 | Sí |
| Resumen de tiempos | `app/Services/Audit/Pipeline/AuditTimingSummarizer.php` | INSPECTED | Agrega `semantic_pairs` y `semantic_calls` (46, 67-70) | 20-83 | Sí |
| Persistencia | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | INSPECTED | Guarda `timings` dentro del JSON `Hallazgos` (342-365) | 336-365 | Sí |
| Normalización | `app/Services/Audit/TextNormalization.php` | INSPECTED | `normalizeArticleName`, `tokenSortedJaccard` | 56-112 | Sí |
| Resolutor de valores | `app/Services/Audit/Pipeline/FieldValueResolver.php` | INSPECTED | Deduplica y ordena artículos antes del motor | 145-157, 276-288, 461-477 | Sí |
| Test del motor | `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | MODIFIED | Tests nuevos | línea base 78 tests / 269 aserciones | Sí |
| Test del store | `tests/Services/Audit/Pipeline/SemanticEvaluationStoreTest.php` | INSPECTED | Filas y olas | línea base 4 tests / 18 aserciones | Sí |
| Tests que instancian el motor | `tests/Integration/SemanticExecutionRedisTest.php` (77), `tests/Services/Audit/Pipeline/DocumentAuditOrchestratorExecutionTest.php` (49), `tests/Services/Audit/GoldenSetReplayTest.php` (76, 105, 145) | IMPACTED | Ejecutan el motor sin juez | fragmentos | Sí |
| Spec previa de prioridad | `plans/sdd-optimizacion-homologacion-semantica-evidencia.md` | MODIFIED | INV-3 (líneas 33-36) se enmienda en una línea (sección 13) | 12-40 | Sí |
| Spec previa N:1 | `plans/sdd-semantic-article-matching-fix.md` | INSPECTED | Cambio 3: cobertura N:1 | 187-229 | Sí (fragmento) |
| Workflow de despliegue | `.github/workflows/deploy-production.yml` | INSPECTED | Modo `deploy` y modo `rollback` | 4-32, 113-157, 164, 427, 614-714 | Sí |
| Compose | `docker-compose.yml` | INSPECTED | `worker-policy` monta solo `./logs` | 276-291 | Sí |

#### Criterio de cierre del perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Símbolo | `rankUnmatchedCandidates` en `app tests` | 3 apariciones, todas en `DocumentPolicyEngine.php` (866, 900, 1089); 0 en tests | `git grep -n` |
| Símbolo | `new DocumentPolicyEngine` en `app bin` | 1: `RulesEvaluationWorker.php:52`, sin juez | `git grep -n` |
| Símbolo | `new DocumentPolicyEngine` en `tests` | 3 archivos (tabla anterior) | `grep -n` |
| Símbolo | `SemanticMatchJudge(` en `app bin` | 1: `SemanticEvaluationWorker.php:94`, fuera del motor | `git grep -n` |
| Importación | `ResolvedSemanticResolver`, `SemanticCandidateCollector` | motor y definiciones; el resolutor usa `SemanticCandidateCollector::identity` | lectura |
| Textual | `semantic_result_missing` | 1 origen: `ResolvedSemanticResolver.php:37` | `grep -rn` |
| Textual | `rankUnmatched`, `asignad`, `prioridad` en `plans/` | 2 specs y el changelog | `git grep -n -i` |
| Configuración | servicio `worker-policy` | `docker-compose.yml:276-291`, comando `php bin/audit-worker.php policy` | lectura |
| Tests | tests que ejecutan `evaluate` o `plan` | tres rutas de la tabla y dos archivos de línea base | lectura |
| Workflows/CI | `phpunit`, job con Redis, modos del despliegue | `ci.yml` 106, 131-149, 159-165; `deploy-production.yml` 113-157, 614-714 | lectura |

Universo inspeccionado: repositorio local en `staging` (ff41b74). Sin acceso al inventario de consumidores externos ni a producción.

### 0.2 Grafo de dependencias acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `evaluateResolved` | `SemanticEvaluationStore::results` (lo pasa el worker) | `RulesEvaluationWorker.php` | 204 | Directa | Estática | Repositorio local |
| `plan` | `RulesEvaluationWorker` | `RulesEvaluationWorker.php` | 222 | Directa | Estática | Repositorio local |
| `ResolvedSemanticResolver` | `SemanticCandidateCollector::identity` | `ResolvedSemanticResolver.php` | 31 | Directa | Estática | Repositorio local |
| `DomainException` | `AuditTaskRunner` (falla permanente, DLQ) | `AuditTaskRunner.php` | 65-104 | Directa | Operacional | Repositorio local |
| Plan consumido | `AuditTaskCommitter` (lo borra) | `AuditTaskCommitter.php` | 162-175 | Directa | Operacional | Repositorio local |
| Planes de auditorías terminales | `AuditExecutionMaintenance` (GC) | `AuditExecutionMaintenance.php` | 583-603 | Transitiva | Operacional | Repositorio local |
| `evaluate` | tests de motor, integración y golden set | tres archivos | 77, 49, 76/105/145 | Directa | Estática | Repositorio local |
| `detalle` del hallazgo | persistencia (`AudDispEst.Hallazgos`) y exportes | `AuditPersistenceWorker.php` | 342-365 | Transitiva | Contractual | Cliente externo `[DESCONOCIDO]` |

### 0.3 Análisis de impacto inverso

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Congelar el estado de asignación en la Fase 3 | INV-3 de la spec previa (nivel 2 con `$matchedFdv` dinámico) | `plans/sdd-optimizacion-homologacion-semantica-evidencia.md:33-36` | Contract (entre specs) | Enmienda de una línea (sección 13); el significado dinámico solo existía en la reproducción defectuosa (SUP-A cerrado) |
| Ídem | `detalle` de filas agotadas: `??=` conserva el primer razonamiento no nulo | `DocumentPolicyEngine.php:921, 942-955` | Data (texto persistido) | Decisión P-1; T5 fija el valor aprobado |
| Ídem | Orden y número de consultas con juez directo (solo tests) | `DocumentPolicyEngine.php:896-923` | Test | Las suites de la matriz 0.1 se ejecutan completas; no existe llamador de producción con juez |
| Ídem | Planes ya almacenados | `audit:semantic:v2:*` | Data | Sin cambio (INV-2) |
| Reversión de imagen | Planes con prefijo parcial | `worker-policy` | Runtime | Con el motor anterior vuelven a fallar; sección 14 |

### 0.3.1 No aplica

`[CONFIRMADO]`: el cambio no reemplaza un mapeo estático por una abstracción dinámica.

### 0.4 Semántica de herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| PHP `arsort` | Ordenación estable desde PHP 8.0 | Documental | Documentación oficial de PHP 8.0 (cambio «sorting is now stable»); entorno PHP 8.2.12 `[CONFIRMADO]` con `php -v` | Sí |
| Store de plan | `advance` corta la fila en el primer par con `is_match=true`; los pares siguientes quedan sin resolver | Estática | `SemanticEvaluationStore.php:139-157` | Sí |
| Resolutor | Busca por `pair_id`, luego por firma canónica; si falta, lanza | Estática | `ResolvedSemanticResolver.php:29-65` | Sí |
| `AuditTaskRunner` | `DomainException` es permanente, sin reintento | Estática | `AuditTaskRunner.php:65-68` | Sí |
| Workflow `deploy-production.yml` | Modo `rollback` ejecuta `ctl rollback-batch-v2` antes de cambiar la imagen; aborta con jobs o reservas activos | Estática | `deploy-production.yml:113-157`; `BatchJobStore::rollbackBatchV2()` | Sí con el modo `deploy` (ver sección 14); No con el modo `rollback` |

### 0.5 Matriz de entornos de ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Docker local con `staging` | `worker-policy` | Sí | Reproducción con el código de `staging` (T1 y T2 de la sección 16) |
| CI automatizado | GitHub Actions | `vendor/bin/phpunit` y job con Redis real | Sí en el commit actual; el cambio exige ejecutar ambos | `ci.yml:106, 131-149, 159-165` |
| Staging | rama `staging` | igual que local | Sí | ff41b74 |
| Producción | LAN, `deploy-production.yml`, imagen por tag | `worker-policy` | `[DESCONOCIDO]` en configuración efectiva | Versionado: el workflow copia `docker-compose.yml` al destino (`:164`) y `worker-policy` solo monta `./logs` (`docker-compose.yml:287-288`); falta comprobar la existencia de un `docker-compose.override.yml` en el host (SUP-D) |
| Testing aislado | PHPUnit sin Redis | `phpunit` | Sí | Línea base verde |

### 0.6 Inventario de información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| El plan recorre cada fila en el orden del recolector y el store corta en el primer positivo | `[CONFIRMADO]` | `SemanticEvaluationStore.php:104-110, 139-157` |
| En la evaluación resuelta, `$matchedFdv` crece con cada positivo y el ranking de la fila siguiente cambia | `[CONFIRMADO]` | `DocumentPolicyEngine.php:896-923, 1095-1135` |
| El motor de producción se construye sin juez; el recolector nunca devuelve positivos | `[CONFIRMADO]` | `RulesEvaluationWorker.php:52`, `SemanticCandidateCollector.php:31`, búsqueda `SemanticMatchJudge(` (0.1) |
| Por lo anterior, el nivel 2 de INV-3 observado en producción siempre significó «asignados en Fases 1, 1.5 y 2»; el significado dinámico solo existió en la reproducción defectuosa y en la ruta del juez directo (solo tests) | `[CONFIRMADO]` | Conclusión de las tres filas anteriores |
| Para que falle hacen falta: al menos dos filas huérfanas, al menos dos artículos documentales primarios (los de `unmatched_articles` forman el nivel 3 y no se reordenan), un positivo en una fila anterior sobre un documento que es nivel 1 de una fila posterior, y esa fila posterior con prefijo truncado por su propio positivo | `[CONFIRMADO]` | `DocumentPolicyEngine.php:1095-1135`; T1, T2 y la matriz de T4 (sección 16); en los 5 planes del incidente hay un documento coincidente con más de un artículo |
| Los 5 planes del incidente lanzan la excepción al evaluarlos en el worker local | `[CONFIRMADO]` | Sonda de solo lectura del orquestador, 5 de 5 |
| El resultado por fila es existencial; con veredictos booleanos fijos y completos, el resultado por campo no cambia | `[CONFIRMADO]` | `SemanticEvaluationStore.php:139-157`; T4 y T5 verifican 0 cambios de resultado |
| El `detalle` depende del orden, solo para filas agotadas en la condición de la sección 10 | `[CONFIRMADO]` | `DocumentPolicyEngine.php:921, 942-955`; T5 |
| Frecuencia en producción | `[DESCONOCIDO]` | Muestra local: 5 fallos en unas 100 auditorías del job `c178c4b6-806e-4109-9657-3b4ac1ab9456` |
| Planes reales de los 5 fallos siguen en Redis | `[INFERIDO]` que pueden haberse borrado | El committer borra el plan consumido y el GC los de auditorías terminales (583-603); TTL no medido |
| `LOG_LEVEL` efectivo en producción | `[DESCONOCIDO]` | El despliegue escribe `LOG_LEVEL=${LOG_LEVEL:-warning}` (`deploy-production.yml:427`); el valor de `vars.LOG_LEVEL` no se conoce |

### 0.7 Información faltante crítica

Ninguna. P-1 y P-2 de la versión 2 no son incertidumbres técnicas: P-2 se cerró con evidencia de código (SUP-A) y P-1 es una decisión de un responsable externo (FASE 4).

### 0.8 Información faltante importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Consumidores externos del texto `detalle` (Q-1) | Sin inventario | Determina si el cambio de motivo afecta a terceros |
| Frecuencia en producción | Sin muestra | Define el umbral de la señal S-1 |
| Valor de `vars.LOG_LEVEL` y existencia de un `docker-compose.override.yml` en el host de producción | No inspeccionado | Define la señal S-1 por logs y la paridad de producción |

### 0.9 Información faltante opcional

Ninguna.

### 0.10 Supuestos declarados

| ID | Supuesto | Base | Consecuencia si es Falso | Severidad | Estado | Mitigación y Detección | Condición de Cierre |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SUP-A | CERRADO: el nivel 2 de INV-3 siempre significó «asignados en Fases 1, 1.5 y 2» en producción | Evidencia de código (0.6) | No aplica | No aplica | Cerrado | No aplica | Cumplida |
| SUP-B | El texto `detalle` de filas agotadas no tiene consumidores externos | `[DESCONOCIDO]` | Cambia criterios de aceptación y señales (secciones 18-19) | S2 | Abierto | T5; verificación en staging V-3; condición de detención en sección 20 | Respuesta a Q-1 |
| SUP-C | La frecuencia local es representativa de producción | `[INFERIDO]` | Cambia el umbral de S-1 | S2 | Mitigado | R-3 (sección 17); S-1 con umbral absoluto 0 (sección 19); condición de detención (sección 20); acción documentada en la sección 14 | Conteo en producción durante 7 días antes del despliegue |
| SUP-D | No existe un `docker-compose.override.yml` en `DEPLOY_PATH` de producción | `[DESCONOCIDO]` | Cambia la paridad de producción (adversarial 6) | S2 | Abierto | V-4 (comprobación con `ls` en el host); condición de detención en sección 20 | Resultado de V-4 |

### 0.11 Clasificación de completitud inicial

Nivel C: las filas `FAIL` de FASE 2 (consumidores externos, compatibilidad de producción) y la pregunta adversarial 6 en `DESCONOCIDO` fijan C (condición 2 de «Determinación del Nivel»). SUP-B y SUP-D (S2 abiertos) limitarían a B.

---

## FASE 1 — Especificación

### 1. Objetivo

- **Problema actual.** Auditorías con varios artículos de la dispensación fallan en la etapa `policy` con `DomainException: semantic_result_missing`. Muestra local: 5 fallos de la etapa `policy` entre unas 100 auditorías procesadas del job `c178c4b6-806e-4109-9657-3b4ac1ab9456` (cliente 2624).
- **Causa raíz `[CONFIRMADO]`.** El plan se construye con un recolector que nunca registra positivos; el ranking de cada fila usa solo las asignaciones de las Fases 1, 1.5 y 2. La evaluación final agrega al mapa de asignación cada positivo de la Fase 3 y el ranking de las filas siguientes cambia. Una fila pide entonces un par que el plan recolectó pero dejó sin veredicto, porque `advance` cortó su fila en el primer positivo. El resolutor lanza `semantic_result_missing`. Las condiciones necesarias están en 0.6.
- **Impacto actual.** La auditoría pasa a `failed` en `policy` con DLQ. Reintentar no ayuda (`AuditTaskRunner.php:65-68`).
- **Resultado esperado.** Para un plan `ready` generado con el mismo snapshot, cada par solicitado por `evaluateResolved` tiene veredicto y pertenece al prefijo resuelto de su fila.
- **Por qué existe.** Bloquea auditorías válidas con varias presentaciones de un medicamento.

### 2. Alcance

#### Incluido

- Congelar el estado de asignación usado por `rankUnmatchedCandidates` en la Fase 3.
- Tests de regresión y de caracterización.
- Enmienda de una línea de INV-3 en la spec previa y entradas de changelog.

#### Excluido

- Cambios en `ResolvedSemanticResolver`, `SemanticCandidateCollector`, `SemanticEvaluationStore`, `RulesEvaluationWorker`.
- La regla de identidad molecular (spec hermana).
- Reprocesar auditorías ya fallidas (terminales; el despliegue no las rehabilita; `POST /audit/dlq/reprocess` no se evaluó).
- Errores `GEMINI_RESPONSE_PROVIDER_BLOCKED` y `gemini_operation_failed`.
- Mostrar `error_message` en `/audit/status/{id}`.
- Retirar las rutas sin llamador de producción (juez directo y batch top-1); se registra como seguimiento de `clean-rebuild-policy`.

### 3. Non Goals

- Relajar el resolutor para tolerar pares faltantes.
- Introducir exclusión 1:1.
- Cambiar umbrales de similitud, Jaccard o el contrato Gemini.

### 4. Estado Actual

1. `plan()` (líneas 34-40) ejecuta `evaluate()` con `SemanticCandidateCollector`. Como `tryBatchSemanticMatch` exige un `SemanticMatchJudge` (línea 1002), la Fase 3 usa el bucle individual y recolecta todos los candidatos de cada fila con el `$matchedFdv` de inicio de fase.
2. El store arma filas y resuelve en orden hasta el primer positivo (139-157).
3. `evaluateResolved()` (42-47) vuelve a evaluar con `ResolvedSemanticResolver`; en el bucle individual (896-923) cada positivo modifica `$matchedFdv` y la fila siguiente recalcula el ranking (1095-1135).

Fragmento actual (líneas 896-923, abreviado):

```php
foreach ($fdvArticles as $fi => $fdvOriginal) {
    if (isset($matchedFdv[$fi])) { continue; }
    $candidates = $this->rankUnmatchedCandidates($fdvOriginal, $docArticles, $unmatchedSet, $matchedFdv);
    foreach ($candidates as $di => $jaccardScore) {
        $judgeResult = $this->semanticJudge->evaluate($fdvOriginal, $docArticles[$di], /* contexto */);
        if ($judgeResult['is_match']) { $matchedFdv[$fi] = $di; break; }
        $lastReasoningByFdv[$fi] ??= $judgeResult['reasoning'] ?? null;
    }
}
```

### 5. Estado Objetivo

- `evaluateArticleSetField` copia `$matchedFdv` al entrar en la Fase 3 (`$assignedBeforeSemantic`) y la usa en las dos llamadas a `rankUnmatchedCandidates`.
- El orden de candidatos de cada fila depende solo del snapshot (INV-1).
- El resto de componentes no cambia.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| D-1 | Cambio común: congelar el estado de asignación para todos los resolutores | (a) Congelar solo al consumir un plan v2. (b) Persistir filas, cursores y veredictos como autoridad del replay | (a) añade una bifurcación para una ruta sin llamadores de producción. (b) cambia la firma `evaluateResolved(snapshot, results)` o el contrato store-motor y duplica la lógica de cobertura; se reserva si el ranking evoluciona |
| D-2 | No relajar el resolutor | Tratar el par ausente como `false` | Convierte un error visible en un `INCONCLUSIVE` silencioso |
| D-3 | No resolver todos los pares de todas las filas | Producto cartesiano filas × documentos | Pierde el corte por positivo y multiplica los pares y las llamadas |
| D-4 | Sin cambios en el plan ni en `plan_id` | Subir versión del plan | El productor del plan no cambia |
| D-5 | El motivo del hallazgo en filas agotadas es el primer razonamiento no nulo en el **orden planificado** (comportamiento natural de `??=`) | Conservar el motivo previo mediante una derivación explícita | Es la opción de menor código. Aprobada por el usuario el 2026-11-25 (P-1) |
| D-6 | Enmendar INV-3 de la spec previa en una línea | Dejar la spec previa sin enmienda | Evita un conflicto documental entre specs |

#### 6.1 Estrategia de Cambio y Excepciones

| Dimensión | Valor |
| --- | --- |
| Estrategia | Refactorización incremental |
| Fila de la compuerta aplicada | Componente productivo con persistencia y consumidores activos |
| Candidatas descartadas y desempate | Reconstruir el planificador (alcance y riesgo); Parche excepcional no aplica |
| Fuente del alcance MVP | Solicitud del usuario del 2026-11-24 tras el diagnóstico, y su decisión «Sí, divide en dos specs» y «Sí, aplica las correcciones y deja la spec en versión 3» |

Excepciones: `Ninguna`.

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| PHP | runtime | 8.2.12 local `[CONFIRMADO]`; `composer.json` sin restricción | Ninguno |
| PHPUnit | librería | `^10.0` en `composer.json` | Tests nuevos |
| Redis | base de datos | sin cambios | Solo lectura en V-1 |

#### 7.1 Fuentes de verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
| Orden de candidatos de la Fase 3 | Código | `DocumentPolicyEngine.php:862-876, 896-923, 1089-1138` | No |
| Prioridad en tres niveles | Spec `sdd-optimizacion-homologacion-semantica-evidencia.md`, INV-3 | líneas 33-36 | Sí, resuelto con D-6 y SUP-A cerrado |
| Cobertura N:1 | Spec `sdd-semantic-article-matching-fix.md`, cambio 3 | líneas 187-229 | No |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| INV-1: para un snapshot, la secuencia de pares solicitada por fila en `evaluateResolved` es el prefijo resuelto de la secuencia planificada de esa fila | Congelación de `$assignedBeforeSemantic` | T1, T2, T4 |
| INV-2: el plan (pares y su orden por fila) no cambia | Sin cambios en recolector ni store | T3 |
| INV-3 (spec previa, enmendada): el nivel 2 son los primarios asignados en las Fases 1, 1.5 y 2 | `rankUnmatchedCandidates` con el estado de inicio de fase | Tests existentes |
| INV-4: el resultado por campo no cambia ante veredictos booleanos fijos y completos | Resultado existencial por fila | T4, T5 |

### 9. Modelo de Datos

`[CONFIRMADO] Sin impacto en la estructura del plan v2`: el plan generado es idéntico antes y después (T3). El texto `detalle` de un hallazgo persistido puede variar en la condición de la sección 10; no hay migración. Script de cambio y rollback de datos: `N/A`.

### 10. Contratos

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno (plan v2) y resultado de política persistido |
| Visibilidad | Interno / Persistente |
| Productor | `RulesEvaluationWorker` (plan), `SemanticEvaluationWorker` (veredictos) |
| Consumidores | `RulesEvaluationWorker` (resolución), persistencia, exportes `[DESCONOCIDO]` |
| Versionado | `schema_version = 2` (`SemanticEvaluationStore.php:63`) |
| Compatibilidad requerida | Ambas durante el despliegue |
| Enforcement | `SemanticEvaluationStoreTest`, T3 |

Antes y después del plan: idénticos.

**Condición exacta de cambio de `detalle`.** El `detalle` cambia solo si se cumplen a la vez: la fila queda agotada (todos sus pares con veredicto negativo) y un positivo de una fila anterior movió uno de sus candidatos del nivel 1 al nivel 2 en el ranking anterior. En ese caso, el primer razonamiento no nulo (`??=`) pertenece a otro candidato. Ejemplo sintético: candidatos planificados [AXIAL, CUARZO] con razones «R-axial» y «R-cuarzo»; con el orden anterior alterado por un positivo previo, el motivo era «R-cuarzo»; con el cambio es «R-axial».

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| RQ-1 | Para un plan `ready` con el mismo snapshot, cada par pedido tiene veredicto y pertenece al prefijo resuelto de su fila | Sección 13, cambio 1 | T1, T2, T4, A-1 |
| RQ-2 | El plan no cambia | Sin cambios en recolector ni store | T3, A-4 |
| RQ-3 | El resultado por campo no cambia ante veredictos fijos | INV-4 | T4, T5 |
| RQ-4 | El motivo publicado sigue la decisión P-1 | D-5 | T5 |
| RQ-5 | Un resultado ausente o malformado sigue lanzando la excepción | Sin cambios en el resolutor | T6 (guardia) |
| RQ-6 | La recurrencia es detectable | Sección 19 | A-6 |
| RQ-7 | INV-3 de la spec previa queda coherente | Sección 13, cambio 3 | A-4 |
| RQ-8 | Toda futura modificación de la Fase 3 conserva la paridad plan-reproducción | T4 como guardia permanente | T4 |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `evaluateArticleSetField` | `rankUnmatchedCandidates` | Orden de candidatos | Congelar estado de asignación | 862-876, 896-923 |
| Hallazgo `detalle` | persistencia | Motivo puede variar (sección 10) | Decisión D-5 | 921, 942-955 |
| Spec previa de prioridad | INV-3 | Documental | Enmienda de una línea | `plans/sdd-optimizacion-…:33-36` |
| Tests de integración y golden set | motor sin juez | Ninguno esperado | Ejecutar completos | 0.1 |
| `worker-policy` | imagen por tag | Requiere rebuild | Sección 14 | `docker-compose.yml:276-291` |

### 13. Cambios por Archivo

#### `[MODIFY]` `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`

Método: `evaluateArticleSetField()` (líneas observadas 785-957); bloque de la Fase 3 en 852-925.

Antes (líneas 861-867, abreviado):

```php
$orphanPairs = [];
foreach ($fdvArticles as $fi => $fdvOriginal) {
    if (isset($matchedFdv[$fi])) {
        continue;
    }
    $candidates = $this->rankUnmatchedCandidates($fdvOriginal, $docArticles, $unmatchedSet, $matchedFdv);
```

Después:

```php
// El orden de candidatos de cada fila depende solo del estado previo a la Fase 3 (INV-1).
$assignedBeforeSemantic = $matchedFdv;
$orphanPairs = [];
foreach ($fdvArticles as $fi => $fdvOriginal) {
    if (isset($matchedFdv[$fi])) {
        continue;
    }
    $candidates = $this->rankUnmatchedCandidates($fdvOriginal, $docArticles, $unmatchedSet, $assignedBeforeSemantic);
```

Esta primera llamada es inocua: `$matchedFdv` no se modifica dentro de ese bucle. Se cambia por homogeneidad.

Antes (línea 900):

```php
$candidates = $this->rankUnmatchedCandidates($fdvOriginal, $docArticles, $unmatchedSet, $matchedFdv);
```

Después:

```php
$candidates = $this->rankUnmatchedCandidates($fdvOriginal, $docArticles, $unmatchedSet, $assignedBeforeSemantic);
```

La condición `isset($matchedFdv[$fi])` del bucle individual conserva el estado vivo. `$assignedBeforeSemantic` se declara dentro del bloque `if ($this->semanticJudge !== null)`, antes de la primera llamada. Sin otros cambios en el archivo.

#### `[MODIFY]` `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`

Tests nuevos T1 a T6 (sección 16), sin modificar los existentes.

#### `[MODIFY]` `plans/sdd-optimizacion-homologacion-semantica-evidencia.md`

Antes (línea 35):

```
  - Nivel 2: `items` primarios extraídos ya asignados en `$matchedFdv` (cobertura suryectiva N:1 para multi-lote).
```

Después:

```
  - Nivel 2: `items` primarios extraídos ya asignados en `$matchedFdv` por las Fases 1, 1.5 y 2, es decir, antes de iniciar la Fase 3 (cobertura suryectiva N:1 para multi-lote). Las coincidencias de la propia Fase 3 no reordenan los candidatos de otras filas (SDD-SEMANTIC-RESOLVE-001).
```

Fin de línea del archivo: se comprueba (CRLF o LF) antes de editar y se conserva.

#### `[MODIFY]` `CHANGELOG.md`, `plans/changelog.md`

Una entrada `fix` con la fecha de la entrega, en orden cronológico descendente.

### 14. Plan de Migración

- **Prerequisitos:** P-1 respondida; rama desde `staging`.
- **Ejecución:** 1) implementar; 2) suite completa y job con Redis real; 3) verificación independiente (agente distinto del implementador); 4) integrar en `staging`; 5) desplegar con `workflow_dispatch` `operation=deploy` y `image_tag=<SHA del commit del fix>`: el modo `deploy` pausa la admisión, detiene escritores, ejecuta `migrate-batch-v2` (idempotente), `repair-jobs` y `reconcile`, levanta el stack y reanuda (`deploy-production.yml:614-714`).
- **Validaciones previas:** suite verde (línea base: 1536 tests en total, de los cuales 160 se omiten); Q-1 respondida o V-3 ejecutada.
- **Validaciones posteriores:** criterios A-1 a A-6.
- **Rollback:** `operation=deploy` con `image_tag=<SHA anterior>`, no `operation=rollback`. El modo `rollback` ejecuta antes `ctl rollback-batch-v2` (`deploy-production.yml:113-157`), que revierte el esquema Redis v2 a v1 y aborta si hay jobs o reservas activos; revertir este fix no requiere ese paso. **Validación previa del rollback:** `git merge-base --is-ancestor 45fd44b <SHA anterior>` debe devolver 0 (la imagen destino contiene SDD-BATCH-001). Efecto: los planes con prefijo parcial vuelven a fallar con el motor anterior. Los hallazgos ya emitidos y las auditorías terminales no se modifican; su revisión es un trámite aparte.
- **Coexistencia de versiones:** el productor del plan no cambia, así que el plan es el mismo con ambas versiones. Con caché parcial el consumidor anterior falla y el nuevo termina; con caché completa ambos terminan. El despliegue reinicia `worker-policy` completo; no se mantienen réplicas de versiones mixtas.

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Dos filas con el mismo documento positivo | Sin excepción | T1 |
| Tres filas X/Y/X | Sin excepción | T2 |
| Fila con un único candidato o con un artículo en `unmatched_articles` | Sin cambio | T4 (fixture `tres_filas_dos_docs_unmatched`) |
| Fila agotada con razones distintas | Motivo según D-5 | T5 |
| Caché parcial o completa | Parcial: falla antes y termina después; completa: termina en ambas | T3 |
| Veredicto ausente o malformado | La excepción sigue lanzándose | T6 |
| Nombres duplicados | Pares deduplicados por `pair_id` | `SemanticEvaluationStoreTest`, línea 69 y siguientes |
| Textos distintos que normalizan igual | Identidades de par distintas | `FieldValueResolver` deduplica y ordena antes del motor (461-477) |

### 16. Testing

Fixtures sintéticos obligatorios: los planes reales contienen identificadores de pacientes y médicos. Fixtures y oráculo comunes para T1 a T5:

- Estado del documento: `tipo_documento='FORMULA MEDICA'`; un campo `NombreArticulo` con `tipoCampo='S'`, `tipoDato='article_name'`, `severity='alta'`, `orden=0`; `fuente_verdad.items` con un elemento `NombreArticulo` por artículo FDV; `visual_checks=[]`.
- Carga normalizada: `items_normalized` con un elemento por artículo del documento, creado con `ExtractedEvidence::fromArray(['valor'=>…, 'presente'=>true, 'estadoExtraccion'=>'FOUND'])`; `fields_normalized=[]`; `unmatched_articles=[]` salvo indicación.
- Procedimiento de olas: `plan()` → `SemanticEvaluationStore::buildPlan` (Redis simulado con caché vacía) → `waves` → marcar los pares de cada ola como resueltos con el oráculo → `advance`, repetido hasta que el plan queda `ready` → `results` → `evaluateResolved`.
- Oráculo (juez simulado): un par casa si el primer término de ambos textos, en mayúsculas, es igual; en T2, `ALFA` y `GAMMA` casan con `MOLX` y `BETA` con `MOLY`.

#### Nuevos Tests (`DocumentPolicyEngineTest.php`)

- **T1 — Dos filas, un documento positivo.** FDV: `CABERGOLINA 0.5MG FCO*4 TABLETA`, `CABERGOLINA 0.5MG FCO*8 TABLETA`. Documento: `CABERGOLINA TAB 0,5 MG`, `LOSARTAN TAB 50 MG`, `METFORMINA TAB 850 MG`. Esperado: 6 pares recolectados, 2 resueltos, 1 llamada simulada por ola, filas ambas en `semantic_match`; `evaluateResolved` devuelve `COINCIDE`. Sin el cambio, lanza `DomainException` con mensaje `semantic_result_missing`.
- **T2 — Tres filas X/Y/X.** FDV: `ALFA 10MG TABLETA`, `BETA 20MG TABLETA`, `GAMMA 30MG TABLETA`. Documento: `MOLX 10 MG`, `MOLZ 20 MG`, `MOLY 30 MG`. Orden planificado esperado: ALFA→[MOLX, MOLY, MOLZ]; BETA→[MOLZ, MOLX, MOLY]; GAMMA→[MOLY, MOLX, MOLZ]. Esperado: 9 pares, 6 resueltos, 3 olas con 6 veredictos en total, resultado `COINCIDE`. Sin el cambio lanza `semantic_result_missing`. Los valores se confirman en la primera ejecución (en rojo) del test antes de aplicar el cambio.
- **T3 — Plan invariante.** Con los fixtures de T1 y T2, el `json_encode` del plan, los `pair_id` y el orden de `rows[*].pair_ids` coinciden con valores fijos tomados antes del cambio. Caso de caché parcial (2 pares cacheados): sin el cambio, falla; con el cambio, `COINCIDE`. Caso de caché completa: `COINCIDE` en ambos.
- **T4 — Matriz de veredictos (guardia permanente).** Fixtures: `dos_filas_tres_docs` (T1), `tres_filas_tres_docs` (T2), `tres_filas_dos_docs_unmatched` (ALFA, BETA, GAMMA frente a `MOLX 10 MG`, `MOLY 30 MG` con `MOLY 30 MG` en `unmatched_articles`), `fase1_asigna_una_fila` (FDV `LOSARTAN 50MG TABLETA`, `ALFA 10MG TABLETA`, `BETA 20MG TABLETA`; documento `LOSARTAN 50MG TABLETA`, `MOLX 10 MG`, `MOLY 30 MG`), `empate_puntaje` (FDV `ALFA TABLETA`, `BETA TABLETA`; documento `MOLX TABLETA`, `MOLY TABLETA`, `MOLZ TABLETA`) y `dos_filas_dos_docs` (FDV `ALFA 10MG TABLETA`, `BETA 20MG TABLETA`; documento `MOLX 10 MG`, `MOLY 30 MG`). Para cada máscara de veredictos de cada fixture, un resolutor trazador (decorador de `ResolvedSemanticResolver`) registra los pares pedidos por `evaluate`. Esperado: 0 excepciones, 0 pares pedidos fuera del prefijo resuelto de su fila, resultado por campo igual al calculado desde las filas del store y al menos una máscara que falla sin el cambio. Debe ejecutarse con cada futura modificación de la Fase 3 o de `rankUnmatchedCandidates`.
- **T5 — Resultado, documento elegido y motivo.** Tres aserciones separadas con razones negativas distintas por candidato. Documento elegido: el último par con `is_match=true` por fila, obtenido con el trazador (`new DocumentPolicyEngine(new Tracer(new ResolvedSemanticResolver($results)))` y `evaluate($snapshot['document_state'], $snapshot['normalized_payload'], $facNro)`); la salida del motor no lo expone. Resultado y documento elegido: iguales a los de la versión anterior donde ésta termina. Motivo: el de D-5 (primer razonamiento en orden planificado), con valor esperado fijo.
- **T6 — Guardia de resultado ausente.** Un veredicto eliminado o con `is_match` no booleano en un prefijo resuelto lanza `semantic_result_missing` con el cambio y sin él. Es una guardia que pasa en ambas versiones; no una prueba de «falla antes».

#### Tests Modificados

Ninguno previsto. Si un test existente cambia de expectativa, el implementador lo documenta en un informe y el verificador lo aprueba; no se ajusta sin esa aprobación.

#### Tests Eliminados

Ninguno.

#### Verificaciones Manuales

- **V-1 (planes reales).** Solo si los planes siguen en Redis. Pasos: invocar `evaluateResolved` con cada plan disponible mediante una sonda de solo lectura. Esperado: 0 excepciones. Si no existen, se sustituye por T1, T2 y T4.
- **V-2 (batch real).** Lanzar un batch del cliente 2624 tras el despliegue; aplicar S-1 sobre la cohorte descrita en A-6.
- **V-3 (staging, SUP-B).** Ejecutar una cohorte de ≥ 20 auditorías con plan en `staging` antes y después del cambio y comparar `resultado` por campo (esperado: 0 diferencias) y `detalle` (diferencias solo en la condición de la sección 10).
- **V-4 (SUP-D).** `ls` del directorio `DEPLOY_PATH` en el host de producción: no debe existir `docker-compose.override.yml`.

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| R-1: el cambio de orden altera el motivo publicado en filas agotadas (condición de la sección 10) | consistencia de datos | Media | Decisión P-1, T5, V-3 |
| R-2: la reversión de imagen reintroduce el fallo en prefijos parciales; usar `operation=rollback` revierte el esquema v2 | operativo | Alta | Sección 14: rollback por `operation=deploy` con el SHA anterior y validación `merge-base` |
| R-3: la frecuencia en producción supera la local | operativo | Media | S-1 con umbral absoluto 0 |
| R-4: un override local en el host oculta archivos del código | operativo | Baja | V-4 |

### 18. Criterios de Aceptación

- A-1: `evaluateResolved` sobre los planes reales disponibles lanza 0 excepciones; si no hay planes, T1, T2 y T4 en verde.
- A-2: `vendor/bin/phpunit tests/Services/Audit/Events/DocumentPolicyEngineTest.php` termina OK con los 78 tests previos más T1-T6 (84 tests).
- A-3: `SemanticEvaluationStoreTest.php` termina OK con 4 tests y 18 aserciones.
- A-4: `git diff --stat staging` solo lista `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`, `plans/sdd-optimizacion-homologacion-semantica-evidencia.md`, `CHANGELOG.md` y `plans/changelog.md`; T3 en verde.
- A-5: la suite completa termina con 1536 tests en total más los nuevos, con 160 omitidos, y el job con Redis real (`tests/Integration`) termina OK.
- A-6: tras el despliegue, S-1 es 0 en una cohorte de al menos 20 auditorías con plan semántico consumido (`timings.semantic_pairs` > 0 en `AudDispEst.Hallazgos`).

### 19. Observabilidad

| Señal | Tipo | Antes (baseline) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
| S-1: fallos `semantic_result_missing` en etapa `policy` posteriores al corte | Alerta / Log | 5 en unas 100 auditorías (local); producción `[DESCONOCIDO]` | 0 | Eventos `dead_letter` con `last_error_message = semantic_result_missing` (`GET /audit/dlq`, `AuditTaskRunner.php:90-96`) y logs `Audit task operation failed` (`AuditTaskRunner.php:101-104`) | > 0 con la cohorte de A-6. Precondición: `LOG_LEVEL` ∈ {`info`, `warning`} para la fuente por logs; la DLQ no depende del nivel | Rollback |
| S-2: diferencia de `resultado` por campo entre la cohorte antes y después, en `staging` (V-3) | Verificación | 0 por INV-4 | 0 | Resultados persistidos `AudDispEst.Hallazgos` de la cohorte | > 0 | Detener el rollout |
| S-3: diferencia de pares y de llamadas (`timings.semantic_pairs`, `timings.semantic_calls`) entre cohortes | Verificación de INV-2 | 0 por INV-2 | 0 | `AuditTimingSummarizer.php:46, 67-70`, persistidos en `Hallazgos` (`AuditPersistenceWorker.php:342-365`) | > 0 | Investigar |

Si la cohorte no alcanza 20 auditorías con plan consumido, la señal se declara pendiente y no cuenta como cumplida.

### 20. Estrategia de Rollout

| Dimensión | Valor |
| --- | --- |
| Estrategia de despliegue | Directo con pausa de admisión (`operation=deploy`) |
| Orden entre productores y consumidores | N/A: productor del plan sin cambios |
| Coexistencia entre versiones | No (reinicio completo de `worker-policy`) |
| Compatibilidad requerida durante rollout | N/A |
| Condición para avanzar rollout | Suite y job Redis verdes; verificación independiente sin hallazgos P1; V-3 sin diferencias de resultado; V-4 sin override |
| Condición para detener rollout | S-1 > 0, S-2 > 0 o V-4 con un override presente |
| Condición de rollback | S-1 > 0 con `semantic_result_missing`, o error nuevo en `worker-policy` |
| Acción de rollback | `operation=deploy` con el SHA anterior y validación `merge-base` (sección 14) |
| Tiempo máximo para iniciar rollback | 60 minutos desde la detección |
| Responsable de decisión | Usuario |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Entidades persistentes definidas | PASS | Sección 9 |
| Columnas mencionadas existen | PASS | Sin columnas SQL |
| Contratos con clasificación | PASS | Sección 10 con condición y ejemplo sintético |
| Requisitos con trazabilidad | PASS | Sección 11 |
| Consumidores analizados | FAIL | Consumidores externos del texto `detalle`: `[DESCONOCIDO]` (0.8, Q-1) |
| Migraciones con rollback | PASS | Sin migración |
| Referencias definidas | PASS | Símbolos leídos en 0.1 |
| Compatibilidad con evidencia | FAIL | Configuración efectiva de producción sin inspeccionar (SUP-D) |
| Criterios verificables | PASS | Sección 18 |
| Observabilidad | PASS | Sección 19 |
| Rollout | PASS | Sección 20 |

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Decisión arquitectónica implícita? | No | D-1 a D-6 (sección 6); P-1 es una decisión de producto registrada como D-5 |
| ¿Contrato sin documentar? | No | Sección 10 |
| ¿Consumidor no analizado? | Sí | 0.8, Q-1 |
| ¿Migración sin rollback? | No | Sección 9 |
| ¿Dato persistido sin migración? | No | Sección 9 |
| ¿Afirmación sin clasificar o con certeza superior a su evidencia? | No | 0.6 y 0.10; las afirmaciones sobre producción están marcadas `[DESCONOCIDO]` |
| ¿Referencias huérfanas? | No | Sección 13 |
| ¿Decisión de implementación sin registrar? | No | Sección 6 |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta (resumen) | Regresión que previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | Arranque invoca algo que el cambio elimina | Runtime | NO | Sin eliminaciones (sección 13) |
| 2 | Cadena de build depende de algo modificado | Build | NO | Sin cambios de build |
| 3 | Pipeline distinto del evaluado | Pipeline | SÍ-CORREGIDO | `ci.yml:131-149, 159-165` ejecuta un job con Redis real; A-5 lo exige |
| 4 | Asume semántica de herramienta sin verificar | Semántica de Herramienta | NO | 0.4 |
| 5 | Validado en un solo entorno | Paridad de Entornos | NO | Matriz 0.5 evalúa local, CI y staging; producción usa el mismo workflow y se cubre con SUP-D |
| 6 | Override en runtime oculta archivos | Runtime por Override | DESCONOCIDO | Versionado verificado (`deploy-production.yml:164`, `docker-compose.yml:287-288`); falta V-4 (SUP-D) |
| 7 | Práctica genérica contradice convención local | Dogmatismo Técnico | SÍ-CORREGIDO | INV-3 previo identificado; D-6 |
| 8 | Altera interfaz pública sin compatibilidad | Contract | SÍ-CORREGIDO | Condición de `detalle` en sección 10 y D-5 |
| 9 | Afecta datos persistidos sin migración | Data | NO | Sin cambios de estructura (sección 9) |
| 10 | Código muerto o compatibilidad especulativa | Clean Architecture | NO | Las rutas sin llamador de producción ya existían; el retiro se declara fuera de alcance (sección 2) |
| 11 | Reemplaza mapeo estático | Abstracción Incorrecta | NO | 0.3.1 |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

**Nivel C — Diseño Parcial.** Condición que lo fija: filas `FAIL` en FASE 2 (consumidores externos y compatibilidad de producción), respuesta `Sí` en FASE 3 («consumidor no analizado») y adversarial 6 en `DESCONOCIDO`. SUP-B y SUP-D (S2 abiertos) limitarían a B. El cambio de la sección 13 está demostrado en laboratorio por dos evaluaciones independientes y las matrices de T4; eso no cierra los consumidores externos ni la configuración de producción.

### Decisión pendiente de un responsable externo

- **P-1.** Motivo del hallazgo en filas agotadas: ¿el primer razonamiento del orden planificado (D-5, recomendado, sin código adicional) o conservar el motivo previo mediante una derivación explícita? Mientras no se decida, T5 usa D-5.

### Preguntas que cierran los supuestos

- **Q-1 (SUP-B).** ¿Algún sistema fuera del repositorio lee el texto `detalle` de los hallazgos persistidos?
- **Q-2 (SUP-D).** ¿Existe un `docker-compose.override.yml` en el host de producción? V-4 lo comprueba si autorizas un `ls` de solo lectura.
- **Q-3 (SUP-C).** ¿Puedes dar el conteo semanal de `semantic_result_missing` en producción o autorizar medirlo?

### Definición de completitud

La spec es técnicamente completa cuando Q-1 y Q-2 estén respondidas y FASE 2 y FASE 3 queden sin `FAIL` ni `Sí`. La verificación independiente la hace un agente distinto del implementador y la aceptación es del usuario.
