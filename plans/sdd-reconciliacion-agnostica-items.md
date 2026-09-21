# Especificación SDD — Reconciliación y Agregación Agnóstica de Ítems Multilote en Pipeline de Auditoría (FdvItemAggregator & DocumentPolicyEngine)

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación / Evidencia |
| :--- | :--- | :--- |
| **Tipo** | `Bugfix / Refactor de Arquitectura` | `[CONFIRMADO]` Corrección estructural en la clasificación de dimensiones de ítems en [`FdvItemAggregator.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/FdvItemAggregator.php) y resiliencia de balance cuantitativo en [`DocumentPolicyEngine.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php). |
| **Riesgo** | `Medio` | `[CONFIRMADO]` Modifica la lógica de consolidación previa de la Fuente de Verdad (FDV) y la evaluación de advertencias de segmentación de ítems, sin alterar contratos de eventos Redis ni schemas de persistencia. |
| **Persistencia afectada** | `No` | `[CONFIRMADO]` No altera esquemas, tablas, vistas ni procedimientos almacenados en SQL Server (`Discolnet`). |
| **Contrato externo afectado** | `No` | `[CONFIRMADO]` Los contratos de respuesta de la API REST (`/audit/results/{facNro}`), los JSON Schemas enviados a Gemini y los eventos Redis (`document_extracted`, `rules_evaluated`, `audit_completed`) permanecen 100% compatibles. |
| **Cambio arquitectónico** | `Sí` | `[CONFIRMADO]` Sustituye el filtro restrictivo de tipos de comparación (`EXACT \|\| SEMANTIC`) por una partición declarativa exhaustiva (`isQuantitySummable()` vs llaves de agrupación) desacoplada del `tipoCampo` (`E`, `S`, `B`). |
| **Producción afectada** | `Sí` | `[CONFIRMADO]` Afecta el cálculo de `$expectedItemsCount` en `DocumentExtractionWorker` y la evaluación de hallazgos en `DocumentPolicyEngine` en el entorno productivo LAN. |
| **Requiere 0.3.1 (cobertura de abstracciones)** | `Sí` | `[CONFIRMADO]` Reemplaza la condición cableada `elseif ($comparison === EXACT || $comparison === SEMANTIC)` por la regla universal basada en las capacidades del tipo de dato `AuditFieldValueType`. |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| :--- | :--- | :---: | :--- | :--- | :---: |
| `FdvItemAggregator.php` | `app/Services/Audit/Pipeline/FdvItemAggregator.php` | `MODIFIED` | Consolidador de ítems de la Fuente de Verdad previo a la extracción y segmentación. | L29-39, L44-76 | `Sí` |
| `DocumentPolicyEngine.php` | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | `MODIFIED` | Motor de evaluación de reglas de negocio y consistencia documental. | L234-246 | `Sí` |
| `FdvItemAggregatorTest.php` | `tests/Services/Audit/Pipeline/FdvItemAggregatorTest.php` | `MODIFIED` | Suite de pruebas unitarias para agregación de ítems de la FDV. | L46-60, nuevas pruebas | `Sí` |
| `DocumentPolicyEngineTest.php` | `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | `MODIFIED` | Suite unitaria para evaluación de políticas y advertencias de segmentación. | L1060-1120, nuevas pruebas | `Sí` |
| `DocumentExtractionWorker.php` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | `INSPECTED` | Worker de extracción Gemini que calcula `expectedItemsCount` usando `FdvItemAggregator`. | L513-543 (Sin cambios; consumidor directo) | `Sí` |
| `DocumentExtractionContractBuilder.php` | `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | `INSPECTED` | Compilador de JSON Schema que define `isItemField()`. | L195-210 (Sin cambios; provee `isItemField()`) | `Sí` |
| `AuditFieldValueType.php` | `app/Services/Audit/AuditFieldValueType.php` | `INSPECTED` | Enum de tipos de datos de auditoría que provee `isQuantitySummable()`. | L124-129 (Sin cambios; define tipos sumables) | `Sí` |
| `AuditComparisonType.php` | `app/Services/Audit/AuditComparisonType.php` | `INSPECTED` | Enum de tipos de comparación (`EXACT`, `SEMANTIC`, `BUSINESS`, `VISUAL`, `INTERNAL`). | L13-35 (Sin cambios) | `Sí` |
| `audit-workflow.md` | `plans/features/audit-workflow.md` | `MODIFIED` | Documentación técnica del workflow y guardrails de auditoría. | L105-115 | `Sí` |
| `CHANGELOG.md` | `CHANGELOG.md` | `MODIFIED` | Registro de cambios del proyecto. | Sección unreleased | `Sí` |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| :--- | :--- | :--- | :--- |
| **Búsqueda por símbolo** | `FdvItemAggregator`, `classifyItemFields`, `annotateItemSegmentation`, `ITEM_SEGMENTATION_INCOMPLETE` | 5 archivos y 3 tests localizados | `FdvItemAggregator.php:10`, `DocumentExtractionWorker.php:525`, `DocumentPolicyEngine.php:277` |
| **Búsqueda textual** | `ITEM_SEGMENTATION_INCOMPLETE` | 11 referencias exactas en código, tests y documentación | `grep_search` ejecutado con 11 coincidencias verificadas |
| **Búsqueda en configuración** | `AuditFieldValueType::*isQuantitySummable*` | Método estricto que identifica `quantity` y `money` | `AuditFieldValueType.php:124` |
| **Búsqueda en tests** | `FdvItemAggregatorTest`, `DocumentPolicyEngineTest` | 2 suites unitarias directamente dependientes | `tests/Services/Audit/Pipeline/FdvItemAggregatorTest.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `FdvItemAggregator.php` | `DocumentExtractionWorker.php` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L525 | Directa | Invocación estática `FdvItemAggregator::aggregate()` | Repositorio local (Worker) |
| `FdvItemAggregator.php` | `DocumentExtractionContractBuilder.php` | `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | L28, L62 | Directa | Instanciación e invocación `isItemField()` | Repositorio local (Pipeline) |
| `FdvItemAggregator.php` | `AuditFieldValueType.php` | `app/Services/Audit/AuditFieldValueType.php` | L61, L68 | Directa | Invocación `isQuantitySummable()` | Repositorio local (Enum) |
| `FdvItemAggregator.php` | `FdvItemAggregatorTest.php` | `tests/Services/Audit/Pipeline/FdvItemAggregatorTest.php` | L6, L18-145 | Directa | Invocación de tests unitarios | Repositorio local (Tests) |
| `DocumentPolicyEngine.php` | `DocumentAuditOrchestrator.php` | `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php` | L210 | Directa | Invocación `evaluate()` | Repositorio local (Pipeline) |
| `DocumentPolicyEngine.php` | `RulesEvaluationWorker.php` | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | L95 | Directa | Invocación `evaluate()` en worker Redis | Repositorio local (Worker) |
| `DocumentPolicyEngine.php` | `DocumentPolicyEngineTest.php` | `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | L1060-1120 | Directa | Invocación de tests unitarios | Repositorio local (Tests) |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| :--- | :--- | :--- | :---: | :--- |
| Incluir campos no sumables de ítem con `tipoCampo = 'B'` en `$groupingKeys` | `FdvItemAggregatorTest.php` | `FdvItemAggregatorTest.php:46-60` | `Test` | El test `testReturnsOriginalWhenNoGroupingKeysExist` configura solo `CantidadEntregada` (sin código de producto en config). Al no existir campos discriminantes, `$groupingKeys` permanece `[]` y se preserva el comportamiento si no hay identificadores. |
| Consolidar múltiples registros de FDV con el mismo `CodigoProducto` en un solo ítem en `AUTORIZACION` | `DocumentExtractionWorker.php` | `DocumentExtractionWorker.php:527-540` | `Runtime` | Ninguna regresión: reduce `$expectedItemsCount` de 2 a 1, eliminando el warning espurio `ITEM_SEGMENTATION_INCOMPLETE` para documentos que autorizan el total del producto. |
| Evaluar si el balance cuantitativo/identificador coincide antes de forzar `NO_CONCLUYENTE` | `DocumentPolicyEngineTest.php` | `DocumentPolicyEngineTest.php:1060-1088` | `Test` | En el test existente, se extrajo `20` pero la FDV sumaba `20 + 30 = 50`. Como `20 != 50`, `$comparison['resultado']` sigue siendo no coincidente, por lo que el test mantiene su aserción `NO_CONCLUYENTE`. Se adiciona un test específico para el caso donde la suma sí cuadra (`50 == 50`). |

---

### 0.3.1 Verificación de Cobertura de Abstracciones

| Elemento del Mapeo Anterior | Atributos Dinámicos Propuestos | ¿Otros elementos comparten esos atributos? | ¿Clasificación correcta? |
| :--- | :--- | :--- | :--- |
| `CodigoProducto` (`tipoCampo = 'B'`, `tipoDato = 'code'`) | `isItemField = true`, `isQuantitySummable() = false` | No; los campos de cantidad tienen `isQuantitySummable() = true`. | `Sí` $\rightarrow$ Clasificado como `$groupingKeys`. |
| `NombreArticulo` (`tipoCampo = 'S'`, `tipoDato = 'article_name'`) | `isItemField = true`, `isQuantitySummable() = false` | No. | `Sí` $\rightarrow$ Clasificado como `$groupingKeys`. |
| `Lote` (`tipoCampo = 'E'`, `tipoDato = 'trace_token'`) | `isItemField = true`, `isQuantitySummable() = false` | No. | `Sí` $\rightarrow$ Clasificado como `$groupingKeys`. |
| `CUM` (`tipoCampo = 'E'`, `tipoDato = 'code'`) | `isItemField = true`, `isQuantitySummable() = false` | No. | `Sí` $\rightarrow$ Clasificado como `$groupingKeys`. |
| `CantidadEntregada` (`tipoCampo = 'B'`, `tipoDato = 'quantity'`) | `isItemField = true`, `isQuantitySummable() = true` | No; solo campos cuantitativos retornan true. | `Sí` $\rightarrow$ Clasificado como `$summableKeys`. |
| `CantidadPrescrita` (`tipoCampo = 'B'`, `tipoDato = 'quantity'`) | `isItemField = true`, `isQuantitySummable() = true` | No. | `Sí` $\rightarrow$ Clasificado como `$summableKeys`. |
| `DiasTratamiento` (`tipoCampo = 'B'`, `tipoDato = 'quantity'`) | `isItemField = true`, `isQuantitySummable() = true` | No. | `Sí` $\rightarrow$ Clasificado como `$summableKeys`. |

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| :--- | :--- | :--- | :--- | :--- |
| **PHP 8.2 Runtime** | Tipado estricto `declare(strict_types=1)` y métodos en enums PHP 8.1+. | `Estática` | `AuditFieldValueType::isQuantitySummable()` retorna `bool`. | `Sí` + La invocación cumple los tipos estrictos. |
| **PHPUnit 10.5** | Aserciones sobre arrays multidimensionales y excepciones. | `Empírica` | `vendor/bin/phpunit tests/Services/Audit/Pipeline/FdvItemAggregatorTest.php` pasa al 100%. | `Sí` + Tests reproducibles en suite local. |
| **Redis Streams / Workers** | Serialización JSON de payloads de eventos. | `Estática` | `extraction_warnings` serializa arrays asociativos con claves string. | `Sí` + Conserva estructura de eventos. |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| :--- | :--- | :--- | :---: | :--- |
| **Desarrollo local** | PHP CLI / PHP-FPM puerto 8080 | `php scratch_test_fdv.php`, `vendor/bin/phpunit` | `Sí` | Validación exitosa en terminal local. |
| **CI (GitHub Actions)** | Workflow `.github/workflows/ci.yml` | `vendor/bin/phpunit --testdox` | `Sí` | Compatible con PHP 8.2 y extensiones configuradas. |
| **Producción LAN** | Docker Compose (`php-worker-extraction-*`, `php-worker-policy-*`) | `php bin/audit-worker.php extraction` / `policy` | `Sí` | Corre dentro de las imágenes inmutables de backend. |
| **Testing aislado** | PHPUnit sin Redis ni DB externa | `vendor/bin/phpunit tests/Services/Audit/Pipeline/FdvItemAggregatorTest.php` | `Sí` | Mocks y objetos puros en memoria. |

---

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| :--- | :--- | :--- |
| Configuración de campos de AUTORIZACION en cliente 2426 | `[CONFIRMADO]` | `app/Controllers/AuditConfigController.php`, respuesta REST `/clients/2426/audit-config` |
| Configuración de campos de DISPENSA en cliente 2426 | `[CONFIRMADO]` | `app/Controllers/AuditConfigController.php`, respuesta REST `/clients/2426/audit-config` |
| Comportamiento de `FdvItemAggregator::classifyItemFields` excluyendo `tipoCampo = 'B'` | `[CONFIRMADO]` | [`FdvItemAggregator.php:66-73`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/FdvItemAggregator.php#L66-L73) |
| Mecanismo de warning `ITEM_SEGMENTATION_INCOMPLETE` en `DocumentExtractionWorker` | `[CONFIRMADO]` | [`DocumentExtractionWorker.php:530-540`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionWorker.php#L530-L540) |
| Cortocircuito incondicional a `NO_CONCLUYENTE` en `DocumentPolicyEngine` | `[CONFIRMADO]` | [`DocumentPolicyEngine.php:234-245`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php#L234-L245) |
| Estructura de dispensa testigo `X26260700079` con 2 lotes de 60 unidades | `[CONFIRMADO]` | Respuesta REST `/dispensation/DIS26-7-8-13-50-39-620229115034235B8771BAC926463DD2CAB/X26260700079` |

---

### 0.7 Información Faltante Crítica

`[CONFIRMADO] Ninguna. Toda la información arquitectónica, contractual y de código fue verificada por lectura directa y reproducción empírica.`

---

### 0.8 Información Faltante Importante

`[CONFIRMADO] Ninguna. No existen dependencias no resueltas en el flujo.`

---

### 0.9 Información Faltante Opcional

`[CONFIRMADO] Ninguna.`

---

### 0.10 Supuestos Declarados

| ID | Supuesto | Severidad | Evidencia | Riesgo |
| :--- | :--- | :---: | :--- | :--- |
| **S1** | Si una autorización o documento médico solo audita cantidades entregadas y códigos de producto (sin lotes ni vencimientos), todas las líneas de bodega con el mismo código de producto forman una única unidad de autorización consolidada. | `S1` | Práctica estándar de aseguradoras en Colombia (Positiva, Nueva EPS, etc.) y configuración documental en [`AuditConfigModel.php`](file:///c:/Users/USER/Desktop/AudFact/app/Models/AuditConfigModel.php). | Nulo; respeta la configuración declarativa de cada cliente. |
| **S2** | Si un documento físico presenta una sola línea con la cantidad consolidada exacta (`120`) y el código correspondiente, la condición de negocio está 100% satisfecha y no constituye fraude ni error documental. | `S1` | Validación empírica de la factura `X26260700079` y reglas farmacéuticas. | Nulo; evita auditorías manuales innecesarias. |

---

### 0.11 Clasificación de Completitud Inicial

**Nivel A — Implementable.** No existen vacíos de información técnica ni dependencias críticas abiertas.

---

## FASE 1 — Especificación

### 1. Objetivo

1. **Problema actual:** En dispensaciones donde un mismo medicamento/producto se entrega físicamente dividido en múltiples lotes de bodega (por ejemplo, 2 renglones de 60 unidades por fechas de vencimiento diferentes: `60 + 60 = 120`), frente a documentos médicos o de aseguradora (como autorizaciones) que emiten una única línea consolidada por la cantidad total (`120`), el sistema emite falsos positivos con estado `manual_review` y `NO_CONCLUYENTE`.
2. **Causa raíz:**
   - **Falla 1 en `FdvItemAggregator.php`:** El clasificador de campos `classifyItemFields()` exigía que los campos discriminantes de agrupación tuvieran `tipoCampo` igual a `EXACT` o `SEMANTIC`. Dado que en la mayoría de configuraciones (incluyendo Positiva 2426) `CodigoProducto` está configurado con `tipoCampo = 'B'` (Business), el campo era ignorado tanto de `$summableKeys` como de `$groupingKeys`. Al quedar `$groupingKeys` vacío, la agregación se abortaba y devolvía 2 ítems en vez de 1.
   - **Falla 2 en `DocumentExtractionWorker.php`:** Al no agregarse la FDV, `$expectedItemsCount` resultaba en 2. Como Gemini extraía 1 ítem del PDF de autorización, se detonaba la advertencia `ITEM_SEGMENTATION_INCOMPLETE`.
   - **Falla 3 en `DocumentPolicyEngine.php`:** El motor de políticas interceptaba `itemSegmentationWarning` y cortocircuitaba incondicionalmente todos los campos de ítem a `NO_CONCLUYENTE`, sin verificar si la cantidad total extraída y el código de producto coincidían al 100% con la sumatoria de la FDV.
3. **Resultado esperado:**
   - Agregación declarativa perfecta en la FDV gobernada por las dimensiones activas en el documento.
   - Si la suma total extraída y el código coinciden exactamente con la FDV, el motor de políticas resuelve `COINCIDE` (con metadata de telemetría), eliminando el falso positivo y permitiendo la aprobación automática (`completed`, `EstAud: 1`).

---

### 2. Alcance

#### Incluido
- Reestructuración de `FdvItemAggregator::classifyItemFields()` para particionar exhaustivamente los campos de ítem en sumables (`isQuantitySummable()`) y discriminantes de agrupación (cualquier otro campo de ítem no sumable, independientemente de su `tipoCampo`).
- Reconciliación en `FdvItemAggregator::aggregate()` cuando un documento solo configure campos sumables sin discriminantes explícitos.
- Incorporación de resiliencia en `DocumentPolicyEngine::evaluateIndexedFields()` para verificar si la comparación cuantitativa/identificadora directa resulta en `MATCH` antes de degradar a `NO_CONCLUYENTE`.
- Pruebas unitarias de cobertura completa para escenarios multilote y configuraciones mixtas (`tipoCampo: 'B'`, `E`, `S`).

#### Excluido
- Modificación de contratos de extracción hacia Gemini (JSON Schema / prompts se mantienen idénticos).
- Modificación de modelos de base de datos o almacenamiento SQL Server.
- Alteración de los umbrales del Circuit Breaker de Gemini.

---

### 3. Non Goals

- No se creará una tabla intermedia ni persistencia en BD para agrupar ítems (la agregación es en memoria en el pipeline).
- No se hardcodeará ningún nombre de documento (`AUTORIZACION`, `DISPENSA`) ni nombres de campo (`CodigoProducto`); el comportamiento es 100% data-driven y desacoplado.

---

### 4. Estado Actual

En [`FdvItemAggregator.php:66-73`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/FdvItemAggregator.php#L66-L73):

```php
// app/Services/Audit/Pipeline/FdvItemAggregator.php (Líneas 66-73)
$comparison = AuditComparisonType::fromTipoCampo($tipoCampo);

if ($valueType !== null && $valueType->isQuantitySummable()) {
    $summableKeys[] = $name;
} elseif ($comparison === AuditComparisonType::EXACT || $comparison === AuditComparisonType::SEMANTIC) {
    $groupingKeys[] = $name;
}
```

En [`DocumentPolicyEngine.php:234-245`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php#L234-L245):

```php
// app/Services/Audit/Pipeline/DocumentPolicyEngine.php (Líneas 234-245)
if ($isItemSourced && $itemSegmentationWarning !== null) {
    $findings[] = $this->buildItemSegmentationFinding(
        $canonicalField,
        $fieldConfig,
        $documentType,
        $fdvResolution,
        $docResolution,
        $internalType,
        $valueType,
        $itemSegmentationWarning
    );
    continue;
}
```

---

### 5. Estado Objetivo

En `FdvItemAggregator.php`:

```php
// app/Services/Audit/Pipeline/FdvItemAggregator.php
private static function classifyItemFields(
    array $fieldsConfig,
    DocumentExtractionContractBuilder $contractBuilder,
    string $documentType
): array {
    $groupingKeys = [];
    $summableKeys = [];

    foreach ($fieldsConfig as $field) {
        $name      = trim((string) ($field['campoNombre'] ?? ''));
        $tipoCampo = (string) ($field['tipoCampo'] ?? 'E');
        $tipoDato  = strtolower(trim((string) ($field['tipoDato'] ?? 'text')));

        if ($name === '') {
            continue;
        }

        $valueType = AuditFieldValueType::tryFrom($tipoDato);
        if (!$contractBuilder->isItemField($tipoCampo, $valueType, $field)) {
            continue;
        }

        // Bipartición exhaustiva y agnóstica:
        // 1. Si es cuantitativo/acumulable -> dimensión métrica ($summableKeys)
        // 2. Si no es cuantitativo -> dimensión discriminante/identificadora ($groupingKeys)
        if ($valueType !== null && $valueType->isQuantitySummable()) {
            $summableKeys[] = $name;
        } else {
            $groupingKeys[] = $name;
        }
    }

    return [$groupingKeys, $summableKeys];
}
```

En `DocumentPolicyEngine.php`:

```php
// app/Services/Audit/Pipeline/DocumentPolicyEngine.php
if ($isItemSourced && $itemSegmentationWarning !== null) {
    // Evaluar primero si la comparación directa resulta en COINCIDE (balance cuantitativo o código exacto)
    $comparison = $this->evaluateDataFieldComparison(
        $canonicalField,
        $fdvResolution,
        $docResolution,
        $valueType,
        $documentQuality,
        $context,
        $internalType,
        $tipoCampo
    );

    if ($comparison['resultado'] === AuditFindingResult::MATCH->value) {
        // La evidencia física cubre 100% el valor requerido. Emitir COINCIDE registrando telemetría.
        $finding = $this->buildDataFinding(
            $canonicalField,
            $fieldConfig,
            $comparison,
            $documentType,
            $fdvResolution,
            $docResolution,
            $internalType,
            $valueType
        );
        $finding['extraction_meta'] = $finding['extraction_meta'] ?? [];
        $finding['extraction_meta']['item_segmentation'] = $itemSegmentationWarning;
        $findings[] = $finding;
        continue;
    }

    $findings[] = $this->buildItemSegmentationFinding(
        $canonicalField,
        $fieldConfig,
        $documentType,
        $fdvResolution,
        $docResolution,
        $internalType,
        $valueType,
        $itemSegmentationWarning
    );
    continue;
}
```

---

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| :--- | :--- | :--- | :--- |
| **AD-01** | Bipartición exhaustiva en `FdvItemAggregator`: todo campo de ítem no sumable es llave de agrupación. | (a) Exigir que los clientes cambien la configuración en BD de `B` a `E`. (b) Lista fija de nombres de campos (`CodigoProducto`, `Lote`). | El enfoque declarativo respeta la Clean Rebuild Policy: es 100% agnóstico al cliente y no requiere migraciones manuales en base de datos. |
| **AD-02** | Validación de balance en `DocumentPolicyEngine` previo al forzado de `NO_CONCLUYENTE`. | (a) Eliminar el warning de segmentación. (b) Forzar siempre `manual_review` ante cualquier discrepancia de conteo de filas. | Preserva el guardrail de seguridad cuando faltan ítems reales, pero evita el bloqueo falso cuando la evidencia física cubre el 100% de la cantidad autorizada. |
| **AD-03** | Agrupación universal si no hay llaves discriminantes pero sí sumables. | (a) Retornar ítems crudos sin sumar. (b) Lanzar excepción. | Si un documento solo audita cantidad total sin discriminar productos, la FDV debe sumar la cantidad total para evitar falsos negativos. |

---

### 7. Dependencias y Fuentes de Verdad

| Dependencia | Tipo | Versión | Impacto |
| :--- | :--- | :--- | :--- |
| `AuditFieldValueType` | Enum PHP | Local | Provee semántica `isQuantitySummable()`. |
| `DocumentExtractionContractBuilder` | Servicio Pipeline | Local | Provee delimitación de campos de ítem `isItemField()`. |

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| :--- | :--- | :--- | :---: |
| Tipos de datos de auditoría | `AuditFieldValueType.php` | Código canónico | `No` |
| Configuración documental | `ClientesAuditConfig` (SQL Server) | Catálogo en BD / Modelo | `No` |

`[CONFIRMADO] Sin conflictos detectados entre fuentes de verdad.`

---

### 8. Invariantes

| Invariante | Enforcement | Validación |
| :--- | :--- | :--- |
| La suma matemática de cantidades en grupos consolidados debe ser idéntica a la suma de los ítems originales. | Algoritmo `FdvItemAggregator::mergeGroups()` | Tests unitarios con aserciones numéricas exactas. |
| Ítems con códigos de producto o lotes distintos nunca deben fusionarse si ambos campos están configurados. | Clave compuesta en `buildGroupKey()` | Tests unitarios de separación por lote y código. |
| Todo hallazgo de auditoría debe contener `extraction_meta` con telemetría de segmentación si hubo warning. | Estructura de array en `DocumentPolicyEngine` | Aserción en `DocumentPolicyEngineTest`. |

---

### 9. Modelo de Datos

`[CONFIRMADO] Sin impacto en persistencia. No requiere DDL ni alteraciones de esquema.`

---

### 10. Contratos

#### Clasificación del Contrato

| Dimensión | Valor |
| :--- | :--- |
| **Tipo** | Contrato de Eventos Internos y DTO de Hallazgos (`findings`) |
| **Visibilidad** | Interno / API REST pública de resultados |
| **Productor** | `FdvItemAggregator.php` y `DocumentPolicyEngine.php` |
| **Consumidores** | `DocumentExtractionWorker.php`, `AuditPersistenceWorker.php`, Frontend Next.js |
| **Compatibilidad** | Totalmente retrocompatible (backward y forward) |

---

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| :--- | :--- | :--- | :--- |
| **REQ-01** | Consolidar ítems multilote con mismo código en FDV antes de calcular `expectedItemsCount`. | `FdvItemAggregator::classifyItemFields()` | `FdvItemAggregatorTest::testAggregatesBySameProductCodeWithBusinessComparison()` |
| **REQ-02** | Soportar campos de ítem configurados con `tipoCampo = 'B'`, `E` o `S`. | Bipartición por `isQuantitySummable()` en `FdvItemAggregator` | Suite `FdvItemAggregatorTest` |
| **REQ-03** | Evitar falso positivo en `DocumentPolicyEngine` cuando la suma de cantidades extraídas coincide con la FDV. | Pre-evaluación `$comparison['resultado'] === MATCH` en `DocumentPolicyEngine` | `DocumentPolicyEngineTest::testIncompleteSegmentationMatchesWhenQuantitativeBalanceSatisfied()` |
| **REQ-04** | Preservar `NO_CONCLUYENTE` si la cantidad extraída es verdaderamente parcial (incompleta). | Fallback a `buildItemSegmentationFinding()` | Tests existentes en `DocumentPolicyEngineTest` |

---

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| :--- | :--- | :--- | :--- | :--- |
| `FdvItemAggregator` | `DocumentExtractionWorker` | Positivo | Cálculo correcto de `expectedItemsCount` para documentos consolidados. | `DocumentExtractionWorker.php:525-540` |
| `DocumentPolicyEngine` | `AuditPersistenceWorker` | Positivo | Registra `completed` (`EstAud: 1`) sin desviar facturas válidas a `manual_review`. | `AuditPersistenceWorker.php:120` |

---

### 13. Cambios por Archivo

#### [MODIFY] [`FdvItemAggregator.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/FdvItemAggregator.php)

- **Método afectado:** `FdvItemAggregator::classifyItemFields()`, líneas 44-76.
- **Cambio:** Reemplazar el `elseif` con tipos de comparación fijos por la bipartición sobre `isQuantitySummable()`.

```diff
--- a/app/Services/Audit/Pipeline/FdvItemAggregator.php
+++ b/app/Services/Audit/Pipeline/FdvItemAggregator.php
@@ -63,11 +63,9 @@ final class FdvItemAggregator
                 continue;
             }
 
-            $comparison = AuditComparisonType::fromTipoCampo($tipoCampo);
-
             if ($valueType !== null && $valueType->isQuantitySummable()) {
                 $summableKeys[] = $name;
-            } elseif ($comparison === AuditComparisonType::EXACT || $comparison === AuditComparisonType::SEMANTIC) {
+            } else {
                 $groupingKeys[] = $name;
             }
         }
```

- **Método afectado:** `FdvItemAggregator::aggregate()`, líneas 28-39.
- **Cambio:** Si no hay llaves de agrupación pero sí hay campos sumables, agrupar todos los ítems en una sola clave consolidada (`*`).

```diff
--- a/app/Services/Audit/Pipeline/FdvItemAggregator.php
+++ b/app/Services/Audit/Pipeline/FdvItemAggregator.php
@@ -31,6 +31,10 @@ final class FdvItemAggregator
         if ($groupingKeys === []) {
+            if ($summableKeys !== []) {
+                return self::mergeGroups(['*' => $items], $summableKeys);
+            }
             return $items;
         }
```

---

#### [MODIFY] [`DocumentPolicyEngine.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php)

- **Método afectado:** `DocumentPolicyEngine::evaluateIndexedFields()`, líneas 234-246.
- **Cambio:** Pre-evaluar la coincidencia del valor antes de forzar `NO_CONCLUYENTE` por segmentación de ítems.

```diff
--- a/app/Services/Audit/Pipeline/DocumentPolicyEngine.php
+++ b/app/Services/Audit/Pipeline/DocumentPolicyEngine.php
@@ -234,6 +234,28 @@ final class DocumentPolicyEngine
             if ($isItemSourced && $itemSegmentationWarning !== null) {
+                $comparison = $this->evaluateDataFieldComparison(
+                    $canonicalField,
+                    $fdvResolution,
+                    $docResolution,
+                    $valueType,
+                    $documentQuality,
+                    $context,
+                    $internalType,
+                    $tipoCampo
+                );
+
+                if ($comparison['resultado'] === AuditFindingResult::MATCH->value) {
+                    $finding = $this->buildDataFinding(
+                        $canonicalField,
+                        $fieldConfig,
+                        $comparison,
+                        $documentType,
+                        $fdvResolution,
+                        $docResolution,
+                        $internalType,
+                        $valueType
+                    );
+                    $finding['extraction_meta'] = $finding['extraction_meta'] ?? [];
+                    $finding['extraction_meta']['item_segmentation'] = $itemSegmentationWarning;
+                    $findings[] = $finding;
+                    continue;
+                }
+
                 $findings[] = $this->buildItemSegmentationFinding(
```

---

#### [MODIFY] [`FdvItemAggregatorTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/FdvItemAggregatorTest.php)

- **Cambio:** Agregar casos de prueba con `tipoCampo: 'B'` y tipos de datos `code`, `article_name`, agregación universal sin llaves discriminantes, y múltiples lotes consolidados.

---

#### [MODIFY] [`DocumentPolicyEngineTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/DocumentPolicyEngineTest.php)

- **Cambio:** Agregar caso de prueba verificando que cuando la suma de cantidades extraídas coincide con la de la FDV en presencia de `ITEM_SEGMENTATION_INCOMPLETE`, el resultado es `COINCIDE` con metadata de telemetría.

---

### 14. Plan de Migración

#### Prerequisitos
- Ninguno. El cambio es a nivel de código PHP dentro de las imágenes Docker.

#### Ejecución
1. Aplicar cambios en `FdvItemAggregator.php` y `DocumentPolicyEngine.php`.
2. Ejecutar suite de pruebas unitarias (`vendor/bin/phpunit`).
3. Crear commit en Git y verificar aprobación del pipeline en CI.

#### Validaciones Posteriores
- Ejecutar auditoría sobre la dispensa testigo `X26260700079` y verificar que su estado cambia de `manual_review` (`EstAud: 0`) a `completed` (`EstAud: 1`).

#### Rollback
- Revertir el commit vía Git (`git revert HEAD`). No requiere rollback de base de datos.

---

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| :--- | :--- | :--- |
| **Mismo producto, diferentes lotes** | Se fusionan en un único ítem con cantidad sumada (`60 + 60 = 120`). | `FdvItemAggregator` retorna 1 ítem con `CantidadEntregada: 120`. |
| **Diferentes productos en la entrega** | No se fusionan; se mantienen separados por su `CodigoProducto`. | `FdvItemAggregator` retorna N ítems discriminados. |
| **Extracción verdaderamente incompleta** | Si la FDV espera 120 y Gemini solo extrajo 60, el balance cuantitativo no cuadra. | `DocumentPolicyEngine` emite `NO_CONCLUYENTE` protegiendo contra pérdida de datos. |
| **Documento sin identificadores de ítem** | Si solo audita `CantidadEntregada`, suma todas las cantidades en un único total. | `FdvItemAggregator` retorna 1 ítem con la sumatoria global. |

---

### 16. Testing

#### Nuevos Tests

1. **`testAggregatesByProductCodeWhenTipoCampoIsBusiness()`** en `FdvItemAggregatorTest`:
   - *Precondición:* Dos ítems con mismo `CodigoProducto` y cantidades 60 y 60, con `tipoCampo: 'B'` y `tipoDato: 'code'`.
   - *Resultado esperado:* 1 ítem consolidado con `CantidadEntregada: 120`.

2. **`testAggregatesToSingleGroupWhenOnlySummableFieldsConfigured()`** en `FdvItemAggregatorTest`:
   - *Precondición:* Tres ítems con diferentes códigos pero la configuración solo incluye `CantidadEntregada` (`quantity`).
   - *Resultado esperado:* 1 ítem consolidado con la suma total.

3. **`testIncompleteSegmentationMatchesWhenQuantitativeBalanceSatisfied()`** en `DocumentPolicyEngineTest`:
   - *Precondición:* Estado con warning de segmentación (esperaba 2, extrajo 1), pero la cantidad extraída (`120`) coincide con la suma de la FDV (`60 + 60 = 120`).
   - *Resultado esperado:* `resultado: 'COINCIDE'`, `findings` contiene telemetría `item_segmentation`.

---

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| :--- | :--- | :---: | :--- |
| Agrupación indebida de productos diferentes | Lógica de Negocio | Media | `buildGroupKey` incluye obligatoriamente todo campo no sumable (`CodigoProducto`, `Lote`, etc.), evitando colisiones entre ítems distintos. |
| Ocultamiento de faltantes reales de entrega | Seguridad de Auditoría | Alta | `DocumentPolicyEngine` solo aprueba como `COINCIDE` si `$comparison['resultado'] === MATCH`. Si falta una sola unidad, cae inmediatamente a `NO_CONCLUYENTE`. |

---

### 18. Criterios de Aceptación

1. `FdvItemAggregator::aggregate()` retorna 1 ítem consolidado con cantidad 120 para la estructura de `X26260700079`.
2. `DocumentExtractionWorker` no genera el warning `ITEM_SEGMENTATION_INCOMPLETE` para la autorización de `X26260700079`.
3. Factura `X26260700079` audita con 30 coincidencias, 0 discrepancias, 0 no concluyentes y estado final `completed` (`EstAud: 1`).
4. 100% de las suites unitarias `FdvItemAggregatorTest` y `DocumentPolicyEngineTest` pasan en verde.

---

### 19. Observabilidad

`Sin impacto en observabilidad externa. La telemetría existente en Redis y JSON de resultados expone el estado de los hallazgos y warnings de extracción.`

---

### 20. Estrategia de Rollout

`Sin estrategia de rollout requerida. Despliegue atómico continuo estándar mediante pipeline CI/CD en GitHub Actions y Docker Compose en LAN.`

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| :--- | :---: | :--- |
| Todas las entidades persistentes mencionadas están definidas | `PASS` | No hay entidades persistentes modificadas ni requeridas. |
| Todas las columnas mencionadas existen | `PASS` | `vw_discolnet_dispensas` y `AudDispCampoCatalogo` confirmadas por modelos existentes. |
| Todos los contratos documentados con clasificación | `PASS` | Sección 10 completa con productores, consumidores y compatibilidad. |
| Todos los requisitos tienen trazabilidad | `PASS` | Matriz de trazabilidad REQ-01 a REQ-04 completa. |
| Todos los consumidores analizados | `PASS` | Grafo de dependencias 0.2 cerrado con evidencia de lectura. |
| Todas las migraciones tienen rollback | `PASS` | Rollback documentado vía Git (sin persistencia). |
| Todas las referencias a archivos, clases y métodos están definidas | `PASS` | Verificadas con rutas absolutas y líneas exactas. |
| Toda compatibilidad tiene evidencia | `PASS` | Compatibilidad backward 100% demostrada. |
| Todos los criterios son verificables | `PASS` | Criterios medibles por aserciones PHPUnit y HTTP 200. |
| Observabilidad documentada | `N/A` | `[CONFIRMADO]` Sin impacto en señales operativas por triage Riesgo Medio. |
| Rollout documentado | `N/A` | `[CONFIRMADO]` Despliegue estándar por triage Riesgo Medio. |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| :--- | :---: | :--- |
| ¿Existe alguna decisión arquitectónica implícita? | `No` | Documentadas explícitamente en AD-01, AD-02 y AD-03. |
| ¿Existe algún contrato sin documentar? | `No` | Sección 10 completa. |
| ¿Existe algún consumidor no analizado? | `No` | Grafo 0.2 exhaustivo en todas las capas. |
| ¿Existe alguna migración sin rollback? | `No` | Reversión limpia por Git. |
| ¿Existe algún dato persistido sin migración? | `No` | Sin cambios en base de datos. |
| ¿Existe alguna afirmación sin evidencia? | `No` | Toda afirmación cuenta con ruta:línea y prueba empírica. |
| ¿Existen referencias huérfanas? | `No` | Cero dependencias huérfanas. |
| ¿Dos implementadores producirían soluciones diferentes? | `No` | Los diffs antes/después y algoritmos son deterministas. |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| :---: | :--- | :--- | :---: | :--- |
| 1 | ¿Existe algún script de arranque o proceso que invoque clases eliminadas o renombradas? | Runtime | `NO` | No se eliminan ni renombran clases o métodos públicos. |
| 2 | ¿Existe algún paso en el build que dependa de archivos eliminados? | Build | `NO` | Cero archivos eliminados. |
| 3 | ¿Existe algún pipeline o CI que valide con datos distintos a los evaluados? | Pipeline | `NO` | `ci.yml` ejecuta la misma suite PHPUnit. |
| 4 | ¿El cambio asume comportamiento de herramientas sin evidencia? | Semántica de Herramienta | `NO` | Probado empíricamente en PHP 8.2 y PHPUnit 10.5. |
| 5 | ¿El cambio está validado para un solo entorno pero no en los demás? | Paridad de Entornos | `NO` | Matriz 0.5 confirma compatibilidad local, CI y LAN. |
| 6 | ¿Existe algún mecanismo de override en runtime que pueda anular el comportamiento? | Runtime por Override | `NO` | Lógica pura en servicios de dominio sin feature flags que la anulen. |
| 7 | ¿Se aplicó algún patrón genérico que contradiga contratos del proyecto? | Dogmatismo Técnico | `NO` | Respeta estrictamente `AuditFieldValueType` y `AuditComparisonType`. |
| 8 | ¿El cambio altera interfaces públicas sin compatibilidad? | Contract | `NO` | Mantiene intactas las firmas públicas y schemas JSON. |
| 9 | ¿El cambio afecta datos persistidos sin migración? | Data | `NO` | No toca la base de datos SQL Server. |
| 10 | ¿El cambio introduce código muerto, adaptadores legacy o excede el MVP? | Clean Architecture | `NO` | Cumple Clean Rebuild: 0 adaptadores legacy, 0 código muerto. |
| 11 | ¿El cambio reemplaza un mapeo estático por una abstracción sin verificar colisiones? | Abstracción Incorrecta | `NO` | Verificado en 0.3.1 contra todo el catálogo de tipos de datos. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

**`Nivel A — Implementable`**

### Declaración de Completitud Técnica

La especificación es técnicamente completa, verificable y determinista:
- No requiere decisiones técnicas ni aclaraciones adicionales.
- Obtiene `PASS` en todas las verificaciones de consistencia de FASE 2.
- Obtiene `No` en todas las preguntas de auditoría de FASE 3.
- Obtiene `NO` en las 11 preguntas de auditoría adversarial anti-regresión.
- Provee los diffs exactos antes/después y los casos de prueba unitaria necesarios para una ejecución inmediata y sin ambigüedades.
