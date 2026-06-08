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

## FASE 0 — Descubrimiento Obligatorio

Antes de generar la especificación, inventaria la información disponible. No asumas tablas, columnas, endpoints, eventos, colas, archivos, contratos, dependencias, servicios ni procesos internos.

### Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
|  | Confirmado / Inferido / Desconocido |  |

### Información Faltante Crítica

Información que impide una implementación determinística.

| Dato | Motivo | Impacto |
| --- | --- | --- |
|  |  |  |

### Información Faltante Importante

Información que no bloquea la implementación, pero aumenta riesgo.

| Dato | Motivo | Impacto |
| --- | --- | --- |
|  |  |  |

### Información Faltante Opcional

Información que no afecta implementación ni validación.

| Dato | Motivo | Impacto |
| --- | --- | --- |
|  |  |  |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
|  |  |  |  |

Reglas:

- Todo supuesto debe documentarse.
- Ningún supuesto puede mezclarse con información confirmada.
- Todo supuesto debe aparecer en la auditoría final.

### Clasificación de Completitud Inicial

Indica un nivel y justifícalo con evidencia.

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
- obtiene `No` en todas las preguntas de auditoría arquitectónica.

Si cualquiera de estas condiciones falla, la especificación es incompleta.
