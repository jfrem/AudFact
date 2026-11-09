# Dictamen arquitectónico: necesidad del Circuit Breaker de Gemini en AudFact

**Fecha:** 2026-11-09. **Recomendación B: retirar el Circuit Breaker de la ruta durable v2.**  
**Decisión de arquitectura: APROBAR el retiro acotado; RECHAZAR su reconstrucción como requisito de este cambio.**

El Circuit Breaker actual no es indispensable para ejecutar auditorías, preservar su evidencia ni controlar cuotas. Su integración agrega un modo de fallo demostrado: transforma una decisión local de no enviar HTTP en intentos agotados y cierre técnico con DLQ. Retirarlo de `executeSingleOperation()` elimina esa causa, conserva los controles propios de v2 y reduce el estado distribuido que debe mantenerse consistente.

**La recomendación no equivale a afirmar que cuota y reintentos cubren una caída prolongada.** Un Circuit Breaker correcto aporta una capacidad distinta: suspender llamadas colectivamente a partir de la salud del proveedor. Sin él, AudFact puede agotar tres intentos reales de una operación y cerrar la auditoría. Ese riesgo debe atenderse con el procedimiento de pausa/recuperación existente y una decisión explícita sobre el tiempo máximo de espera del negocio. Si se exige recuperación automática sin cierre de auditorías durante interrupciones largas, se necesita una política adicional; el retiro por sí solo no satisface ese requisito.

## 1. Alcance y supuestos

### Evidencia examinada

- Repositorio: `C:/Users/USER/Desktop/AudFact`.
- HEAD: `7e808dbd569a0ed5deca978283d9ff0cb4c8c1f2`.
- Cambio analizado: `5a3205090373eac223a5083e4089cb4cddbc8b6a`, comparado con su padre.
- Componentes: gateway, presupuesto, runner, cola, workers de extracción/semántica, pausa operativa, recuperación DLQ y pruebas asociadas.
- Alcance de esta entrega: informe y registro documental. No se implementó el retiro, no se cambió configuración ni se accedió a producción.

| Afirmación de los antecedentes | Resultado de la verificación |
|---|---|
| El commit activó el CB en la operación durable | Confirmado por `git show 5a320509 -- app/Services/Audit/GeminiGateway.php`: agrega `cbCheck()` y dos llamadas a `cbRecordFailure()`. |
| Antes de ese commit v2 omitía el CB | Confirmado para `executeTaskRequest() → executeSingleOperation()` en el padre del commit. No implica que el CB estuviera inactivo en todas las rutas del gateway. |
| v2 no registra éxitos del CB | Confirmado: `cbRecordSuccess()` se invoca únicamente desde `executePost()`, en `app/Services/Audit/GeminiGateway.php:406`. |
| Hubo más de 110 auditorías en DLQ en seis segundos por ráfagas 503 | Antecedente del usuario, compatible con el defecto estático. No se verificaron timestamps, causas remotas, cantidad ni SHA desplegado. El 503 local del CB no prueba un HTTP 503 de Google. |
| Hubo meses y cientos de miles de auditorías sin CB | No verificado. El historial local de `executeSingleOperation` muestra commits de 2026-10-16 y 2026-11-08; no certifica meses ni volumen productivo. La recomendación no depende de aceptar esa cifra. |
| El CB requiere Lua desde cero porque todo es no atómico | Matiz: `incrWithExpire()` ya usa Lua para INCR+EXPIRE (`core/RedisClient.php:308`). Lo que falta es atomicidad del protocolo completo de admisión/transición/sondeo, no del incremento aislado. |

La auditoría previa se conserva como antecedente: [auditoría independiente del incidente](auditoria-independiente-circuit-breaker-dlq-2026-11-09.md). Este dictamen **reemplaza su recomendación de conservar y endurecer el CB para la ruta v2**, porque evalúa primero si esa responsabilidad justifica su costo. Sus hallazgos causales siguen vigentes.

### Referencias principales

Los números corresponden al HEAD anterior; las referencias `ruta:línea` del informe son verificables sobre esa revisión.

| Ref. | Evidencia estática | Implicación |
|---|---|---|
| E1 | [GeminiGateway.php:132](C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiGateway.php:132), `:173`, `:195` | Con tarea se utiliza el flujo durable; sin tarea existe otra ruta con CB. |
| E2 | [GeminiGateway.php:207](C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiGateway.php:207), `:220`, `:238`, `:298` | Primero reutiliza respuesta, después reserva cuota y finalmente consulta CB; el catch finaliza la reserva como fallo. |
| E3 | [GeminiGateway.php:324](C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiGateway.php:324), `:347`, `:406`, `:493`, `:518`, `:528` | Registro de fallos, éxito solo síncrono, rechazo RuntimeException 503 y umbral/cooldown. |
| E4 | [GeminiQuotaBudget.php:180](C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/GeminiQuotaBudget.php:180), `:219`, `:226`, `:233`, `:238` | Admisión Lua, cuota/concurrencia, cargos y cierre idempotente del intento. |
| E5 | [AuditTaskRunner.php:62](C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditTaskRunner.php:62), `:65`, `:72`, `:84`, `:90` | Diferencia deferral de cuota y fallo; backoff con jitter y Retry-After; agotamiento produce failed y DLQ. |
| E6 | [FairAuditTaskQueue.php:132](C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/FairAuditTaskQueue.php:132), `:284`, `:309` | Deferral libera lease/slot; incrementa intentos solo con attempted=true; reactivación durable. |
| E7 | [DocumentExtractionWorker.php:203](C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionWorker.php:203), [SemanticEvaluationWorker.php:93](C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/SemanticEvaluationWorker.php:93) | Ambos consumidores productivos revisados crean gateway con `withTask()`. |
| E8 | [audit-execution-maintenance.php:27](C:/Users/USER/Desktop/AudFact/bin/audit-execution-maintenance.php:27), [AuditTaskRunner.php:30](C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditTaskRunner.php:30) | Ya existen pause/observe/resume y bloqueo de nuevos claims. |
| E9 | [AuditDlqController.php:120](C:/Users/USER/Desktop/AudFact/app/Controllers/AuditDlqController.php:120), [FairAuditTaskQueue.php:219](C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/FairAuditTaskQueue.php:219) | Reproceso mediante nueva raíz y métrica de edad de espera. |

## 2. Dominio y método

Se aplicó `audit-skill-router → architecture-assessment` para límites de responsabilidad, errores, estado y escalabilidad; `clean-rebuild-policy` para decidir la estrategia; `audfact-audit-gemini` y `audfact-project-overview` para contratos; `find-docs` para documentación actual de Gemini; y `audfact-docs-sync` para cierre documental.

La puntuación cubre exclusivamente arquitectura del flujo revisado. Seguridad global, calidad de todo el repositorio y gobernanza organizacional quedan fuera de este dictamen.

Se distinguieron hechos del código, resultados de pruebas ejecutadas, antecedentes reportados e inferencias de diseño. Context7 resolvió la documentación oficial `/websites/ai_google_dev_gemini-api` y recuperó su estrategia de reintentos. Google recomienda backoff, jitter y un límite de reintentos para errores transitorios. Esto respalda esos mecanismos, pero no establece que los tiempos actuales de AudFact satisfagan su necesidad de negocio. [Guía oficial de resolución de problemas](https://ai.google.dev/gemini-api/docs/troubleshooting).

## 3. Hallazgos y evaluación de alternativas

### 3.1 Hallazgos verificables

Se conservan los identificadores de la auditoría previa. Las severidades describen el código actual o, donde se indica, el riesgo de una solución propuesta.

| ID | Dominio | Severidad | Componente | Evidencia | Comando de evidencia | Riesgo técnico | Impacto de negocio | Prioridad | Recomendación | Tipo | Responsable propuesto | Plazo |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| ARCH-001 | Arquitectura | Crítica | CB → runner | `app/Services/Audit/GeminiGateway.php:511`; `app/Services/Audit/Pipeline/AuditTaskRunner.php:72`, `:90` | `rg -n -e RuntimeException -e task_operation_retry -e TYPE_DEAD_LETTER app/Services/Audit/GeminiGateway.php app/Services/Audit/Pipeline/AuditTaskRunner.php` | Rechazo sin HTTP consume intentos y cierra la raíz. | Interrupción evitable de auditorías válidas; reproceso manual. | P0 | Retirar intervención CB en v2. | Incremental | Backend + QA | Antes del siguiente lote grande |
| ARCH-002 | Arquitectura | Alta | Contador y recuperación | `app/Services/Audit/GeminiGateway.php:347`, `:406`, `:537`; `core/RedisClient.php:315` | `rg -n -e cbRecordSuccess -e cbRecordFailure -e CB_STATE app/Services/Audit/GeminiGateway.php` | Éxitos v2 no limpian fallos; no hay half-open; recuperación no controlada. | Corte colectivo por fallos dispersos y posibles reaperturas. | P1 | Eliminar dependencia en v2; reconstruir solo con necesidad demostrada. | Incremental | Backend | Mismo cambio |
| ARCH-003 | Arquitectura | Alta | CB y presupuesto | `app/Services/Audit/GeminiGateway.php:220`, `:298`; `app/Services/Audit/Pipeline/GeminiQuotaBudget.php:226`, `:257` | `rg -n -e 'quota->reserve' -e cbCheck -e _http_attempts -e ZREM app/Services/Audit/GeminiGateway.php app/Services/Audit/Pipeline/GeminiQuotaBudget.php` | Rechazos locales cargan RPM/RPD/TPM reservado y métricas HTTP. | Capacidad disponible infrautilizada y diagnóstico de costos/errores distorsionado. | P1 | Retiro evita esta fuente de cargos artificiales. | Incremental | Backend | Mismo cambio |
| ARCH-005 | Arquitectura | Alta | Contrato de pruebas | `tests/Services/Audit/GeminiGatewayTest.php:306`; `tests/Integration/GeminiTaskExecutionRedisTest.php:165` | `rg -n -e testOpenCircuit -e testRetryExhaustion -e withTask tests/Services/Audit/GeminiGatewayTest.php tests/Integration/GeminiTaskExecutionRedisTest.php` | Tests unitarios aceptan la excepción defectuosa; no cubren composición CB abierto + cuota + runner. | Regresión de disponibilidad compatible con CI verde. | P1 | Sustituir contratos CB v2 por pruebas integradas de retiro. | Incremental | Backend + QA | Antes del despliegue |
| ARCH-004 | Arquitectura | Media | Deferral prolongado propuesto | `app/Services/Audit/Pipeline/FairAuditTaskQueue.php:290`, `:315`; `app/Services/Audit/Pipeline/AuditTaskRunner.php:62` | `rg -n -e due_at_ms -e recovery_count -e QuotaDeferredException app/Services/Audit/Pipeline/FairAuditTaskQueue.php app/Services/Audit/Pipeline/AuditTaskRunner.php` | Cambiar solo a deferral sin límite de edad puede dejar espera indefinida. Es riesgo de la alternativa, no el comportamiento actual del CB. | Lote retenido sin compromiso de resolución. | P1 | Definir alerta, plazo e intervención antes de añadir suspensión automática. | Estructural, condicional | Producto + Operaciones | Diseño de continuidad |

### 3.2 Necesidad: qué aporta realmente un Circuit Breaker

| Mecanismo | Pregunta que responde | Lo que no resuelve |
|---|---|---|
| `GeminiQuotaBudget` | ¿Cabe otra operación en el presupuesto configurado y la concurrencia disponible? | No observa tasa de 503, no adapta límites por salud y no garantiza que Google tenga capacidad. |
| Runner + backoff/Retry-After | ¿Cuándo reintentar esta operación fallida y cuándo agotar su presupuesto? | No suspende colectivamente otras tareas nuevas ante una caída común. |
| Cola durable y equidad | ¿Cómo conservar, ordenar, aplazar y reclamar trabajo sin monopolizar slots? | No decide por sí misma si Gemini está sano ni aumenta el horizonte de reintentos. |
| Circuit Breaker correcto | ¿Conviene detener temporalmente nuevas llamadas por una señal compartida de indisponibilidad? | No crea capacidad en Google, no conserva datos por sí solo y no define la resolución de una espera prolongada. |

Por tanto, **no son mecanismos equivalentes ni existe redundancia total**. El beneficio diferencial del CB es reducir llamadas probablemente inútiles y ocupación de workers durante fallas correlacionadas. Puede ser útil en sistemas asíncronos; la asincronía no lo vuelve automáticamente innecesario.

En AudFact, ese beneficio es incremental: los workers están limitados, la cola libera slots al diferir y ya existen timeouts y presupuestos. El transporte durable limita conexión a 10 segundos, conteo a 20 segundos y generación al menor valor entre `GEMINI_TIMEOUT` y el tiempo restante del turno (`app/Services/Audit/GeminiGateway.php:307`). No hay espera HTTP ilimitada, aunque una dependencia lenta puede ocupar todos los workers disponibles.

La documentación de Google advierte que la capacidad efectiva puede variar y que las cuotas publicadas no la garantizan. Tener margen local no excluye 429/503: también puede haber consumo externo al presupuesto, desajustes de configuración o límites del proveedor que el modelo local no represente. [Límites oficiales de Gemini](https://ai.google.dev/gemini-api/docs/rate-limits?hl=en).

Microsoft distingue Circuit Breaker de retry y señala que, en arquitecturas con mensajes, aislamiento y reintentos pueden bastar. Es una consideración contextual, no una prohibición del patrón. La decisión aquí se sustenta en el flujo de AudFact y su falla demostrada. [Patrón Circuit Breaker](https://learn.microsoft.com/en-us/azure/architecture/patterns/circuit-breaker).

### 3.3 Comportamiento con y sin circuito

| Escenario | CB actual | Sin CB en v2 | CB reconstruido correctamente |
|---|---|---|---|
| Cuota local agotada | Reserve devuelve DEFERRED antes de consultar CB. | Mismo aplazamiento sin intentos. | Mismo contrato de cuota. |
| 503 aislados entre éxitos | Acumula fallos sin reset de éxitos v2; puede bloquear tareas que habrían tenido éxito. | Cada operación aplica su retry; otras tareas elegibles continúan. | Ventana/umbral bien definidos deben tolerar ruido y evitar aperturas injustificadas. |
| Caída total con errores rápidos | Reduce HTTP después de abrir, pero continúa consumiendo reintentos locales y genera DLQ durante el cooldown. | Continúa enviando dentro de límites; tres fallos de una misma operación pueden producir DLQ. | Suspende nuevas operaciones y conserva tareas mediante deferral; prueba recuperación con pocos sondeos. |
| 429 externo con cuota local disponible | Cuenta el error y puede abrir para el carril. Luego el rechazo local pierde la señal tipada Retry-After. | Runner respeta Retry-After de la operación real; otras tareas siguen intentándolo. | Puede compartir cooldown si el alcance del límite está correctamente identificado. |
| Respuestas lentas/timeouts | Puede abrir después de que lleguen los fallos; no cancela solicitudes ya enviadas. | Concurrencia y timeout acotan ocupación, pero puede reducirse mucho el rendimiento. | Evita nuevas llamadas tras detectar la caída; tampoco rescata las ya enviadas. |
| Recuperación del proveedor | Expira open sin half-open; varios workers pueden reintentar juntos y el contador puede seguir vivo. | Tareas pendientes/diferidas vuelven según scheduler/cuota; las raíces ya failed necesitan reproceso. | Sondeos con lease y recuperación gradual, sin estampida. |
| Respuesta durable ya guardada | Se reutiliza antes de reserve/CB. | Se conserva ese beneficio. | Debe seguir reutilizándose incluso con circuito abierto. |

**Ventana de agotamiento:** en `AuditTaskRunner.php:72` hay un primer intento y dos reintentos de la misma operación, con esperas de 1 y 5 segundos, más jitter determinista de 0 a 250 ms en cada espera. Si no existe Retry-After mayor y los fallos son inmediatos, el componente de espera suma **6 a 6,5 segundos**. A esto se añaden procesamiento, HTTP, contención y programación de la cola. No es un límite exacto de duración del incidente.

Un circuito abierto genera `RuntimeException`, no `GeminiOperationException`: su TTL textual no participa en `retryAfterMs`. Así, un cooldown de 60 segundos puede coexistir con agotamiento de intentos en una fracción de ese intervalo. El retiro corrige esa incompatibilidad, pero tres 503 remotos rápidos también pueden agotar el presupuesto.

El límite es por operación; una auditoría puede incluir conteo, generación y múltiples documentos. No debe presentarse como “tres llamadas máximas por factura”. Tampoco basta cambiar `AUDIT_EVENT_MAX_RETRIES`: el runner durable contiene este límite directamente.

### 3.4 Opción 1: reconstruir/endurecer el CB

No sería suficiente agregar `cbRecordSuccess()` ni elevar el umbral. Una versión correcta exigiría:

| Responsabilidad adicional | Trabajo necesario | Riesgo de integración |
|---|---|---|
| Clasificación de señales | Separar fallos de red/5xx, throttling, credenciales y errores del documento; decidir alcance por proyecto/modelo/operación/carril. | Un fallo particular bloquea trabajo sano si se agrega al dominio equivocado. Hoy cualquier HTTP no exitoso salvo 400 puede incrementar el contador. |
| Máquina de estados | Closed/open/half-open, contador o tasa en ventana, mínimo de muestra, cooldown y política de éxitos. | Oscilación, apertura por ruido y parámetros sin sustento en tráfico real. |
| Atomicidad distribuida | Transiciones Lua, tiempo Redis, leases de sondeo y generación/fence para ignorar éxitos tardíos. | Reset de un circuito nuevo por una respuesta antigua; varios sondeos simultáneos. |
| Admisión y contabilidad | Coordinar elegibilidad y reserva; rechazo previo al envío sin cargo; distinguir reserva cancelada de resultado incierto. | Mover `cbCheck()` antes de `reserve()` deja una carrera entre chequeo y reserva. Redis y HTTP no forman una transacción atómica. |
| Integración con runner | Excepción/resultado de admisión que difiera sin intentos, libere recursos y preserve artefactos. | Encapsular otra RuntimeException reproduce ARCH-001; cambiar solo el tipo conserva ARCH-003. |
| Recuperación y límites | PTTL/tiempo coherente, jitter, sondeos limitados, edad máxima observable e intervención. | Bucle inmediato por TTL anómalo o tarea aplazada indefinidamente. |
| Pruebas y operación | Redis real aislado, concurrencia, caída de workers, carreras, métricas y runbook. | La complejidad vive en la composición, no se prueba con un método aislado. |

El incremento con expiración ya es atómico, pero el check, el registro del fallo y el cambio de estado son operaciones separadas. Además, los éxitos sin tarea borran estado y contador sin verificar a qué generación pertenecen (`GeminiGateway.php:524`). Reconstruir debe resolver ambos problemas.

No se puede garantizar “cero llamadas después de abrir” cancelando retroactivamente operaciones admitidas. El contrato debe definir cuáles pueden continuar, cómo quedan registradas y cómo se conserva el resultado desconocido después de un timeout o caída del proceso.

**Costo relativo: alto.** Introduce otra máquina de estados coordinada con tarea, lease, presupuesto y resultado durable; añade calibración y responsabilidades operativas permanentes. No se estiman días/persona ni ahorro monetario porque faltan mediciones y capacidad del equipo. Su retorno sería significativo si se demostraran caídas recurrentes, ocupación costosa de workers o una obligación de recuperación automática que los controles actuales no satisfagan.

### 3.5 Opción 2: retiro limpio de la intervención en v2

El cambio acotado consiste en retirar de `executeSingleOperation()`:

1. `cbCheck()` de la línea 298.
2. `cbRecordFailure(0)` del catch de red en la línea 324.
3. El bloque de `cbRecordFailure($code)` de las líneas 345–348.

Se preservan validación del grant/deadline, transporte, clasificación documental, `GeminiOperationException`, Retry-After, artefactos, `finish()`, cuotas, reintentos, colas y outbox. El resultado vuelve al comportamiento de admisión previo a `5a320509` en esta ruta.

Esto elimina el rechazo local del CB y sus cargos artificiales asociados. **No demuestra que toda reserva equivalga siempre a HTTP enviado**: siguen existiendo ventanas de caída del proceso y validaciones locales del grant, que deben conservar su contabilidad conservadora. No se propone reembolsar resultados inciertos.

`FINISH` normalmente libera inflight con ZREM (`GeminiQuotaBudget.php:257`); la falla observada no es una fuga normal y permanente de slots. Los cargos de minuto/día y la reserva de tokens permanecen. Es distorsión de presupuesto local, no prueba de que Google haya facturado una llamada que nunca recibió.

| Riesgo residual tras el retiro | Naturaleza | Tratamiento proporcional |
|---|---|---|
| Continuar enviando a Gemini durante una caída común | Real por diseño; magnitud productiva sin medir. | Límites, timeouts, alertas y pausa operativa; medir solicitudes fallidas y ocupación. |
| Agotar retries de operaciones reales y aumentar DLQ | Real y explícito en runner; ya existía antes del CB v2. | Pausa temprana y reproceso; acordar horizonte de espera si el negocio exige más continuidad. |
| 429 por consumo externo o discrepancia de cuota | Posible y técnicamente plausible; no demostrado en este incidente. | Verificar presupuesto con AI Studio y consumidores del mismo proyecto; respetar Retry-After. |
| Ráfaga al recuperarse | Posible; limitada por scheduler/cuota, sin garantía de suavizado suficiente. | Reanudar de forma controlada y observar tasa/latencia; mantener jitter. |
| Pérdida de documentos por quitar el CB | No se identifica mecanismo causal en el cambio propuesto. | La durabilidad depende de Redis, fuente de documentos, hashes, outbox y persistencia; validarlos. |
| Precisión clínica degradada | No cambia el modelo, prompt ni reglas. | Preservar fallo técnico separado de conclusión documental; nunca fabricar resultados para “completar” el lote. |

No es correcto revertir íntegramente `5a320509`: contiene también cambios de rasterización y pruebas ajenos al retiro. Tampoco procede borrar toda la clase o todas las variables CB sin revisar consumidores: `sendWithStructuredOutput()` y `sendPrebuiltBody()` conservan ramas sin tarea que llaman al circuito (`GeminiGateway.php:135`, `:176`, `:390`).

La búsqueda estática identifica la ruta durable de los dos workers, pero no demuestra inexistencia de todo consumidor directo o externo. La compatibilidad sin tarea se preserva en este cambio; su eliminación completa requiere inventario de consumidores y decisión separada. Esa preservación no valida el diseño del CB para dichas rutas.

### 3.6 Alternativas más simples

**Primera alternativa: usar la pausa operativa ya implementada.** El CLI `bin/audit-execution-maintenance.php` implementa:

- `pause`: activa pausa de admisión y ejecución.
- `observe`: mantiene admisión pausada pero habilita ejecución para drenar.
- `resume`: valida protocolo, presupuesto, heartbeat y capacidad antes de retirar pausas.

Estos comandos son evidencia del mecanismo existente, no acciones ejecutadas en esta revisión. El runner verifica la pausa antes de reclamar (`AuditTaskRunner.php:30`). Mantenimiento conserva recuperación de reservas y vaciado de outbox antes de evaluar la pausa (`AuditExecutionMaintenance.php:127`).

Limitaciones: pausa global, no exclusivamente Gemini; las operaciones ya iniciadas pueden finalizar o fallar; otras etapas pueden verse detenidas y aumenta la edad del lote. `observe` vuelve a permitir HTTP: no es el modo apropiado para mantener suspendida una dependencia caída. Un operador no garantiza reaccionar antes de seis segundos; esta opción mitiga el incidente y permite control, pero no garantiza cero DLQ ante fallas rápidas.

Un supervisor que reinicia procesos por el mismo 503 no repara al proveedor y puede añadir recuperaciones de lease. Automatizar pausa/reanudación exigiría señales, histéresis y pruebas; tampoco es un mecanismo gratuito.

**Segunda alternativa, únicamente si se valida necesidad: cooldown compartido dentro de la admisión.** Puede implementarse un “no admitir hasta” por alcance del proveedor dentro del mismo script que decide cuota. Al estar activo devolvería `DEFERRED` antes de generar cargos. Se reutilizan la excepción tipada y la cola; se registran motivo, vencimiento, edad y responsable de intervención.

Esta capacidad **no existe hoy**: los límites se capturan en el constructor y deben ser positivos (`GeminiQuotaBudget.php:26`, `:38`). Cambiar una variable a cero no implementa una pausa válida, y modificar el entorno no actualiza automáticamente todos los objetos de workers persistentes.

Un cooldown fijo con Retry-After compartido es más pequeño que un CB completo, pero no detecta por sí solo todas las caídas, ni controla sondeos o la recuperación gradual. Si se agregan tasa de errores, estados, probes y cierre por éxitos, se habrá reconstruido esencialmente un Circuit Breaker bajo otro nombre. No se recomienda incorporarlo preventivamente en el retiro.

## 4. Puntuación del dominio

La puntuación describe el estado inspeccionado, **no una implementación futura ni todo AudFact**. Se identificaron primero las tres brechas materiales de la sección 8.

| Control arquitectónico | Valor | Traza y evidencia |
|---|---:|---|
| Límites y responsabilidades claras | 1 | pass: gateway, cuota, runner y cola distinguibles (E1–E6). |
| Dirección de dependencias/acoplamiento | 0,5 | partial: coordinación de admisión repartida entre gateway/CB/presupuesto. |
| Ausencia de ciclos o violaciones críticas de dependencia en la cadena | 1 | pass: no se identifica ciclo estructural en los componentes revisados. |
| Separación dominio/infraestructura | 0,5 | partial: reglas documentales separadas, pero gateway mezcla transporte y estado durable. |
| Límites de error y aislamiento | 0 | fail: ARCH-001 transforma rechazo local en fallo terminal. |
| Estado sin efectos globales ocultos | 0 | fail: ARCH-002/003 afectan otras tareas y la cuota común. |
| Extensibilidad para una mejora realista | 0,5 | partial: existen deferral y pausa, pero una suspensión automática requiere coordinar contratos. |
| Estrategia de escalabilidad | 1 | pass: presupuesto, concurrencia, slots y delayed explícitos. |

Bruta: `round(4.5 / 8 * 5, 1) = 2.8`. ARCH-001 activa el techo de 2,0 por riesgo crítico de disponibilidad en la ruta productiva.

| Dominio | Bruta / 5 | Peso | Ponderada tras techo | Techo aplicado | Fundamento | Confianza |
|---|---:|---:|---:|---|---|---|
| Arquitectura del flujo Gemini v2 | 2,8 | 100% | 2,00 | Sí | 3 pass, 3 partial, 2 fail; ARCH-001 sin mitigar. | Media |

## 5. Resultado global y alineación con negocio

**Puntuación global del alcance: 2,0/5. Clasificación: C. Regla de techo aplicada: sí. Confianza global: media.** La cadena causal está respaldada por código; no se extrapola a toda la operación productiva.

| Necesidad de negocio | Aporte del CB actual | Mecanismo determinante |
|---|---|---|
| Precisión de la auditoría médica | No añade precisión a extracción ni evaluación. | Contratos de extracción, evidencia y reglas de dominio. |
| Conservación de información clínica | No ofrece garantía de durabilidad; la DLQ tampoco equivale automáticamente a pérdida del documento. | Fuente recuperable, Redis durable, hashes, artefactos, outbox y persistencia. |
| Disponibilidad del lote | Efecto negativo demostrado al cerrar tareas por rechazo local. | Admisión, cola, reintentos compatibles, intervención y recuperación. |
| Costos controlados | Puede reducir llamadas remotas durante una caída, pero infla contabilidad local con rechazos. Ahorro económico neto no medido. | Presupuesto y medición de intentos reales/unknown; recuperación sin duplicación innecesaria. |
| Operación y mantenimiento | Añade estado, parámetros, sincronización y casos de recuperación. | Menos mecanismos superpuestos y contratos observables. |

“Cero pérdida” exige distinguir conservación del documento, conservación del trabajo, conclusión correcta y finalización oportuna. Un evento en DLQ mantiene trazabilidad de un fallo, pero no es una auditoría concluida con éxito. Un timeout puede dejar un resultado remoto desconocido y producir repetición de HTTP; ni quitar ni añadir CB garantiza exactamente una ejecución remota.

### Decisión según clean-rebuild-policy

- **Decisión:** APROBAR retiro acotado de la intervención CB en v2. RECHAZAR reconstrucción del CB como condición de este arreglo.
- **Alcance MVP:** auditar con contratos actuales, cuota compartida, ejecución durable y fallos técnicos trazables; eliminar cierre artificial provocado por CB.
- **Estrategia:** refactorización incremental con eliminación del comportamiento innecesario en la ruta activa. No reconstrucción del pipeline.
- **Contratos activos:** HTTP público/202, prioridades, tareas/leases, presupuesto, artefactos, resultado documental, persistencia y reproceso DLQ.
- **Compuertas activadas:** contratos, pruebas de composición, estado/errores, higiene del retiro y observabilidad.
- **Excepciones:** ninguna para el retiro acotado. La rama sin tarea queda fuera del cambio porque no se ha demostrado eliminación segura de todos sus consumidores.
- **Validación:** evidencia y pruebas de la sección 7; el dictamen no certifica un cambio todavía no implementado.

La política pide límites proporcionales, preservar contratos consumidos y evitar capas sin responsabilidad actual. No ordena reconstruir cada componente problemático: para un sistema productivo prioriza evolución incremental y eliminación demostrablemente segura. [Política local aplicada](../../.agent/skills/clean-rebuild-policy/SKILL.md).

El patrón Circuit Breaker puede aportar valor operativo real. **La implementación actual es una complejidad perjudicial para v2; no hay evidencia suficiente para convertir su reconstrucción en una prioridad de negocio.** La opción B presenta la mejor relación entre beneficio demostrado, costo y reversibilidad.

## 6. Interacción de riesgos y criterio para reconsiderar

La cuota es compartida por scope/modelo (`GeminiQuotaBudget.php:46`), mientras el CB usa carril o namespace general (`GeminiGateway.php:474`). Por ello, los cargos artificiales de un carril pueden afectar el presupuesto del otro, aunque el estado open no sea compartido entre batch y priority. Un CB nuevo debe resolver explícitamente si salud y cuota tienen el mismo alcance.

El riesgo residual principal es agotar operaciones reales durante una caída larga. No se resuelve conservando una implementación que agota intentos aun sin HTTP. Debe medirse y administrarse por separado.

Reconsiderar automatización de suspensión solo si se demuestra al menos una necesidad: ocupación sostenida de workers que incumple el tiempo objetivo, volumen/costo material de intentos inútiles, frecuencia elevada de reproceso por caídas comunes, o requisito aprobado de recuperación automática sin terminar auditorías durante una ventana definida. Medir solicitudes reales por operación/carril, tasa 429/5xx/red, latencia, uso de inflight, edad de cola, DLQ por causa y tiempo de recuperación.

La comparación económica debe incluir ahorro de recursos y reproceso evitado frente a implementación, mantenimiento, retrasos por aperturas falsas e incidentes introducidos. No existe evidencia para monetizar esos términos en esta revisión ni fijar umbrales óptimos.

## 7. Pasos de ejecución, validación y plan 30/60/90

### Ejecución recomendada

1. **Fijar contrato y línea base.** Preparar SDD específica del retiro y certificar la suite exigida por el repositorio antes de editar código. Identificar SHA/digest desplegado y respaldar evidencia de las auditorías afectadas sin exponer datos clínicos.
2. **Cambiar solo el gateway durable.** Retirar las tres intervenciones descritas en 3.5. No revertir rasterización ni migrar tareas/Redis. No sustituir el CB por un umbral enorme o una bandera temporal.
3. **Actualizar los contratos de prueba.** Sustituir expectativas que exigen CB en `sendSingleAttempt/countInputTokens`; conservar cobertura de los consumidores directos sin tarea mientras existan.
4. **Validar integración en Redis aislado con HTTP simulado.** Comprobar la matriz siguiente y ambas etapas/carriles. Asegurar que todas las primitivas Redis del doble operen sobre el mismo namespace: `tests/Support/IsolatedAuditRedis.php:16` crea su conexión propia y solo sobreescribe parte de la API del padre.
5. **Documentar y empaquetar.** Actualizar SDD del retiro, descripción del flujo, skills afectadas, changelog y reporte. Las variables CB continúan si la rama directa las consume. Si después se retiran o cambian, sincronizar `.env.example`, catálogo, workflow y configuración remota conforme al contrato del proyecto.
6. **Desplegar de forma controlada.** Usar pausa/ventana operativa y las imágenes inmutables; coordinar todos los workers que llaman Gemini. Verificar digests y evitar mezcla prolongada de versiones, porque workers antiguos continuarían rechazando por CB. Reanudar con observación de cuota, HTTP real, colas y DLQ.
7. **Recuperar lo ya fallido.** Seleccionar por IDs, causa y ventana confirmados; usar el mecanismo existente `POST /audit/dlq/reprocess`. Crea nueva raíz trazable (`AuditDlqController.php:120`); el retiro no revive raíces terminales. No purgar DLQ ni resetear contadores para aparentar recuperación.
8. **Rollback definido.** Conservar imagen anterior y digests; ante regresión propia del retiro, pausar y restaurar una imagen compatible con v2. Volver al HEAD actual reintroduce el defecto CB: no reanudar masivamente sin contención. No requiere revertir esquema ni borrar tareas; probar previamente pause/resume y recuperación de leases.

### Contratos de aceptación

| Caso | Resultado exigido |
|---|---|
| Claves `cb:gemini:*` abiertas y cuota disponible | La ruta v2 no consulta/muta CB; envía la operación elegible. Una respuesta exitosa no termina en DLQ por estado legacy. |
| Cuota agotada | DEFERRED, cero nuevos cargos/HTTP y sin incremento de `operation_attempt`; libera slot/lease. |
| 503 real seguido de éxito | Retry durable con backoff/jitter; reutiliza countTokens cuando corresponde; no cierre por un rechazo local CB. |
| 429 con Retry-After | Respeta segundos y fecha HTTP; cada operación realmente intentada queda trazada. |
| Caída total con tres fallos de la misma operación | Sigue el cierre técnico/DLQ vigente, una sola vez. Documenta expresamente que el retiro no amplía la tolerancia temporal. |
| Respuesta guardada y fallo de finish/commit | Recupera sin nueva generación HTTP; cargos/recibos idempotentes. |
| Red/timeout con resultado incierto | Conserva unknown y cargos conservadores; no interpreta ausencia de respuesta como rechazo documental. |
| Errores permanentes y PDF inválido | Conserva clasificación previa de contenido frente a fallo técnico; no altera reglas clínicas. |
| Concurrencia entre extraction/semantic y batch/priority | Respeta cuota compartida, justicia y max_inflight; sin efecto de claves CB residuales. |
| Muerte de worker, pausa y reanudación | Recuperación por lease/outbox sin pérdida lógica ni duplicación de efectos; observar impacto de solicitudes ya iniciadas. |

Además de las pruebas funcionales, verificar ausencia de llamadas CB dentro de `executeSingleOperation()` y conservar las llamadas de compatibilidad que permanezcan justificadas. No considerar suficiente una búsqueda global con cero coincidencias si todavía hay consumidores activos.

### Plan por horizonte

| Horizonte | Acción | Dominio | Responsable propuesto | Criterio de éxito |
|---|---|---|---|---|
| Inmediato / antes del próximo lote grande | P0, incremental: retiro, pruebas integradas, despliegue controlado y recuperación afectada. | Arquitectura | Backend + QA + Operaciones | Ningún cierre v2 por CB local; artefactos/cuotas preservados; recuperación trazable. |
| Hasta 30 días | P1, incremental: runbook de caída, alertas por edad/tasa de errores y definición de ventana de continuidad. | Arquitectura operativa | Operaciones + Producto | Responsable y condición de pausa/reanudación explícitos; tiempo de detección y reproceso medidos. |
| Hasta 60 días | P2, condicional: decidir si hace falta cooldown integrado o política de retry más larga usando datos reales. | Arquitectura | Backend + Producto | Decisión con SLO y beneficio esperado; si no hay necesidad, no añadir componente. |
| Hasta 90 días | P2, incremental: ejercicio de caída/recuperación e inventario de consumidores sin tarea. | Arquitectura | QA + Operaciones + Backend | Demostración reproducible de recuperación; retirar legacy solo si se acredita falta de consumo. |

### Verificación realizada en esta revisión

- Comparación estática de HEAD, commit señalado y padre; referencias cruzadas de CB, cuota, runner, workers, pausa y DLQ.
- Historial: `git log --format='%h %ad %s' --date=short -S 'executeSingleOperation' -- app/Services/Audit/GeminiGateway.php`.
- Pruebas existentes:

```powershell
php vendor/bin/phpunit --configuration phpunit.xml --filter 'GeminiGatewayTest|GeminiQuotaBudgetTest|FairAuditTaskQueueMetricsTest|GeminiTaskExecutionRedisTest' --do-not-cache-result
```

Resultado real: **41 casos, 140 aserciones, 9 omitidos; 32 ejecutados satisfactoriamente, salida 0**, PHP 8.2.12/PHPUnit 10.5.63, 13,621 segundos. Las nueve omisiones requieren Redis real. No se ejecutó la suite completa ni se simuló un despliegue del retiro. Los tests actuales incluyen expectativas que afirman el comportamiento defectuoso, por lo que su resultado verde no invalida ARCH-001.

Validación documental: compilación MDX directa del informe y parseo de su JSON satisfactorios; 28 referencias únicas completas `ruta:línea` comprobadas contra archivos y límites de línea. `npm run build` en `website/` terminó con salida 0. Ese build valida el portal existente; la compilación directa cubre este informe, cuya creación en `plans/reports/` no implica publicación automática. `git diff --check` y el hook real `node .agent/scripts/check-docs-updated.mjs` finalizaron sin errores de cierre.

### Registro documental

Se creó este informe y se añadió una entrada a `plans/changelog.md`, conservando cambios anteriores. Según la matriz documental, estos son los únicos documentos modificados; la arquitectura descrita como implementada continúa siendo la del HEAD revisado.

| Archivo | ¿Cambio? | Razón | Estado |
|---|---|---|---|
| `plans/reports/dictamen-relevancia-circuit-breaker-v2-2026-11-09.md` | Sí | Evaluación y decisión solicitadas. | Hecho |
| `plans/changelog.md` | Sí | Registro del dictamen y alcance real. | Hecho |
| `plans/architecture.md`, `plans/api-endpoints.md` | No | No se implementó ningún cambio de arquitectura o API. | N/A |
| `AGENTS.md`, `README.md`, SDD del cambio original | No | No se cambió configuración ni se reescribe evidencia histórica como si el retiro estuviera aplicado. | N/A |

| Skill/registro | ¿Cambio? | Razón | Estado |
|---|---|---|---|
| `audfact-audit-gemini`, `audfact-project-overview` | No | No hay contrato nuevo implementado; la decisión futura queda en este informe. | N/A |
| `CATALOG.md` | No | El informe está cubierto por el mapeo vigente de `plans/*`; no se crea módulo ni skill. | N/A |
| `clean-rebuild-policy`, `architecture-assessment` | No | Se aplicaron como criterios de evaluación. | N/A |

## 8. Evidencia pendiente

| Brecha material | Cómo obtenerla | Efecto en confianza |
|---|---|---|
| Operación real y causalidad exacta del incidente | Correlacionar SHA/digest, errores HTTP, aperturas, task IDs, timestamps y DLQ; medir caudal/latencia/costos y consumo externo de cuota. | Impide atribuir todas las entradas y cuantificar ahorro neto; no invalida el defecto estático. |
| Validación integrada del retiro y consumidores directos | Implementar y ejecutar matriz con Redis real aislado, carga concurrente y HTTP simulado; inventariar uso sin tarea y ensayar rollback. | Impide certificar la implementación propuesta o eliminar toda compatibilidad. |
| Compromiso de continuidad del negocio | Definir espera máxima, tiempo de intervención, tolerancia a DLQ técnica y exigencia de recuperación automática. | Impide decidir un nuevo horizonte de retry o afirmar que la pausa manual cumple el SLO. |

Son tres brechas materiales: confianza global **media** según la rúbrica. El criterio de decisión permanece firme: retirar el CB defectuoso de v2 y tratar cualquier necesidad adicional de suspensión como requisito independiente, medido y explícito.

## 9. Salida estructurada

```json
{
  "recommendation": "B",
  "decision": "APROBAR",
  "strategy": "Retiro acotado del Circuit Breaker en la ruta durable v2",
  "rebuild_required": false,
  "inspected_head": "7e808dbd569a0ed5deca978283d9ff0cb4c8c1f2",
  "target_commit": "5a3205090373eac223a5083e4089cb4cddbc8b6a",
  "production_inspected": false,
  "production_code_modified": false,
  "removal_implemented": false,
  "long_outage_recovery_guaranteed_by_removal": false,
  "domain_scores": [
    {
      "domain": "Architecture",
      "raw_score": 2.8,
      "weight": 1.0,
      "weighted_score": 2.0,
      "cap_applied": true,
      "confidence": "Medium"
    }
  ],
  "global_score": 2.0,
  "classification": "C",
  "ceiling_rule_applied": true,
  "confidence": "Medium",
  "caps_triggered": ["ARCH-001: cierre terminal por rechazo local sin HTTP"],
  "validation": {
    "tests_total": 41,
    "tests_executed": 32,
    "tests_skipped": 9,
    "assertions": 140,
    "exit_code": 0,
    "real_redis_integration_run": false,
    "full_suite_run": false
  },
  "findings": [
    {
      "id": "ARCH-001",
      "domain": "Architecture",
      "severity": "Critical",
      "component": "Circuit Breaker y AuditTaskRunner",
      "evidence": ["app/Services/Audit/GeminiGateway.php:511", "app/Services/Audit/Pipeline/AuditTaskRunner.php:90"],
      "evidence_command": "rg -n -e task_operation_retry -e TYPE_DEAD_LETTER app/Services/Audit/Pipeline/AuditTaskRunner.php",
      "priority": "P0"
    },
    {
      "id": "ARCH-002",
      "domain": "Architecture",
      "severity": "High",
      "component": "Contador y recuperacion del circuito",
      "evidence": ["app/Services/Audit/GeminiGateway.php:406", "app/Services/Audit/GeminiGateway.php:537", "core/RedisClient.php:315"],
      "evidence_command": "rg -n -e cbRecordSuccess -e cbRecordFailure app/Services/Audit/GeminiGateway.php",
      "priority": "P1"
    },
    {
      "id": "ARCH-003",
      "domain": "Architecture",
      "severity": "High",
      "component": "Admision y presupuesto",
      "evidence": ["app/Services/Audit/GeminiGateway.php:220", "app/Services/Audit/Pipeline/GeminiQuotaBudget.php:226"],
      "evidence_command": "rg -n -e _http_attempts -e _request_failures app/Services/Audit/Pipeline/GeminiQuotaBudget.php",
      "priority": "P1"
    },
    {
      "id": "ARCH-004",
      "domain": "Architecture",
      "severity": "Medium",
      "component": "Deferral prolongado propuesto",
      "evidence": ["app/Services/Audit/Pipeline/FairAuditTaskQueue.php:290", "app/Services/Audit/Pipeline/FairAuditTaskQueue.php:315"],
      "evidence_command": "rg -n -e due_at_ms -e recovery_count app/Services/Audit/Pipeline/FairAuditTaskQueue.php",
      "priority": "P1"
    },
    {
      "id": "ARCH-005",
      "domain": "Architecture",
      "severity": "High",
      "component": "Contrato de pruebas integradas",
      "evidence": ["tests/Services/Audit/GeminiGatewayTest.php:306", "tests/Integration/GeminiTaskExecutionRedisTest.php:165"],
      "evidence_command": "rg -n -e testOpenCircuit -e testRetryExhaustion tests/Services/Audit/GeminiGatewayTest.php tests/Integration/GeminiTaskExecutionRedisTest.php",
      "priority": "P1"
    }
  ]
}
```

