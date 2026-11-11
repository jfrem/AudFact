# Plantilla SDD

Usa esta referencia como estructura obligatoria para producir o auditar especificaciones de implementación con Specification Driven Development.

## Reglas Globales

### Rol

Actúa como Arquitecto de Software especializado en Specification Driven Development. La salida debe permitir que un desarrollador senior, un agente de IA y un revisor técnico lleguen a soluciones sustancialmente equivalentes sin reuniones adicionales.

### Clasificación Obligatoria

Toda afirmación específica **sobre el estado, comportamiento o estructura del sistema** debe etiquetarse a nivel de oración, viñeta o fila:

- `[CONFIRMADO]`: existe evidencia explícita.
- `[INFERIDO]`: existe evidencia indirecta o deducción razonable. No confundir con un valor plausible inventado: rellenar un campo con un valor típico, común o esperado cuando no existe evidencia es invención, no inferencia.
- `[DESCONOCIDO]`: no existe información suficiente.

Las reglas metodológicas de esta plantilla no requieren clasificación; la clasificación aplica exclusivamente a afirmaciones sobre el proyecto.

Restricción crítica: está prohibido presentar información inferida como confirmada.

### Prioridad de Reglas

1. No inventar información.
2. Mantener trazabilidad.
3. Mantener determinismo.
4. Mantener consistencia interna.
5. Completar la estructura requerida.
6. Maximizar el nivel de detalle útil.

### Limitación de Inferencias

Una afirmación `[INFERIDO]` puede utilizarse para diseñar una hipótesis, pero no puede utilizarse como evidencia suficiente para:

- cerrar una dependencia crítica;
- aprobar una migración;
- declarar compatibilidad;
- declarar ausencia de consumidores;
- clasificar la especificación como Nivel A.

Cuando una inferencia es la única base para una decisión crítica, esa decisión debe documentarse como supuesto con severidad S3 o S4.

### Conversión de Desconocidos

Todo `[DESCONOCIDO]` que afecte una decisión de implementación debe convertirse en un supuesto S1–S4 o permanecer explícitamente como información faltante (0.7–0.9). Un desconocido no puede permanecer sin clasificar mientras la especificación procede como si fuera irrelevante.

### Evidencia Negativa

Una afirmación negativa (ausencia de consumidores, dependencias, referencias o impacto) requiere evidencia del universo inspeccionado y del mecanismo de búsqueda utilizado. Declarar ausencia sin documentar qué se buscó, con qué patrones y dónde, equivale a `[INFERIDO]`.

### Lenguaje Prohibido

No uses frases ambiguas como:

- "Se debería"
- "Probablemente"
- "Podría"
- "Según sea necesario"
- "Etcétera"
- "Implementar la lógica correspondiente"
- "Realizar los ajustes necesarios"
- "Actualizar donde aplique"
- "Manejar casos especiales"

También evita variantes sin tilde o en minúsculas, y sus equivalentes en el idioma de salida (por ejemplo, en inglés: "should", "probably", "might", "as needed", "etc.", "handle edge cases", "update where applicable"). Sustituye cada frase por comportamiento verificable o marca el dato como `[DESCONOCIDO]`.

Excepción: en Supuestos (0.10), Casos Límite (15) y Riesgos (17) se permite lenguaje condicional siempre que la condición y la consecuencia sean explícitas y verificables (`si <condición>, entonces <consecuencia>`). "Podría" sin condición explícita sigue prohibido.

### Evidencia

Cuando exista evidencia local, cita la ruta relativa a la raíz del repositorio, el símbolo y las líneas observadas en el momento del descubrimiento (ej: `src/billing/fees.py — FeeCalculator::compute(), líneas observadas: 120-145`). No uses rutas absolutas del equipo local. Cuando la evidencia provenga del usuario, cita el dato exacto de la solicitud. Cuando no haya evidencia, usa `[DESCONOCIDO]`.

### Niveles de Completitud

- `Nivel A — Implementable`: Completa y determinista. No existen dependencias críticas desconocidas. Solo admite supuestos S1 y supuestos S2 en estado `Mitigado` (ver 0.10).
- `Nivel B — Implementable con Supuestos Declarados`: Implementable con supuestos S2 abiertos explícitos. No es técnicamente completa en el sentido estricto, pero permite implementación controlada.
- `Nivel C — Diseño Parcial`: No completamente implementable. Contiene supuestos S3 o hallazgos de auditoría sin corregir que afectan decisiones de diseño.
- `Nivel D — Descubrimiento Requerido`: No implementable. Contiene supuestos S4 o falta evidencia fundamental.

### Determinación del Nivel

El nivel final es el **más bajo** entre los niveles máximos de todas las condiciones que se cumplan:

| Condición | Nivel máximo |
| --- | --- |
| Perímetro no cerrado, archivo con "Verificado por Lectura" = No, información faltante crítica (0.7) abierta o supuesto S4 abierto | D |
| Supuesto S3 abierto; fila de 0.3 sin corrección; `FAIL` en FASE 2; `Sí` en FASE 3; pregunta adversarial `SÍ-NO-CORREGIDO` o `DESCONOCIDO` | C (D si el supuesto resultante es S4) |
| Supuesto S2 en estado `Abierto`, supuesto sin severidad o estado asignados, o información faltante importante (0.8) abierta | B (C si el supuesto no tiene severidad) |
| Solo supuestos S1 y S2 `Mitigado`, y todas las auditorías en `PASS` / `No` / `NO` o `SÍ-CORREGIDO` | A |

Las auditorías determinan el nivel; no impiden entregar la especificación. Toda especificación se entrega con su nivel y su justificación.

## Clasificación del Cambio (Triage)

Antes de iniciar FASE 0, clasificar el cambio propuesto para calibrar el nivel de rigor:

| Dimensión | Valores Posibles |
| --- | --- |
| Tipo | Bug / Feature / Refactor / Migración / Infraestructura / Contrato |
| Riesgo | Bajo / Medio / Alto / Crítico |
| Persistencia afectada | Sí / No / Desconocido |
| Contrato externo afectado | Sí / No / Desconocido |
| Cambio arquitectónico | Sí / No |
| Producción afectada | Sí / No / Desconocido |
| Requiere 0.3.1 (cobertura de abstracciones) | Sí / No |

Reglas de calibración según profundidad de descubrimiento:

- **Riesgo Bajo** (Bug simple, refactor cosmético sin cambio de contrato): Descubrimiento mínimo demostrable. Las secciones de Observabilidad y Rollout pueden marcarse como N/A con justificación.
- **Riesgo Medio** (Feature nueva sin migración ni contrato externo): Descubrimiento completo del código afectado. Todas las secciones de FASE 0 y FASE 1 son obligatorias.
- **Riesgo Alto** (Migración, cambio de contrato, infraestructura): Descubrimiento completo + infraestructura + operación + rollback. Todas las secciones obligatorias, incluyendo Observabilidad y Rollout.
- **Riesgo Crítico** (Cambio en producción con persistencia y contratos externos): Descubrimiento completo + infraestructura + operación + rollback + señales medibles. Todas las secciones obligatorias y los criterios de aceptación deben incluir señales de rollback medibles.

El triage calibra la profundidad del descubrimiento y permite marcar secciones como N/A con evidencia, pero nunca permite omitir FASE 0, FASE 2 o FASE 3. Las reglas de evidencia no se reducen por nivel de riesgo.

### Perfil Reducido (solo Riesgo Bajo)

Elegible únicamente si el triage registra: Persistencia afectada = No, Contrato externo afectado = No, Cambio arquitectónico = No y Producción afectada ≠ Desconocido. Si cualquiera de esas condiciones cambia durante el descubrimiento, abandonar el perfil reducido.

| Bloque | Tratamiento en perfil reducido |
| --- | --- |
| FASE 0 | 0.1, 0.2, 0.3, 0.6, 0.10 y 0.11 completas. 0.4 y 0.5 pueden limitarse a las herramientas y entornos realmente implicados. 0.3.1 solo si el triage lo exige. 0.7–0.9 pueden declarar "Ninguna". |
| FASE 1 | Obligatorias: 1, 2, 11, 13, 16 y 18. El resto puede agruparse en un único bloque `N/A` con una línea de justificación clasificada por sección. |
| FASE 2 y FASE 3 | Todas las filas se responden; una fila puede remitir a la evidencia del triage o de FASE 0 en lugar de repetirla. |

## FASE 0 — Descubrimiento Empírico Obligatorio

Antes de generar la especificación, ejecutar el protocolo de descubrimiento completo. No asumas tablas, columnas, endpoints, eventos, colas, archivos, contratos, dependencias, servicios ni procesos internos. Toda afirmación debe tener evidencia en el formato definido en «Evidencia».

### 0.1 Perímetro de Impacto

Listar todos los archivos relacionados con el cambio y abrirlos; nunca inferir su contenido del nombre. Profundidad de lectura:

- `MODIFIED` e `IMPACTED`: lectura completa. En archivos de gran tamaño, lectura completa de los símbolos afectados, sus llamadores y sus dependencias dentro del archivo, registrando los rangos leídos.
- `INSPECTED`: lectura de los fragmentos suficientes para justificar la clasificación, registrando los rangos leídos.

Clasificar cada archivo en exactamente una de las siguientes categorías:

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
|  |  | MODIFIED / IMPACTED / INSPECTED |  |  | Sí / No |

Categorías:

- `MODIFIED`: Su contenido cambia directamente como parte de este cambio.
- `IMPACTED`: No necesariamente cambia, pero su comportamiento puede verse afectado por el cambio.
- `INSPECTED`: Fue leído durante el descubrimiento y la evidencia recopilada confirma que no cambia ni su comportamiento resulta afectado por **este cambio específico**. No implica irrelevancia universal; otro cambio podría reclasificarlo como IMPACTED o MODIFIED.

Regla: Ningún archivo puede aparecer en este inventario sin haber sido abierto y leído con la profundidad indicada. Si la columna "Verificado por Lectura" contiene un "No", la especificación queda limitada a Nivel D hasta completar la lectura.

#### Criterio de Cierre del Perímetro

El perímetro se considera cerrado cuando se ha agotado el conjunto de mecanismos de búsqueda aplicables al tipo de cambio y no existen resultados adicionales relevantes dentro del universo inspeccionado. Documentar los métodos utilizados:

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por símbolo |  |  |  |
| Búsqueda por importación/require |  |  |  |
| Búsqueda textual |  |  |  |
| Búsqueda en configuración |  |  |  |
| Búsqueda en tests |  |  |  |
| Búsqueda en workflows/CI |  |  |  |

#### Expansión Controlada del Alcance

Si durante cualquier paso posterior del descubrimiento se descubre un componente necesario no contemplado en el perímetro inicial, el perímetro se amplía. El cambio debe re-registrarse en Triage, Alcance, Impact Analysis y Cambios por Archivo.

### 0.2 Grafo de Dependencias Acopladas

Para cada archivo del Perímetro de Impacto, identificar todos los artefactos que lo consumen, invocan, importan, incluyen, referencian o dependen de su existencia. Incluir: scripts de arranque, entrypoints, bootstraps, workflows de CI/CD, configuración de runtime, tests, otros archivos del mismo dominio y consumidores fuera del repositorio.

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
|  |  |  |  | Directa / Transitiva | Estática / Dinámica / Operacional / Contractual | Repositorio local / Otro repositorio / Servicio externo / Cliente externo / Operación manual |

Regla: Cada arista del grafo debe tener evidencia de lectura directa del archivo dependiente. No se permite declarar "sin dependencias" sin haber buscado activamente en el repositorio. Para consumidores fuera del repositorio, registrar la evidencia de búsqueda o `[DESCONOCIDO]` si no existe acceso al inventario de consumidores externos.

### 0.3 Análisis de Impacto Inverso (Regresiones)

Para cada cambio propuesto (eliminación, adición, modificación), formular y responder la pregunta: "Si aplico este cambio, ¿qué componente del grafo de dependencias deja de funcionar?"

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
|  |  |  | Build / Runtime / Test / Contract / Data / Pipeline / DX |  |

Tipos de regresión:

- `Build`: falla en compilación, transpilación, generación de artefactos o instalación de dependencias.
- `Runtime`: falla en ejecución del sistema (arranque, request handling, procesamiento, workers).
- `Test`: falla en suite de pruebas existente (unitarias, integración, e2e).
- `Contract`: ruptura de contrato de API, evento, esquema, interfaz pública o protocolo de comunicación.
- `Data`: pérdida, corrupción o inconsistencia de datos persistidos.
- `Pipeline`: falla en workflow de CI/CD, deploy o validación automatizada.
- `DX`: degradación de experiencia de desarrollo sin falla funcional directa.

Regla: Si la tabla tiene filas sin corrección propuesta, la especificación se clasifica como máximo Nivel C.

### 0.3.1 Verificación de Cobertura de Abstracciones

Si el cambio propone **reemplazar un mapeo estático** (constante, lista fija, tabla de lookup, switch/case exhaustivo, diccionario hardcodeado) **por una abstracción dinámica** (método semántico, interfaz polimórfica, evaluación por metadatos, configuración en base de datos, resolución por convención), ejecutar obligatoriamente:

1. **Enumerar exhaustivamente** cada elemento del mapeo estático actual.
2. Para **cada elemento**, determinar empíricamente los atributos que la abstracción dinámica usaría para clasificarlo.
3. Verificar que **ningún otro elemento del sistema** comparte esos mismos atributos pero pertenece a una categoría diferente (colisión).
4. Documentar el resultado en la tabla de cobertura:

| Elemento del Mapeo Estático | Atributos Dinámicos | ¿Otros elementos comparten esos atributos? | ¿Clasificación correcta? |
| --- | --- | --- | --- |
|  |  | Sí (listar cuáles) / No | Sí / No |

Cuando los atributos dinámicos provienen de datos persistidos (base de datos, configuración externa, archivos de datos), la verificación **debe incluir una consulta empírica a los datos reales** del sistema. No se permite verificar la cobertura solo contra el código fuente.

Regla: Si algún elemento no es cubierto por la abstracción dinámica sin colisiones, el reemplazo no es viable. Debe mantenerse el mapeo estático o proponer una alternativa que resuelva todas las colisiones.

### 0.4 Verificación de Semántica de Herramientas

Para cada herramienta, parser o evaluador cuyo comportamiento el cambio dependa, verificar que el cambio respeta sus reglas de evaluación.

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
|  |  | Documental / Empírica / Estática / Experimental | URL, ruta:línea o descripción del experimento | Sí / No + justificación |

Tipos de evidencia:

- `Documental`: La herramienta declara oficialmente ese comportamiento en su documentación.
- `Empírica`: Se observó el comportamiento ejecutando el sistema.
- `Estática`: Se verificó mediante análisis de código o configuración.
- `Experimental`: Se reprodujo mediante prueba controlada.

Ejemplos de herramientas que frecuentemente requieren verificación (lista no exhaustiva; adaptar al dominio del cambio):

- Gestores de paquetes (orden de resolución, lockfiles, scripts de lifecycle).
- Sistemas de build (caché de capas, multi-stage, orden de evaluación de ignores).
- Servidores y proxies (orden de evaluación de directivas, variables de template, rewrite rules).
- Parsers de configuración (YAML anchors, JSON schema, INI sections, ENV precedence).
- ORMs y query builders (lazy loading, transacciones implícitas, migraciones).
- Frameworks de routing (orden de matching, middlewares, precedencia de rutas).
- Motores de templates (herencia, bloques, scoping de variables).
- Evaluadores de shell (orden de expansión, quoting, exit codes, subshells).

Regla: No se permite asumir comportamiento de una herramienta sin evidencia. Si se asume, clasificar como `[INFERIDO]` y documentar el riesgo.

### 0.5 Matriz de Entornos de Ejecución

Para cada cambio propuesto, verificar compatibilidad en todos los entornos donde el artefacto se ejecuta. Las filas son una base: agregar los entornos propios del proyecto e indicar en "Flujo" la herramienta concreta (por ejemplo, el proveedor de CI).

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local |  |  | Sí / No / N/A |  |
| CI automatizado |  |  | Sí / No / N/A |  |
| Staging / Preproducción |  |  | Sí / No / N/A |  |
| Producción |  |  | Sí / No / N/A |  |
| Testing aislado |  |  | Sí / No / N/A |  |

Regla: No se permite dejar celdas vacías. Si un entorno no existe en el proyecto, marcar como `N/A` con evidencia que confirme su inexistencia. Toda celda "No" genera una regresión que debe aparecer en la tabla 0.3.

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
|  | `[CONFIRMADO]` / `[INFERIDO]` / `[DESCONOCIDO]` |  |

### 0.7 Información Faltante Crítica

Información que impide una implementación determinística.

| Dato | Motivo | Impacto |
| --- | --- | --- |
|  |  |  |

### 0.8 Información Faltante Importante

Información que no bloquea la implementación, pero aumenta riesgo.

| Dato | Motivo | Impacto |
| --- | --- | --- |
|  |  |  |

### 0.9 Información Faltante Opcional

Información que no afecta implementación ni validación.

| Dato | Motivo | Impacto |
| --- | --- | --- |
|  |  |  |

### 0.10 Supuestos Declarados

| ID | Supuesto | Base | Consecuencia si es Falso | Severidad | Estado | Mitigación y Detección | Condición de Cierre |
| --- | --- | --- | --- | --- | --- | --- | --- |
|  |  | `[INFERIDO]` + evidencia indirecta, o `[DESCONOCIDO]` | qué secciones, decisiones o comportamientos cambian | S1 / S2 / S3 / S4 | Abierto / Mitigado | referencias a 16, 17, 19, 20 o N/A | evidencia que lo convertiría en `[CONFIRMADO]` |

Clasificación de severidad de supuestos:

- `S1 — No bloqueante`: El supuesto no afecta la implementación ni la validación.
- `S2 — Riesgo operativo`: Si resulta falso, cambia el comportamiento operativo (rendimiento, volumen, tiempos, capacidad, coste) pero no el diseño ni los cambios por archivo.
- `S3 — Afecta diseño`: El supuesto influye en una decisión arquitectónica. Si resulta incorrecto, el diseño cambia.
- `S4 — Bloquea implementación`: Sin este dato, la implementación no puede proceder.

#### Prueba de Severidad

Asignar la severidad respondiendo en orden; la primera respuesta afirmativa fija la severidad:

1. ¿Sin este dato es imposible redactar algún cambio de la sección 13 o el script de la sección 9? → `S4`.
2. Si el supuesto es falso, ¿cambia alguna decisión de la sección 6, algún contrato de la sección 10 o algún archivo de la sección 13? → `S3`.
3. Si el supuesto es falso, ¿cambia algún comportamiento operativo, criterio de aceptación o señal de las secciones 18–20? → `S2`.
4. En otro caso → `S1`.

Si la respuesta a una pregunta es dudosa, asignar la severidad más alta de las posibles. Está prohibido rebajar una severidad para alcanzar un nivel de completitud.

#### Estados

- `Abierto`: el supuesto está declarado, pero no cumple los requisitos de `Mitigado`.
- `Mitigado` (solo S1 y S2): el riesgo está contenido y su falsedad sería detectada y atendida. Un S2 está mitigado únicamente si cumple **todas** estas condiciones:
  1. su consecuencia si es falso no modifica las secciones 6, 10 ni 13 (en caso contrario es S3);
  2. existe una mitigación registrada en la sección 17;
  3. existe un mecanismo de detección verificable: una prueba en la sección 16, una señal con umbral en la sección 19 o una condición de detención en la sección 20. En cambios de Riesgo Alto o Crítico, la detección debe incluir una señal de la sección 19 o una condición de la sección 20; una prueba previa al despliegue no basta;
  4. existe una respuesta definida al detectarse (rollback, detención del rollout o acción documentada en las secciones 14 o 20).
- Un S3 o S4 no puede mitigarse: solo se resuelve obteniendo evidencia.

#### Cierre

Un supuesto se cierra únicamente cuando se obtiene la evidencia descrita en su condición de cierre. Al cerrarse, sale de esta tabla y pasa al inventario (0.6) como `[CONFIRMADO]`; si la evidencia lo contradice, el diseño se revisa y se regresa al paso de descubrimiento correspondiente. Una inferencia nunca cierra un supuesto.

Reglas:

- Todo supuesto debe documentarse con todas las columnas de la tabla.
- Ningún supuesto puede mezclarse con información confirmada.
- Todo supuesto debe aparecer en la auditoría final, con su severidad y estado.
- Las decisiones pendientes de un responsable externo (negocio, regulación, presupuesto, SLA) no son supuestos técnicos; se documentan según FASE 4.
- El efecto de los supuestos en el nivel de completitud se determina exclusivamente con la tabla «Determinación del Nivel».

### 0.11 Clasificación de Completitud Inicial

Indica un nivel y justifícalo con evidencia de los pasos 0.1-0.10.

## FASE 1 — Especificación

### 1. Objetivo

Documenta:

- problema actual;
- causa raíz;
- impacto actual;
- resultado esperado;
- por qué existe este cambio.

### 2. Alcance

#### Incluido

Lista exhaustiva de funcionalidades afectadas.

#### Excluido

Lista exhaustiva de funcionalidades fuera del alcance.

### 3. Non Goals

Documenta cambios relacionados que no serán implementados.

### 4. Estado Actual

Documenta:

- arquitectura actual;
- comportamiento actual;
- flujo actual;
- dependencias involucradas;
- limitaciones conocidas.

Incluye cuando exista evidencia:

- código;
- endpoints;
- contratos;
- queries;
- eventos;
- diagramas;
- estructuras de datos.

### 5. Estado Objetivo

Documenta:

- arquitectura objetivo;
- comportamiento esperado;
- flujo futuro;
- responsabilidades por componente;
- interacciones entre componentes.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
|  |  |  |  |

Toda decisión relevante debe aparecer aquí.

#### 6.1 Estrategia de Cambio y Excepciones

Aplicar la compuerta de decisión de la skill `clean-rebuild-policy`:

| Dimensión | Valor |
| --- | --- |
| Estrategia | Clean rebuild / Clean rebuild interno / Refactorización incremental / Reconstruir / Eliminar / Parche excepcional |
| Fila de la compuerta aplicada | contexto comprobado que la justifica |
| Candidatas descartadas y desempate | descripción o N/A |
| Fuente del alcance MVP | requisito, ticket, solicitud o documento; `[DESCONOCIDO]` si no existe |

Toda excepción (adaptador temporal, compatibilidad acotada o parche excepcional):

| Regla Exceptuada | Motivo Verificable | Responsable | Superficie | Condición de Retiro | Validación | Rollback |
| --- | --- | --- | --- | --- | --- | --- |
|  |  | rol o persona (`pendiente de confirmación` si no fue confirmado) |  |  |  |  |

Una excepción sin condición de retiro verificable no se admite. Si no existen excepciones, declarar `Ninguna`.

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
|  | librería / servicio / API / base de datos / cola / infraestructura / tercero |  |  |

#### 7.1 Fuentes de Verdad

Cuando el cambio involucra múltiples artefactos que pueden declarar el mismo comportamiento (código, esquema, configuración, documentación, tests, contratos), identificar la fuente de verdad para cada dimensión:

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
|  | Código / Schema / Config / OpenAPI / Migración / Test / Documentación / Otro |  | Sí / No |

#### Procedimiento de Resolución de Conflictos

Si dos o más fuentes declaran comportamientos contradictorios:

1. Detectar el conflicto y documentarlo explícitamente.
2. No resolverlo mediante inferencia.
3. Identificar la autoridad de cada fuente según el proyecto.
4. Registrar el conflicto como supuesto S3 si afecta el diseño, o S4 si bloquea la implementación.
5. Resolver antes de clasificar como Nivel A.

Si no existen conflictos, declarar `[CONFIRMADO] Sin conflictos detectados entre fuentes de verdad` con evidencia.

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
|  |  |  |

Toda modificación debe preservar los invariantes existentes o reemplazarlos explícitamente.

### 9. Modelo de Datos

Si existe impacto en persistencia, documenta los elementos aplicables al tipo de almacenamiento. Para bases relacionales:

- tablas nuevas;
- tablas modificadas;
- tablas eliminadas;
- columnas agregadas;
- columnas modificadas;
- columnas eliminadas;
- índices;
- constraints;
- foreign keys;
- triggers;
- vistas;
- procedimientos almacenados.

Para otros almacenamientos (documentales, clave-valor, búsqueda, colas con esquema, archivos, caché): colecciones o índices, estructura de documentos o mensajes, formatos de serialización, claves, TTL y políticas de invalidación afectadas.

Si no existe impacto confirmado en persistencia, declara `[CONFIRMADO] Sin impacto en persistencia` solo con evidencia verificable. Si no hay evidencia, declara `[DESCONOCIDO]`.

#### Script de Cambio

Incluye el script completo en el lenguaje nativo del almacenamiento: DDL SQL, script de migración del motor u ORM, transformación de archivos o procedimiento de invalidación de caché. No resumas.

#### Orden de Ejecución

Secuencia exacta.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
|  |  |  |  |

Para migraciones complejas, documentar adicionalmente:

| Dimensión | Valor |
| --- | --- |
| Estrategia | expand/contract / big-bang / rolling |
| Compatibilidad entre versiones | Sí / No / N/A |
| Duración esperada de locks | estimación o N/A |
| Volumen de datos afectados | estimación |
| Estrategia de backfill | descripción o N/A |
| Comportamiento durante despliegue parcial | descripción |

#### Rollback

Incluye el script completo de reversión en el mismo lenguaje que el script de cambio.

### 10. Contratos

Para cada API, evento, cola, batch, archivo, integración o mensaje interno, documenta:

#### Clasificación del Contrato

| Dimensión | Valor |
| --- | --- |
| Tipo | API REST / Evento / Cola / Batch / Archivo / Integración / Mensaje interno |
| Visibilidad | Público / Interno / Persistente / Temporal |
| Productor | Componente que genera el contrato |
| Consumidores | Componentes que consumen el contrato |
| Versionado | Sí / No / N/A |
| Compatibilidad requerida | Backward / Forward / Ambas / Ninguna |
| Enforcement | Schema validation / Tests / Runtime check / Ninguno |

#### Antes

Contrato actual.

#### Después

Contrato final.

Indica:

- campos agregados;
- campos eliminados;
- campos modificados;
- campos deprecados;
- compatibilidad backward;
- compatibilidad forward;
- ejemplos completos.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
|  |  |  |  |

No se permiten requisitos huérfanos.

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
|  |  |  |  |  |

No se permite declarar "Impacto cero" sin evidencia verificable.

### 13. Cambios por Archivo

Para cada archivo usa un estado:

- `[NEW]`: archivo nuevo.
- `[MODIFY]`: archivo existente.
- `[DELETE]`: archivo eliminado.

Documenta:

- ruta relativa a la raíz del repositorio;
- clases afectadas;
- funciones afectadas;
- métodos afectados;
- símbolo + líneas observadas (ej: `Clase::metodo(), líneas observadas: 120-145`);
- fragmentos según el estado:
  - `[MODIFY]`: antes y después, con las líneas observadas del código actual;
  - `[NEW]`: contenido completo o esqueleto determinista del archivo (solo "después");
  - `[DELETE]`: contenido eliminado o resumen de sus símbolos (solo "antes") y referencia a la evidencia de ausencia de consumidores en 0.2.

Preferir referencias basadas en símbolos sobre números de línea solos, para mayor resiliencia ante ediciones posteriores.

Todo archivo afectado debe aparecer aquí.

### 14. Plan de Migración

#### Prerequisitos

#### Ejecución

Secuencia paso a paso.

#### Validaciones Previas

#### Validaciones Posteriores

#### Rollback

Procedimiento completo de reversión.

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
|  |  |  |

Incluye entradas inválidas, datos corruptos, concurrencia, duplicados, reintentos, compatibilidad histórica, fallos de integración y secuencias inválidas.

### 16. Testing

#### Nuevos Tests

Para cada prueba: objetivo, precondiciones, pasos y resultado esperado.

#### Tests Modificados

Para cada prueba: objetivo, precondiciones, pasos y resultado esperado.

#### Tests Eliminados

Para cada prueba eliminada: motivo y cobertura de reemplazo.

#### Verificaciones Manuales

Para cada verificación: objetivo, precondiciones, pasos y resultado esperado.

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
|  | técnico / operativo / rendimiento / seguridad / migración / consistencia de datos |  |  |

### 18. Criterios de Aceptación

Todos los criterios deben ser verificables.

Ejemplos válidos:

- endpoint responde HTTP 200;
- evento contiene campo requerido;
- constraint rechaza registros inválidos;
- migración ejecuta sin errores;
- tests pasan.

Ejemplos inválidos:

- funciona correctamente;
- mejora experiencia;
- se comporta mejor;
- optimiza rendimiento.

### 19. Observabilidad

Si el triage clasifica el cambio como Riesgo Alto o Crítico, documentar las señales operativas afectadas:

| Señal | Tipo | Antes (baseline) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
|  | Métrica / Log / Trace / Alerta | baseline observable existente o `[DESCONOCIDO]` | comportamiento esperado |  |  | Investigar / Rollback / Escalar |

Si el cambio no afecta señales operativas y el riesgo es Bajo o Medio, declarar `Sin impacto en observabilidad` con justificación.

### 20. Estrategia de Rollout

Si el triage clasifica el cambio como Riesgo Alto o Crítico, documentar la estrategia de despliegue:

| Dimensión | Valor |
| --- | --- |
| Estrategia de despliegue | Directo / Gradual / Canary / Blue-Green / Feature Flag |
| Orden entre productores y consumidores | Productor primero / Consumidor primero / Simultáneo / N/A |
| Coexistencia entre versiones | Sí (duración) / No / N/A |
| Compatibilidad requerida durante rollout | Backward / Forward / Ambas / N/A |
| Condición para avanzar rollout | descripción medible |
| Condición para detener rollout | descripción medible |
| Condición de rollback | descripción medible o N/A |
| Acción de rollback | descripción del procedimiento |
| Tiempo máximo para iniciar rollback | estimación o N/A |
| Responsable de decisión | rol o persona |

Si el cambio no requiere estrategia de rollout y el riesgo es Bajo o Medio, declarar `Sin estrategia de rollout requerida` con justificación.

## FASE 2 — Auditoría de Consistencia

Valida cada fila:

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes mencionadas por la especificación están definidas | PASS / FAIL |  |
| Todas las columnas mencionadas existen | PASS / FAIL |  |
| Todos los contratos documentados con clasificación | PASS / FAIL |  |
| Todos los requisitos tienen trazabilidad | PASS / FAIL |  |
| Todos los consumidores analizados | PASS / FAIL |  |
| Todas las migraciones tienen rollback | PASS / FAIL |  |
| Todas las referencias a archivos, clases, funciones, métodos, variables, comandos, endpoints, eventos y configuraciones están definidas | PASS / FAIL |  |
| Toda compatibilidad tiene evidencia | PASS / FAIL |  |
| Todos los criterios son verificables | PASS / FAIL |  |
| Observabilidad documentada (si aplica por triage) | PASS / FAIL / N/A |  |
| Rollout documentado (si aplica por triage) | PASS / FAIL / N/A |  |

Ninguna sección puede omitirse silenciosamente. Una sección no aplicable debe aparecer explícitamente como `N/A`, acompañada de `[CONFIRMADO]` o `[INFERIDO]` y su justificación.

Si existe cualquier `FAIL`, la especificación es incompleta y su nivel máximo es C (ver «Determinación del Nivel»).

## FASE 3 — Auditoría Arquitectónica

Responde cada pregunta con evidencia verificable:

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | Sí / No | FASE 0.x / ruta:línea |
| ¿Existe algún contrato sin documentar? | Sí / No |  |
| ¿Existe algún consumidor no analizado? | Sí / No |  |
| ¿Existe alguna migración sin rollback? | Sí / No |  |
| ¿Existe algún dato persistido sin migración? | Sí / No |  |
| ¿Existe alguna afirmación sobre el proyecto sin clasificar, o clasificada con un nivel de certeza superior a su evidencia? | Sí / No |  |
| ¿Existen referencias huérfanas? | Sí / No |  |
| ¿Queda alguna decisión de implementación sin registrar en la sección 6, o algún cambio de la sección 13 que admita más de una interpretación? | Sí / No | sección y elemento revisados |

Regla: Responder `No` sin evidencia equivale a `[INFERIDO]` y debe documentarse como supuesto.

Si alguna respuesta es `Sí`, la especificación es incompleta y su nivel máximo es C (ver «Determinación del Nivel»).

### Auditoría Adversarial Anti-Regresión

Responde cada pregunta con evidencia verificable. Estas preguntas están diseñadas para detectar clases de regresión que los patrones de diseño genéricos no capturan.

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿Existe algún script de arranque, entrypoint, bootstrap, migración o proceso de inicialización que invoque un binario, comando, clase, función o archivo que este cambio elimina, mueve o renombra? | Runtime | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 2 | ¿Existe algún paso posterior en la cadena de build, instalación de dependencias o generación de artefactos que dependa de un paquete, binario, archivo o estado generado en un paso anterior que este cambio elimina o modifica? | Build | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 3 | ¿Existe algún pipeline, workflow o validación automatizada que construya, ejecute o valide el artefacto modificado con un flujo, configuración o conjunto de datos distinto al que fue evaluado en esta especificación? | Pipeline | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 4 | ¿El cambio asume un comportamiento de parser, evaluador, framework, ORM, router, gestor de paquetes u otra herramienta sin verificar su documentación oficial o comportamiento empírico observable? | Semántica de Herramienta | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 5 | ¿El cambio está optimizado o validado para un solo entorno (producción, desarrollo, CI) pero no fue evaluado en los demás entornos donde el artefacto se ejecuta? | Paridad de Entornos | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 6 | ¿Existe algún mecanismo de override en runtime (variable de entorno, volumen montado, configuración inyectada, feature flag, carga dinámica) que pueda ocultar, reemplazar o anular un archivo, clase, configuración o comportamiento que este cambio da por presente o fijo? | Runtime por Override | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 7 | ¿Se aplicó algún patrón de "best practice" de la industria, convención de framework o recomendación genérica sin verificar si el proyecto local tiene una implementación, convención o contrato existente que lo contradice? | Dogmatismo Técnico | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 8 | ¿El cambio modifica, elimina o altera el comportamiento de alguna interfaz pública (endpoint, evento, schema, firma de función, contrato de respuesta) que sea consumida por otros componentes, servicios o clientes externos sin documentar la estrategia de compatibilidad? | Contract | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 9 | ¿El cambio afecta datos persistidos (esquema, columnas, índices, formatos de serialización, claves de caché) sin incluir migración, rollback y validación de integridad? | Data | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 10 | ¿El cambio introduce código muerto, dependencias obsoletas, compatibilidad especulativa sin consumidor demostrado, adaptadores o capas de compatibilidad sin excepción registrada en 6.1, o alcance más allá del MVP requerido? | Clean Architecture | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |
| 11 | ¿El cambio reemplaza un mapeo estático (constante, lista fija, switch/case, tabla de lookup) por una abstracción dinámica sin haber verificado empíricamente que cada elemento del mapeo original es cubierto sin colisiones con elementos de otras categorías? | Abstracción Incorrecta | NO / SÍ-CORREGIDO / SÍ-NO-CORREGIDO / DESCONOCIDO |  |

Estados:

- `NO`: No existe regresión. Requiere evidencia verificable.
- `SÍ-CORREGIDO`: Existe regresión, pero la especificación contiene corrección determinista documentada en FASE 0.3.
- `SÍ-NO-CORREGIDO`: Existe regresión sin solución documentada. Bloquea clasificación Nivel A.
- `DESCONOCIDO`: Falta evidencia para determinar el resultado. Equivale a `[INFERIDO]` y debe documentarse como supuesto.

Reglas:

- Si cualquier pregunta resulta `SÍ-NO-CORREGIDO` o `DESCONOCIDO`, la especificación no puede clasificarse como Nivel A ni B (ver «Determinación del Nivel»).
- La columna "Evidencia" debe contener ruta:línea del archivo inspeccionado o referencia al paso de FASE 0 que respalda la respuesta.
- Responder `NO` sin evidencia equivale a `DESCONOCIDO` y debe reclasificarse.
- Si durante la auditoría adversarial una pregunta requiere evidencia empírica que no fue recopilada en FASE 0, la especificación **debe detenerse y regresar al paso de descubrimiento correspondiente**. No se permite responder preguntas adversariales con inferencias cuando la evidencia empírica es obtenible.

## FASE 4 — Resultado Final

### Nivel de Completitud

Indica uno, derivado de la tabla «Determinación del Nivel» y citando la condición que lo fija:

- `Nivel A — Implementable`
- `Nivel B — Implementable con Supuestos Declarados`
- `Nivel C — Diseño Parcial`
- `Nivel D — Descubrimiento Requerido`

Si el nivel es C o D, añadir las preguntas concretas que el usuario debe responder para resolver cada supuesto S3/S4 y cada elemento de 0.7.

### Definición de Completitud

Una especificación se considera **técnicamente completa** únicamente cuando:

- no requiere aclaraciones técnicas adicionales;
- no requiere decisiones arquitectónicas posteriores;
- permite implementación determinista;
- permite revisión técnica independiente;
- permite validación objetiva mediante pruebas;
- obtiene `PASS` en todas las verificaciones de auditoría;
- obtiene `No` en todas las preguntas de auditoría arquitectónica;
- obtiene `NO` o `SÍ-CORREGIDO` en todas las preguntas de auditoría adversarial anti-regresión; ninguna pregunta resulta `SÍ-NO-CORREGIDO` o `DESCONOCIDO`;
- ninguna respuesta adversarial `NO` fue emitida sin evidencia verificable;
- toda afirmación negativa (ausencia de consumidores, dependencias o referencias) está respaldada por evidencia del universo inspeccionado y método de búsqueda;
- todos los archivos del perímetro de impacto fueron verificados por lectura directa;
- el grafo de dependencias acopladas fue construido con evidencia de lectura en cada arista;
- el análisis de impacto inverso no tiene regresiones sin corrección;
- la matriz de entornos de ejecución no tiene celdas vacías ni incompatibilidades sin resolver;
- si el cambio reemplaza mapeos estáticos por abstracciones dinámicas, existe una tabla de cobertura completa (FASE 0.3.1) con evidencia empírica;
- ningún supuesto S3 o S4 permanece abierto y todo supuesto S2 está en estado `Mitigado` conforme a 0.10;
- toda excepción de la sección 6.1 tiene condición de retiro verificable.

Decisiones externas al sistema técnico (prioridad de negocio, aceptación de riesgo, requisitos regulatorios no documentados, decisiones de producto, presupuesto, SLA contractual) deben documentarse como **decisiones pendientes de un responsable externo**, no como incertidumbres técnicas. La completitud técnica es independiente de la completitud organizacional.

Si cualquiera de las condiciones técnicas falla, la especificación es incompleta.
