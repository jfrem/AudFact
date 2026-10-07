---
name: ddf-agnostic-spec
description: >
  Framework de Documentación Determinista (DDF) para reingeniería agnóstica. Usar cuando se redacte
  cualquiera de los 7 documentos de especificación para la reescritura desacoplada de la plataforma
  (DOC-01 a DOC-07): meta-modelo de datos, motor de reglas, contratos de IA, puertos y adaptadores,
  máquina de estados, perfil de referencia AudFact o suite de calibración de paridad.
---

# DDF Agnostic Spec — Framework de Documentación Determinista

## Purpose

Usar esta skill para actuar como **Arquitecto de Especificación de Plataforma** especializado en el framework **Deterministic Documentation Framework (DDF-SDD)**. El objetivo es producir documentos de especificación técnica que sean completos, verificables, deterministas, agnósticos al dominio, auditables y ejecutables por un equipo de desarrollo o un agente de IA con inferencia cero.

Esta skill gobierna la redacción de los **7 documentos canónicos** (DOC-01 a DOC-07) que constituyen la base técnica para reescribir AudFact como una plataforma universal de auditoría documental, completamente desacoplada del dominio de salud, del proveedor de IA, de la base de datos y del almacenamiento físico.

La especificación maestra y el registro de decisiones SaaS viven en [opt/SPEC-SAAS-MASTER.md](../../../opt/SPEC-SAAS-MASTER.md). Consultarlos para conducir el cierre de gaps. Cuando el usuario solicite resolverlos juntos, presentar una sola pregunta de producto por vez, registrar la respuesta y avanzar con las decisiones técnicas que no dependan de información pendiente. Una recomendación no constituye aceptación del usuario. La multitenencia del SaaS es una capacidad nueva: `NitSec` / `FacNitSec` identifican clientes de negocio del sistema actual y no se convierten automáticamente en `tenant_id`.

**No producir** guías conceptuales, ensayos exploratorios ni recomendaciones genéricas. Cada documento debe especificar contratos formales (JSON Schema, interfaces tipadas, diagramas de estado, algoritmos paso a paso) con el nivel de precisión necesario para que un implementador los traduzca directamente a código.

## Required References

Antes de redactar cualquier documento de especificación, leer obligatoriamente:

1. `references/document-spec-template.md` — Estructura canónica obligatoria y checklist de auto-auditoría para cada DOC.
2. El checklist maestro de levantamiento (artefacto de conversación) para identificar los ítems pendientes del documento específico.

## Scope — Los 7 Documentos Canónicos

| Código | Nombre Canónico | Objetivo |
|:---:|---|---|
| **DOC-01** | `SPEC-DOMAIN-METAMODEL.md` | Abstracción de datos, Casos de Auditoría, Fuentes de Verdad y Tipos Documentales dinámicos. |
| **DOC-02** | `SPEC-RULE-ENGINE.md` | Motor de reglas declarativo, operadores de cotejo, homologación semántica y álgebra de veredictos. |
| **DOC-03** | `SPEC-AI-PROVIDER-SPI.md` | Contratos de proveedores de IA multimodal, compilador de esquemas, caching y circuit breaker. |
| **DOC-04** | `SPEC-PORTS-AND-ADAPTERS.md` | Arquitectura Hexagonal: puertos de datos, almacenamiento, eventos y telemetría. |
| **DOC-05** | `SPEC-PIPELINE-STATE-MACHINE.md` | Máquina de estados finita (DAG), esquemas de eventos, DLQ e idempotencia. |
| **DOC-06** | `PROFILE-HEALTH-DISPENSATION.md` | Perfil declarativo de referencia para la operación de AudFact; sus clientes pertenecen al tenant de una empresa contratante según DEC-P-001 del maestro, y la asignación histórica exige un mapeo explícito. |
| **DOC-07** | `SPEC-GOLDEN-BENCHMARK.md` | Datasets versionados, oráculos revisados y suite de validación: paridad histórica, corrección, reproducibilidad y prueba de dominio dual. Tamaños y umbrales se justifican en GAP-11 del maestro. |

## Los 5 Principios Fundamentales

Todo documento producido bajo esta skill debe adherirse rigurosamente a estos principios. El incumplimiento de cualquiera de ellos invalida el documento.

### Principio 1: Evidencia Epistémica (Taxonomía Obligatoria)

Toda afirmación sobre el sistema actual (AudFact) o sobre el comportamiento esperado del nuevo motor debe clasificarse explícitamente:

| Etiqueta | Significado | Requisito Obligatorio |
|:---:|---|---|
| `[CONFIRMADO]` | Hecho observado en código, base de datos o estándar técnico; también una decisión de producto expresamente aceptada por el usuario, identificada como diseño futuro. | Para hechos observados: ruta, clase, método y líneas exactas (`Clase::metodo(), líneas 45-80`) o fuente primaria técnica. Para decisiones de producto: ID canónico, respuesta explícita y fecha; no presentarlas como comportamiento implementado. |
| `[INFERIDO]` | Deducción lógica basada en evidencia indirecta. | Documentar premisa, evidencia indirecta e hipótesis. **Nunca puede cerrar una dependencia crítica.** |
| `[DESCONOCIDO]` | Información faltante o vacío de negocio. | Debe convertirse en un supuesto formal ($S_1 \dots S_n$) con severidad o bloquear el documento hasta su resolución. |

Reglas adicionales:

- Clasificar a nivel de oración, viñeta o fila de tabla; una etiqueta a nivel de sección es insuficiente.
- Inventar un valor plausible no es inferir. Rellenar un campo con un dato "típico" sin evidencia indirecta es invención, no inferencia → usar `[DESCONOCIDO]`.
- Una afirmación negativa (ej. "no existen consumidores") requiere documentar qué se buscó, con qué patrones y dónde.

### Principio 2: Prohibición de Lenguaje Ambiguo

Queda prohibido el uso de las siguientes frases y cualquier variante equivalente:

- ❌ "se debería" / "podría" / "probablemente" / "tal vez"
- ❌ "según sea necesario" / "donde aplique" / "cuando corresponda"
- ❌ "manejar casos especiales" / "implementar la lógica correspondiente"
- ❌ "realizar los ajustes necesarios" / "actualizar donde aplique"
- ❌ "etcétera" / "y demás" / "entre otros"

Sustitución obligatoria: comportamiento algorítmico exacto, tabla de decisión, esquema JSON o `[DESCONOCIDO]`.

### Principio 3: Abstracción Sin Fugas (*Leak-Free Abstraction Test*)

Cada contrato o interfaz diseñada debe superar la **Prueba de Dominio Dual**:

1. ¿Puede este contrato resolver el caso actual de AudFact (dispensación farmacéutica)?
2. ¿Puede este **mismo** contrato resolver un caso completamente diferente (ej. conciliación de remisiones logísticas, liquidación de siniestros de seguros, o verificación documental de créditos bancarios)?

Si la respuesta a cualquiera de las dos es NO, la abstracción tiene una fuga y debe rediseñarse.

**Regla de filtrado léxico**: El core del documento no debe contener términos de dominio específico de salud (`paciente`, `médico`, `fórmula médica`, `EPS`, `dispensación`, `medicamento`). Estos términos solo pueden aparecer en la sección de **Línea Base en AudFact** o en el documento **DOC-06 (Perfil de Referencia)**.

**Alcance comercial y validación**: DEC-P-008 del maestro confirma AudFact como primer perfil comercial. Esto mantiene la prueba de dominio dual como requisito del núcleo. Aplicar DEC-T-011 y DOM-01 a DOM-06: el mismo artefacto del núcleo y las mismas versiones de contratos procesan ambos dominios mediante perfiles, adaptadores y fixtures identificados. Un ejemplo redactado o un catálogo sin términos de salud no acredita una prueba ejecutada. El segundo perfil de validación no queda aprobado para comercialización por incluirlo en la suite.

**Métricas del benchmark**: Definir denominador, oráculo, muestreo, umbral y evidencia para cada métrica en DOC-07. Separar coincidencia con resultados legados de corrección revisada y de exactitud de extracción con IA. No convertir un tamaño de dataset prefijado en una garantía estadística ni marcar el gate PASS sin ejecutar el protocolo correspondiente.

### Principio 4: Construcción Limpia (*Clean Build Policy*)

- **Cero código muerto, cero legacy en el core**: Todo puente con sistemas heredados vive confinado en adaptadores periféricos (ACL), nunca dentro de la lógica de dominio.
- **Enfoque en MVP**: Cada abstracción debe justificarse con un caso de uso concreto. El overengineering especulativo es una infracción.
- **Modularidad estricta**: Cada documento especifica un módulo independiente con responsabilidades únicas y contratos de entrada/salida explícitos.

### Principio 5: Criterios de Aceptación Verificables (*Executable Contracts*)

Todo requisito funcional o técnico debe acompañarse de al menos uno de:

- Caso de prueba con input y output esperado exacto (formato Given-When-Then).
- Esquema JSON Schema estricto (`draft-2020-12`) validable por herramientas estándar.
- Aserción matemática o lógica ($condición \implies resultado$).

Si un requisito no puede ser probado mediante un test automatizado, la especificación está incompleta.

## Workflow Secuencial Obligatorio

La redacción de cada documento (DOC-01 a DOC-07) sigue una secuencia estricta de **6 pasos** con puertas de calidad (*gates*) que deben satisfacerse antes de avanzar.

### Paso 1 — Descubrimiento y Extracción de Línea Base

Antes de diseñar la abstracción, **extraer y documentar exhaustivamente cómo funciona hoy en AudFact**:

- Identificar todos los archivos, clases, métodos, consultas SQL, configuraciones y contratos que implementan la funcionalidad que este documento abstrae.
- Leer el código fuente directamente; no inferir comportamiento del nombre de las funciones.
- Registrar rutas absolutas, líneas específicas y propósito de cada componente.
- Ejecutar búsquedas por símbolo, referencia y consumidores para cerrar el perímetro.

**Gate**: Existe un inventario cerrado de componentes actuales con rutas, líneas y clasificación `[CONFIRMADO]`.

### Paso 2 — Identificación de Patrones Universales

Analizar la línea base extraída y separar:

- **Lógica de dominio (específica de salud)** → Se encapsulará en el perfil de referencia (DOC-06).
- **Lógica de plataforma (universal y reutilizable)** → Se abstrae en el contrato agnóstico.

Para cada patrón identificado, documentar:

| Patrón en AudFact | Componente(s) Actual(es) | Abstracción Propuesta | ¿Supera la Prueba de Dominio Dual? |
|---|---|---|:---:|

**Gate**: Toda lógica ha sido clasificada como dominio o plataforma, y las abstracciones propuestas superan la Prueba de Dominio Dual.

### Paso 3 — Diseño del Contrato Técnico Formal

Redactar la especificación técnica del componente agnóstico:

- **Interfaces y Puertos**: Firmas de métodos/funciones con tipos estrictos (usar pseudocódigo tipado estilo TypeScript o Go).
- **Esquemas de Datos**: JSON Schema `2020-12` para cada estructura de datos canónica.
- **Algoritmos**: Pseudocódigo determinista paso a paso para toda lógica no trivial.
- **Diagramas**: Máquinas de estado (Mermaid stateDiagram), flujos (flowchart) o grafos de dependencias según aplique.
- **Invariantes**: Condiciones que deben ser verdaderas en todo momento del ciclo de vida del componente.

**Gate**: Cada contrato tiene esquema formal, firma tipada, algoritmo explícito y al menos un diagrama.

### Paso 4 — Verificación de Cobertura y Paridad

Para cada funcionalidad documentada en la línea base (Paso 1), verificar que el contrato agnóstico (Paso 3) la cubre sin pérdida:

| Funcionalidad Actual en AudFact | Contrato Agnóstico que la Cubre | ¿Cobertura Completa? | Notas |
|---|---|:---:|---|

**Gate**: Tabla de cobertura completa sin celdas vacías. Toda funcionalidad está cubierta o explícitamente documentada como fuera de alcance con justificación.

### Paso 5 — Redacción del Documento Completo

Ensamblar el documento usando la estructura obligatoria de `references/document-spec-template.md`:

1. Metadatos y Control de Estado.
2. Resumen Ejecutivo y Alcance.
3. Línea Base en AudFact (del Paso 1).
4. Diseño del Contrato Agnóstico (del Paso 3).
5. Análisis de Impacto Inverso y Verificación de Abstracción.
6. Matriz de Trazabilidad y Riesgos.
7. Criterios de Aceptación Verificables.
8. Puerta de Calidad (Auto-Auditoría Adversarial).

**Gate**: Todas las secciones están presentes o explícitamente marcadas como N/A con justificación.

### Paso 6 — Auto-Auditoría Adversarial

Antes de presentar el documento, ejecutar la auto-auditoría de `references/document-spec-template.md`:

1. **Auditoría de Consistencia**: ¿Hay contradicciones internas? → Cada `FAIL` bloquea la entrega.
2. **Auditoría de Fugas de Abstracción**: ¿Hay términos de dominio en el core? → Cada fuga bloquea la entrega.
3. **Preguntas Adversariales**: Aplicar las preguntas del template. Cada `SÍ-NO-CORREGIDO` o `DESCONOCIDO` impide clasificación Nivel A.

**Gate**: Todas las auditorías pasan. El documento se clasifica con su nivel de completitud final.

## Niveles de Completitud del Documento

| Nivel | Significado | Requisitos |
|:---:|---|---|
| **A — Especificable** | Completo y determinista. Listo para implementación directa. | Cero `[DESCONOCIDO]` críticos, cero fugas de abstracción, cobertura 100%. |
| **B — Especificable con Supuestos** | Implementable con incertidumbres menores declaradas. | Supuestos $S_1$/$S_2$ documentados. Sin $S_3$/$S_4$. |
| **C — Diseño Parcial** | No completamente implementable. Requiere resolución de incógnitas. | Contiene $S_3$ que afectan decisiones de diseño. |
| **D — Descubrimiento Requerido** | No implementable. Falta evidencia fundamental. | Contiene $S_4$ o vacíos de negocio sin resolver. |

## Severidad de Supuestos

| Severidad | Impacto | Acción Requerida |
|:---:|---|---|
| $S_1$ | Bajo. Si es incorrecto, el cambio es cosmético. | Documentar y proceder. |
| $S_2$ | Medio. Si es incorrecto, requiere refactorización acotada. | Documentar y validar antes de producción. |
| $S_3$ | Alto. Si es incorrecto, invalida una decisión de diseño. | Bloquea la sección afectada hasta resolución. |
| $S_4$ | Crítico. Si es incorrecto, invalida el documento completo. | Bloquea el documento hasta resolución. |

## Output Requirements

### Verificación reproducible de contratos compartidos

- Comprobador local de DOC-01/02: `node opt/tools/validate-ddf.cjs`. Los aliases `validate-doc-01.cjs` y `validate-doc-02.cjs` validan siempre el conjunto compartido.
- Usa las versiones exactas Ajv 8.20.0 y ajv-formats 2.1.1 del lockfile de `website/`; `npm ci --prefix website` reproduce su instalación. Actualizar el contrato de herramientas deliberadamente al cambiar versiones.
- Registrar schemas antes de compilarlos, comprobar referencias/fragmentos locales y rechazar referencias externas no registradas. No cargar schemas remotos suministrados por perfiles.
- Propietarios actuales: DOC-01/Common para Severity; DOC-02 para ComparisonStrategy, FindingOutcome, Finding y OperatorParameters. Los consumidores referencian esos schemas; no duplican enums o políticas divergentes.
- Separar QUANTITY_COVERAGE (documento >= referencia) de TOLERANCE (abs(diferencia) < factor). Parámetros cerrados y fuente de factor explícita; números decimales canónicos con aritmética exacta.
- El comprobador acredita schemas, fixtures y funciones de contrato; no acredita autorización, publicación transaccional, proveedor IA, paridad del adaptador ni prueba dual del motor. Conservar esos gates pendientes hasta ejecutarlos.
- Cada reporte debe indicar comando, versiones, conteos, fallos y alcance. No trasladar PASS históricos cuyo mecanismo no existe o cuya revisión difiere.
- El maestro registra RISK-06 cuando `opt/` no está versionado. Continuar el descubrimiento local, pero resolver ubicación canónica y CI antes de aprobar la guía de implementación.

- Directorio de destino: Todos los documentos DOC-01 a DOC-07 deben alojarse en opt/ (ej. opt/DOC-01-SPEC-DOMAIN-METAMODEL.md).
- Idioma: Español (Latinoamérica) por defecto, salvo que el usuario solicite otro.
- Estructura: Alineada estrictamente con `references/document-spec-template.md`.
- Tablas: Obligatorias donde el template las requiere.
- Esquemas: JSON Schema `draft-2020-12` completo para toda estructura de datos.
- Interfaces: Pseudocódigo tipado con firmas completas (TypeScript-like o Go-like).
- Diagramas: Mermaid exclusivamente (stateDiagram-v2, flowchart TD/LR, classDiagram).
- Referencias: Ruta absoluta + símbolo + líneas (`Clase::metodo(), líneas 120-145`).
- Criterios de aceptación: Verificables, observables e independientemente comprobables.
- Cada propuesta de abstracción debe incluir un ejemplo de input/output concreto.

## Relación con Otras Skills

| Skill | Relación |
|---|---|
| `write-sdd-spec` | `ddf-agnostic-spec` hereda los principios de evidencia epistémica y lenguaje prohibido de SDD, pero los extiende con la Prueba de Dominio Dual y el filtrado léxico de fugas de abstracción. Para cambios en el código existente de AudFact, usar `write-sdd-spec`. Para documentos de la nueva plataforma agnóstica, usar `ddf-agnostic-spec`. |
| `audfact-project-overview` | Fuente de contexto para el Paso 1 (Descubrimiento de Línea Base). |
| `audfact-audit-gemini` | Fuente principal de evidencia para DOC-02 (Motor de Reglas), DOC-03 (Contratos de IA) y DOC-05 (Pipeline). |
| `audfact-sqlsrv-models` | Fuente principal de evidencia para DOC-01 (Meta-Modelo) y DOC-04 (Puertos de Datos). |
| `clean-rebuild-policy` | Los principios de construcción limpia de esta skill son un subconjunto del Principio 4 de DDF. |
