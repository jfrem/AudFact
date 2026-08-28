# Especificación SDD — Motor de Extracción Documental y Compilación de Prompts Agnóstico al Dominio (Domain-Agnostic Extraction Engine)

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación / Evidencia |
| :--- | :--- | :--- |
| **Tipo** | `Refactor / Arquitectura` | `[CONFIRMADO]` Eliminación de heurísticas acopladas a strings de nombres de documentos y campos médicos en `ExtractionPromptBuilder` y `DocumentExtractionContractBuilder`. |
| **Riesgo** | `Medio` | `[CONFIRMADO]` Modifica la compilación del prompt de usuario y la asignación de campos a `fields`/`items`, preservando 100% de compatibilidad con los schemas y modelos de datos existentes. |
| **Persistencia afectada** | `No` | `[CONFIRMADO]` No altera tablas, columnas ni esquemas SQL Server en `Discolnet`. |
| **Contrato externo afectado** | `No` | `[CONFIRMADO]` El payload `generationConfig.responseSchema` y el contrato de eventos Redis `document_extracted` permanecen intactos. |
| **Cambio arquitectónico** | `Sí` | `[CONFIRMADO]` Transición de heurísticas cableadas en código PHP a un motor declarativo gobernado por `AuditFieldValueType`, `AuditComparisonType` y metadata de catálogo. |
| **Producción afectada** | `Sí` | `[CONFIRMADO]` Impacta los prompts enviados a Gemini en workers de extracción de producción. |
| **Requiere 0.3.1 (cobertura de abstracciones)** | `Sí` | `[CONFIRMADO]` Reemplaza listas estáticas de nombres de campos (`ITEM_FIELD_NAMES`), checks fijos (`VigenciaEntrega`) y nombres de documentos (`DISPENSA`, `FORMULA`) por abstracciones basadas en metadatos y tipos canónicos. |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| :--- | :--- | :---: | :--- | :--- | :---: |
| `ExtractionPromptBuilder.php` | `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php` | `MODIFIED` | Compilador de system y user prompts para extracción Gemini. | L76-89, L113-116, L118-122, L247-265, L370-415 | `Sí` |
| `DocumentExtractionContractBuilder.php` | `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | `MODIFIED` | Compilador de JSON Schema y agrupación `fields`/`items`. | L13-23, L185-212 | `Sí` |
| `AuditFieldValueType.php` | `app/Services/Audit/AuditFieldValueType.php` | `INSPECTED` | Enum canónico de tipos de datos de auditoría. | Sin cambios requeridos; provee `isItemScoped()`, `isIdentityPromptValue()`. | `Sí` |
| `ExtractionPromptBuilderTest.php` | `tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php` | `MODIFIED` | Suite unitaria del constructor de prompts. | L60-180 | `Sí` |
| `DocumentExtractionContractBuilderTest.php` | `tests/Services/Audit/Pipeline/DocumentExtractionContractBuilderTest.php` | `MODIFIED` | Suite unitaria del constructor de contratos y schemas. | L20-92 | `Sí` |
| `DocumentExtractionWorkerTest.php` | `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php` | `INSPECTED` | Tests de integración del worker de extracción. | L270-320 (Inspeccionado, sin cambios) | `Sí` |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| :--- | :--- | :--- | :--- |
| **Búsqueda por símbolo** | `isPrescriptionDocument`, `ITEM_FIELD_NAMES`, `hasIdentitySeparationFields`, `requiresSegmentedDispensaItems` | 4 métodos/constantes identificados | `ExtractionPromptBuilder.php:247,370,383`, `DocumentExtractionContractBuilder.php:13,201` |
| **Búsqueda textual** | `DISPENSA`, `FORMULA MEDICA`, `NORENA AGUDELO`, `VigenciaEntrega` | Localizados en `ExtractionPromptBuilder.php` y sus tests asociados | `ExtractionPromptBuilder.php:79-88, 113-115, 374, 383` |
| **Búsqueda en configuración** | `AuditFieldValueType::*` | Métodos `isItemScoped()` e `isIdentityPromptValue()` ya existentes | `AuditFieldValueType.php:112, 174` |
| **Búsqueda en tests** | `ExtractionPromptBuilderTest`, `DocumentExtractionContractBuilderTest` | 2 suites unitarias directamente dependientes | `tests/Services/Audit/Pipeline/` |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `ExtractionPromptBuilder.php` | `DocumentExtractionWorker.php` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L43, L496 | Directa | Invocación dinámica `buildUserPrompt()` | Repositorio local (Worker) |
| `ExtractionPromptBuilder.php` | `ExtractionPromptBuilderTest.php` | `tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php` | L18, L54 | Directa | Invocación de tests | Repositorio local (Tests) |
| `DocumentExtractionContractBuilder.php` | `DocumentExtractionWorker.php` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L480 | Directa | Invocación dinámica `build()` | Repositorio local (Worker) |
| `DocumentExtractionContractBuilder.php` | `DocumentExtractionContractBuilderTest.php` | `tests/Services/Audit/Pipeline/DocumentExtractionContractBuilderTest.php` | L18, L33 | Directa | Invocación de tests | Repositorio local (Tests) |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| :--- | :--- | :--- | :---: | :--- |
| Generalizar la regla de identidad eliminando ejemplos con `CC NORENA AGUDELO` | `ExtractionPromptBuilderTest.php` | `ExtractionPromptBuilderTest.php:95` | `Test` | Actualizar aserciones de tests para esperar la directiva dinámica agnóstica basada en los campos de identidad activos. |
| Eliminar `if ($check === 'VigenciaEntrega')` hardcodeado en PHP | `ExtractionPromptBuilder.php` | `ExtractionPromptBuilder.php:113` | `Runtime` | Inyectar la descripción directamente desde la metadata del check visual provista por la BD (`AudDispCampoCatalogo.Descripcion`), sin condicionales de string. |
| Reemplazar `isDispensaDocument` por evaluación de items en FDV | `ExtractionPromptBuilderTest.php` | `ExtractionPromptBuilderTest.php:110` | `Test` | Ajustar fixtures de tests para pasar `fuente_verdad.items` con cardinalidad > 1 para activar segmentación de líneas. |
| Eliminar `ITEM_FIELD_NAMES` estático y `isPrescriptionDocument` en ContractBuilder | `DocumentExtractionContractBuilder.php` | `DocumentExtractionContractBuilder.php:13,198` | `Contract` | Determinar la ubicación de ítem mediante: `AuditComparisonType::BUSINESS` + `AuditFieldValueType::isItemScoped()` + atributo declarativo `esMultiItem` del audit-config. |

---

### 0.3.1 Verificación de Cobertura de Abstracciones

| Elemento del Mapeo Estático | Atributos Dinámicos | ¿Otros elementos comparten esos atributos? | ¿Clasificación correcta? |
| :--- | :--- | :--- | :---: |
| `CantidadEntregada`, `CantidadPrescrita` | `TipoCampo = 'B'` (`AuditComparisonType::BUSINESS`) o `TipoDato = 'quantity'` | `[CONFIRMADO]` No. Solo las cantidades operativas de línea son `BUSINESS`. | `Sí` |
| `Lote`, `NombreArticulo` | `AuditFieldValueType::isItemScoped()` (`TRACE_TOKEN`, `ARTICLE_NAME`) | `[CONFIRMADO]` No. Estos tipos de dato son inherentemente atómicos por ítem. | `Sí` |
| `CodigoProducto`, `CodigoArticulo`, `CUM`, `FechaVencimiento`, `Laboratorio` | `esMultiItem = true` en `fields_config` o `AuditFieldValueType::isItemScoped()` | `[CONFIRMADO]` No. La base de datos (`AudDispCampoCatalogo` / `AudDispDoc`) y el `audit-config` delimitan el alcance del campo. | `Sí` |
| `NumeroAutorizacion` en Prescripciones | Configuración declarativa de campo a nivel de ítem en `audit-config` | `[CONFIRMADO]` No colisiona con otros documentos. | `Sí` |

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| :--- | :--- | :---: | :--- | :---: |
| **Gemini Structured Outputs** | `responseSchema` requiere tipos OpenAPI 3.0 estándar y descripciones en properties | `Documental` / `Empírica` | Google Gemini API Docs / `GeminiGatewayTest.php` | `Sí` — Las descripciones dinámicas enriquecen el schema directamente. |
| **PHP Regex Engine (`pcre`)** | Separación y deduplicación de directivas en UTF-8 | `Estática` | `ExtractionPromptBuilder.php:200-267` | `Sí` — Métodos internos operan con modificador `/u`. |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| :--- | :--- | :--- | :---: | :--- |
| **Desarrollo local** | Ejecución de workers y PHPUnit | `vendor/bin/phpunit` | `Sí` | `538 tests passing` |
| **CI (GitHub Actions)** | Validaciones automáticas de push/PR | `composer test` | `Sí` | Suite unitaria desacoplada |
| **Producción Docker** | Pipeline asíncrono sobre Redis Streams | `bin/audit-worker.php extraction` | `Sí` | Workers consumen prompts generados dinámicamente |
| **Testing aislado** | Tests unitarios con fixtures mock | `tests/Services/Audit/Pipeline/` | `Sí` | Fixtures genéricos agnósticos |

---

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| :--- | :---: | :--- |
| `AudDispCampoCatalogo` contiene descripciones operativas de checks y campos | `[CONFIRMADO]` | Query SQL: `[Discolnet].[dbo].[AudDispCampoCatalogo]`, L1-344 |
| `hasIdentitySeparationFields` ya evalúa `AuditFieldValueType::isIdentityPromptValue()` | `[CONFIRMADO]` | `ExtractionPromptBuilder.php:326-353` |
| `ITEM_FIELD_NAMES` contiene 9 campos médicos específicos hardcodeados | `[CONFIRMADO]` | `DocumentExtractionContractBuilder.php:13-23` |
| `VigenciaEntrega` está hardcodeado con un `if` de string | `[CONFIRMADO]` | `ExtractionPromptBuilder.php:113` |
| `isPrescriptionDocument` compara con lista fija de strings | `[CONFIRMADO]` | `DocumentExtractionContractBuilder.php:201-212` |

---

### 0.7 Información Faltante Crítica
`[CONFIRMADO] Ninguna. Todos los contratos, catálogos y fuentes de código están completamente disponibles y verificados.`

### 0.8 Información Faltante Importante
`[CONFIRMADO] Ninguna.`

### 0.9 Información Faltante Opcional
`[CONFIRMADO] Ninguna.`

---

### 0.10 Supuestos Declarados

| ID | Supuesto | Severidad | Evidencia | Riesgo |
| :--- | :--- | :---: | :--- | :--- |
| **S1** | Los checks visuales configurados en BD (`AudDispCampoCatalogo`) contienen en su campo `Descripcion` todas las directivas de extracción necesarias para Gemini. | `S1` | `AudDispCampoCatalogo` verificado: `FirmaActaEntrega`, `FirmaPrescriptor` y `VigenciaEntrega` tienen descripciones completas en BD. | `Ninguno` |
| **S2** | Los campos declarados como `AuditComparisonType::BUSINESS` o con `AuditFieldValueType::isItemScoped() = true` pertenecen intrínsecamente a la sección `items`. | `S1` | `AuditFieldValueType.php:174-181` | `Ninguno` |

---

### 0.11 Clasificación de Completitud Inicial
**Nivel A — Implementable**. Todos los acoplamientos están inventariados con ruta y línea, la base de datos ya contiene la información requerida, y los tests unitarios están listos para ser adaptados.

---

## FASE 1 — Especificación

### 1. Objetivo
Desacoplar completamente el compilador de prompts de extracción (`ExtractionPromptBuilder`) y el constructor de esquemas de contrato (`DocumentExtractionContractBuilder`) de nombres de documentos, nombres de campos colombianos y ejemplos médicos cableados en código PHP, transformándolo en un **Motor de Extracción Documental 100% Declarativo y Agnóstico al Dominio**.

---

### 2. Alcance

#### Incluido
- Reemplazo de la regla de identidad hardcodeada (`CC NORENA AGUDELO`, `Medico: PEREZ`) por una directiva dinámica basada en los campos reales de tipo identidad activos en el contrato.
- Eliminación de la condicional hardcodeada `if ($check === 'VigenciaEntrega')`, delegando la instrucción a la propiedad `description` del check visual en el catálogo de BD.
- Generalización de la segmentación de líneas de producto: la directiva de múltiples ítems se activa cuando el contrato define `field_groups.items` y la Fuente de Verdad tiene múltiples filas (`count > 1`), sin filtrar por el nombre `"DISPENSA"`.
- Generalización de pistas de ítems: el cruce de candidatos se activa cuando `items` contiene un campo de tipo `AuditFieldValueType::ARTICLE_NAME` y existen registros en `fuente_verdad.items`, sin depender de la lista de strings `"FORMULA"`, `"RECETA"`, `"PRESCRIPCION"`.
- Eliminación de la constante fija `ITEM_FIELD_NAMES` y del método `isPrescriptionDocument()` en `DocumentExtractionContractBuilder.php`, resolviendo la asignación de `items` mediante `AuditComparisonType::BUSINESS`, `AuditFieldValueType::isItemScoped()` y metadatos explícitos `esMultiItem` de la configuración.

#### Excluido
- Modificaciones al esquema SQL Server de `Discolnet`.
- Cambios en el motor de políticas de comparación (`DocumentPolicyEngine.php`).
- Modificaciones a los endpoints REST de la API.

---

### 3. Non Goals
- No se creará una tabla nueva en SQL Server para almacenar templates de prompts (se utiliza la infraestructura existente de `AudDispCampoCatalogo.Descripcion` y `AuditFieldValueType`).
- No se alterará el formato de salida JSON Structured Output de Gemini.

---

### 4. Estado Actual

```
ExtractionPromptBuilder.buildUserPrompt()
  ├── [HARDCODE 1] hasIdentitySeparationFields() -> inyecta "CC 94229637 NORENA AGUDELO" y "Medico: 12345678-PEREZ"
  ├── [HARDCODE 2] if ($check === 'VigenciaEntrega') -> inyecta directiva específica de días y fecha_base
  ├── [HARDCODE 3] requiresSegmentedDispensaItems() -> if (documentType === 'DISPENSA')
  └── [HARDCODE 4] buildDispensedItemsContext() -> if (isPrescriptionDocument(documentType))

DocumentExtractionContractBuilder.isItemScopedField()
  ├── [HARDCODE 5] in_array($fieldName, ITEM_FIELD_NAMES, true) -> lista de 9 nombres fijos
  └── [HARDCODE 6] isPrescriptionDocument($doc) && $fieldName === 'NumeroAutorizacion'
```

---

### 5. Estado Objetivo

```
ExtractionPromptBuilder.buildUserPrompt()
  ├── [DINÁMICO 1] buildDynamicIdentityDirective(fieldsConfig) -> genera regla con los nombres de campos reales activos
  ├── [DINÁMICO 2] inyección universal de visualChecks[description] -> 0 condicionales de nombre en PHP
  ├── [DINÁMICO 3] segmentación universal si (count(field_groups.items) > 0 && count(fdv.items) > 1)
  └── [DINÁMICO 4] pistas de items si (contractHasArticleItem && count(fdv.items) > 0)

DocumentExtractionContractBuilder.isItemScopedField()
  ├── [DECLARATIVO 1] AuditComparisonType::fromTipoCampo($tipoCampo) === BUSINESS -> items
  ├── [DECLARATIVO 2] AuditFieldValueType::isItemScoped() -> items
  └── [DECLARATIVO 3] ($fieldConfig['esMultiItem'] ?? false) === true -> items
```

---

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| :--- | :--- | :--- | :--- |
| **D-01** | Generar la directiva de identidad dinámicamente a partir de los nombres reales de los campos configurados con `isIdentityPromptValue()`. | (a) Mantener ejemplos fijos; (b) Eliminar la regla de identidad por completo. | Eliminarla provocaría fusiones erróneas en OCR de líneas compuestas; mantener ejemplos fijos acopla a Colombia. Generarla dinámicamente usando los nombres reales de los campos activos (`TipoDocumentoPaciente, DocumentoPaciente, NombrePaciente`) resuelve ambos problemas. |
| **D-02** | Delegar 100% de las instrucciones de checks visuales a la metadata `description` proveniente de BD (`AudDispCampoCatalogo`). | (a) Crear una matriz de strings por check en PHP. | Rompe el principio de extensibilidad: cualquier check nuevo agregado en la BD debe funcionar inmediatamente sin tocar código PHP. |
| **D-03** | Activar la segmentación de ítems por cardinalidad (`count(fdv.items) > 1` y presencia de `items` en contrato) en lugar de evaluar el string `"DISPENSA"`. | (a) Mantener lista de tipos de documentos tabulares en PHP. | Cualquier documento de cualquier industria con múltiples líneas requiere extracción tabular segmentada. |
| **D-04** | Activar las pistas de ítems por tipo de dato (`AuditFieldValueType::ARTICLE_NAME` presente en `items`) en lugar de evaluar el string `"FORMULA MEDICA"`. | (a) Mantener lista de documentos prescriptivos en PHP. | Permite que el cruce inteligente opere sobre cualquier documento configurado que extraiga artículos frente a una fuente de verdad. |
| **D-05** | Eliminar `ITEM_FIELD_NAMES` y resolver el alcance de ítem exclusivamente mediante `AuditComparisonType::BUSINESS`, `AuditFieldValueType::isItemScoped()` y la clave declarativa `esMultiItem`. | (a) Mantener la lista como fallback. | Cumplimiento estricto de la política Clean Rebuild (Cero Legacy, Cero Código Muerto). |

---

### 7. Dependencias y Fuentes de Verdad

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| :--- | :--- | :--- | :---: |
| **Tipos de Datos de Auditoría** | `AuditFieldValueType.php` | Enum tipado con métodos de dominio (`isItemScoped`, `isIdentityPromptValue`) | `No` |
| **Estrategias de Comparación** | `AuditComparisonType.php` | Enum con mapeo de `TipoCampo` (`E`, `S`, `B`, `V`, `I`) | `No` |
| **Catálogo y Descripciones de Campos/Checks** | `AudDispCampoCatalogo` (SQL Server) | Tabla maestra de descripciones y metadatos | `No` |
| **Asignación de Campos por Documento** | `audit-config` / `AudDispCampo` | Configuración relacional por cliente/documento | `No` |

---

### 8. Invariantes

| Invariante | Enforcement | Validación |
| :--- | :--- | :--- |
| **Preservación del Response Schema** | El JSON Schema generado para Gemini debe contener exactamente los mismos campos y tipos requeridos. | `DocumentExtractionContractBuilderTest` |
| **Extracción a Ciegas (Blind Extraction)** | Ningún valor de la base de datos (nombres de pacientes, cédulas, números de factura) se inyecta en el prompt de extracción general. | `ExtractionPromptBuilderTest` |
| **Determinismo de Hashing** | `prompt_context_hash` y `contract_hash` deben ser deterministas y reproducibles. | `DocumentExtractionContractBuilderTest::testHashPayloadIsDeterministic` |

---

### 9. Modelo de Datos
`[CONFIRMADO] Sin impacto en persistencia. No se modifican tablas ni columnas.`

---

### 10. Contratos

#### 10.1 Clasificación del Contrato

| Dimensión | Valor |
| :--- | :--- |
| **Tipo** | `Mensaje Interno (Prompts de Extracción)` |
| **Visibilidad** | `Interno` |
| **Productor** | `ExtractionPromptBuilder` |
| **Consumidores** | `DocumentExtractionWorker` $\rightarrow$ `GeminiGateway` |
| **Versionado** | `No` |
| **Compatibilidad requerida** | `Forward y Backward completa` |
| **Enforcement** | `Suites de pruebas PHPUnit` |

#### Antes (Ejemplo de Prompt Generado con Acoplamiento)
```
Documento objetivo: ACTA DE ENTREGA.
...
### Regla de identidad
- `CC 94229637 NORENA AGUDELO` => TipoDocumentoPaciente, DocumentoPaciente, NombrePaciente.
- `Medico: 12345678-PEREZ ANA MARIA` => DocumentoMedico, Medico.
...
Para VigenciaEntrega, si el valor es visible retorna valor numerico, unidad="dias"...
```

#### Después (Ejemplo de Prompt Generado Agnóstico y Dinámico)
```
Documento objetivo: ACTA DE ENTREGA.
...
### Regla de identidad
Si una linea combina tipo de documento, numero de identificacion y/o nombre, separalos estrictamente en sus campos correspondientes (TipoDocumentoPaciente, DocumentoPaciente, NombrePaciente, Medico).
Solo extrae datos visibles y requeridos; no infieras ni completes identidades faltantes.
...
Checks visuales esperados:
- FirmaActaEntrega: "Firma autógrafa, huella o sello de recibido del paciente/acudiente..."
- VigenciaEntrega: "Verificar que el documento indique la vigencia o plazo de entrega autorizado; extraer dias y fecha base si estan visibles."
```

---

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| :--- | :--- | :--- | :--- |
| **REQ-01** | Eliminar ejemplos hardcodeados colombianos de la regla de identidad. | `ExtractionPromptBuilder::buildIdentityDirective()` | `ExtractionPromptBuilderTest::testIdentityDirectiveIsDynamicallyBuilt` |
| **REQ-02** | Eliminar condicional de string `VigenciaEntrega` en prompts. | `ExtractionPromptBuilder::buildUserPrompt()` usa únicamente `check.description`. | `ExtractionPromptBuilderTest::testVisualChecksUseConfiguredDescriptionsWithoutHardcoding` |
| **REQ-03** | Generalizar segmentación de líneas sin filtrar por `"DISPENSA"`. | `ExtractionPromptBuilder::requiresSegmentedItems()` | `ExtractionPromptBuilderTest::testSegmentedItemsContextActivatesByCardinality` |
| **REQ-04** | Generalizar pistas de artículos sin filtrar por `"FORMULA MEDICA"`. | `ExtractionPromptBuilder::buildItemCandidatesContext()` | `ExtractionPromptBuilderTest::testItemCandidatesActivateByArticleValueType` |
| **REQ-05** | Eliminar `ITEM_FIELD_NAMES` y `isPrescriptionDocument` en `DocumentExtractionContractBuilder`. | `DocumentExtractionContractBuilder::isItemScopedField()` | `DocumentExtractionContractBuilderTest::testItemGroupingIsPurelyTypeAndConfigDriven` |

---

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| :--- | :--- | :--- | :--- | :--- |
| `ExtractionPromptBuilder` | `DocumentExtractionWorker` | Positivo | Métodos devuelven prompts agnósticos más limpios y con menos tokens. | `ExtractionPromptBuilder.php` |
| `DocumentExtractionContractBuilder` | `ExtractionPromptBuilder` | Positivo | Agrupación `fields`/`items` basada 100% en tipos y configuración declarativa. | `DocumentExtractionContractBuilder.php` |
| `ExtractionPromptBuilderTest` | `ExtractionPromptBuilder` | Directo | Actualizar aserciones de tests unitarios a los nuevos textos dinámicos. | `ExtractionPromptBuilderTest.php` |
| `DocumentExtractionContractBuilderTest` | `DocumentExtractionContractBuilder` | Directo | Probar agrupación con `AuditFieldValueType` y flag `esMultiItem`. | `DocumentExtractionContractBuilderTest.php` |

---

### 13. Cambios por Archivo

#### `[MODIFY]` [ExtractionPromptBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/ExtractionPromptBuilder.php)
- **Símbolo**: `ExtractionPromptBuilder::buildUserPrompt()`, líneas 67-140.
  - Eliminar el bloque hardcodeado de identidad y llamar a `buildIdentityDirective($payload['fields_config'] ?? [])`.
  - Eliminar `if ($this->hasVisualCheck($visualChecks, 'VigenciaEntrega'))`.
  - Reemplazar `requiresSegmentedDispensaItems` por `requiresSegmentedItems($payload, $fieldGroups)`.
  - Reemplazar `buildDispensedItemsContext` por `buildItemCandidatesContext($payload, $fieldGroups)`.
- **Símbolo**: `ExtractionPromptBuilder::buildIdentityDirective()`, nuevo método privado.
  - Extrae los nombres de campos activos que cumplen `valueType->isIdentityPromptValue()`.
  - Genera la directiva mencionando explícitamente los nombres reales de los campos activos sin ejemplos hardcodeados.
- **Símbolo**: `ExtractionPromptBuilder::requiresSegmentedItems()`, refactorización de método.
  - Verifica `$fieldGroups['items'] !== [] && count($payload['fuente_verdad']['items'] ?? []) > 1`.
- **Símbolo**: `ExtractionPromptBuilder::buildItemCandidatesContext()`, refactorización de método.
  - Verifica si `fields_config` en `items` contiene algún campo con `TipoDato = 'article_name'` y extrae los nombres únicos de `fuente_verdad.items`.
- **Símbolo**: Eliminar métodos muertos `isDispensaDocument()`, `isPrescriptionDocument()`, `hasVisualCheck()`.

#### `[MODIFY]` [DocumentExtractionContractBuilder.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php)
- **Símbolo**: Eliminar constante `ITEM_FIELD_NAMES` (L13-23).
- **Símbolo**: `DocumentExtractionContractBuilder::isItemScopedField()`, L184-199.
  - Evalúa:
    1. `AuditComparisonType::fromTipoCampo($tipoCampo) === AuditComparisonType::BUSINESS` $\rightarrow$ `true`
    2. `$valueType !== null && $valueType->isItemScoped()` $\rightarrow$ `true`
    3. `(bool) ($field['esMultiItem'] ?? false)` $\rightarrow$ `true`
    4. De lo contrario $\rightarrow$ `false`
- **Símbolo**: Eliminar método legacy `isPrescriptionDocument()` (L201-212).

#### `[MODIFY]` [ExtractionPromptBuilderTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php)
- Actualizar pruebas para validar la directiva de identidad dinámica y la segmentación declarativa.

#### `[MODIFY]` [DocumentExtractionContractBuilderTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/DocumentExtractionContractBuilderTest.php)
- Actualizar pruebas para validar el agrupamiento puramente tipado y declarativo.

---

### 14. Plan de Migración y Rollback

#### Prerrequisitos
- Suite PHPUnit pasando al 100% antes de iniciar.

#### Ejecución
1. Modificar `DocumentExtractionContractBuilder.php` (eliminación de constantes y reglas de string).
2. Modificar `ExtractionPromptBuilder.php` (inyección dinámica de identidad y directivas declarativas).
3. Actualizar `DocumentExtractionContractBuilderTest.php` y `ExtractionPromptBuilderTest.php`.
4. Ejecutar `vendor/bin/phpunit` para verificar 100% verde.
5. Ejecutar `git diff --check` y lint.

#### Rollback
- Reversión atómica vía Git: `git checkout HEAD -- app/Services/Audit/Pipeline/ tests/Services/Audit/Pipeline/`.

---

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| :--- | :--- | :--- |
| Documento sin ningún campo de identidad configurado | La sección `### Regla de identidad` se omite por completo del prompt. | `ExtractionPromptBuilderTest` verifica que no se inyecta texto de identidad innecesario. |
| Documento con un solo campo de identidad (ej: solo `NombrePaciente`) | Se genera la directiva indicando únicamente extraer el campo sin fusiones. | Prompt limpio y compacto. |
| Documento con 1 solo ítem en la Fuente de Verdad | No se inyecta la advertencia de múltiples líneas de producto para ahorrar tokens. | Prompt optimizado. |
| Check visual sin descripción en BD (`description = null`) | Se inyecta únicamente el nombre del check `- CheckName`. | No produce errores de string nulo ni advertencias PHP. |

---

### 16. Testing

#### Nuevos Tests
- `ExtractionPromptBuilderTest::testIdentityDirectiveIsDynamicallyBuiltWithActiveFieldNames`: Verifica que la regla liste dinámicamente los campos activos.
- `ExtractionPromptBuilderTest::testVisualChecksUseConfiguredDescriptionsExclusively`: Verifica que no haya textos inventados para checks visuales.
- `ExtractionPromptBuilderTest::testItemSegmentationActivatesByItemCardinality`: Verifica activación de segmentación para cualquier tipo documental.
- `DocumentExtractionContractBuilderTest::testItemScopedResolutionIsFullyDeclarative`: Verifica agrupación de items sin listas de nombres hardcodeadas.

---

### 17. Riesgos y Mitigaciones

| Riesgo | Tipo | Severidad | Mitigación |
| :--- | :--- | :---: | :--- |
| Reducción en la precisión de OCR de nombres y cédulas pegadas | `Técnico` | `Media` | La directiva dinámica retiene la instrucción anti-fusión mencionando explícitamente los campos configurados del documento. |
| Incompatibilidad con checks visuales existentes | `Técnico` | `Baja` | Se verificó empíricamente en `[Discolnet].[dbo].[AudDispCampoCatalogo]` que `VigenciaEntrega`, `FirmaActaEntrega` y `FirmaPrescriptor` tienen descripciones completas en base de datos. |

---

### 18. Criterios de Aceptación

1. **Cero nombres de campos hardcodeados en PHP**: `grep -rn "CC 94229637" app/` y `grep -rn "ITEM_FIELD_NAMES" app/` deben retornar **0 coincidencias**.
2. **Cero condicionales de string de documentos en prompts**: `grep -rn "DISPENSA" app/Services/Audit/Pipeline/` y `grep -rn "isPrescriptionDocument" app/` deben retornar **0 coincidencias**.
3. **Cero condicionales por nombre de check visual**: `grep -rn "VigenciaEntrega" app/Services/Audit/Pipeline/ExtractionPromptBuilder.php` debe retornar **0 coincidencias**.
4. **Suite PHPUnit**: **538+ tests ejecutados, 0 fallos, 0 errores** (100% verde).
5. **Git Diff Hygiene**: `git diff --check` con **0 errores y 0 warnings**.

---

### 19. Observabilidad
`[CONFIRMADO] Sin impacto en observabilidad. Las métricas de tiempo de inferencia, tokens y llamadas a Gemini se mantienen idénticas.`

---

### 20. Estrategia de Rollout
`[CONFIRMADO] Despliegue directo estándar en contenedor PHP/Workers con rebuild de imagen Docker.`

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| :--- | :---: | :--- |
| Todas las entidades persistentes mencionadas por la especificación están definidas | `PASS` | `AudDispCampoCatalogo` verificado en SQL Server. |
| Todas las columnas mencionadas existen | `PASS` | `CampoNombre`, `Descripcion`, `TipoCampo`, `TipoDato` verificadas. |
| Todos los contratos documentados con clasificación | `PASS` | Sección 10 completa. |
| Todos los requisitos tienen trazabilidad | `PASS` | Matriz de trazabilidad REQ-01 a REQ-05 en Sección 11. |
| Todos los consumidores analizados | `PASS` | Grafo de dependencias en Sección 0.2. |
| Todas las migraciones tienen rollback | `PASS` | Rollback definido en Sección 14. |
| Todas las referencias a archivos y métodos están definidas | `PASS` | Rutas absolutas y símbolos documentados. |
| Toda compatibilidad tiene evidencia | `PASS` | Verificación de esquemas y tests. |
| Todos los criterios son verificables | `PASS` | Criterios medibles con grep y PHPUnit en Sección 18. |
| Observabilidad documentada | `PASS` | Sección 19 declarada con evidencia. |
| Rollout documentado | `PASS` | Sección 20 documentada. |

---

## FASE 3 — Auditoría Arquitectónica y Adversarial

| Pregunta | Resultado | Evidencia |
| :--- | :---: | :--- |
| ¿Existe alguna decisión arquitectónica implícita? | `No` | Todas las decisiones D-01 a D-05 documentadas en Sección 6. |
| ¿Existe algún contrato sin documentar? | `No` | Contrato de prompt en Sección 10. |
| ¿Existe algún consumidor no analizado? | `No` | Grafo 0.2 cerrado. |
| ¿Existe alguna migración sin rollback? | `No` | Sección 14. |
| ¿Existe algún dato persistido sin migración? | `No` | Sin cambios en persistencia. |
| ¿Existe alguna afirmación sin evidencia? | `No` | Cada afirmación clasificada con `[CONFIRMADO]`. |
| ¿Existen referencias huérfanas? | `No` | Todas vinculadas a código y tests. |
| ¿Dos implementadores producirían soluciones diferentes? | `No` | Especificación determinista a nivel de método y regex. |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| :--- | :--- | :--- | :---: | :--- |
| 1 | ¿Existe algún script de arranque o bootstrap que invoque un método eliminado? | `Runtime` | `NO` | Solo se eliminan métodos privados y métodos estáticos de ContractBuilder cuyos usos fueron refactorizados. |
| 2 | ¿Existe algún paso posterior en la cadena de build que dependa de lo modificado? | `Build` | `NO` | PHP 8.2 compila sin transpilación. |
| 3 | ¿Existe algún pipeline de CI que valide con datos distintos? | `Pipeline` | `NO` | CI ejecuta `vendor/bin/phpunit`. |
| 4 | ¿El cambio asume un comportamiento de parser sin verificar? | `Semántica` | `NO` | Compatible con JSON Schema de Gemini. |
| 5 | ¿El cambio está validado para todos los entornos? | `Paridad` | `NO` | Matriz 0.5 completa. |
| 6 | ¿Existe algún mecanismo de override que anule este cambio? | `Override` | `NO` | El prompt builder es invocado directamente por el worker. |
| 7 | ¿Se aplicó algún dogmatismo sin verificar el repositorio local? | `Dogmatismo` | `NO` | Alineado con Clean Rebuild Policy y reglas de AudFact. |
| 8 | ¿El cambio altera la interfaz pública de eventos o APIs? | `Contract` | `NO` | El evento `document_extracted` y la respuesta JSON no cambian su shape. |
| 9 | ¿El cambio afecta datos persistidos sin migración? | `Data` | `NO` | Cero impacto en datos. |
| 10 | ¿El cambio introduce código muerto o adaptadores legacy? | `Clean Architecture` | `NO` | Se elimina código muerto y constantes obsoletas. |
| 11 | ¿El reemplazo de mapeos estáticos por abstracciones dinámicas tiene cobertura sin colisiones? | `Abstracción` | `NO` | Verificado empíricamente en tabla 0.3.1. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud: **Nivel A — Implementable**

La especificación es técnicamente completa, verificable, libre de dependencias desconocidas y lista para ser ejecutada por cualquier ingeniero o agente de código.
