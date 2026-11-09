# Reporte de Implementación: Retiro del Circuit Breaker en la Ruta Durable v2

**Identificador SDD:** `SDD-RETIRE-CB-V2-001`  
**Fecha:** 2026-11-09  
**Estado:** Certificado en Verde (Código 0)  

---

## 1. Resumen Ejecutivo y Motivación

En la arquitectura asíncrona v2 de AudFact, las tareas de auditoría operan de forma durable mediante colas Redis Streams, gobernadas por dos subsistemas complementarios:
1. **Control de Cuota (`GeminiQuotaBudget`):** Administra leases de cuota con Lua scripts en Redis, gobernando RPM, TPM, RPD y concurrencia máxima (*inflight*) con diferenciación por carriles (*priority* vs *batch*). Ante falta de cuota, pospone limpiamente la tarea mediante `QuotaDeferredException` sin penalizar sus intentos.
2. **Reintentos y Resiliencia (`AuditTaskRunner`):** Ejecuta tareas capturando excepciones de operación (`GeminiOperationException`), respetando la cabecera HTTP `Retry-After` del proveedor y aplicando backoff exponencial con jitter determinista.

El intento anterior de activar el Circuit Breaker inlined dentro de `GeminiGateway::executeSingleOperation()` introdujo una interferencia destructiva:
- Cuando 3 llamadas fallaban con HTTP 503 (sobrecarga transitoria de Google Gemini), el Circuit Breaker pasaba a estado `open` durante 60 segundos.
- Durante ese periodo, cualquier tarea que intentaba ejecutarse era interceptada por `$this->cbCheck()`, la cual lanzaba un `\RuntimeException(503)` local antes de tocar la red o verificar la cuota.
- `AuditTaskRunner` capturaba ese `\RuntimeException(503)` local como un fallo de tarea no-cuota, consumiendo sus 3 intentos en tan solo 6 segundos (esperas de 1s y 5s), mientras el CB seguía abierto por 54 segundos más.
- Como resultado, **~110 auditorías perfectamente sanas fueron descartadas prematuramente y enviadas a la Dead Letter Queue (`audfact:audit.dlq`)**.

Bajo la política `clean-rebuild-policy`, se determinó retirar el Circuit Breaker de la ruta durable v2 mediante una ablación quirúrgica exacta, eliminando complejidad innecesaria y permitiendo que la arquitectura asíncrona v2 opere con sus mecanismos nativos de resiliencia.

---

## 2. Componentes Modificados

### 2.1 `app/Services/Audit/GeminiGateway.php`
Ablación de 3 líneas exactas en `executeSingleOperation()`:
1. **L298 eliminada:** `$this->cbCheck();` al inicio de la operación.
2. **L324 eliminada:** `$this->cbRecordFailure(0);` en el bloque `catch (GuzzleException $e)`.
3. **L346-348 eliminadas:** `if ($code !== 400) { $this->cbRecordFailure($code); }` en la validación de respuestas HTTP no-200.

*Nota:* Los métodos privados auxiliares del Circuit Breaker (`cbCheck`, `cbRecordSuccess`, `cbRecordFailure`) se preservaron intactos en la clase porque son utilizados legítimamente por la ruta directa/síncrona sin tarea (`executePost()`, `sendWithStructuredOutput()`).

### 2.2 `tests/Services/Audit/GeminiGatewayTest.php`
Refactorización de 3 contratos de prueba TDD para certificar que la ruta durable v2 **no consulta ni muta** el Circuit Breaker en Redis:
1. **`testOpenCircuitDoesNotBlockSingleOperationInV2DurableRoute`**: Demuestra que aunque en Redis exista la clave `cb:gemini:priority:state = 'open'`, la llamada HTTP procede normalmente y responde 200 OK. Mockea `$redis->expects($this->never())->method('get')` y `$redis->expects($this->never())->method('incr')`.
2. **`testSingleOperationPropagatesHttpErrorsWithoutTouchingCircuitBreaker`**: Demuestra que ante fallos HTTP (400, 429, 503), la excepción `GeminiOperationException` se propaga con su código y `retryAfterMs` intactos, sin tocar contadores en Redis (`never()->method('incr')`).
3. **`testSingleOperationPropagatesNetworkFailureWithoutTouchingCircuitBreaker`**: Demuestra que ante caídas de red (`ConnectException`), se propaga `unknownResult: true` sin mutar el Circuit Breaker en Redis.

---

## 3. Certificación de Pruebas

| Suite | Comando | Resultado |
|---|---|---|
| Específica | `php vendor/bin/phpunit --configuration phpunit.xml --filter "GeminiGatewayTest"` | **15 tests, 82 aserciones, OK (Código 0)** |
| Completa | `php vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` | **1060 tests, 5524 aserciones, 78 skipped, 0 fallos, 0 errores. OK (Código 0)** |

---

## 4. Revisión Técnica Independiente (Paso 5)

La especificación `plans/sdd-retire-circuit-breaker-v2.md` fue sometida a revisión independiente mediante el CLI de Gemini (`gemini -y -p`), obteniendo dictamen **APROBADO** en los 5 checks:
- Perímetro y alcance del retiro: Conforme.
- Coherencia con la arquitectura del pipeline v2: Conforme.
- Contrato de pruebas TDD: Conforme.
- Cumplimiento de `clean-rebuild-policy`: Conforme.
- Plan de rollback y reproceso DLQ: Conforme.
Documentado en: `plans/reports/auditoria-independiente-sdd-retire-cb-v2-2026-11-09.md`.

