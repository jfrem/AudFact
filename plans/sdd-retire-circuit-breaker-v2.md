# SDD — Retiro Acotado del Circuit Breaker en la Ruta Durable v2

**ID:** SDD-RETIRE-CB-V2-001  
**Fecha:** 2026-11-09  
**Autor:** Antigravity (Automated)  
**Estado:** Nivel A — Implementable  
**Dictamen Previo:** Dictamen Arquitectónico APROBADO por Codex (`plans/reports/dictamen-relevancia-circuit-breaker-v2-2026-11-09.md`)  
**Política Aplicada:** `clean-rebuild-policy` (Compuerta: Retiro de complejidad innecesaria en componente productivo)

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor |
| --- | --- |
| Tipo | Refactor / Eliminación de código innecesario / Bugfix de resiliencia |
| Riesgo | Medio |
| Persistencia afectada | No — [CONFIRMADO] no se alteran tablas SQL ni contratos de esquema en base de datos |
| Contrato externo afectado | No — [CONFIRMADO] no se modifica la API REST pública ni la estructura de los eventos de Redis Streams |
| Cambio arquitectónico | Sí — [CONFIRMADO] se retira la máquina de estados del Circuit Breaker en la ruta de ejecución durable v2 |
| Producción afectada | Sí — [CONFIRMADO] elimina el modo de fallo que causaba el envío espurio de auditorías a la Dead Letter Queue |
| Requiere Paso 3.1 (cobertura de abstracciones) | No — [CONFIRMADO] |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| `GeminiGateway.php` | `app/Services/Audit/GeminiGateway.php` | MODIFIED | Punto de acceso a Google Gemini API; orquesta llamadas en v2 y v1 | L296–350 (`executeSingleOperation`) | Sí [CONFIRMADO] |
| `GeminiGatewayTest.php` | `tests/Services/Audit/GeminiGatewayTest.php` | MODIFIED | Suite de pruebas unitarias del Gateway | L305–380 (pruebas de contrato de `singleOperation`) | Sí [CONFIRMADO] |
| `AuditTaskRunner.php` | `app/Services/Audit/Pipeline/AuditTaskRunner.php` | INSPECTED | Ejecutor de tareas de Redis Streams con reintentos y DLQ | L60–105 | Sí [CONFIRMADO] |
| `GeminiQuotaBudget.php` | `app/Services/Audit/Pipeline/GeminiQuotaBudget.php` | INSPECTED | Control centralizado de RPM, TPM, RPD e inflight en Redis | L180–260 | Sí [CONFIRMADO] |
| `DocumentPdfRasterizer.php` | `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php` | UNTOUCHED | Rasterización por bloques de PDFs | Todo el archivo | Sí [CONFIRMADO] |

#### Criterio de Cierre del Perímetro:
- **Búsqueda por símbolo**: Búsqueda exhaustiva de llamadas a `cbCheck()` y `cbRecordFailure()` en todo el repositorio.
- **Evidencia**: Se confirmó que `cbCheck()` y `cbRecordFailure()` solo se invocan en `GeminiGateway.php` en dos rutas:
  1. Ruta durable v2: `GeminiGateway::executeSingleOperation()` (L298, L324, L347) → **OBJETO DE ESTE RETIRO**.
  2. Ruta legacy/síncrona sin tarea: `GeminiGateway::executePost()` (L406, L438, L451, L458) y métodos `sendWithStructuredOutput` / `sendPrebuiltBody` → **SE PRESERVA INTACTA** para consumidores directos o scripts legacy.

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `GeminiGateway.php` | `DocumentExtractionWorker` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L95, L203 | Directa | Inyección vía `withTask()` | Repositorio local [CONFIRMADO] |
| `GeminiGateway.php` | `SemanticEvaluationWorker` | `app/Services/Audit/Pipeline/SemanticEvaluationWorker.php` | L22, L93 | Directa | Inyección vía `withTask()` | Repositorio local [CONFIRMADO] |
| `GeminiGateway.php` | `AuditTaskRunner` | `app/Services/Audit/Pipeline/AuditTaskRunner.php` | L24–105 | Indirecta | Captura `GeminiOperationException` | Repositorio local [CONFIRMADO] |
| `GeminiGateway.php` | `GeminiGatewayTest` | `tests/Services/Audit/GeminiGatewayTest.php` | L305–380 | Directa | Tests unitarios PHPUnit | Repositorio local [CONFIRMADO] |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Mitigación / Corrección en Spec |
| --- | --- | --- | --- | --- |
| Retirar `cbCheck()` de `executeSingleOperation()` | Tareas con circuito abierto previo en Redis | `GeminiGateway.php:298` | Positiva | Las tareas ya no serán rechazadas con `RuntimeException(503)` local; consultarán la API real o serán aplazadas limpiamente por `GeminiQuotaBudget` [CONFIRMADO] |
| Retirar `cbRecordFailure()` de `executeSingleOperation()` | Contadores en Redis `cb:gemini:*:fails` | `GeminiGateway.php:324, 347` | Intencional | No se incrementará el contador del Circuit Breaker desde la ruta v2. La contabilidad de errores HTTP recae exclusivamente en `GeminiQuotaBudget` y en la telemetría del Logger [CONFIRMADO] |
| Actualizar `GeminiGatewayTest` | Pruebas que exigían fallo de CB en `executeSingleOperation` | `GeminiGatewayTest.php:306, 328, 357` | Test | Se sustituyen las aserciones que esperaban `RuntimeException(503)` por aserciones de aislamiento: verificar que `executeSingleOperation` NO interactúa con las claves del Circuit Breaker [CONFIRMADO] |

---

## FASE 1 — Diseño Técnico de Reconstrucción Limpia

### 1. Justificación Arquitectónica bajo Clean Rebuild Policy

1. **Eliminación de Capa Ceremonial Redundante:**
   El pipeline v2 durable ya cuenta con:
   - `GeminiQuotaBudget`: control centralizado y atómico en Redis de RPM, TPM, RPD y Máximo Inflight.
   - `AuditTaskRunner`: reintentos con backoff exponencial, jitter y respeto del header `Retry-After` de Google.
   - `FairAuditTaskQueue`: colas retrasadas (`delayed`) y equidad por job.
   La adición del Circuit Breaker en `executeSingleOperation` duplicaba esta protección de forma defectuosa, compitiendo con la cuota y causando la muerte prematura de tareas hacia la DLQ en 6 segundos.
2. **Cero Residuos y Cero Nuevas Claves:**
   Al retirar el Circuit Breaker de la ruta v2, no se añaden nuevas claves en Redis, no se requieren nuevos scripts Lua y se erradica la posibilidad de colisión entre el Circuit Breaker y la cola de tareas.

### 2. Contratos y Modificaciones Exactas

#### 2.1 Archivo: `app/Services/Audit/GeminiGateway.php`

En el método privado `executeSingleOperation()`:

**Punto 1 (Línea 298):**
*Eliminar la llamada a `$this->cbCheck();`*.
```php
// ANTES:
private function executeSingleOperation(string $method, array $body, array $context): array
{
    $this->cbCheck();
    $now = $this->taskQueue?->nowMs() ?? (int) floor(microtime(true) * 1000);
    ...

// DESPUÉS:
private function executeSingleOperation(string $method, array $body, array $context): array
{
    $now = $this->taskQueue?->nowMs() ?? (int) floor(microtime(true) * 1000);
    ...
```

**Punto 2 (Línea 324):**
*Eliminar la llamada a `$this->cbRecordFailure(0);` dentro del bloque `catch (GuzzleException $e)`*.
```php
// ANTES:
    } catch (GuzzleException $e) {
        if ($method === 'generateContent') {
            $this->saveDebugLog($body, ['error' => $e->getMessage()], $context, 'http_error');
        }
        $this->cbRecordFailure(0);
        throw new GeminiOperationException($context['operation'], durationMs: (int) ((hrtime(true) - $start) / 1000000), unknownResult: true);
    }

// DESPUÉS:
    } catch (GuzzleException $e) {
        if ($method === 'generateContent') {
            $this->saveDebugLog($body, ['error' => $e->getMessage()], $context, 'http_error');
        }
        throw new GeminiOperationException($context['operation'], durationMs: (int) ((hrtime(true) - $start) / 1000000), unknownResult: true);
    }
```

**Punto 3 (Líneas 345–348):**
*Eliminar el bloque condicional que invocaba `$this->cbRecordFailure($code);`*.
```php
// ANTES:
        if ($method === 'generateContent') {
            $this->saveDebugLog($body, ['error' => (string) $response->getBody(), 'http_status' => $code], $context, 'http_error');
        }
        // Un payload rechazado no indica indisponibilidad del proveedor.
        if ($code !== 400) {
            $this->cbRecordFailure($code);
        }
        throw new GeminiOperationException($context['operation'], $code, $retry, $reason, (int) ((hrtime(true) - $start) / 1000000));

// DESPUÉS:
        if ($method === 'generateContent') {
            $this->saveDebugLog($body, ['error' => (string) $response->getBody(), 'http_status' => $code], $context, 'http_error');
        }
        throw new GeminiOperationException($context['operation'], $code, $retry, $reason, (int) ((hrtime(true) - $start) / 1000000));
```

#### 2.2 Archivo: `tests/Services/Audit/GeminiGatewayTest.php`

Actualizar la suite de pruebas unitarias:

1. **`testOpenCircuitBlocksSingleOperationWithoutHttp` (L306–325):**
   - **Propósito:** Esta prueba afirmaba que un circuito abierto en Redis bloqueaba `executeSingleOperation`.
   - **Nuevo Contrato:** Debe demostrar que la ruta durable v2 (`sendSingleAttempt` / `countInputTokens`) **ignora el estado del Circuit Breaker y despacha normalmente la llamada HTTP**.
   - Se renombra a `testOpenCircuitDoesNotBlockSingleOperationInV2DurableRoute()`. Con un mock HTTP que devuelve 200 OK y un Redis mock con estado `open`, la llamada debe completarse satisfactoriamente sin lanzar `RuntimeException(503)`.
2. **`testSingleOperationRecordsProviderFailureExceptBadRequest` (L327–354):**
   - **Propósito:** Afirmaba que un HTTP 500/503/429 incrementaba el contador del CB.
   - **Nuevo Contrato:** Debe afirmar que ante fallos HTTP, `GeminiOperationException` se propaga con su código y `retryAfterMs` intactos, **sin invocar `incr` en Redis para el CB** (`$redis->expects($this->never())->method('incr')`).
   - Se renombra a `testSingleOperationPropagatesHttpErrorsWithoutTouchingCircuitBreaker()`.
3. **`testSingleOperationRecordsNetworkFailureWithUnknownResult` (L356–379):**
   - **Propósito:** Afirmaba que un fallo de red incrementaba el contador del CB.
   - **Nuevo Contrato:** Debe afirmar que ante un fallo de red, `GeminiOperationException` se propaga con `unknownResult: true`, **sin invocar `incr` en Redis para el CB** (`$redis->expects($this->never())->method('incr')`).
   - Se renombra a `testSingleOperationPropagatesNetworkFailureWithoutTouchingCircuitBreaker()`.

---

## FASE 2 — Plan de Pruebas y Validación (TDD)

### Casos de Prueba Verificables

| Test ID | Archivo | Escenario | Resultado Esperado |
| --- | --- | --- | --- |
| `TC-RETIRE-01` | `GeminiGatewayTest` | Ejecutar `sendSingleAttempt` con clave Redis `cb:gemini:priority:state = 'open'` | La llamada HTTP se realiza; responde 200 OK; NO lanza 503 por Circuit Breaker [CONFIRMADO] |
| `TC-RETIRE-02` | `GeminiGatewayTest` | Ejecutar `sendSingleAttempt` con respuesta HTTP 503 y `Retry-After: 5` | Lanza `GeminiOperationException(503, retryAfterMs: 5000)`; `RedisClient::incr` nunca es llamado [CONFIRMADO] |
| `TC-RETIRE-03` | `GeminiGatewayTest` | Ejecutar `sendSingleAttempt` con `ConnectException` de red | Lanza `GeminiOperationException(0, unknownResult: true)`; `RedisClient::incr` nunca es llamado [CONFIRMADO] |
| `TC-RETIRE-04` | `GeminiGatewayTest` | Llamada directa síncrona `sendWithStructuredOutput` con CB abierto | Conserva el comportamiento protector síncrono v1: lanza `RuntimeException(503)` [CONFIRMADO] |
| `TC-RETIRE-05` | Suite completa | `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` | 1060 tests en verde, 0 fallos, 0 errores [CONFIRMADO] |

---

## FASE 3 — Plan de Rollback y Despliegue

### Rollback
1. Si se detecta alguna anomalía post-despliegue, se puede revertir al commit `5a320509` en menos de 2 minutos mediante GitHub Actions o git revert local.
2. No hay datos persistidos en SQL Server ni migraciones de base de datos asociadas a este cambio.

### Reproceso de la DLQ en Producción
Una vez desplegada la imagen corregida en producción (`172.16.0.3`):
1. El endpoint `POST /audit/dlq/reprocess` requiere `{ "streamId": "<id>" }` (identificador Redis Streams tipo `^\d+-\d+$`) y cabecera de autenticación (`X-API-KEY` o `Authorization: Bearer <TOKEN>`).
2. Para reprocesar en lote las auditorías acumuladas en la DLQ durante el incidente:
   ```bash
   # Obtener los streamIds de la DLQ y enviarlos secuencialmente a reprocess:
   curl -s -H "X-API-KEY: $API_KEY" http://localhost:8080/audit/dlq | jq -r '.data.items[].stream_id' | while read id; do
       curl -s -X POST http://localhost:8080/audit/dlq/reprocess -H "X-API-KEY: $API_KEY" -H "Content-Type: application/json" -d "{\"streamId\":\"$id\"}"
   done
   ```
3. Monitorear `/metrics/async` y los logs de `audit-worker` para verificar que las auditorías recuperadas culminen exitosamente y que `deadLetterDepth` permanezca estable ante reintentos transitorios de Gemini.

---

## FASE 4 — Certificación de Resultados

- **Paso 5 (Revisión Independiente)**: Dictamen `APROBADO` emitido por revisor independiente CLI Gemini (`SDD-RETIRE-CB-V2-001`), documentado en `plans/reports/auditoria-independiente-sdd-retire-cb-v2-2026-11-09.md`.
- **Paso 8 (Pruebas Unitarias Finales)**: 
  - Suite específica `GeminiGatewayTest`: **15 tests, 82 aserciones, OK (Código 0)**.
  - Suite unitaria completa: **1060 tests, 5524 aserciones, 78 skipped, 0 fallos, 0 errores. OK (Código 0)**.
- **Estado de Implementación**: Conforme y lista para despliegue productivo.

