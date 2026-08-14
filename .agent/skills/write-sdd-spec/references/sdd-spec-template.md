# Plantilla SDD

Usa esta referencia como estructura obligatoria para producir o auditar especificaciones de implementación con Specification Driven Development.

## Reglas Globales

### Rol

Actúa como Arquitecto de Software especializado en Specification Driven Development. La salida debe permitir que un desarrollador senior, un agente de IA y un revisor técnico lleguen a soluciones sustancialmente equivalentes sin reuniones adicionales.

### Clasificación Obligatoria

Toda afirmación específica del proyecto debe etiquetarse a nivel de oración, viñeta o fila:

- `[CONFIRMADO]`: existe evidencia explícita.
- `[INFERIDO]`: existe evidencia indirecta o deducción razonable.
- `[DESCONOCIDO]`: no existe información suficiente.

Restricción crítica: está prohibido presentar información inferida como confirmada.

### Prioridad de Reglas

1. No inventar información.
2. Mantener trazabilidad.
3. Mantener determinismo.
4. Mantener consistencia interna.
5. Completar la estructura requerida.
6. Maximizar el nivel de detalle útil.

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

También evita variantes sin tilde o en minúsculas. Sustituye cada frase por comportamiento verificable o marca el dato como `[DESCONOCIDO]`.

### Evidencia

Cuando exista evidencia local, cita rutas de archivo y líneas aproximadas. Cuando la evidencia provenga del usuario, cita el dato exacto de la solicitud. Cuando no haya evidencia, usa `[DESCONOCIDO]`.

### Niveles de Completitud

- `Nivel A — Implementable`: no existen dependencias críticas desconocidas.
- `Nivel B — Implementable con Supuestos Declarados`: existen inferencias controladas y documentadas.
- `Nivel C — Diseño Parcial`: falta información crítica para implementar.
- `Nivel D — Descubrimiento Requerido`: no existe información suficiente para producir una especificación útil.

## FASE 0 — Descubrimiento Empírico Obligatorio

Antes de generar la especificación, ejecutar el protocolo de descubrimiento secuencial completo. No asumas tablas, columnas, endpoints, eventos, colas, archivos, contratos, dependencias, servicios ni procesos internos. Toda afirmación debe tener evidencia con ruta absoluta y número(s) de línea.

### 0.1 Perímetro de Impacto

Listar todos los archivos directamente afectados por el cambio. Para cada archivo, abrir y leer su contenido completo (no inferir del nombre). Registrar:

| Archivo | Ruta | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- |
|  |  |  |  | Sí / No |

Regla: Ningún archivo puede aparecer en este inventario sin haber sido abierto y leído. Si la columna "Verificado por Lectura" contiene un "No", la especificación no puede avanzar.

### 0.2 Grafo de Dependencias Acopladas

Para cada archivo del Perímetro de Impacto, identificar todos los artefactos que lo consumen, invocan, importan, incluyen, referencian o dependen de su existencia. Incluir: scripts de arranque, entrypoints, bootstraps, workflows de CI/CD, configuración de runtime, tests, y otros archivos del mismo dominio.

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) de Acoplamiento | Naturaleza |
| --- | --- | --- | --- | --- |
|  |  |  |  | require / include / COPY / invocación / referencia / consumo |

Regla: Cada arista del grafo debe tener evidencia de lectura directa del archivo dependiente. No se permite declarar "sin dependencias" sin haber buscado activamente en el repositorio.

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

### 0.4 Verificación de Semántica de Herramientas

Para cada herramienta, parser o evaluador cuyo comportamiento el cambio dependa, verificar que el cambio respeta sus reglas de evaluación.

| Herramienta | Regla Relevante | Evidencia Documental | Cambio Compatible |
| --- | --- | --- | --- |
|  |  | URL oficial o comportamiento empírico observado | Sí / No + justificación |

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

Para cada cambio propuesto, verificar compatibilidad en todos los entornos donde el artefacto se ejecuta.

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local |  |  | Sí / No |  |
| CI (GitHub Actions) |  |  | Sí / No |  |
| Producción |  |  | Sí / No |  |
| Testing aislado |  |  | Sí / No |  |

Regla: No se permite dejar celdas vacías. Toda celda "No" genera una regresión que debe aparecer en la tabla 0.3.

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
|  | Confirmado / Inferido / Desconocido |  |

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

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
|  |  |  |  |

Reglas:

- Todo supuesto debe documentarse.
- Ningún supuesto puede mezclarse con información confirmada.
- Todo supuesto debe aparecer en la auditoría final.

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

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
|  | librería / servicio / API / base de datos / cola / infraestructura / tercero |  |  |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
|  |  |  |

Toda modificación debe preservar los invariantes existentes o reemplazarlos explícitamente.

### 9. Modelo de Datos

Si existe impacto en persistencia, documenta:

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

Si no existe impacto confirmado en persistencia, declara `[CONFIRMADO] Sin impacto en persistencia` solo con evidencia verificable. Si no hay evidencia, declara `[DESCONOCIDO]`.

#### DDL

Incluye SQL completo. No resumas.

#### Orden de Ejecución

Secuencia exacta.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
|  |  |  |  |

#### Rollback

Incluye SQL completo.

### 10. Contratos

Para cada API, evento, cola, batch, archivo, integración o mensaje interno, documenta:

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

- ruta completa;
- clases afectadas;
- funciones afectadas;
- métodos afectados;
- líneas aproximadas;
- fragmentos antes/después.

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

## FASE 2 — Auditoría de Consistencia

Valida cada fila:

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas están definidas | PASS / FAIL |  |
| Todas las columnas existen | PASS / FAIL |  |
| Todos los contratos documentados | PASS / FAIL |  |
| Todos los requisitos tienen trazabilidad | PASS / FAIL |  |
| Todos los consumidores analizados | PASS / FAIL |  |
| Todas las migraciones tienen rollback | PASS / FAIL |  |
| Todas las referencias están definidas | PASS / FAIL |  |
| Toda compatibilidad tiene evidencia | PASS / FAIL |  |
| Todos los criterios son verificables | PASS / FAIL |  |

Si existe cualquier `FAIL`, la especificación es incompleta.

## FASE 3 — Auditoría Arquitectónica

Responde cada pregunta:

| Pregunta | Resultado |
| --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | Sí / No |
| ¿Existe algún contrato sin documentar? | Sí / No |
| ¿Existe algún consumidor no analizado? | Sí / No |
| ¿Existe alguna migración sin rollback? | Sí / No |
| ¿Existe algún dato persistido sin migración? | Sí / No |
| ¿Existe alguna afirmación sin evidencia? | Sí / No |
| ¿Existen referencias huérfanas? | Sí / No |
| ¿Dos implementadores producirían soluciones diferentes? | Sí / No |

Si alguna respuesta es `Sí`, la especificación es incompleta.

### Auditoría Adversarial Anti-Regresión

Responde cada pregunta con evidencia verificable. Estas preguntas están diseñadas para detectar clases de regresión que los patrones de diseño genéricos no capturan.

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿Existe algún script de arranque, entrypoint, bootstrap, migración o proceso de inicialización que invoque un binario, comando, clase, función o archivo que este cambio elimina, mueve o renombra? | Runtime | Sí / No |  |
| 2 | ¿Existe algún paso posterior en la cadena de build, instalación de dependencias o generación de artefactos que dependa de un paquete, binario, archivo o estado generado en un paso anterior que este cambio elimina o modifica? | Build | Sí / No |  |
| 3 | ¿Existe algún pipeline, workflow o validación automatizada que construya, ejecute o valide el artefacto modificado con un flujo, configuración o conjunto de datos distinto al que fue evaluado en esta especificación? | Pipeline | Sí / No |  |
| 4 | ¿El cambio asume un comportamiento de parser, evaluador, framework, ORM, router, gestor de paquetes u otra herramienta sin verificar su documentación oficial o comportamiento empírico observable? | Semántica de Herramienta | Sí / No |  |
| 5 | ¿El cambio está optimizado o validado para un solo entorno (producción, desarrollo, CI) pero no fue evaluado en los demás entornos donde el artefacto se ejecuta? | Paridad de Entornos | Sí / No |  |
| 6 | ¿Existe algún mecanismo de override en runtime (variable de entorno, volumen montado, configuración inyectada, feature flag, carga dinámica) que pueda ocultar, reemplazar o anular un archivo, clase, configuración o comportamiento que este cambio da por presente o fijo? | Runtime por Override | Sí / No |  |
| 7 | ¿Se aplicó algún patrón de "best practice" de la industria, convención de framework o recomendación genérica sin verificar si el proyecto local tiene una implementación, convención o contrato existente que lo contradice? | Dogmatismo Técnico | Sí / No |  |
| 8 | ¿El cambio modifica, elimina o altera el comportamiento de alguna interfaz pública (endpoint, evento, schema, firma de función, contrato de respuesta) que sea consumida por otros componentes, servicios o clientes externos sin documentar la estrategia de compatibilidad? | Contract | Sí / No |  |
| 9 | ¿El cambio afecta datos persistidos (esquema, columnas, índices, formatos de serialización, claves de caché) sin incluir migración, rollback y validación de integridad? | Data | Sí / No |  |
| 10 | ¿El cambio introduce código muerto, dependencias obsoletas, adaptadores legacy, capas de compatibilidad retroactiva o alcance más allá del MVP requerido? | Clean Architecture | Sí / No |  |

Reglas:

- Si cualquier pregunta se responde con `Sí` y no tiene corrección documentada en FASE 0.3, la especificación no puede clasificarse como Nivel A.
- La columna "Evidencia" debe contener ruta:línea del archivo inspeccionado o referencia al paso de FASE 0 que respalda la respuesta.
- Responder "No" sin evidencia equivale a `[INFERIDO]` y debe documentarse como supuesto.

## FASE 4 — Resultado Final

### Nivel de Completitud

Indica uno:

- `Nivel A — Implementable`
- `Nivel B — Implementable con Supuestos Declarados`
- `Nivel C — Diseño Parcial`
- `Nivel D — Descubrimiento Requerido`

### Definición de Completitud

Una especificación se considera completa únicamente cuando:

- no requiere reuniones adicionales;
- no requiere aclaraciones adicionales;
- no requiere decisiones arquitectónicas posteriores;
- permite implementación determinística;
- permite revisión técnica independiente;
- permite validación objetiva mediante pruebas;
- obtiene `PASS` en todas las verificaciones de auditoría;
- obtiene `No` en todas las preguntas de auditoría arquitectónica;
- obtiene `No` en todas las preguntas de auditoría adversarial anti-regresión, o tiene corrección documentada para cada `Sí`;
- todos los archivos del perímetro de impacto fueron verificados por lectura directa;
- el grafo de dependencias acopladas fue construido con evidencia de lectura en cada arista;
- el análisis de impacto inverso no tiene regresiones sin corrección;
- la matriz de entornos de ejecución no tiene celdas vacías ni incompatibilidades sin resolver.

Si cualquiera de estas condiciones falla, la especificación es incompleta.
