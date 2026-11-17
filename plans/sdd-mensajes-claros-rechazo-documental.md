# SDD-DOC-REJECTION-MESSAGES-001: Mensajes Claros para el Auditor Humano en Rechazos de Integridad Documental

## Clasificación del Cambio (Triage)

| Dimensión | Valor |
| --- | --- |
| Tipo | Feature / UX de Dominio |
| Riesgo | Bajo |
| Persistencia afectada | No |
| Contrato externo afectado | No |
| Cambio arquitectónico | No |
| Producción afectada | Sí |
| Requiere 0.3.1 (cobertura de abstracciones) | Sí (mapeo exhaustivo de las 14 razones en `DocumentRejectionReason::ALLOWED`) |

> Elegible para **Perfil Reducido (Riesgo Bajo)** `[CONFIRMADO]`: no modifica esquema SQL (`AudDispEst`, `AdjuntosDispensacion`), no altera contratos de eventos Redis Streams (`document_rejected` / `rules_evaluated`) ni esquemas JSON de API/frontend; únicamente humaniza los textos descriptivos (`detalle` y `payload.hallazgos[0].Descripcion`) generados al evaluar rechazos de contenido documental.

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| `DocumentRejectionReason.php` | `app/Services/Audit/Pipeline/DocumentRejectionReason.php` | `MODIFIED` | Allowlist cerrada de razones `document_content`; alojará mapeo único `describeForAuditor()` | `L1-50` (lectura completa) | Sí |
| `RulesEvaluationWorker.php` | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | `MODIFIED` | Consume `DocumentRejectionReason` en `buildContentRejectedPolicyResult()` para generar hallazgo y decisión | `L456-545`, `L1-150`, `L750-990` | Sí |
| `DocumentPdfRasterizer.php` | `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php` | `INSPECTED` | Expone `resolveMaxPagesFromEnv()` sin I/O para conocer `PDF_MAX_PAGES` activo | `L62-97` | Sí |
| `AuditFindingRules.php` | `app/Services/Audit/AuditFindingRules.php` | `INSPECTED` | Provee `buildRejectionPayload($facNro, $hallazgos)` | `L533-548` | Sí |
| `AuditResultPersistenceModel.php` | `app/Models/AuditResultPersistenceModel.php` | `INSPECTED` | Persiste `Hallazgos` (`NVARCHAR(MAX)`) y `document_decisions[].payload` como JSON UTF-8 | `L480-585` | Sí |
| `result-items-table.tsx` | `frontend/components/audit/result-items-table.tsx` | `INSPECTED` | Renderiza `item.detalle` directamente en la columna «Resumen» de hallazgos | `L1-105` (lectura completa) | Sí |
| `DocumentRejectionReasonTest.php` | `tests/Services/Audit/Pipeline/DocumentRejectionReasonTest.php` | `MODIFIED` | Cobertura exhaustiva por reflexión (`ReflectionClassConstant::IS_PUBLIC`) de las 14 razones y fallback | `L1-68` | Sí |
| `RulesEvaluationWorkerTest.php` | `tests/Services/Audit/Events/RulesEvaluationWorkerTest.php` | `MODIFIED` | Aserciones del mensaje claro en `findings[0]['detalle']` y `document_decisions[0]['payload']['hallazgos'][0]['Descripcion']` | `L107-220` | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda textual | `Documento rechazado por validación de integridad\|Documento no procesable\|No fue posible asociar` | 4 coincidencias en 2 archivos (`RulesEvaluationWorker.php:511,539,609` y `RulesEvaluationWorkerTest.php:202`) | `git grep` sobre `tests/ app/ frontend/` |
| Búsqueda por símbolo | `INTEGRIDAD_DOCUMENTO\|DOCUMENT_EXCEEDS_PAGE_LIMIT\|buildRejectionPayload` | Todos los productores y consumidores localizados en `app/Services/Audit/Pipeline/` y `tests/` | `git grep` sobre `app/ tests/ frontend/` |
| Búsqueda en frontend/modelos | `rejection_reason\|HALLAZGOS\|DetalleError` | Frontend renderiza `item.detalle` sin mapear `rejection_reason`; `AudDispEst.DetalleError` es `NVARCHAR(MAX)` | `frontend/components/audit/result-items-table.tsx:L67-96`; `app/Models/migration/migration_AudDispEst_updated.sql:L21` |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `RulesEvaluationWorker.php` | `DocumentRejectionReason` | `app/Services/Audit/Pipeline/DocumentRejectionReason.php` | `L502-505` | Directa | Estática (`REJECTION_CLASS`, `isAllowed`) | Repositorio local |
| `RulesEvaluationWorker.php` | `DocumentPdfRasterizer` | `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php` | — (nueva llamada estática) | Directa | Estática pura (`resolveMaxPagesFromEnv()`) | Repositorio local |
| `RulesEvaluationWorker.php` | `AuditFindingRules` | `app/Services/Audit/AuditFindingRules.php` | `L536` | Directa | Estática (`buildRejectionPayload()`) | Repositorio local |
| `AuditResultPersistenceModel.php` | Resultado de `RulesEvaluationWorker` | `app/Models/AuditResultPersistenceModel.php` | `L500-525` | Transitiva | Serialización JSON de `payload` y `Hallazgos` | Repositorio local |
| `result-items-table.tsx` | Campo `detalle` vía `/audit/results/{facNro}` | `frontend/components/audit/result-items-table.tsx` | `L67-96`, `L93-96` | Transitiva | Contrato HTTP JSON (`findings[].detalle`) | Repositorio local |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Cambiar `$detail` en `RulesEvaluationWorker::buildContentRejectedPolicyResult()` | `RulesEvaluationWorkerTest::testDocumentRejectedPublishesRulesEvaluatedWithCanonicalFinding` | `tests/Services/Audit/Events/RulesEvaluationWorkerTest.php:L108-162` | Ninguna (el test actual no asertaba el literal anterior) `[CONFIRMADO]` | Ampliar aserciones en dicho test y agregar pruebas dedicadas para `DOCUMENT_EXCEEDS_PAGE_LIMIT` y coherencia `detalle === Descripcion` |
| Acoplar `buildMappingRejectedPolicyResult()` | `RulesEvaluationWorkerTest::testMappingRejectionPublishesMapFindingAndPreservesTraceability` | `tests/Services/Audit/Events/RulesEvaluationWorkerTest.php:L202` | `Test` (si se alterara el literal de mapping) `[CONFIRMADO]` | `buildMappingRejectedPolicyResult()` queda `UNTOUCHED` fuera de este alcance |

### 0.3.1 Verificación de Cobertura de Abstracciones

Cada una de las 14 constantes públicas de razón en `DocumentRejectionReason` se consolida en una única tabla `AUDITOR_MESSAGES` consumida tanto por `isAllowed()` como por `describeForAuditor()` mediante `strtr()`:

| Elemento del Mapeo Estático | Atributos Dinámicos | ¿Otros elementos comparten esos atributos? | ¿Clasificación correcta? |
| --- | --- | --- | --- |
| `EMPTY_DOCUMENT` | `$reason === 'EMPTY_DOCUMENT'` | No | Sí `[CONFIRMADO]` |
| `DOCUMENT_TOO_SMALL` | `$reason === 'DOCUMENT_TOO_SMALL'` | No | Sí `[CONFIRMADO]` |
| `UNSUPPORTED_MIME` | `$reason === 'UNSUPPORTED_MIME'` | No | Sí `[CONFIRMADO]` |
| `UNKNOWN_FILE_SIGNATURE` | `$reason === 'UNKNOWN_FILE_SIGNATURE'` | No | Sí `[CONFIRMADO]` |
| `MIME_MISMATCH` | `$reason === 'MIME_MISMATCH'` | No | Sí `[CONFIRMADO]` |
| `ENCRYPTED_DOCUMENT` | `$reason === 'ENCRYPTED_DOCUMENT'` | No | Sí `[CONFIRMADO]` |
| `EMPTY_PDF_NO_PAGES` | `$reason === 'EMPTY_PDF_NO_PAGES'` | No | Sí `[CONFIRMADO]` |
| `CORRUPTED_DOCUMENT` | `$reason === 'CORRUPTED_DOCUMENT'` | No | Sí `[CONFIRMADO]` |
| `RASTERIZATION_FAILURE` | `$reason === 'RASTERIZATION_FAILURE'` | No | Sí `[CONFIRMADO]` |
| `GEMINI_DECODE_FAILURE` | `$reason === 'GEMINI_DECODE_FAILURE'` | No | Sí `[CONFIRMADO]` |
| `GEMINI_PROVIDER_BLOCKED` | `$reason === 'GEMINI_PROVIDER_BLOCKED'` | No | Sí `[CONFIRMADO]` |
| `GEMINI_OUTPUT_TRUNCATED` | `$reason === 'GEMINI_OUTPUT_TRUNCATED'` | No | Sí `[CONFIRMADO]` |
| `GEMINI_UNSAFE_FINISH` | `$reason === 'GEMINI_UNSAFE_FINISH'` | No | Sí `[CONFIRMADO]` |
| `DOCUMENT_EXCEEDS_PAGE_LIMIT` | `$reason === 'DOCUMENT_EXCEEDS_PAGE_LIMIT'` + `$maxPages` | No | Sí `[CONFIRMADO]` |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| PHP 8.2 `match` + `ReflectionClass` | Expresión estricta por identidad `===` | Estática | `DocumentRejectionReason::ALLOWED` posee 14 constantes string únicas (`app/Services/Audit/Pipeline/DocumentRejectionReason.php:L25-40`) | Sí |
| SQL Server `NVARCHAR(MAX)` + `json_encode(..., JSON_UNESCAPED_UNICODE)` | Soporta tildes y mensajes de ~220 caracteres sin truncado | Estática | `app/Models/migration/migration_AudDispEst_updated.sql:L21`; `app/Models/AuditResultPersistenceModel.php:L521-524` | Sí |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | PHP 8.2 CLI + Docker Compose | `RulesEvaluationWorker::buildContentRejectedPolicyResult()` | Sí | Mismo runtime PHP 8.2 y `Core\Env` |
| CI automatizado | GitHub Actions `ci.yml` (`phpunit`) | `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` | Sí | Sin binarios ni servicios externos nuevos |
| Staging / Preproducción | `N/A` (no existe en el proyecto) | `N/A` | N/A | `.github/workflows/` solo define `ci`, `frontend-ci`, `publish-images`, `deploy-production` |
| Producción | Docker Compose en `nodo1` (`APP_ENV=production`) | Worker `policy` (`php bin/audit-worker.php policy`) | Sí | Lee `PDF_MAX_PAGES=10` desde env o default `10` |
| Testing aislado | PHPUnit unitario sin Redis/SQL | `DocumentRejectionReasonTest` y `RulesEvaluationWorkerTest` | Sí | Método estático puro sin I/O |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Productor único de hallazgos de contenido rechazado | `[CONFIRMADO]` | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php:L511-541` |
| Consumo UI directo de `findings[].detalle` | `[CONFIRMADO]` | `frontend/components/audit/result-items-table.tsx:L67-96` |
| 14 razones permitidas en `DocumentRejectionReason::ALLOWED` | `[CONFIRMADO]` | `app/Services/Audit/Pipeline/DocumentRejectionReason.php:L10-40` |
| Valor por defecto y lector sin I/O de `PDF_MAX_PAGES` | `[CONFIRMADO]` | `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php:L28,71-84` |

### 0.7 Información Faltante Crítica
Ninguna.

### 0.8 Información Faltante Importante
Ninguna.

### 0.9 Información Faltante Opcional
Ninguna.

### 0.10 Supuestos Declarados
Ninguno. Toda la información de alcance, consumidores y formatos está confirmada por lectura directa.

### 0.11 Clasificación de Completitud Inicial
**Nivel A — Implementable**: perímetro cerrado por lectura completa de todos los archivos involucrados, sin supuestos S3/S4 abiertos.

---

## FASE 1 — Especificación

### 1. Objetivo

- **Problema actual**: Cuando un adjunto falla la validación de contenido o supera el límite de páginas (`X07261005323`), `RulesEvaluationWorker::buildContentRejectedPolicyResult()` (`L511, L539`) persiste textos técnicos crudos con códigos en inglés (`"Documento rechazado por validación de integridad: DOCUMENT_EXCEEDS_PAGE_LIMIT"` y `"Documento no procesable: DOCUMENT_EXCEEDS_PAGE_LIMIT"`).
- **Causa raíz**: `DocumentRejectionReason` no expone descripciones legibles por razón y `RulesEvaluationWorker` interpola directamente el identificador interno de constante en dos cadenas distintas.
- **Impacto actual**: El auditor humano debe interpretar códigos técnicos (`DOCUMENT_EXCEEDS_PAGE_LIMIT`, `UNKNOWN_FILE_SIGNATURE`, `EMPTY_PDF_NO_PAGES`, etc.) para entender la causal del hallazgo y decidir la acción correctiva en `manual_review`.
- **Resultado esperado**: Tanto el campo `findings[].detalle` como `document_decisions[].payload.hallazgos[0].Descripcion` muestran un mensaje único, claro y accionable en español que identifica el nombre del documento, la causa exacta en lenguaje natural (incluyendo el límite activo de páginas para `DOCUMENT_EXCEEDS_PAGE_LIMIT`) y la acción sugerida para el auditor, manteniendo el código técnico intacto en `document_decisions[].rejection_reason`.

### 2. Alcance

#### Incluido
- Método público estático `DocumentRejectionReason::describeForAuditor(string $reason, string $documentName, ?int $maxPages = null): string` con cobertura explícita de las 14 razones en `ALLOWED`.
- Uso unificado en `RulesEvaluationWorker::buildContentRejectedPolicyResult()` para `finding['detalle']` y `document_decision['payload']['hallazgos'][0]['Descripcion']`.
- Pruebas unitarias en `DocumentRejectionReasonTest` y `RulesEvaluationWorkerTest`.

#### Excluido
- Modificación de `RulesEvaluationWorker::buildMappingRejectedPolicyResult()` (`L609`), que ya posee contrato probado en `RulesEvaluationWorkerTest::L202`.
- Cambios en esquemas SQL, endpoints REST, payload del evento `document_rejected` o componentes React.

### 3. Non Goals
- No traducir ni ocultar `document_decisions[].rejection_reason`, el cual permanece en `MAYUSCULAS_SNAKE_CASE` como identificador técnico auditable para métricas y filtros.

### 4. Estado Actual y 5. Estado Objetivo

- **Antes (`RulesEvaluationWorker.php:L511, L539`)**: `finding['detalle']` = `"Documento rechazado por validación de integridad: {$reason}"`; `decision.payload.hallazgos[0].Descripcion` = `"Documento no procesable: {$reason}"`.
- **Después**: `DocumentRejectionReason::describeForAuditor($reason, $documentName, $maxPages)` produce un único texto en español para ambos campos; por ejemplo, para `FORMULA MEDICA` + `DOCUMENT_EXCEEDS_PAGE_LIMIT` (`$maxPages = 10`):
  > `El PDF adjunto de 'FORMULA MEDICA' supera el límite máximo permitido de 10 páginas para auditoría automática. Verifique si se cargaron anexos no requeridos (como historia clínica o epicrisis) y realice la revisión manual.`

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| DA-01 | Ubicar `describeForAuditor()` en `DocumentRejectionReason` | Poner un `match` de 14 ramas en `RulesEvaluationWorker` | Evita dispersar el conocimiento de las 14 constantes y permite validarlo de forma unitaria y hermética sin instanciar workers. |
| DA-02 | Unificar `$detail` entre `finding['detalle']` y `decision.payload.hallazgos[0].Descripcion` | Mantener dos textos distintos | Elimina duplicación e inconsistencia visual entre la tabla de hallazgos y el detalle por adjunto. |
| DA-03 | Pasar `DocumentPdfRasterizer::resolveMaxPagesFromEnv()` solo cuando `$reason === DOCUMENT_EXCEEDS_PAGE_LIMIT` | Hardcodear `'10'` en el mensaje | Si `PDF_MAX_PAGES` cambia por configuración, el mensaje al auditor refleja el umbral real vigente sin acoplar `DocumentRejectionReason` al entorno. |

#### 6.1 Estrategia de Cambio y Excepciones

| Dimensión | Valor |
| --- | --- |
| Estrategia | Refactorización incremental |
| Fila de la compuerta aplicada | Regla de calidad MVP: cambio puntual de contrato de presentación textual en un dominio estable sin deuda estructural ni ruptura de contratos activos |
| Candidatas descartadas y desempate | Clean rebuild descartado (el pipeline de rechazo ya es modular); parche descartado (la tabla de razones vive limpiamente en `DocumentRejectionReason`) |
| Fuente del alcance MVP | Solicitud explícita del usuario sobre `RulesEvaluationWorker.php:L514` tras validar `X07261005323` en producción |

Excepciones registradas: `Ninguna`.

### 7–10. Resumen Consolidado de Perfil Reducido

- **7. Dependencias / 7.1 Fuentes de Verdad**: `DocumentRejectionReason::ALLOWED` es la única fuente de verdad para códigos válidos (`[CONFIRMADO] Sin conflictos detectados entre fuentes de verdad`, verificado en `DocumentRejectionReason.php:L25-40`).
- **8. Invariantes**: `document_decisions[].rejection_reason` conserva el código exacto de `DocumentRejectionReason`; `finding['campo']` permanece `'INTEGRIDAD_DOCUMENTO'`, `'tipo_auditoria' => 'integrity'`, `'Codigo' => 'INTEGRIDAD'`, y la auditoría sigue terminando en `manual_review` con `severidad = alta`.
- **9. Modelo de Datos**: `[CONFIRMADO] Sin impacto en persistencia` (`AudDispEst.Hallazgos` y `AdjuntosDispensacion.ObservacionAdjunto` almacenan strings UTF-8 sin restricción de longitud fija inferior al límite `NVARCHAR(MAX)`).
- **10. Contratos**: Interno (`DocumentRejectionReason::describeForAuditor` + salida textual dentro de `rules_evaluated`); backward compatible en estructura JSON.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| REQ-01 | Mensajes claros y accionables en español para las 14 razones de `DocumentRejectionReason` | `DocumentRejectionReason::describeForAuditor()` | `DocumentRejectionReasonTest::testEveryAllowedReasonProducesClearHumanSpanishMessage` |
| REQ-02 | Indicar límite de páginas vigente en `DOCUMENT_EXCEEDS_PAGE_LIMIT` | Parámetro `?int $maxPages` en `describeForAuditor()` alimentado por `DocumentPdfRasterizer::resolveMaxPagesFromEnv()` | `DocumentRejectionReasonTest::testExceedsPageLimitIncludesConfiguredMaxPages` y `RulesEvaluationWorkerTest` |
| REQ-03 | Coherencia entre `findings[0]['detalle']` y `document_decisions[0]['payload']['hallazgos'][0]['Descripcion']` | Variable única `$detail` en `RulesEvaluationWorker::buildContentRejectedPolicyResult()` | `RulesEvaluationWorkerTest::testPageLimitRejectionProducesClearAuditorMessageInFindingAndDecision` |

### 12. Impact Analysis y 13. Cambios por Archivo

- `[MODIFY]` `app/Services/Audit/Pipeline/DocumentRejectionReason.php`: agregar `public static function describeForAuditor(string $reason, string $documentName, ?int $maxPages = null): string`.
- `[MODIFY]` `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` (`buildContentRejectedPolicyResult()`, `L511-541`): reemplazar `$detail` y `'Descripcion'` por llamada única a `DocumentRejectionReason::describeForAuditor(...)`.
- `[MODIFY]` `tests/Services/Audit/Pipeline/DocumentRejectionReasonTest.php`: validar exhaustividad sobre `ALLOWED` y formato con/sin `$maxPages`.
- `[MODIFY]` `tests/Services/Audit/Events/RulesEvaluationWorkerTest.php`: validar coherencia y texto claro para `DOCUMENT_EXCEEDS_PAGE_LIMIT` y `UNKNOWN_FILE_SIGNATURE`.

### 14. Plan de Migración, 19. Observabilidad, 20. Rollout

`N/A` `[CONFIRMADO]` (Perfil Reducido Riesgo Bajo: sin migración SQL/Redis ni banderas de despliegue; rollback mediante `git revert` estándar).

### 15. Casos Límite y 16. Testing

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| `$documentName` vacío o espacios | Normaliza a `'DOCUMENTO'` para evitar comillas vacías `''` | Mensaje contiene `'DOCUMENTO'` |
| `$reason === DOCUMENT_EXCEEDS_PAGE_LIMIT` con `$maxPages = 10` | Incluye `'10 páginas'` e instrucción de revisar anexos no requeridos | Assert `str_contains(..., '10 páginas')` |
| `$reason === DOCUMENT_EXCEEDS_PAGE_LIMIT` con `$maxPages = null` o `<= 0` | Usa redacción válida sin `'0 páginas'` ni número inventado | Assert sin `'0 páginas'` |
| Razón desconocida pasada directo al helper | Fallback legible en español mencionando el código entre paréntesis | Assert texto en español sin excepción |

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| Agregar una nueva constante en `DocumentRejectionReason` sin añadir mensaje humano | consistencia de datos | Baja | Test con reflexión sobre `DocumentRejectionReason::ALLOWED` que falla si una constante cae al fallback |

### 18. Criterios de Aceptación

- `DocumentRejectionReason::describeForAuditor()` cubre las 14 constantes de `ALLOWED` sin caer al fallback (verificado por prueba unitaria).
- `RulesEvaluationWorkerTest` verifica que `DOCUMENT_EXCEEDS_PAGE_LIMIT` produce el mismo mensaje claro en `hallazgos.items[0].detalle` y en `document_decisions[0].payload.hallazgos[0].Descripcion`, sin exponer textos crudos `"Documento no procesable: ..."`, conservando `rejection_reason === 'DOCUMENT_EXCEEDS_PAGE_LIMIT'`.
- Suite completa `php vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` termina con código `0` y `0` fallos.

---

## FASE 2, 3 y 4 — Auditorías y Nivel Final

- **Auditoría de Consistencia (FASE 2)**: `PASS` en todas las filas aplicables (`[CONFIRMADO]` por lectura directa de `RulesEvaluationWorker.php`, `DocumentRejectionReason.php`, `DocumentPdfRasterizer.php`, `AuditResultPersistenceModel.php` y `result-items-table.tsx`). `N/A` justificado en migraciones/observabilidad/rollout por Perfil Reducido Riesgo Bajo.
- **Auditoría Arquitectónica y Anti-Regresión (FASE 3)**: `No` / `NO` en todas las preguntas (ningún binario/script afectado, sin cambios de build, sin caché ni BD modificada, cobertura estática exhaustiva en 0.3.1 mediante `match` + test por reflexión).
- **Nivel Final (FASE 4)**: **`Nivel A — Implementable`** (sin supuestos S2 abiertos ni S3/S4, con evidencia directa en todos los archivos del perímetro).
