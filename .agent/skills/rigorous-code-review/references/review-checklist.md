# Checklist de revisión por dimensión

Catálogo de comprobaciones para el paso 4 de `SKILL.md`. Las dimensiones siguen el orden de riesgo de la revisión. Aplicar solo las pertinentes al cambio; una casilla no es un hallazgo hasta que pasa la verificación del paso 6.

Cada dimensión incluye **cómo verificar**: el modo de convertir una sospecha en evidencia.

## Índice

1. [Seguridad](#1-seguridad)
2. [Integridad de datos y persistencia](#2-integridad-de-datos-y-persistencia)
3. [Corrección funcional y regresiones](#3-corrección-funcional-y-regresiones)
4. [Contratos y compatibilidad](#4-contratos-y-compatibilidad)
5. [Manejo de errores y resiliencia](#5-manejo-de-errores-y-resiliencia)
6. [Pruebas](#6-pruebas)
7. [Rendimiento](#7-rendimiento)
8. [Infraestructura y configuración](#8-infraestructura-y-configuración)
9. [Arquitectura y diseño](#9-arquitectura-y-diseño)
10. [Mantenibilidad](#10-mantenibilidad)

## 1. Seguridad

- Inyección: SQL, NoSQL, comandos de sistema, rutas de archivo (*path traversal*), plantillas, `eval`, deserialización insegura, prompt injection cuando la entrada llega a un LLM.
- Autenticación: endpoints o acciones nuevas sin verificación de identidad.
- Autorización: comprobación de que el actor puede operar sobre **ese** recurso concreto (no solo que está autenticado); referencias directas a objetos (IDOR).
- Validación y sanitización de toda entrada externa en el borde del sistema, con esquema; tipos MIME reales y no extensiones; límites de tamaño.
- Exposición de datos sensibles en respuestas, mensajes de error, trazas o logs (credenciales, tokens, datos personales).
- Sesiones y credenciales: expiración, rotación, almacenamiento seguro, comparación en tiempo constante.
- Secretos incorporados al repositorio o a artefactos de build.
- Dependencias nuevas o actualizadas con vulnerabilidades conocidas, abandonadas o innecesarias.
- Errores que revelan estructura interna (SQL, rutas, versiones).

**Cómo verificar:** trazar el dato desde su punto de entrada hasta el punto sensible (consulta, comando, respuesta, log) y listar cada validación encontrada en el camino. Si no hay ninguna, el hallazgo está demostrado. Para dependencias, usar la herramienta de auditoría del ecosistema si existe (`npm audit`, `pip-audit`…) sin modificar archivos.

## 2. Integridad de datos y persistencia

- Migraciones: reversibles, compatibles con los datos existentes, seguras con la aplicación antigua y la nueva en ejecución simultánea durante el despliegue.
- Columnas o campos nuevos obligatorios sin valor por defecto sobre tablas con datos.
- Transacciones: operaciones que deben ser atómicas y no lo son; transacciones abiertas durante llamadas externas.
- Escrituras parciales ante fallo a mitad de un proceso de varios pasos.
- Pérdida de precisión: dinero en coma flotante, truncamiento de fechas o zonas horarias, conversión de tipos.
- Borrados en cascada o actualizaciones masivas sin filtro correcto.
- Estado persistido en cada transición cuando el proceso lo exige.

**Cómo verificar:** ejecutar mentalmente o en pruebas el escenario «falla en el paso N» y describir el estado final de los datos. Comparar el esquema nuevo con los datos y consumidores actuales.

## 3. Corrección funcional y regresiones

- El cambio cumple todos los requisitos funcionales del ticket o la especificación, y nada contradice el comportamiento esperado.
- Errores lógicos: condiciones invertidas, operadores incorrectos, errores por uno (*off-by-one*), cortocircuitos erróneos, comparaciones de igualdad por referencia frente a valor.
- Casos límite: colecciones vacías, un solo elemento, máximos, cero, negativos, Unicode, zonas horarias, años bisiestos.
- Valores nulos, vacíos, inválidos o desconocidos en cada entrada y en cada resultado de llamadas externas.
- Estados imposibles representables o transiciones de estado no permitidas.
- Cálculos, validaciones y reglas de negocio correctos frente al requisito.
- Concurrencia: condiciones de carrera, *check-then-act*, estado compartido mutable, orden de operaciones asíncronas, promesas no esperadas.
- Regresiones: comportamiento anterior que cambia sin que el requisito lo pida.

**Cómo verificar:** elegir entradas concretas para cada rama modificada y seguir la ejecución. Para regresiones, comparar el comportamiento antes y después con el código de la base. Si es posible, escribir una prueba temporal fuera del repositorio o ejecutar la función en un entorno aislado, sin modificar archivos rastreados.

## 4. Contratos y compatibilidad

- Interfaces, tipos y firmas modificados: todos los implementadores y consumidores actualizados.
- Cambios incompatibles en APIs públicas, eventos, colas, formatos de archivo o esquemas compartidos.
- Contratos entre servicios y componentes: campos renombrados, eliminados u obligatorios nuevos.
- Compatibilidad con datos persistidos y mensajes en vuelo con el formato antiguo.
- Dependencias externas afectadas (clientes, SDKs, integraciones de terceros).
- Contratos activos rotos sin migración explícita.
- Funcionalidad eliminada que todavía tiene consumidores.

**Cómo verificar:** buscar cada símbolo, ruta, nombre de evento o campo modificado en todo el repositorio, incluidos pruebas, configuración, scripts y documentación. Registrar las búsquedas en el reporte. Los consumidores fuera del repositorio que no se pueden verificar se declaran como premisa.

## 5. Manejo de errores y resiliencia

- Errores capturados y descartados en silencio (`catch {}`, `except: pass`, promesas sin `catch`, resultados de error ignorados).
- Errores que se propagan con el contexto adecuado y con el tipo correcto; fallos técnicos que no se confunden con resultados de negocio.
- Timeouts en toda llamada externa; límites de espera razonables.
- Reintentos solo para errores transitorios, con backoff y límite; nunca sobre operaciones no idempotentes sin protección.
- Idempotencia de operaciones que pueden repetirse (reintentos, mensajes duplicados, doble envío).
- Recuperación ante fallos y liberación de recursos (conexiones, archivos, locks) en todos los caminos, incluidos los de error.
- Registro del mismo error en varias capas.
- Degradación controlada cuando falla un servicio dependiente.

**Cómo verificar:** para cada llamada que puede fallar en el código modificado, responder qué ocurre si lanza, devuelve error o no responde, y qué ve el llamador final.

## 6. Pruebas

- Existen pruebas para el comportamiento nuevo o modificado.
- Cubren escenarios positivos, negativos y casos límite relevantes.
- Hay una prueba de regresión para el defecto que el cambio corrige.
- Nivel adecuado: unitarias para lógica, de integración para bordes con infraestructura, contractuales para interfaces compartidas.
- Las aserciones validan el comportamiento observable y fallarían si el comportamiento fuera incorrecto.
- No reproducen la implementación (mismo cálculo en la prueba y en el código), ni verifican solo que se llamó a un mock.
- No son frágiles: sin dependencia del orden de ejecución, del reloj real, de la red, de datos aleatorios sin semilla ni de esperas fijas.
- No manipulan estado privado o global sin restaurarlo.
- Las reglas del proyecto sobre pruebas se cumplen (por ejemplo, prohibición de red real o uso de fakes de puertos).

**Cómo verificar:** para cada prueba relevante, preguntar qué cambio incorrecto en el código la haría fallar. Si ninguno razonable lo haría, la prueba no valida el comportamiento. Ejecutar las pruebas afectadas y leer la salida real.

## 7. Rendimiento

- Consultas N+1 y llamadas remotas dentro de ciclos.
- Operaciones costosas repetidas dentro de ciclos que podrían calcularse una vez.
- Complejidad temporal o espacial inadecuada para el volumen real de datos.
- Carga completa en memoria de colecciones o archivos sin límite.
- Entrada/salida redundante o síncrona en caminos calientes o en el bucle de eventos.
- Bloqueos innecesarios y contención.
- Paginación y límites de resultados en consultas y endpoints.
- Caché: invalidación correcta, claves completas, sin datos de un usuario visibles para otro.
- No exigir optimizaciones sin evidencia de volumen o de un camino caliente.

**Cómo verificar:** estimar el número de operaciones en función del tamaño de entrada realista (por ejemplo, «una consulta por fila; 10 000 filas por lote según `config/batch.ts:L5`»). Sin un volumen plausible que cause impacto, no es un hallazgo.

## 8. Infraestructura y configuración

- Variables de entorno nuevas documentadas (`.env.example` o equivalente) y validadas al arrancar.
- Configuración obsoleta o huérfana tras el cambio.
- Docker, CI/CD y manifiestos de despliegue coherentes con el cambio.
- Migraciones ejecutables en el orden del despliegue y con rollback.
- Secretos o credenciales en archivos de configuración versionados.
- Procesos persistentes (workers, colas, cron) con logs, métricas o alertas suficientes para detectar fallos.
- El cambio puede desplegarse y revertirse sin intervención manual no documentada.

**Cómo verificar:** recorrer la secuencia de despliegue (build → migración → arranque → tráfico) y la de rollback, y señalar el paso que fallaría.

## 9. Arquitectura y diseño

- Separación de responsabilidades y límites entre dominio, datos, presentación e integraciones, según la arquitectura declarada del proyecto.
- Dependencias en la dirección permitida por las reglas del proyecto (por ejemplo, el dominio no importa infraestructura).
- Responsabilidades asignadas al componente correcto.
- Acoplamiento innecesario entre módulos.
- Duplicación de lógica que ya existe en el código base (buscarla antes de reportar).
- Abstracciones, capas o configurabilidad sin un segundo caso de uso real.
- Soluciones especulativas para necesidades futuras no demostradas.
- Coherencia con los patrones existentes del proyecto cuando son pertinentes.

**Cómo verificar:** citar la regla o el patrón concreto del proyecto que el cambio viola (`AGENTS.md`, estructura de directorios, módulo equivalente existente). Una preferencia arquitectónica sin regla ni consecuencia concreta es, como mucho, P4.

## 10. Mantenibilidad

- Legibilidad: el flujo se entiende sin conocer el historial del autor.
- Complejidad innecesaria, anidamiento profundo, funciones o métodos excesivamente grandes.
- Nombres ambiguos o engañosos (un nombre que contradice el comportamiento es P2, no P3).
- Números y cadenas mágicos con significado de negocio.
- Imports sin uso, variables muertas, código inalcanzable o comentado.
- Comentarios obsoletos que contradicen el código.
- Efectos secundarios inesperados en funciones que parecen puras o de consulta.

**Cómo verificar:** confirmar que el problema no lo resuelve ya el formateador o el linter del proyecto. Proponer el cambio concreto (nombre alternativo, extracción, constante) en lugar de una crítica general.
