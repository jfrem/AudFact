# Plantilla de Documento de Especificación Agnóstica (DDF)

Usa esta referencia como estructura obligatoria para producir o auditar cualquiera de los 7 documentos canónicos (DOC-01 a DOC-07) del framework DDF-SDD para la reingeniería agnóstica de la plataforma.

## Estructura Obligatoria del Documento

Cada documento debe contener las siguientes 8 secciones en este orden exacto. Ninguna sección puede omitirse silenciosamente. Una sección no aplicable debe aparecer explícitamente como `N/A` con justificación.

---

### Sección 1 — Metadatos y Control de Estado

```yaml
document_id: "<DOC-XX>"           # DOC-01 a DOC-07
document_name: "<Nombre canónico>"
version: "v0.1.0-draft"           # Semver: draft → review → validated → approved
status: "DRAFT | REVIEW | VALIDATED | APPROVED"
completeness_level: "A | B | C | D"
last_updated: "<ISO-8601>"
authors: []
reviewers: []
assumptions_count:
  S1: 0
  S2: 0
  S3: 0    # >0 → max Nivel C
  S4: 0    # >0 → max Nivel D
unknowns_critical: 0
unknowns_non_critical: 0
```

Reglas:
- `S3 > 0` → El documento no puede clasificarse como Nivel A ni Nivel B.
- `S4 > 0` → El documento no puede clasificarse como Nivel A, B ni C.
- `unknowns_critical > 0` y no convertidos a supuestos → El documento está bloqueado.

---

### Sección 2 — Resumen Ejecutivo y Alcance

#### 2.1 Problema que Resuelve
Descripción concisa del problema arquitectónico que este documento abstrae. Máximo 3 párrafos.

#### 2.2 Alcance Incluido (In-Scope)
Lista enumerada de responsabilidades y funcionalidades que este documento especifica.

#### 2.3 Alcance Excluido (Out-of-Scope)
Lista enumerada de responsabilidades explícitamente fuera de este documento, con referencia al documento que las cubre (ej. "Persistencia de veredictos → DOC-04").

#### 2.4 Dependencias entre Documentos

| Este Documento Requiere | Documento Fuente | Estado Actual |
|---|---|---|
| (ej. Tipos primitivos de extracción) | DOC-01 | DRAFT / APPROVED |

---

### Sección 3 — Línea Base en AudFact (Evidencia del Sistema Actual)

> Esta sección documenta **cómo funciona hoy** la funcionalidad que el documento abstrae. Toda afirmación debe estar clasificada con `[CONFIRMADO]`, `[INFERIDO]` o `[DESCONOCIDO]`.

#### 3.1 Inventario de Componentes Actuales

| Componente | Ruta | Método / Símbolo | Líneas | Propósito | Clasificación |
|---|---|---|---|---|---|
| | `file:///ruta` | `Clase::metodo()` | L10-L50 | | `[CONFIRMADO]` |

#### 3.2 Flujo de Datos Actual
Diagrama Mermaid del flujo de datos que involucra los componentes del inventario.

```mermaid
flowchart TD
    %% Completar con el flujo real
```

#### 3.3 Reglas de Negocio Actuales
Tabla exhaustiva de cada regla de negocio o comportamiento observado, con evidencia:

| ID | Regla | Evidencia (Archivo:Línea) | Clasificación |
|---|---|---|---|
| R01 | | | `[CONFIRMADO]` |

#### 3.4 Casos Borde y Excepciones Documentados
Lista de casos borde observados en el código actual, con evidencia de cómo se manejan.

---

### Sección 4 — Diseño del Contrato Agnóstico (Especificación Técnica)

> Esta sección es el **núcleo del documento**. Define los contratos, esquemas, interfaces y algoritmos de la nueva plataforma agnóstica.

> **Regla de Filtrado Léxico**: Esta sección NO debe contener términos de dominio específico de salud (paciente, médico, fórmula médica, EPS, dispensación, medicamento). Si aparece alguno, la auditoría adversarial fallará.

#### 4.1 Modelo de Datos / Esquemas

Para cada estructura de datos canónica, proveer:

1. **Descripción conceptual** (1-2 oraciones).
2. **JSON Schema formal** (estándar `2020-12`):

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "<uri-canónica>",
  "title": "<Nombre>",
  "type": "object",
  "properties": {},
  "required": []
}
```

3. **Ejemplo concreto** de instancia válida del schema.

#### 4.2 Interfaces y Puertos

Para cada interfaz, proveer firma tipada completa:

```typescript
// Pseudocódigo tipado — NO es implementación final
interface NombreDelPuerto {
  metodo(param: Tipo): Promise<TipoRetorno>;
}
```

Incluir:
- Precondiciones (qué debe ser verdadero antes de invocar).
- Postcondiciones (qué garantiza después de ejecutar).
- Invariantes (qué se preserva siempre).
- Modos de fallo esperados y comportamiento ante cada uno.

#### 4.3 Algoritmos y Lógica de Evaluación

Para toda lógica no trivial, proveer pseudocódigo determinista paso a paso:

```
ALGORITMO: NombreDelAlgoritmo
ENTRADA: param1: Tipo, param2: Tipo
SALIDA: resultado: Tipo

1. Validar precondiciones...
2. Para cada elemento en colección:
   2.1. Si condición_exacta → acción_determinista
   2.2. Si no → acción_alternativa_explícita
3. Retornar resultado
```

#### 4.4 Diagramas de Estado / Flujo

```mermaid
stateDiagram-v2
    %% Completar con estados y transiciones
```

#### 4.5 Invariantes del Módulo

Lista enumerada de condiciones que deben ser verdaderas en todo momento:

- **INV-01**: [Descripción de la invariante].
- **INV-02**: [Descripción de la invariante].

---

### Sección 5 — Análisis de Impacto Inverso y Verificación de Abstracción

#### 5.1 Tabla de Cobertura (Paridad con AudFact)

| Funcionalidad Actual (Sección 3) | Contrato Agnóstico (Sección 4) | ¿Cobertura Completa? | Notas |
|---|---|:---:|---|
| R01: [regla] | [interfaz/schema] | ✅ / ❌ | |

Regla: Toda fila con ❌ debe tener una justificación explícita y una ruta de resolución.

#### 5.2 Prueba de Dominio Dual

Para cada contrato principal de la Sección 4, demostrar que funciona en dos dominios independientes:

| Contrato | Caso AudFact (Salud) | Caso Alternativo (Logística/Seguros/Banca) | ¿Funciona en Ambos? |
|---|---|---|:---:|

#### 5.3 Auditoría de Fugas de Abstracción (Filtrado Léxico)

Términos de dominio buscados en la Sección 4:

| Término Buscado | ¿Encontrado en Sección 4? | Acción |
|---|:---:|---|
| paciente | ❌ | — |
| médico | ❌ | — |
| fórmula médica | ❌ | — |
| dispensación | ❌ | — |
| medicamento | ❌ | — |
| EPS | ❌ | — |
| factura (como concepto de salud) | ❌ | — |

Regla: Cualquier ✅ en esta tabla bloquea la aprobación del documento.

---

### Sección 6 — Matriz de Trazabilidad y Riesgos

#### 6.1 Trazabilidad Bidireccional

| Requisito Funcional | Elemento del Contrato (Sección 4) | Criterio de Aceptación (Sección 7) |
|---|---|---|
| | | |

#### 6.2 Riesgos Identificados

| ID | Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|:---:|:---:|---|
| RISK-01 | | Alta/Media/Baja | Alto/Medio/Bajo | |

#### 6.3 Supuestos Declarados

| ID | Supuesto | Severidad | Justificación | Ruta de Validación |
|---|---|:---:|---|---|
| $S_1$ | | S1/S2/S3/S4 | | |

---

### Sección 7 — Criterios de Aceptación Verificables

Para cada funcionalidad especificada, proveer al menos un caso de prueba:

#### Formato Given-When-Then

```gherkin
Feature: [Nombre de la funcionalidad]

  Scenario: [Nombre del escenario]
    Given [precondición explícita con datos concretos]
    When [acción exacta con parámetros]
    Then [resultado esperado verificable]
```

#### Tabla de Casos de Prueba

| ID | Descripción | Input | Output Esperado | Tipo |
|---|---|---|---|---|
| TC-01 | | JSON/datos concretos | JSON/datos concretos | Unitario / Integración / Paridad |

---

### Sección 8 — Puerta de Calidad (Auto-Auditoría Adversarial)

Antes de clasificar el documento como aprobado, responder **cada** pregunta con evidencia:

#### 8.1 Auditoría de Consistencia

| # | Pregunta | Resultado | Evidencia |
|---|---|:---:|---|
| C-01 | ¿Toda afirmación sobre AudFact tiene clasificación epistémica? | PASS/FAIL | |
| C-02 | ¿Existen contradicciones entre la Sección 3 y la Sección 4? | PASS/FAIL | |
| C-03 | ¿Toda entrada de la tabla de cobertura (5.1) está resuelta? | PASS/FAIL | |
| C-04 | ¿Los esquemas JSON Schema son válidos según `draft-2020-12`? | PASS/FAIL | |
| C-05 | ¿Cada interfaz tiene pre/post-condiciones y modos de fallo? | PASS/FAIL | |

#### 8.2 Auditoría de Fugas de Abstracción

| # | Pregunta | Resultado | Evidencia |
|---|---|:---:|---|
| A-01 | ¿La Sección 4 pasa el filtrado léxico (5.3)? | PASS/FAIL | |
| A-02 | ¿Cada contrato pasa la Prueba de Dominio Dual (5.2)? | PASS/FAIL | |
| A-03 | ¿Algún esquema hardcodea valores específicos de un solo dominio? | PASS/FAIL | |

#### 8.3 Auditoría de Completitud

| # | Pregunta | Resultado | Evidencia |
|---|---|:---:|---|
| D-01 | ¿Existen `[DESCONOCIDO]` críticos sin convertir a supuesto? | PASS/FAIL | |
| D-02 | ¿Existen supuestos $S_3$ o $S_4$ sin resolver? | PASS/FAIL | |
| D-03 | ¿Toda funcionalidad tiene al menos un criterio de aceptación? | PASS/FAIL | |
| D-04 | ¿El nivel de completitud asignado es consistente con los conteos de supuestos y desconocidos? | PASS/FAIL | |

#### 8.4 Preguntas Adversariales

| # | Pregunta | Respuesta | Evidencia |
|---|---|:---:|---|
| ADV-01 | ¿Un desarrollador que no conozca AudFact puede implementar este contrato solo con la Sección 4? | SÍ/NO | |
| ADV-02 | ¿Si se cambia la base de datos de SQL Server a PostgreSQL, este contrato sigue siendo válido? | SÍ/NO/N-A | |
| ADV-03 | ¿Si se cambia el proveedor de IA de Gemini a OpenAI, este contrato sigue siendo válido? | SÍ/NO/N-A | |
| ADV-04 | ¿Si se auditan contratos legales en lugar de documentos de salud, este contrato sigue siendo válido? | SÍ/NO/N-A | |

---

### Clasificación Final

```yaml
completeness_level: "A | B | C | D"
justification: "<Justificación basada en evidencia de las auditorías>"
blockers: []          # Lista de ítems que impiden avanzar al siguiente nivel
next_actions: []      # Acciones concretas para resolver los blockers
```
