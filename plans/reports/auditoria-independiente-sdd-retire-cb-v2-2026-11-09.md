# Informe de Revisión Técnica Independiente de la Especificación SDD

- **Documento evaluado:** `plans/sdd-retire-circuit-breaker-v2.md`  
- **Identificador:** `SDD-RETIRE-CB-V2-001`  
- **Fecha de revisión:** 2026-11-09  
- **Veredicto:** **APROBADO**

---

### EVALUACIÓN DETALLADA POR CHECK ESPECÍFICO

#### 1. Perímetro y alcance del retiro
* **Delimitación quirúrgica a `GeminiGateway::executeSingleOperation()`:**
  * **Verificación de líneas:** Se contrastó la especificación contra el código fuente real en `app/Services/Audit/GeminiGateway.php`:
    * **L298:** `$this->cbCheck();` al inicio de `executeSingleOperation()` — delimitado para supresión exacta.
    * **L324:** `$this->cbRecordFailure(0);` en el bloque `catch (GuzzleException $e)` — delimitado para supresión exacta.
    * **L346–348:** Bloque condicional `if ($code !== 400) { $this->cbRecordFailure($code); }` — delimitado para supresión exacta.
  * **Preservación de la ruta síncrona/directa:**
    * El SDD establece expresamente que la ruta directa sin tarea (`executePost()`, `sendWithStructuredOutput()`, `sendPrebuiltBody()`) conserva intactas sus llamadas a `$this->cbCheck()`, `$this->cbRecordSuccess()` y `$this->cbRecordFailure()`.
    * Esto salvaguarda la protección ante fallos para consumidores directos fuera del pipeline de tareas asíncronas, impidiendo regresiones en scripts de mantenimiento o utilidades independientes.
  * **Evaluación:** **CONFORME**.

---

#### 2. Coherencia con la arquitectura del pipeline v2
* **Control de cuota y reintentos (v2 durable):**
  * La ruta v2 desacoplada gestiona el flujo mediante:
    1. **`GeminiQuotaBudget` (Lua en Redis):** Control atómico y centralizado de límites RPM, TPM, RPD, concurrencia máxima (*inflight*) y justicia por carriles (*fair lane turns*). Los aplazamientos transitorios emiten `QuotaDeferredException` sin penalizar intentos de ejecución.
    2. **`AuditTaskRunner`:** Captura `GeminiOperationException`, respetando la cabecera HTTP `Retry-After` del proveedor y aplicando retroceso exponencial determinista con *jitter* determinista (`crc32`).
* **Erradicación del modo de fallo DLQ:**
  * Se confirmó la etiología del fallo: Cuando el Circuit Breaker pasaba a estado `open` (cooldown de 60 s), `cbCheck()` lanzaba un `\RuntimeException(503)` local sin cabeceras de reintento.
  * En `AuditTaskRunner.php:72`, un `\RuntimeException` no es un aplazamiento de cuota; consume los reintentos locales (`attempt < 2`) con esperas de 1 s y 5 s. Al sexto segundo, la tarea agotaba sus 3 intentos mientras el CB seguía abierto por 54 segundos más, provocando el descarte prematuro e injustificado de auditorías sanas hacia la Dead Letter Queue (`AuditEvent::TYPE_DEAD_LETTER`).
  * El retiro de `cbCheck()` y `cbRecordFailure()` en `executeSingleOperation()` elimina de raíz este cortocircuito local espurio, permitiendo que las tareas completen sus llamadas reales a la API o sean diferidas limpiamente por la cuota.
  * **Evaluación:** **CONFORME**.

---

#### 3. Contrato de pruebas (TDD)
* **Definición de tests en `tests/Services/Audit/GeminiGatewayTest.php`:**
  * Se especifican con exactitud las tres pruebas a refactorizar:
    1. `testOpenCircuitBlocksSingleOperationWithoutHttp` (L306–325) $\rightarrow$ renombrado a `testOpenCircuitDoesNotBlockSingleOperationInV2DurableRoute()`.
    2. `testSingleOperationRecordsProviderFailureExceptBadRequest` (L327–354) $\rightarrow$ renombrado a `testSingleOperationPropagatesHttpErrorsWithoutTouchingCircuitBreaker()`.
    3. `testSingleOperationRecordsNetworkFailureWithUnknownResult` (L356–379) $\rightarrow$ renombrado a `testSingleOperationPropagatesNetworkFailureWithoutTouchingCircuitBreaker()`.
* **Aislamiento de claves CB en Redis:**
  * Las pruebas de fallo HTTP y de red exigen taxativamente `$redis->expects($this->never())->method('incr')`, garantizando que la ruta v2 no muta contadores de fallo.
  * La prueba de estado abierto demuestra que `executeSingleOperation()` despacha con éxito HTTP 200 OK aun existiendo la clave `cb:gemini:priority:state = 'open'` en Redis, confirmando que la ruta v2 ignora el estado del circuito.
  * Se conserva en la suite la verificación de que la ruta síncrona directa (`sendWithStructuredOutput()`) continúa evaluando el CB (`TC-RETIRE-04`).
  * **Evaluación:** **CONFORME**.

---

#### 4. Cumplimiento de `clean-rebuild-policy`
* **Eliminación de complejidad sin residuos:**
  * El diseño realiza una ablación directa de 3 invocaciones en `executeSingleOperation()`.
  * No introduce adaptadores intermediarios superfluos, *wrappers* no operativos, variables booleanas de omisión (*flags* condicionales) ni claves huérfanas en Redis.
  * Los métodos auxiliares privados `cbCheck()`, `cbRecordSuccess()` y `cbRecordFailure()` se mantienen activos únicamente porque la ruta síncrona (`executePost()`) los requiere, evitando dejar código muerto.
  * **Evaluación:** **CONFORME**.

---

#### 5. Plan de rollback y reproceso DLQ
* **Viabilidad y seguridad del rollback:**
  * El cambio no genera migraciones de base de datos SQL ni mutaciones en tablas de persistencia.
  * Es 100% reversible a nivel de código Git en menos de 2 minutos vía commit o despliegue automatizado.
* **Reproceso de auditorías en DLQ:**
  * Los endpoints `GET /audit/dlq` y `POST /audit/dlq/reprocess` existen y fueron validados en `app/Controllers/AuditDlqController.php` y `app/Routes/web.php`.
  * La autenticación mediante `X-API-KEY: $API_KEY` (o `Authorization: Bearer <TOKEN>`) mapea exactamente con la implementación en `core/AuthMiddleware.php` (`MCP_WEBHOOK_SECRET`).
  * El script bash documentado en Fase 3 procesa secuencialmente cada `streamId` (`^\d+-\d+$`), asegurando una reactivación idempotente de las auditorías sin riesgo de duplicación.
  * **Evaluación:** **CONFORME**.

---

### CONCLUSIÓN TÉCNICA

La especificación cumple con los estándares de rigor técnico, exactitud quirúrgica, seguridad de despliegue y preservación de contratos.

**APROBADO**

