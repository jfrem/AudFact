# DOC-07 — SPEC-GOLDEN-BENCHMARK

## Suite de Calibración de Paridad: Framework de Replay Determinístico

```yaml
document_id: "DOC-07"
document_name: "SPEC-GOLDEN-BENCHMARK"
version: "v0.1.0-draft"
status: "DRAFT"
completeness_level: "B"
last_updated: "2026-10-05T22:47:00Z"
authors:
  - "Agente de IA (skill ddf-agnostic-spec) - borrador sin revisión humana"
reviewers: []
assumptions_count:
  S1: 2
  S2: 1
  S3: 0
  S4: 0
unknowns_critical: 0
unknowns_non_critical: 2
```

---

## §1 — Changelog y Decisiones de Revisión

| Versión | Fecha | Cambio |
|---|---|---|
| v0.1.0-draft | 2026-10-05 | Borrador inicial. Framework de benchmark basado en Golden Set Replay. |

---

## §2 — Resumen Ejecutivo y Alcance

### 2.1 Problema que Resuelve

Cuando un sistema de auditoría documental se reescribe en otra plataforma (lenguaje, framework, proveedor de IA), no existe garantía de que la lógica producirá los mismos resultados. Las diferencias en normalización textual, tolerancia numérica, lógica de conjuntos o comparación semántica pueden introducir regresiones silenciosas.

Este documento especifica una **suite de calibración de paridad** basada en:
1. **Golden Set**: Conjunto de fixtures que representan casos canónicos con resultados esperados.
2. **Replay Determinístico**: Ejecución doble del motor para verificar estabilidad (hash idéntico).
3. **Validación Estructural**: Verificación de que los hallazgos producidos respetan la shape canónica.
4. **Regresión por Campo**: Verificación de que cada campo produce el veredicto esperado.

### 2.2 Alcance Incluido (In-Scope)

1. Formato de fixture (Golden Case).
2. Framework de replay con 3 dimensiones de verificación.
3. Catálogo de razones de rechazo documental.
4. Catálogo de validaciones de integridad documental.
5. Estrategia de hashing canónico.
6. Proceso de adición de nuevos casos.
7. Criterios de paridad para re-plataformado.

### 2.3 Alcance Excluido (Out-of-Scope)

- Lógica interna del motor de reglas → **DOC-02**.
- Prompts de extracción → **DOC-03 / DOC-06**.
- Pipeline de eventos → **DOC-05**.

### 2.4 Dependencias entre Documentos

| Este Documento Requiere | Documento Fuente | Estado |
|---|---|---|
| FindingVerdict values | DOC-02 | DRAFT |
| ValueType enum | DOC-01 | DRAFT |
| DocumentType catalog | DOC-06 | DRAFT v0.1.0 |
| ComparisonStrategy codes | DOC-02 | DRAFT |

---

## §3 — Línea Base en AudFact (Evidencia del Sistema Actual)

### 3.1 Golden Set Replay Test

| Componente | Ruta | Líneas | Propósito | Clasificación |
|---|---|---|---|---|
| GoldenSetReplayTest | [GoldenSetReplayTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php) | L1-271 | Framework de replay con 3 test methods, dataProvider de fixtures, helpers | `[CONFIRMADO]` |
| Fixture: D65260408592 | [golden_D65260408592.json](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Fixtures/golden_D65260408592.json) | — | Caso: Fórmula Médica — Acetaminofén, CIE10 en lista | `[CONFIRMADO]` |

### 3.2 Tres Dimensiones de Verificación

| Dimensión | Test Method | Propósito | Evidencia |
|---|---|---|---|
| **Determinismo** | `testGoldenCaseIsReproducible()` | 2 corridas del engine → SHA-256 idéntico | [GoldenSetReplayTest.php:74-98](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php#L74-L98) |
| **Veredicto** | `testGoldenCaseMatchesExpectedDecision()` | Cada campo produce el resultado canónico esperado | [GoldenSetReplayTest.php:103-138](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php#L103-L138) |
| **Forma** | `testGoldenCaseHallazgosHaveCanonicalShape()` | Cada hallazgo tiene todas las claves requeridas | [GoldenSetReplayTest.php:143-163](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php#L143-L163) |

### 3.3 Hashing Canónico

Algoritmo de hash para verificación de determinismo ([GoldenSetReplayTest.php:254-269](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php#L254-L269)):

1. Extraer `hallazgos.items` de cada resultado.
2. Ordenar items por `campo` (sort léxico ascendente).
3. Proyectar cada item a `{campo, resultado, valueType}`.
4. Serializar a JSON (UTF-8 sin escape).
5. SHA-256 del JSON.

**Invariante**: Solo los campos que forman la decisión canónica participan en el hash. Metadata operacional (timestamps, duración) se excluye. `[CONFIRMADO]`

### 3.4 Shape Canónica de un Hallazgo

Claves requeridas en cada hallazgo ([GoldenSetReplayTest.php:152-161](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php#L152-L161)):

| Clave | Tipo | Descripción |
|---|---|---|
| `campo` | string | Nombre del campo evaluado |
| `resultado` | string | Veredicto: COINCIDE, VALOR_DISTINTO, etc. (DOC-02) |
| `valorFuenteVerdad` | mixed | Valor de referencia del sistema |
| `valorDocumento` | mixed | Valor extraído del documento |
| `valueType` | string | Tipo de valor (DOC-01) |
| `tipo_auditoria` | string | Estrategia de comparación (DOC-02) |
| `severidad` | string | Nivel de severidad (DOC-01) |
| `documento` | string | Nombre del tipo documental |

### 3.5 Catálogo de Razones de Rechazo Documental

| Razón | Constante | Etapa | Clasificación |
|---|---|---|---|
| Documento vacío | `EMPTY_DOCUMENT` | Integridad | `[CONFIRMADO]` |
| Documento demasiado pequeño | `DOCUMENT_TOO_SMALL` | Integridad | `[CONFIRMADO]` |
| MIME no soportado | `UNSUPPORTED_MIME` | Integridad | `[CONFIRMADO]` |
| Firma de archivo desconocida | `UNKNOWN_FILE_SIGNATURE` | Integridad | `[CONFIRMADO]` |
| MIME declarado ≠ detectado | `MIME_MISMATCH` | Integridad | `[CONFIRMADO]` |
| PDF cifrado | `ENCRYPTED_DOCUMENT` | Integridad | `[CONFIRMADO]` |
| PDF sin páginas | `EMPTY_PDF_NO_PAGES` | Integridad | `[CONFIRMADO]` |
| Fallo de decodificación Gemini | `GEMINI_DECODE_FAILURE` | Extracción | `[CONFIRMADO]` |
| Documento corrupto (sin EOF) | `CORRUPTED_DOCUMENT` | Integridad | `[CONFIRMADO]` |
| Fallo de rasterización | `RASTERIZATION_FAILURE` | Pre-extracción | `[CONFIRMADO]` |

**Fuente**: [DocumentRejectionReason.php:10-19](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentRejectionReason.php#L10-L19). `[CONFIRMADO]`

### 3.6 Validaciones de Integridad Documental

| Validación | Orden | Criterio | Evidencia |
|---|---|---|---|
| Contenido no vacío | 1 | `base64_decode` no produce cadena vacía | [DocumentIntegrityValidator.php:54-61](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L54-L61) |
| Tamaño mínimo | 2 | ≥ 4 bytes (mínimo para magic bytes) | [DocumentIntegrityValidator.php:64-66](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L64-L66) |
| MIME soportado | 3 | In `[pdf, jpeg, png, webp, tiff, gif]` | [DocumentIntegrityValidator.php:68-70](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L68-L70) |
| Magic bytes match | 4 | Firma binaria corresponde al MIME declarado | [DocumentIntegrityValidator.php:72-79](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L72-L79) |
| PDF no cifrado | 5 | No contiene `/Encrypt` en raw | [DocumentIntegrityValidator.php:81-83](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L81-L83) |
| PDF tiene páginas | 6 | Contiene `/Page` object | [DocumentIntegrityValidator.php:85-87](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L85-L87) |
| PDF tiene EOF | 7 | Contiene `%%EOF` marker | [DocumentIntegrityValidator.php:89-91](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L89-L91) |

### 3.7 Casos Borde y Excepciones Documentados

1. **Fixture missing**: Si el archivo JSON del fixture no existe en disco, el data provider retorna un array marcado con `_missing=true` y el test falla con mensaje claro indicando qué fixture falta. [GoldenSetReplayTest.php:175-178](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php#L175-L178). `[CONFIRMADO]`

2. **Campo no presente en hallazgos**: Si `expected.campo_results` contiene un campo que el engine no produjo, `assertArrayHasKey` falla con mensaje que incluye el fixture ID y el nombre del campo faltante. [GoldenSetReplayTest.php:127-131](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/GoldenSetReplayTest.php#L127-L131). `[CONFIRMADO]`

3. **PDF con `/Encrypt` parcial**: La detección busca `str_contains($raw, '/Encrypt')`. Un PDF legítimo con un objeto que contiene la subcadena `/Encrypt` en otro contexto sería rechazado (falso positivo). [DocumentIntegrityValidator.php:81-83](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L81-L83). `[CONFIRMADO]`

4. **WebP sin magic bytes en tabla**: La tabla `MAGIC_SIGNATURES` no incluye WebP (`RIFF...WEBP`), pero `SUPPORTED_MIMES` sí incluye `image/webp`. Un WebP válido sería rechazado con `UNKNOWN_FILE_SIGNATURE`. [DocumentIntegrityValidator.php:33-41 vs 19-26](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentIntegrityValidator.php#L33-L41). `[CONFIRMADO]`

---

## §4 — Diseño del Contrato Agnóstico (Especificación Técnica)

### 4.1 Esquema del Golden Fixture

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://schemas.invalid/audit-platform/doc-07/v0.1/golden-fixture.schema.json",
  "title": "GoldenFixture",
  "description": "Caso canónico para la suite de calibración de paridad.",
  "type": "object",
  "properties": {
    "id": { "type": "string", "description": "Identificador único del caso." },
    "description": { "type": "string", "description": "Descripción del escenario (redactada)." },
    "source_truth": {
      "type": "object",
      "description": "Datos de referencia del sistema (Fuente de Verdad).",
      "properties": {
        "header": { "type": "object", "additionalProperties": true },
        "items": { "type": "array", "items": { "type": "object", "additionalProperties": true } }
      },
      "required": ["header"]
    },
    "documents": {
      "type": "array",
      "description": "Array de documentos con datos pre-extraídos.",
      "items": {
        "type": "object",
        "properties": {
          "document_type": { "type": "string" },
          "fields_config": {
            "type": "array",
            "description": "Configuración de campos del audit-config para este documento.",
            "items": { "$ref": "#/$defs/FieldConfig" }
          },
          "fields": { "type": "object", "additionalProperties": true },
          "items": { "type": "array", "items": { "type": "object", "additionalProperties": true } },
          "document_quality": { "type": "string", "default": "legible" }
        },
        "required": ["document_type", "fields_config", "fields"]
      },
      "minItems": 1
    },
    "expected": {
      "type": "object",
      "description": "Resultado canónico esperado.",
      "properties": {
        "approved": { "type": "boolean" },
        "campo_results": {
          "type": "object",
          "description": "Mapa campo → veredicto esperado (DOC-02 FindingVerdict values).",
          "additionalProperties": { "type": "string" }
        }
      },
      "required": ["campo_results"]
    }
  },
  "required": ["id", "description", "source_truth", "documents", "expected"],
  "$defs": {
    "FieldConfig": {
      "type": "object",
      "properties": {
        "campoNombre": { "type": "string" },
        "tipoCampo": { "type": "string", "description": "Código de comparación: E/S/B/V/I/P" },
        "tipoDato": { "type": "string", "description": "DOC-01 ValueType" },
        "orden": { "type": "integer" },
        "severity": { "type": "string" },
        "codigoCampo": { "type": ["string", "null"] },
        "description": { "type": "string" },
        "aplicaServicio": { "type": ["string", "null"] },
        "esMultiItem": { "type": "boolean" }
      },
      "required": ["campoNombre", "tipoCampo", "tipoDato"]
    }
  }
}
```

### 4.2 Esquema de un Hallazgo Canónico

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://schemas.invalid/audit-platform/doc-07/v0.1/canonical-finding.schema.json",
  "title": "CanonicalFinding",
  "type": "object",
  "properties": {
    "campo": { "type": "string" },
    "resultado": { "type": "string", "description": "DOC-02 FindingVerdict value" },
    "valorFuenteVerdad": { "description": "Valor de referencia del sistema" },
    "valorDocumento": { "description": "Valor extraído del documento" },
    "valueType": { "type": "string", "description": "DOC-01 ValueType value" },
    "tipo_auditoria": { "type": "string", "description": "DOC-02 ComparisonStrategy code" },
    "severidad": { "type": "string", "description": "DOC-01 Severity value" },
    "documento": { "type": "string", "description": "Nombre del tipo documental" }
  },
  "required": ["campo", "resultado", "valorFuenteVerdad", "valorDocumento", "valueType", "tipo_auditoria", "severidad", "documento"]
}
```

### 4.3 Esquema de Resultado de Integridad Documental

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://schemas.invalid/audit-platform/doc-07/v0.1/integrity-result.schema.json",
  "title": "IntegrityValidationResult",
  "type": "object",
  "properties": {
    "valid": { "type": "boolean" },
    "reason": { "type": ["string", "null"], "description": "Razón de rechazo del catálogo (§3.5)" },
    "declared_mime": { "type": "string" },
    "detected_mime": { "type": ["string", "null"] },
    "size_bytes": { "type": "integer", "minimum": 0 }
  },
  "required": ["valid", "reason", "declared_mime", "detected_mime", "size_bytes"]
}
```

### 4.4 Interfaces

#### Interfaz: ParityBenchmark

```typescript
interface ParityBenchmark {
  /**
   * Carga todos los fixtures del golden set.
   */
  loadFixtures(): GoldenFixture[];

  /**
   * Ejecuta el replay determinístico de un fixture.
   *
   * Precondiciones: fixture es un GoldenFixture válido
   * Postcondiciones:
   *   - Ejecuta el PolicyEngine 2 veces con los mismos datos
   *   - Calcula hash canónico de cada corrida
   *   - Compara hashes
   */
  replayDeterminism(fixture: GoldenFixture): ReplayResult;

  /**
   * Verifica que cada campo produzca el veredicto esperado.
   */
  verifyVerdicts(fixture: GoldenFixture): VerdictResult;

  /**
   * Verifica que la shape de hallazgos sea canónica.
   */
  verifyShape(fixture: GoldenFixture): ShapeResult;
}

type ReplayResult = {
  fixture_id: string;
  deterministic: boolean;
  hash_run_1: string;
  hash_run_2: string;
};

type VerdictResult = {
  fixture_id: string;
  passed: boolean;
  mismatches: Array<{ campo: string; expected: string; actual: string }>;
};

type ShapeResult = {
  fixture_id: string;
  passed: boolean;
  missing_keys: Array<{ finding_index: number; key: string }>;
};
```

#### Interfaz: IntegrityValidator

```typescript
interface IntegrityValidator {
  /**
   * Valida la integridad estructural de un documento binario.
   *
   * Precondiciones: document contiene data (base64), mime (string)
   * Postcondiciones: retorna resultado con valid, reason, mime info, size
   * 
   * Invariante: las validaciones se ejecutan en orden secuencial;
   *   la primera que falla determina el motivo de rechazo
   */
  validate(document: { data: string; mime: string }): IntegrityResult;
}
```

### 4.5 Algoritmos

#### ALG-01: CanonicalHash — Hashing para verificación de determinismo

```
ALGORITMO: CanonicalHash
ENTRADA: results: PolicyResult[]
SALIDA: hash: string (SHA-256)

1. normalized ← []
2. PARA CADA result EN results:
   2.1. items ← result.hallazgos.items
   2.2. SORT items BY campo ASC (léxico)
   2.3. projected ← MAP items → {campo, resultado, valueType}
   2.4. APPEND projected TO normalized

3. json ← JSON.stringify(normalized, UTF-8, no-escape-unicode)
4. RETORNAR SHA256(json)
```

#### ALG-02: DeterminismReplay — Replay doble para estabilidad

```
ALGORITMO: DeterminismReplay
ENTRADA: fixture: GoldenFixture, engine: PolicyEngine
SALIDA: {deterministic: bool, hash1: string, hash2: string}

1. results_1 ← []
2. results_2 ← []

3. PARA i EN [1, 2]:
   PARA CADA doc EN fixture.documents:
     state ← buildState(fixture.source_truth, doc)
     payload ← buildPayload(doc)
     result ← engine.evaluate(state, payload)
     SI i = 1: APPEND result TO results_1
     SI NO:   APPEND result TO results_2

4. hash1 ← CanonicalHash(results_1)
5. hash2 ← CanonicalHash(results_2)
6. RETORNAR {deterministic: hash1 = hash2, hash1, hash2}
```

#### ALG-03: IntegrityValidation — Pipeline de validación secuencial

```
ALGORITMO: IntegrityValidation
ENTRADA: document: {data_base64: string, mime: string}
SALIDA: IntegrityResult

CONSTANTES:
  MIN_SIZE = 4 bytes
  SUPPORTED = [pdf, jpeg, png, webp, tiff, gif]
  MAGIC_SIGNATURES = {"%PDF"→pdf, "\xFF\xD8\xFF"→jpeg, "\x89PNG"→png, ...}

1. raw ← base64_decode(document.data_base64)
   SI raw = vacío → REJECT(EMPTY_DOCUMENT)

2. SI length(raw) < MIN_SIZE → REJECT(DOCUMENT_TOO_SMALL)

3. SI document.mime ∉ SUPPORTED → REJECT(UNSUPPORTED_MIME)

4. detected ← lookup(raw[0..6], MAGIC_SIGNATURES)
   SI detected = null → REJECT(UNKNOWN_FILE_SIGNATURE)

5. SI detected ≠ document.mime → REJECT(MIME_MISMATCH)

6. SI document.mime = "application/pdf":
   6.1. SI raw contains "/Encrypt" → REJECT(ENCRYPTED_DOCUMENT)
   6.2. SI raw NOT contains "/Page" → REJECT(EMPTY_PDF_NO_PAGES)
   6.3. SI raw NOT contains "%%EOF" → REJECT(CORRUPTED_DOCUMENT)

7. RETORNAR {valid: true, ...}
```

#### ALG-04: FixtureExpansion — Proceso de adición de nuevo caso

```
ALGORITMO: FixtureExpansion
ENTRADA: case_data: {source_truth, extracted_documents}
SALIDA: fixture_file: JSON

1. id ← unique_identifier(case_data)
2. REDACTAR datos personales (nombres sintéticos, IDs ficticios)
3. CONSTRUIR fixture:
   {
     id, description,
     source_truth: {header, items} CON DATOS REDACTADOS,
     documents: [{document_type, fields_config, fields, items}],
     expected: {
       approved: CALCULAR desde reglas,
       campo_results: {campo → veredicto EJECUTADO Y VERIFICADO}
     }
   }
4. GUARDAR en fixtures/golden_{id}.json
5. AGREGAR id al catálogo de fixtures activos
6. EJECUTAR suite completa para verificar no-regresión
```

### 4.6 Invariantes del Módulo

- **INV-01**: El hash canónico es determinista: mismos datos → mismo hash, independientemente del lenguaje de implementación.
- **INV-02**: Solo `{campo, resultado, valueType}` participan en el hash. Metadata operacional se excluye.
- **INV-03**: Los fixtures contienen datos redactados (sintéticos). No hay datos reales de pacientes.
- **INV-04**: Las validaciones de integridad son secuenciales: la primera falla determina el motivo de rechazo.
- **INV-05**: Los MIME soportados están definidos como un conjunto cerrado. Nuevos formatos requieren actualización explícita.
- **INV-06**: Los fixtures son auto-contenidos: no requieren red, base de datos, Redis ni API externa.

### 4.7 Criterios de Paridad para Re-plataformado

| Dimensión | Criterio | Umbral Mínimo |
|---|---|---|
| **Determinismo** | Hash de 2 corridas idéntico para 100% de fixtures | 100% |
| **Veredicto** | Cada campo produce el veredicto canónico esperado | 100% |
| **Forma** | Cada hallazgo contiene las 8 claves requeridas | 100% |
| **Integridad** | Las 7 validaciones de integridad producen los mismos rechazos/aprobaciones | 100% |
| **Regresión** | Ningún fixture que antes pasaba ahora falla | 0 regresiones |
| **Cobertura mínima** | Al menos 1 fixture por tipo documental × tipo de valor | Progresivo |

---

## §5 — Análisis de Impacto Inverso y Verificación de Abstracción

### 5.1 Tabla de Cobertura

| Funcionalidad Actual (§3) | Contrato Agnóstico (§4) | ¿Cobertura? |
|---|---|:---:|
| GoldenSetReplayTest 3 test methods | ParityBenchmark interface (3 methods) | ✅ |
| Hash SHA-256 canónico | ALG-01 CanonicalHash | ✅ |
| Replay doble | ALG-02 DeterminismReplay | ✅ |
| Shape validation (8 claves) | CanonicalFinding schema | ✅ |
| Fixture format | GoldenFixture schema | ✅ |
| IntegrityValidator pipeline | ALG-03 IntegrityValidation | ✅ |
| 10 razones de rechazo | Catálogo §3.5 | ✅ |
| 7 validaciones secuenciales | ALG-03 pasos 1-7 | ✅ |
| Proceso de adición de caso | ALG-04 FixtureExpansion | ✅ |

**Cobertura: 9/9 = 100%.**

### 5.2 Prueba de Dominio Dual

| Contrato | Caso AudFact (Salud) | Caso Alternativo (Seguros — Verificación de Pólizas) | ¿Funciona? |
|---|---|---|:---:|
| GoldenFixture | Fórmula médica con Acetaminofén | Póliza de auto con cobertura contra terceros | ✅ |
| CanonicalHash | SHA-256 de {campo, resultado, valueType} | SHA-256 de {campo, resultado, valueType} (misma proyección) | ✅ |
| IntegrityValidator | PDF médico, JPEG de fórmula | PDF de póliza, TIFF de acta notarial | ✅ |
| FieldConfig | tipoCampo=E para NIT, tipoDato=nit | tipoCampo=E para PolicyNumber, tipoDato=code | ✅ |

### 5.3 Auditoría de Fugas de Abstracción

| Término Buscado | ¿Encontrado en §4? | Acción |
|---|:---:|---|
| paciente | ❌ | — |
| médico | ❌ | — |
| fórmula médica | ❌ | — |
| dispensación | ❌ | — |
| medicamento | ❌ | — |
| EPS | ❌ | — |

---

## §6 — Matriz de Trazabilidad y Riesgos

### 6.1 Trazabilidad Bidireccional

| Requisito Funcional | Elemento del Contrato (§4) | Criterio de Aceptación (§7) |
|---|---|---|
| Replay determinístico | ALG-01 CanonicalHash + ALG-02 DeterminismReplay | TC-01 |
| Veredicto canónico por campo | ParityBenchmark.verifyVerdicts() | TC-02 |
| Shape canónica de hallazgos | CanonicalFinding schema | TC-03 |
| Validación de integridad documental | ALG-03 IntegrityValidation | TC-04 a TC-08 |
| Paridad cross-platform | CanonicalHash equivalencia | TC-09 |

### 6.2 Riesgos

| ID | Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|:---:|:---:|---|
| RISK-01 | Golden set con 1 solo fixture no cubre edge cases | Alta | Alto | Plan de expansión progresiva (ALG-04) |
| RISK-02 | Cambio en serialización JSON entre lenguajes afecta hash | Media | Medio | Hash sobre proyección canónica mínima, no sobre payload completo |
| RISK-03 | Fixtures con datos sintéticos no representan distribución real | Media | Medio | Agregar fixtures derivados de producción (redactados) |

### 6.2 Supuestos

| ID | Supuesto | Severidad | Justificación |
|---|---|:---:|---|
| A-01 | El PolicyEngine evaluado en el Golden Set es determinista cuando no se invoca Gemini (comparaciones léxicas puras). | S1 | El test de replay verifica esta propiedad. |
| A-02 | SHA-256 produce el mismo hash en cualquier lenguaje para la misma cadena UTF-8. | S1 | Propiedad fundamental de SHA-256. |
| A-03 | Los fixtures auto-contenidos son suficientes para verificar paridad sin necesidad de tests de integración end-to-end. | S2 | Los tests unitarios cubren el PolicyEngine; los tests de integración del pipeline están fuera del benchmark. |

### 6.3 Desconocidos No Críticos

| ID | Hecho | Clasificación |
|---|---|:---:|
| U-01 | ¿Cuántos fixtures son necesarios para cobertura estadísticamente significativa de los 13 tipos de valor? | `[DESCONOCIDO]` |
| U-02 | ¿Cómo se maneja el versionado del Golden Set cuando las reglas del motor cambian intencionalmente? | `[DESCONOCIDO]` |

---

## §7 — Criterios de Aceptación Verificables

### Golden Set Replay

| ID | Descripción | Input | Output Esperado | Tipo |
|---|---|---|---|---|
| TC-01 | Fixture válido produce hash determinístico | golden_D65260408592 | hash_run_1 = hash_run_2 | Unitario |
| TC-02 | Cada campo produce veredicto canónico | golden_D65260408592 | Todos los campo_results coinciden | Unitario |
| TC-03 | Hallazgos tienen 8 claves requeridas | golden_D65260408592 | 0 missing keys | Unitario |

### Integridad Documental

| ID | Descripción | Input | Output Esperado | Tipo |
|---|---|---|---|---|
| TC-04 | Base64 vacío → EMPTY_DOCUMENT | data="" | valid=false, reason=EMPTY_DOCUMENT | Unitario |
| TC-05 | PDF sin %%EOF → CORRUPTED_DOCUMENT | PDF truncado | valid=false, reason=CORRUPTED_DOCUMENT | Unitario |
| TC-06 | JPEG con mime declarado PDF → MIME_MISMATCH | JPEG + mime=pdf | valid=false, reason=MIME_MISMATCH | Unitario |
| TC-07 | PDF cifrado → ENCRYPTED_DOCUMENT | PDF con /Encrypt | valid=false, reason=ENCRYPTED_DOCUMENT | Unitario |
| TC-08 | PDF válido → valid=true | PDF completo | valid=true, reason=null | Unitario |

### Paridad Cross-Platform

| ID | Descripción | Input | Output Esperado | Tipo |
|---|---|---|---|---|
| TC-09 | Hash idéntico entre implementación original y re-plataformada | Mismos fixtures | hash_original = hash_nuevo | Integración |

---

## §8 — Puerta de Calidad (Auto-Auditoría Adversarial)

### 8.1 Auditoría de Consistencia

| # | Pregunta | Resultado | Evidencia |
|---|---|:---:|---|
| C-01 | ¿Toda afirmación sobre AudFact tiene clasificación epistémica? | PASS | 10+ evidencias `[CONFIRMADO]` en §3 |
| C-02 | ¿Existen contradicciones entre §3 y §4? | PASS | Cobertura 9/9 en §5.1 |
| C-03 | ¿Toda entrada de la tabla de cobertura (5.1) está resuelta? | PASS | 9/9 con ✅ |
| C-04 | ¿Los esquemas JSON Schema son válidos según draft-2020-12? | PASS | 3 schemas con `$schema` correcto |
| C-05 | ¿Cada interfaz tiene pre/post-condiciones y modos de fallo? | PASS | ParityBenchmark e IntegrityValidator con contratos completos |

### 8.2 Auditoría de Fugas

| # | Pregunta | Resultado | Evidencia |
|---|---|:---:|---|
| A-01 | ¿La Sección 4 pasa el filtrado léxico (5.3)? | PASS | 7/7 términos ausentes |
| A-02 | ¿Cada contrato pasa la Prueba de Dominio Dual (5.2)? | PASS | Salud + Seguros |
| A-03 | ¿Algún esquema hardcodea valores específicos de un solo dominio? | PASS | Schemas usan strings genéricos para campo, resultado y valueType |

### 8.3 Auditoría de Completitud

| # | Pregunta | Resultado | Evidencia |
|---|---|:---:|---|
| D-01 | ¿Existen `[DESCONOCIDO]` críticos sin convertir a supuesto? | PASS | 0 críticos, 2 no-críticos en §6.3 |
| D-02 | ¿Existen supuestos S3 o S4 sin resolver? | PASS | 0 S3, 0 S4 |
| D-03 | ¿Toda funcionalidad tiene al menos un criterio de aceptación? | PASS | 9 test cases cubriendo 3 bloques |
| D-04 | ¿El nivel de completitud asignado es consistente con conteos? | PASS | Nivel B: 2 S1 + 1 S2, 0 S3/S4, 0 desconocidos críticos |

### 8.4 Preguntas Adversariales

| # | Pregunta | Respuesta | Evidencia |
|---|---|:---:|---|
| ADV-01 | ¿Un equipo en otro lenguaje puede implementar el benchmark sin ver código PHP? | SÍ | Schemas JSON + ALG-01/02/03/04 son suficientes |
| ADV-02 | ¿Si se cambia la base de datos de SQL Server a PostgreSQL, este contrato sigue válido? | SÍ | Benchmark no accede a BD; fixtures son auto-contenidos |
| ADV-03 | ¿Si se cambia el proveedor de IA de Gemini a OpenAI, este contrato sigue válido? | SÍ | Benchmark evalúa PolicyEngine, no el proveedor de IA |
| ADV-04 | ¿Si se auditan contratos legales en lugar de documentos de salud, este contrato sigue válido? | SÍ | Fixture format es agnóstico; campos y tipos se configuran vía fields_config |

---

### Clasificación Final

```yaml
completeness_level: "B"
justification: >
  3 supuestos menores (2 S1 + 1 S2). 0 desconocidos críticos.
  2 desconocidos no-críticos sobre sizing del golden set.
  Cobertura 9/9. Filtrado léxico limpio.
  RISK-01 es el riesgo más relevante: solo 1 fixture activo.
  Plan de expansión progresiva documentado en ALG-04.
blockers:
  - "Expandir Golden Set a ≥1 fixture por tipo documental (DOC-06)"
next_actions:
  - "Crear golden fixtures para Acta de Entrega, Autorización y Factura"
  - "Resolver U-02: definir política de versionado del Golden Set"
  - "Ejecutar suite de paridad tras cualquier re-plataformado"
```
