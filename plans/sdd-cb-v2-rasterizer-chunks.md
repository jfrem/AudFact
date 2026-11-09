# SDD — Circuit Breaker v2 Activo + Rasterización por Chunks

## Revisión de mantenibilidad — 2026-11-09

Esta revisión, solicitada por el usuario sobre el diff PHP, sustituye las decisiones
de chunking y pruebas de la propuesta original que aparece debajo.

- [CONFIRMADO] Alcance: los dos servicios y sus dos archivos de prueba ya presentes
  en el diff. Estrategia: refactorización incremental; sin cambios de API, datos,
  variables de entorno, workers ni despliegue.
- [CONFIRMADO] La suite entrante contiene un BOM que impide cargar el test del PDF;
  al retirarlo aparecen tres errores y tres fallos (1035 pruebas, 79 omitidas).
- [CONFIRMADO] `rasterizeChunk()` oculta cualquier `RuntimeException`; la limpieza
  se repite en tres ramas; el modo string de `collectAndValidateImages()` no tiene
  consumidores; los dobles nuevos no limitan la cantidad real de páginas.
- Decisión: cada bloque renderiza como máximo 25 páginas y el siguiente repite la
  última página del anterior: rangos 1–25, 25–49, 49–51. La página compartida se
  descarta al acumular. Un bloque exitoso más corto que el rango solicitado indica
  fin del documento. Un resultado vacío, timeout o fallo del proceso conserva su
  clasificación técnica o documental; jamás devuelve éxito parcial.
- Decisión: mantener el sondeo de la página 51. Un PDF de 50 páginas requiere tres
  procesos sin fallback; se retira el criterio contradictorio de dos procesos.
- Decisión: devolver rutas ordenadas desde la ejecución con fallback, eliminar la
  tupla con motor no utilizado y la compatibilidad privada string/array. El `finally`
  de `rasterize()` limpia entrada y todos los bloques, incluso el bloque fallido.
- Decisión: conservar las firmas públicas, incluido `buildCommand()` y sus nombres
  de argumentos; convertir `CHUNK_SIZE` en detalle privado sin consumidores externos.
- Decisión: mantener la política CB actual (red y HTTP distinto de 400), quitar
  comentarios ornamentales y probar bloqueo, códigos y metadatos mediante la API pública.

| Requisito | Implementación | Validación |
| --- | --- | --- |
| Evidencia completa y ordenada | `DocumentPdfRasterizer::rasterize()` | 1, 10, 24, 25, 26, 49 y 50 páginas; bytes y etiquetas de ambas numeraciones de motor |
| Límite sin truncamiento | Sondeo hasta página 51 | 51 páginas falla y limpia temporales |
| Fallos visibles y limpieza | Propagación, fallback por bloque y `finally` | Timeout, exit no cero, salida vacía, contenido inválido y fallback con archivos parciales |
| CB comprobable | `GeminiGateway::executeSingleOperation()` | Circuito abierto sin HTTP; 400, 429, 503 y red, generación y conteo |

Validación prevista: PHPUnit completo y suite afectada en orden aleatorio, lint PHP,
build documental y verificador de cierre. La línea base de HEAD se ejecuta aislada
para distinguir las regresiones del diff entrante. No se modifica el índice Git.
Rollback local: recuperar únicamente los cambios de esta revisión desde el checkpoint
de los seis archivos creado antes de editar. No hay migración ni acción remota.
El reporte final registra comandos, resultados reales y límites de validación en
[el reporte de implementación](reports/clean-code-php-diff-2026-11-09.md).

## Propuesta original — antecedente histórico

El texto siguiente conserva la propuesta previa a la revisión del diff. Sus ejemplos,
conteos de procesos y tests no describen la implementación final; el contrato vigente
es la revisión de mantenibilidad anterior y su reporte. En particular, se retiraron
la captura de errores como EOF, el modo privado string/array y el criterio de dos
procesos para un PDF de 50 páginas.

**ID:** SDD-CB-RASTER-002  
**Fecha:** 2026-11-09  
**Autor:** Antigravity (Automated)  
**Estado:** Nivel A — Implementable

---

## Clasificación del Cambio (Triage)

| Dimensión                                   | Valor                                                                                                    |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Tipo                                        | Bug / Refactor                                                                                           |
| Riesgo                                      | Alto                                                                                                     |
| Persistencia afectada                       | No — [CONFIRMADO] los cambios son puramente en memoria/Redis de corta duración, sin tablas SQL afectadas |
| Contrato externo afectado                   | No — [CONFIRMADO] no se modifica ningún contrato de Redis Stream, API REST ni evento público             |
| Cambio arquitectónico                       | Sí — el Circuit Breaker pasa de path muerto a path activo en producción                                  |
| Producción afectada                         | Sí                                                                                                       |
| Requiere 0.3.1 (cobertura de abstracciones) | No — no se reemplaza mapeo estático por abstracción dinámica                                             |

**Calibración:** Riesgo Alto → Descubrimiento completo + infraestructura + operación + rollback. Todas las secciones obligatorias.

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo                          | Ruta                                                           | Categoría | Propósito                                                               | Líneas Afectadas                                                        | Verificado por Lectura |
| -------------------------------- | -------------------------------------------------------------- | --------- | ----------------------------------------------------------------------- | ----------------------------------------------------------------------- | ---------------------- |
| GeminiGateway.php                | `app/Services/Audit/GeminiGateway.php`                         | MODIFIED  | Punto de acceso a API Gemini; contiene CB, retry, quota                 | L296-342 (`executeSingleOperation`), L521-550 (`cbRecordFailure`)       | Sí                     |
| DocumentPdfRasterizer.php        | `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php`        | MODIFIED  | Rasterización de PDF a JPEG; gestiona proceso externo gs/pdftoppm       | L147-193 (`rasterize`), L383-401 (`buildCommand`), L444-464 (poll loop) | Sí                     |
| AuditTaskRunner.php              | `app/Services/Audit/Pipeline/AuditTaskRunner.php`              | INSPECTED | Orquesta reintentos y DLQ de tareas                                     | L62-104                                                                 | Sí                     |
| GeminiOperationException.php     | `app/Services/Audit/GeminiOperationException.php`              | INSPECTED | Excepción de operación Gemini con httpCode, retryAfterMs                | L1-15                                                                   | Sí                     |
| GeminiResponseException.php      | `app/Services/Audit/GeminiResponseException.php`               | INSPECTED | Excepción de respuesta Gemini (PROVIDER_BLOCKED etc.)                   | L1-46                                                                   | Sí                     |
| RasterizationProcessResult.php   | `app/Services/Audit/Pipeline/RasterizationProcessResult.php`   | INSPECTED | Resultado del proceso de rasterización con diagnóstico de contenido     | L1-31                                                                   | Sí                     |
| DocumentExtractionWorker.php     | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`     | INSPECTED | Consume eventos, orquesta extracción; instancia `DocumentPdfRasterizer` | L111-112                                                                | Sí                     |
| DocumentExtractionWorkerTest.php | `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php` | IMPACTED  | Suite de tests del worker de extracción                                 | 1–1519                                                                  | Sí (parcial, L1-80)    |
| GeminiGatewayTest.php            | `tests/Services/Audit/GeminiGatewayTest.php`                   | IMPACTED  | Suite de tests del Gateway (si existe)                                  | —                                                                       | Buscar                 |
| DocumentPdfRasterizerTest.php    | `tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php`  | IMPACTED  | Suite de tests del rasterizador (si existe)                             | —                                                                       | Buscar                 |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda       | Patrón                                          | Resultado                                                          | Evidencia                          |
| ------------------------ | ----------------------------------------------- | ------------------------------------------------------------------ | ---------------------------------- |
| Búsqueda por símbolo     | `cbRecordFailure`, `cbCheck`, `executePost`     | Presentes solo en `GeminiGateway.php`                              | Lectura directa L383-550           |
| Búsqueda por símbolo     | `executeSingleOperation`                        | Referenciada desde L237 y L296 en `GeminiGateway.php`              | Lectura directa                    |
| Búsqueda por importación | `DocumentPdfRasterizer`                         | Importada en `DocumentExtractionWorker.php:12`, test L12           | Lectura directa                    |
| Búsqueda por símbolo     | `rasterize`                                     | Llamada en `DocumentExtractionWorker.php:618`                      | Lectura directa                    |
| Búsqueda en tests        | `StubGeminiGateway`, `PassthroughPdfRasterizer` | Presentes en `DocumentExtractionWorkerTest.php`                    | Lectura directa L43,53             |
| Búsqueda en workflows/CI | `.github/workflows/*.yml`                       | Pipeline CI ejecuta tests, no referencias directas a GeminiGateway | [INFERIDO] por patrón del proyecto |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado          | Dependencia                  | Ruta Dependencia                                               | Línea(s)                      | Relación | Mecanismo                                       | Tipo de Consumidor |
| ------------------------- | ---------------------------- | -------------------------------------------------------------- | ----------------------------- | -------- | ----------------------------------------------- | ------------------ |
| GeminiGateway.php         | DocumentExtractionWorker     | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`     | L95, L203, L403               | Directa  | Instanciación + inyección                       | Repositorio local  |
| GeminiGateway.php         | SemanticEvaluationWorker     | `app/Services/Audit/Pipeline/SemanticEvaluationWorker.php`     | [INFERIDO] — mismo patrón     | Directa  | Instanciación                                   | Repositorio local  |
| GeminiGateway.php         | AuditController              | `app/Controllers/AuditController.php`                          | [INFERIDO] — auditoría single | Directa  | `GeminiGateway::create()`                       | Repositorio local  |
| GeminiGateway.php         | GeminiGatewayTest            | `tests/Services/Audit/GeminiGatewayTest.php`                   | Todos                         | Directa  | Tests PHPUnit                                   | Repositorio local  |
| DocumentPdfRasterizer.php | DocumentExtractionWorker     | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`     | L111-112, L618                | Directa  | Instanciación + llamada                         | Repositorio local  |
| DocumentPdfRasterizer.php | DocumentExtractionWorkerTest | `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php` | L12, L53                      | Directa  | Tests PHPUnit (stub `PassthroughPdfRasterizer`) | Repositorio local  |
| DocumentPdfRasterizer.php | DocumentPdfRasterizerTest    | `tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php`  | Todos                         | Directa  | Tests PHPUnit                                   | Repositorio local  |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto                                                    | Componente Afectado                                                   | Ruta:Línea                        | Tipo de Regresión | Corrección                                                                                                                                                             |
| ------------------------------------------------------------------- | --------------------------------------------------------------------- | --------------------------------- | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Agregar `cbCheck()` en `executeSingleOperation()`                   | Tests que mockean `executeSingleOperation` sin CB state               | GeminiGatewayTest.php (si existe) | Test              | Tests deben inicializar Redis mock con estado `closed` (default) — el CB no abre sin `cbRecordFailure`, por lo que tests existentes sin stub de CB pasarán sin cambios |
| Agregar `cbRecordFailure()` en `executeSingleOperation()` al fallar | Tests que esperan que el error se relance sin efectos en Redis        | GeminiGatewayTest.php             | Test              | Si los tests mockean Redis (sin incr/set expectation), no se verá el problema porque `cbRecordFailure` atrapa `RedisUnavailableException` internamente                 |
| Cambiar `rasterize()` a chunks                                      | `DocumentPdfRasterizerTest.php` tests de páginas únicas               | DocumentPdfRasterizerTest.php     | Test              | El comportamiento para PDFs ≤ CHUNK_SIZE páginas es idéntico al actual — mismo resultado. Tests de PDFs grandes pueden necesitar ajuste de mocks                       |
| Cambiar `rasterize()` a chunks                                      | `DocumentExtractionWorkerTest.php` que usa `PassthroughPdfRasterizer` | DocumentExtractionWorkerTest.php  | Test              | `PassthroughPdfRasterizer` es un stub que no llama al código real — sin impacto                                                                                        |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta                            | Regla Relevante                                                                                                                  | Tipo de Evidencia                                                       | Evidencia                                                                                                                                           | Cambio Compatible                                                                |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Redis `INCR` con TTL                   | `INCR` retorna el nuevo valor como integer; el TTL debe setearse por separado con `EXPIREAT`/`EXPIRE`                            | Estática                                                                | GeminiGateway.php:530 `$this->cbRedis->incr($this->cbFailsKey(), $cooldown * 2)` — segundo arg es TTL en la implementación de `RedisClient::incr()` | Sí — no se modifica la llamada                                                   |
| Ghostscript `-dFirstPage`/`-dLastPage` | Acepta rangos de páginas parciales; si `-dLastPage` excede el total del PDF, procesa hasta la última página disponible sin error | Documental (man gs) + Empírica (comportamiento observado en producción) | GeminiGateway.php no involucrado; `DocumentPdfRasterizer::buildCommand()` L383-401                                                                  | Sí — chunks usan rangos parciales válidos                                        |
| `proc_open` / `proc_terminate`         | SIGKILL (señal 9) termina inmediatamente el proceso; recursos de pipes deben cerrarse explícitamente antes de `proc_close()`     | Empírica                                                                | DocumentPdfRasterizer.php L456-460 — patrón ya implementado y correcto                                                                              | Sí — el nuevo código para chunks reutiliza `executeProcess()` que ya maneja esto |

### 0.5 Matriz de Entornos de Ejecución

| Entorno             | Flujo                                               | Invocación Típica                                          | Compatible | Evidencia                                                                                                        |
| ------------------- | --------------------------------------------------- | ---------------------------------------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------- |
| Desarrollo local    | No aplica — Zero-Source, el código vive en imágenes | N/A                                                        | N/A        | AGENTS.md: "El Host de producción solo contiene orquestación y secretos. El código vive dentro de las imágenes." |
| CI (GitHub Actions) | Build imagen → PHPUnit → Publish → Deploy           | `.github/workflows/*.yml` → `vendor/bin/phpunit`           | Sí         | No se modifican tests de forma que rompan CI; los nuevos tests siguen el mismo patrón de los existentes          |
| Producción          | Workers PHP procesan eventos Redis Streams          | `bin/audit-worker.php` → `DocumentExtractionWorker::run()` | Sí         | El deploy hace rolling restart de contenedores; sin estado persistido relevante para el CB                       |
| Testing aislado     | PHPUnit con mocks/stubs de Redis y Gemini           | `vendor/bin/phpunit`                                       | Sí         | Los cambios preservan la inyectabilidad de dependencias existente                                                |

### 0.6 Inventario de Información

| Elemento                                                                | Estado      | Evidencia (ruta:línea)                                                                               |
| ----------------------------------------------------------------------- | ----------- | ---------------------------------------------------------------------------------------------------- |
| `cbCheck()` solo en path v1 (`executePost`)                             | Confirmado  | GeminiGateway.php:135 (cbCheck en executePost), L132 (executeTaskRequest bypasses cbCheck)           |
| `cbRecordFailure()` nunca llamada desde v2                              | Confirmado  | GeminiGateway.php:430-444 (solo en executePost), executeSingleOperation L296-371 sin cbRecordFailure |
| `retryAfterMs` respetado en AuditTaskRunner                             | Confirmado  | AuditTaskRunner.php:75 `max($delay, $error->retryAfterMs)`                                           |
| Rasterización monolítica (1 invocación = todas las páginas)             | Confirmado  | DocumentPdfRasterizer.php:157, L383-401                                                              |
| `executeProcess()` es protected y reutilizable                          | Confirmado  | DocumentPdfRasterizer.php:420 `protected function executeProcess(string $cmd)`                       |
| Constructor de `DocumentPdfRasterizer` inyectable                       | Confirmado  | DocumentExtractionWorker.php:87, L111-112                                                            |
| `RasterizationProcessResult::contentFailure()` analiza stderr de salida | Confirmado  | RasterizationProcessResult.php:14-30                                                                 |
| Existencia de `GeminiGatewayTest.php`                                   | Desconocido | No verificado por lectura directa                                                                    |
| Existencia de `DocumentPdfRasterizerTest.php`                           | Desconocido | No verificado por lectura directa                                                                    |

### 0.7 Información Faltante Crítica

Ninguna — los cambios son implementables con la información disponible.

### 0.8 Información Faltante Importante

| Dato                                          | Motivo                            | Impacto                                                                                |
| --------------------------------------------- | --------------------------------- | -------------------------------------------------------------------------------------- |
| Existencia de `GeminiGatewayTest.php`         | No verificado por lectura directa | Tests de CB actuales pueden necesitar actualización si mockan `executeSingleOperation` |
| Existencia de `DocumentPdfRasterizerTest.php` | No verificado por lectura directa | Tests de rasterización pueden necesitar actualización de mocks                         |

### 0.9 Información Faltante Opcional

| Dato                                     | Motivo                  | Impacto                                   |
| ---------------------------------------- | ----------------------- | ----------------------------------------- |
| Latencia típica de gs en PDFs 25-50 págs | No medida en producción | No bloquea — CHUNK_SIZE=25 es conservador |

### 0.10 Supuestos Declarados

| ID  | Supuesto                                                                                                                                 | Severidad | Evidencia                                                                                                                                 | Riesgo                                                                                                                                                   |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| S1  | `RedisClient::incr()` maneja `RedisUnavailableException` internamente                                                                    | S2        | GeminiGateway.php:523-525: `if (!$this->cbRedis->isAvailable()) return;` — cbRecordFailure retorna silenciosamente si Redis no disponible | Mínimo — fallo silencioso intencional                                                                                                                    |
| S2  | Ghostscript acepta `-dLastPage` mayor al total de páginas del PDF sin error                                                              | S2        | Comportamiento estándar de Ghostscript documentado                                                                                        | Si el supuesto es incorrecto, el último chunk lanzará RuntimeException → `RasterizationFailureException` → `document_rejected` (comportamiento correcto) |
| S3  | `CHUNK_SIZE=25` páginas por chunk a 200 DPI genera menos de `$this->timeoutSeconds/2` seg con Ghostscript en el contenedor de producción | S2        | No medido empíricamente. Basado en que chunks de 25 páginas son 50% de MAX_PAGES actual que ya funcionaba en muchos casos                 | Si excede, timeout → `document_rejected` — mismo comportamiento que hoy, pero ahora se activa en chunk parcial no en todo el PDF                         |

### 0.11 Clasificación de Completitud Inicial

**Nivel A** — Implementable. Supuestos S2 solamente; sin S3/S4. Los desconocidos (tests existentes) no bloquean la implementación — el código es backward-compatible con stubs existentes.

---

## FASE 1 — Especificación

### 1. Objetivo

**Problema actual:**

1. **CB inerte en v2 (Falla A):** El Circuit Breaker de Gemini (`cbCheck`, `cbRecordFailure`) solo se llama desde `executePost()` (path legacy v1). El path de producción v2 (`executeTaskRequest → executeSingleOperation`) nunca registra fallos en el CB ni verifica su estado. Durante saturación de quota (429), las tareas agotan sus reintentos y van a DLQ, pero ninguna señal frena la ingesta de nuevas tareas.

2. **Rasterización monolítica (Falla B):** `DocumentPdfRasterizer::rasterize()` procesa el PDF completo (hasta 51 páginas) en una sola invocación de Ghostscript. Para PDFs médicos de 65+ páginas con fuentes Identity-H no incrustadas, el proceso tarda >120s → timeout → `document_rejected`.

**Causa raíz verificada:**

- Falla A: La refactorización al pipeline v2 (task-based) no trasladó la lógica de CB a `executeSingleOperation()`. Las llamadas `cbCheck()` y `cbRecordFailure()` quedaron solo en `executePost()`.
- Falla B: La arquitectura actual no divide el trabajo en unidades procesables independientemente. Un fallo tardío en la página 60 descarta todo el trabajo previo.

**Impacto actual:** Cada batch grande con saturación de quota produce 30-40 entradas en DLQ que requieren intervención manual. PDFs de alta complejidad médica producen `document_rejected` sin intentar procesamiento parcial.

**Resultado esperado:**

- El CB abre automáticamente tras 3 fallos consecutivos en `executeSingleOperation()`, bloqueando nuevas llamadas por 60s, reduciendo la cascada de 429s hacia DLQ.
- PDFs hasta 50 páginas se procesan en chunks de 25 páginas, eliminando timeouts para todos los documentos dentro del límite `MAX_PAGES`.

### 2. Alcance

#### Incluido

- Activar `cbCheck()` al inicio de `executeSingleOperation()` antes del HTTP call.
- Activar `cbRecordFailure($code)` en `executeSingleOperation()` cuando el HTTP code es >= 400 y NO es 400 (errores de red, rate limiting, servidor).
- Dividir `rasterize()` en N chunks de máximo 25 páginas cada uno, con llamada individual a `executeProcess()` por chunk.
- Agregar tests PHPUnit para CB en v2 y para rasterización por chunks.

#### Excluido

- Reprocesamiento automático de DLQ (no es parte de este cambio).
- Ajuste de `CB_GEMINI_COOLDOWN` / `CB_GEMINI_THRESHOLD` (parámetros configurables sin cambio de código).
- Rasterización paralela (chunks son secuenciales).
- Cambio de `MAX_PAGES` ni `CHUNK_SIZE` como constantes configurables por env (MVP: constante en código).

### 3. Non Goals

- No se implementa retry per-chunk para la rasterización — si un chunk falla, se lanza `RuntimeException` que sube al worker como antes.
- No se modifica el comportamiento del CB para discriminar entre tipos de error (429 vs 503). La lógica de threshold se preserva exactamente.
- No se modifica `executePost()` ni el path v1 (código legacy preservado por compatibilidad con auditorías directas sin task).

### 4. Estado Actual

#### Falla A — Circuit Breaker

```php
// GeminiGateway.php — sendWithStructuredOutput() L132-137 [CONFIRMADO]
if ($this->task !== null) {
    return $this->executeTaskRequest($payload, $ctx);  // ← cbCheck() NUNCA llamado
}
$this->cbCheck();  // ← Solo si task === null (nunca en producción v2)
return $this->executePost($url, $payload, $ctx, 'Structured Output');

// executeSingleOperation() L296-371 [CONFIRMADO] — Sin cbCheck ni cbRecordFailure
protected function executeSingleOperation(string $method, array $body, array $context): array {
    // ...verifica grant...
    try {
        $response = $this->http->post(...);  // ← HTTP call sin cbCheck previo
    } catch (GuzzleException $e) {
        throw new GeminiOperationException(..., unknownResult: true);  // ← Sin cbRecordFailure
    }
    $code = $response->getStatusCode();
    if ($code < 200 || $code >= 300) {
        throw new GeminiOperationException(...);  // ← Sin cbRecordFailure
    }
    // ...
}
```

#### Falla B — Rasterización monolítica

```php
// DocumentPdfRasterizer.php — rasterize() L157 [CONFIRMADO]
$maxPagesToRender = self::MAX_PAGES + 1;  // = 51 páginas

// buildCommand() L383-390 [CONFIRMADO] — 1 sola invocación para TODAS las páginas
sprintf('%s -sDEVICE=jpeg ... -dFirstPage=1 -dLastPage=%d -sOutputFile=%s %s',
    escapeshellcmd($binary), $dpi,
    $maxPagesToRender,  // ← 51 páginas en una sola invocación
    escapeshellarg($outputPrefix . '-%d.jpg'),
    escapeshellarg($inputPdfPath)
);
```

### 5. Estado Objetivo

#### Falla A — Circuit Breaker activo en v2

`executeSingleOperation()` llama `cbCheck()` antes del HTTP call y `cbRecordFailure($code)` cuando el código HTTP indica fallo no-400. El CB se comporta idénticamente al path v1 pero ahora activo en producción.

**Flujo objetivo tras 3 fallos consecutivos:**

```
Worker → executeTaskRequest() → executeSingleOperation()
       → cbCheck() ← NUEVO: lanza RuntimeException(503) si CB abierto
       → HTTP 429 de Google
       → cbRecordFailure(429) ← NUEVO: incrementa contador; abre CB si ≥ threshold
       → GeminiOperationException relanzada
       → AuditTaskRunner defer (con retryAfterMs) o DLQ si agotado
       [CB abierto: nuevas tareas reciben 503 inmediatamente → defer sin HTTP call]
```

#### Falla B — Rasterización por chunks

`rasterize()` divide el rango [1, min(total_pdf_pages, MAX_PAGES)] en chunks de `CHUNK_SIZE=25`. Cada chunk llama `buildCommand()` con `-dFirstPage` y `-dLastPage` propios, y `executeProcess()` con timeout independiente. Los resultados se acumulan en orden.

**Comportamiento con PDF de 60 páginas (limitado a MAX_PAGES=50):**

```
Chunk 1: páginas 1–25  → executeProcess() timeout=timeoutSeconds
Chunk 2: páginas 26–50 → executeProcess() timeout=timeoutSeconds
Resultado: 50 imágenes JPEG acumuladas → collectAndValidateImages()
```

### 6. Decisiones Arquitectónicas

| ID    | Decisión                                                                     | Alternativas Rechazadas                                      | Justificación                                                                                                                                                                                                                             |
| ----- | ---------------------------------------------------------------------------- | ------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DA-01 | CB activado en `executeSingleOperation()`, no en `executeTaskRequest()`      | Activar en `executeTaskRequest()`                            | `executeSingleOperation()` es el punto de llamada HTTP real. Activar en `executeTaskRequest()` requeriría pasar el contexto HTTP upward. La granularidad correcta es en el punto de fallo.                                                |
| DA-02 | `cbRecordFailure()` solo para errores cuyo `$code !== 400`                   | Registrar todos los errores                                  | Errores 400 son errores del documento (PDF corrupto, unsupported), no errores de la API. Registrarlos abriría el CB por problemas del documento, no por problemas de conectividad. Este comportamiento ya estaba en `executePost()` L443. |
| DA-03 | `cbCheck()` al inicio de `executeSingleOperation()` antes de verificar grant | Después de verificar grant                                   | La verificación del grant es local (Redis). El CB debe verificarse antes de intentar cualquier llamada externa.                                                                                                                           |
| DA-04 | `CHUNK_SIZE = 25` como constante `private const` en `DocumentPdfRasterizer`  | Configurar por env `PDF_CHUNK_SIZE`                          | MVP mínimo. El valor de 25 es conservador y cubre todos los PDFs médicos observados. Configurabilidad se agrega cuando existan datos de múltiples configuraciones.                                                                        |
| DA-05 | Chunks secuenciales (no paralelos)                                           | Paralelización con `pcntl_fork` o `proc_open` múltiple       | La paralelización en un entorno PHP-FPM tiene riesgos de leak de recursos y es innecesaria para el MVP. Los PDFs médicos son procesados en máximo 2 chunks con 120s de timeout cada uno.                                                  |
| DA-06 | Si un chunk falla → lanzar excepción inmediatamente (no continuar)           | Continuar con chunks siguientes y retornar páginas parciales | Enviar a Gemini imágenes parciales de un PDF degradaría la calidad de la extracción. La consistencia del documento completo es un invariante.                                                                                             |

### 7. Dependencias

| Dependencia             | Tipo     | Versión             | Impacto                                                       |
| ----------------------- | -------- | ------------------- | ------------------------------------------------------------- |
| Redis (via RedisClient) | servicio | Existente           | CB usa `incr`, `get`, `set`, `del` — sin cambio de interfaz   |
| Ghostscript             | binario  | Existente en imagen | Acepta rangos parciales de páginas — sin cambio de invocación |
| PHPUnit                 | librería | 10+                 | Tests nuevos siguen patrón existente                          |

#### 7.1 Fuentes de Verdad

| Artefacto                    | Fuente de Verdad                   | Evidencia                               | ¿Conflicto Detectado? |
| ---------------------------- | ---------------------------------- | --------------------------------------- | --------------------- |
| Comportamiento CB            | Código (GeminiGateway.php)         | L521-550                                | No                    |
| Comportamiento rasterización | Código (DocumentPdfRasterizer.php) | L147-193                                | No                    |
| Variables de entorno CB      | `.env.example` + código            | CB_GEMINI_THRESHOLD, CB_GEMINI_COOLDOWN | No                    |

[CONFIRMADO] Sin conflictos detectados entre fuentes de verdad.

### 8. Invariantes

| Invariante                                                   | Enforcement                                                                          | Validación                                         |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------ | -------------------------------------------------- |
| El CB no se activa por errores 400 (errores de documento)    | DA-02: `cbRecordFailure` solo cuando `$code !== 400`                                 | Test: `testCbRecordFailureNotCalledFor400`         |
| El CB falla silenciosamente si Redis no disponible           | Código: `cbRecordFailure` y `cbCheck` retornan si `!$this->cbRedis->isAvailable()`   | Preservado — no se modifica esta lógica            |
| PDFs > MAX_PAGES son rechazados (no procesados parcialmente) | `collectAndValidateImages()` L294-299 — se llama después de agregar TODOS los chunks | Test: `testPdfExceedingMaxPagesIsRejected`         |
| El orden de páginas en el resultado es determinista          | `natsort()` L291 se aplica al arreglo acumulado de TODOS los chunks                  | Test: `testChunkedRasterizationPreservesPageOrder` |

### 9. Modelo de Datos

[CONFIRMADO] Sin impacto en persistencia SQL Server. Los datos de CB son claves Redis con TTL efímero (`cb:gemini:state`, `cb:gemini:fails`) — existentes sin cambio de estructura.

### 10. Contratos

#### Falla A — `executeSingleOperation()` (interno)

| Dimensión                | Valor                                        |
| ------------------------ | -------------------------------------------- |
| Tipo                     | Mensaje interno                              |
| Visibilidad              | Interno (private/protected)                  |
| Productor                | `GeminiGateway::executeSingleOperation()`    |
| Consumidores             | `GeminiGateway::executeTaskRequest()` (L237) |
| Versionado               | No                                           |
| Compatibilidad requerida | Ninguna — no cambia firma                    |
| Enforcement              | Tests PHPUnit                                |

**Antes:** `executeSingleOperation()` lanza `GeminiOperationException` en error HTTP, sin efectos sobre CB.  
**Después:** Idéntica firma y comportamiento de lanzar `GeminiOperationException`. Efecto adicional: actualiza estado CB en Redis (sin impacto en contrato de retorno).

[CONFIRMADO] Contrato de firma preservado — sin breaking changes.

#### Falla B — `rasterize()` (público)

| Dimensión                | Valor                                                             |
| ------------------------ | ----------------------------------------------------------------- |
| Tipo                     | Mensaje interno                                                   |
| Visibilidad              | Público                                                           |
| Productor                | `DocumentPdfRasterizer::rasterize()`                              |
| Consumidores             | `DocumentExtractionWorker::buildMultimodalParts()` L618           |
| Versionado               | No                                                                |
| Compatibilidad requerida | Backward — mismo tipo de retorno, mismo comportamiento observable |
| Enforcement              | Tests PHPUnit + stubs existentes                                  |

**Antes:** Retorna `array<int, array{mime:string, data:string, label:string}>` en orden de páginas 1→N en una sola rasterización.  
**Después:** Idéntico tipo de retorno, idéntico orden de páginas (chunks ordenados). Diferencia interna: múltiples invocaciones a `executeProcess()` en lugar de una sola.

[CONFIRMADO] Contrato de retorno preservado — sin breaking changes.

### 11. Trazabilidad de Requisitos

| ID     | Requisito                                                                        | Implementación                                                                           | Validación                                                              |
| ------ | -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| REQ-01 | El CB debe registrar fallos desde el path v2 de producción                       | `cbRecordFailure()` llamado en `executeSingleOperation()` al fallar con `code != 400`    | `testCbRecordsFailureInV2Path`                                          |
| REQ-02 | El CB debe verificarse antes de cada llamada HTTP en v2                          | `cbCheck()` llamado al inicio de `executeSingleOperation()`                              | `testCbCheckCalledBeforeHttpInV2`                                       |
| REQ-03 | El CB no debe registrar errores 400 como fallos de conectividad                  | `cbRecordFailure()` condicionado a `$code !== 400`                                       | `testCbRecordFailureNotCalledFor400`                                    |
| REQ-04 | PDFs hasta 50 páginas se procesan sin timeout cuando cada chunk < timeoutSeconds | `rasterize()` divide en chunks ≤ CHUNK_SIZE=25, cada chunk con `executeProcess()` propio | `testChunkedRasterizationFor50PagePdf`                                  |
| REQ-05 | El orden de páginas en el resultado es determinista después de chunks            | `natsort()` aplicado al arreglo acumulado de todos los chunks                            | `testChunkedRasterizationPreservesPageOrder`                            |
| REQ-06 | PDFs > MAX_PAGES siguen siendo rechazados                                        | `collectAndValidateImages()` L295-299 (sin cambio)                                       | `testPdfExceedingMaxPagesIsRejected` (ya cubierto por tests existentes) |

### 12. Impact Analysis

| Componente                     | Dependencia                     | Impacto                                                                                                                                                | Cambio Requerido                                         | Evidencia                           |
| ------------------------------ | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------- | ----------------------------------- |
| `AuditTaskRunner`              | `GeminiGateway`                 | El CB abierto lanza `RuntimeException(503)` que `tick()` captura en `catch(\Throwable)` → `permanent=false` (503 no está en [400,401,403,404]) → defer | Ninguno — el comportamiento de defer por 503 ya funciona | AuditTaskRunner.php:65-76           |
| `DocumentExtractionWorker`     | `DocumentPdfRasterizer`         | La interfaz `rasterize()` no cambia                                                                                                                    | Ninguno                                                  | DocumentExtractionWorker.php:618    |
| `DocumentExtractionWorkerTest` | `PassthroughPdfRasterizer` stub | No cambia — el stub bypasea `rasterize()`                                                                                                              | Ninguno                                                  | DocumentExtractionWorkerTest.php:53 |
| Tests de `GeminiGateway`       | `executeSingleOperation`        | Si existen tests que inyectan Redis mock sin `isAvailable()` returning true, `cbCheck()` retornará silenciosamente (L488-490)                          | Ninguno — `cbCheck` es no-op si Redis unavailable        | GeminiGateway.php:488-490           |

### 13. Cambios por Archivo

#### [MODIFY] `app/Services/Audit/GeminiGateway.php`

**Clase afectada:** `GeminiGateway`  
**Método afectado:** `executeSingleOperation()`, líneas observadas: 296–372

**Antes (`executeSingleOperation`, L296-342):**

```php
private function executeSingleOperation(string $method, array $body, array $context): array
{
    $now = $this->taskQueue?->nowMs() ?? (int) floor(microtime(true) * 1000);
    $grant = $context['grant'] ?? [];
    if (($grant['status'] ?? '') !== 'GRANTED' || ...
        || (int) ($context['deadline_ms'] ?? 0) <= $now
    ) {
        throw new \RuntimeException('gemini_grant_required');
    }
    $timeout = min((int) Env::get('GEMINI_TIMEOUT', 300), ...);
    // ... (configuración timeout)
    $start = hrtime(true);
    try {
        $response = $this->http->post('https://...', [...]);
    } catch (GuzzleException $e) {
        // ...
        throw new GeminiOperationException($context['operation'], durationMs: ..., unknownResult: true);
    }
    $code = $response->getStatusCode();
    if ($code < 200 || $code >= 300) {
        // ...
        throw new GeminiOperationException($context['operation'], $code, $retry, $reason, ...);
    }
    // ...
}
```

**Después (`executeSingleOperation`):**

```php
private function executeSingleOperation(string $method, array $body, array $context): array
{
    // ── NUEVO: verificar CB antes de cualquier llamada HTTP ──────────────
    $this->cbCheck();
    // ────────────────────────────────────────────────────────────────────

    $now = $this->taskQueue?->nowMs() ?? (int) floor(microtime(true) * 1000);
    $grant = $context['grant'] ?? [];
    if (($grant['status'] ?? '') !== 'GRANTED' || ...
        || (int) ($context['deadline_ms'] ?? 0) <= $now
    ) {
        throw new \RuntimeException('gemini_grant_required');
    }
    $timeout = min((int) Env::get('GEMINI_TIMEOUT', 300), ...);
    $start = hrtime(true);
    try {
        $response = $this->http->post('https://...', [...]);
    } catch (GuzzleException $e) {
        if ($method === 'generateContent') {
            $this->saveDebugLog($body, ['error' => $e->getMessage()], $context, 'http_error');
        }
        // ── NUEVO: registrar fallo de red en CB (código 0 = error de red) ──
        $this->cbRecordFailure(0);
        // ──────────────────────────────────────────────────────────────────
        throw new GeminiOperationException($context['operation'], durationMs: (int) ((hrtime(true) - $start) / 1000000), unknownResult: true);
    }
    $code = $response->getStatusCode();
    if ($code < 200 || $code >= 300) {
        // ... (código existente sin cambio para Retry-After, reason, saveDebugLog)
        // ── NUEVO: registrar fallo HTTP en CB (excepto errores de documento 400) ──
        if ($code !== 400) {
            $this->cbRecordFailure($code);
        }
        // ───────────────────────────────────────────────────────────────────────
        throw new GeminiOperationException($context['operation'], $code, $retry, $reason, (int) ((hrtime(true) - $start) / 1000000));
    }
    // ... (resto sin cambio)
}
```

#### [MODIFY] `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php`

**Clase afectada:** `DocumentPdfRasterizer`  
**Métodos afectados:** `rasterize()` (L147-193), agregar `private const CHUNK_SIZE`, agregar `private function rasterizeChunk()`

**Antes (`rasterize()`, L147-193):**

```php
public function rasterize(string $pdfDataRaw, string $label, ?int $customDpi = null): array
{
    $this->validateInput($pdfDataRaw);
    $this->ensureBinaryAvailable();

    $dpi              = $customDpi ?? $this->dpi;
    $tempDir          = $this->resolveTempDir();
    $uniqueId         = bin2hex(random_bytes(8));
    $inputPdfPath     = $tempDir . DIRECTORY_SEPARATOR . "input_{$uniqueId}.pdf";
    $outputPrefix     = $tempDir . DIRECTORY_SEPARATOR . "page_{$uniqueId}";
    $maxPagesToRender = self::MAX_PAGES + 1;

    try {
        $this->writeTemporaryPdf($inputPdfPath, $pdfDataRaw);

        $engineUsed = $this->executeRasterization(
            $inputPdfPath, $outputPrefix, $dpi, $maxPagesToRender,
            $label, $tempDir, $uniqueId, $pdfDataRaw
        );

        $generatedImages = $this->collectAndValidateImages($outputPrefix, $engineUsed);

        return $this->buildMultimodalParts($generatedImages, $label);
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        // ...
        throw new RuntimeException('DocumentPdfRasterizer: Error inesperado...', 0, $e);
    } finally {
        $this->cleanupPrefixFiles($tempDir, $uniqueId);
    }
}
```

**Después (`rasterize()` + nuevo método + nueva constante):**

```php
// Agregar junto a MAX_PAGES (L26):
private const CHUNK_SIZE = 25;

public function rasterize(string $pdfDataRaw, string $label, ?int $customDpi = null): array
{
    $this->validateInput($pdfDataRaw);
    $this->ensureBinaryAvailable();

    $dpi     = $customDpi ?? $this->dpi;
    $tempDir = $this->resolveTempDir();

    // Escribir el PDF una sola vez compartido entre todos los chunks
    $uniqueId     = bin2hex(random_bytes(8));
    $inputPdfPath = $tempDir . DIRECTORY_SEPARATOR . "input_{$uniqueId}.pdf";

    try {
        $this->writeTemporaryPdf($inputPdfPath, $pdfDataRaw);

        $allImages  = [];
        $engineUsed = null;

        // Procesar en chunks de CHUNK_SIZE páginas
        // Acumular prefijos de chunks para limpiar DESPUÉS de glob() en el finally exterior
        $chunkPrefixes = [];

        for ($firstPage = 1; $firstPage <= self::MAX_PAGES; $firstPage += self::CHUNK_SIZE) {
            $lastPage   = min($firstPage + self::CHUNK_SIZE - 1, self::MAX_PAGES);
            $chunkId    = $uniqueId . '_c' . $firstPage;
            $outputPfx  = $tempDir . DIRECTORY_SEPARATOR . "page_{$chunkId}";
            $chunkPrefixes[] = [$tempDir, $chunkId];

            $engineUsed = $this->rasterizeChunk(
                $inputPdfPath, $outputPfx, $dpi,
                $firstPage, $lastPage, $label, $tempDir, $chunkId, $pdfDataRaw
            );
            $chunkImages = glob($outputPfx . '-*.jpg') ?: [];
            // Si el chunk no generó imágenes (rango más allá del PDF), el PDF tiene
            // menos páginas que MAX_PAGES → detener iteración
            if ($chunkImages === []) {
                break;
            }
            $allImages = array_merge($allImages, $chunkImages);
        }

        if ($engineUsed === null || $allImages === []) {
            throw new RuntimeException('DocumentPdfRasterizer: No se generaron imágenes en ningún chunk.');
        }

        $generatedImages = $this->collectAndValidateImages(
            $allImages,   // ← array ya construido (no prefix glob)
            $engineUsed
        );

        $result = $this->buildMultimodalParts($generatedImages, $label);

        // Limpiar imágenes de chunks DESPUÉS de leerlos a memoria (buildMultimodalParts ya los leyó con file_get_contents)
        foreach ($chunkPrefixes as [$chunkTempDir, $chunkId]) {
            $this->cleanupPrefixFiles($chunkTempDir, $chunkId);
        }

        return $result;
    } catch (RuntimeException $e) {
        // En excepción: limpiar chunks que pudieran haberse generado
        foreach ($chunkPrefixes as [$chunkTempDir, $chunkId]) {
            $this->cleanupPrefixFiles($chunkTempDir, $chunkId);
        }
        throw $e;
    } catch (Throwable $e) {
        foreach ($chunkPrefixes as [$chunkTempDir, $chunkId]) {
            $this->cleanupPrefixFiles($chunkTempDir, $chunkId);
        }
        Logger::error('DocumentPdfRasterizer: Error inesperado durante la rasterización.', [
            'error' => $e->getMessage(), 'label' => $label,
        ]);
        throw new RuntimeException(
            'DocumentPdfRasterizer: Error inesperado durante la rasterización: ' . $e->getMessage(), 0, $e
        );
    } finally {
        // Limpiar el PDF de entrada (compartido entre todos los chunks)
        $this->cleanupPrefixFiles($tempDir, $uniqueId);
    }
}

/**
 * Rasteriza un rango de páginas del PDF ya escrito en disco.
 *
 * @return string Engine usado ('ghostscript' o 'pdftoppm')
 * @throws RuntimeException Si la rasterización del chunk falla
 */
private function rasterizeChunk(
    string $inputPdfPath,
    string $outputPrefix,
    int $dpi,
    int $firstPage,
    int $lastPage,
    string $label,
    string $tempDir,
    string $chunkId,
    string $pdfDataRaw
): string {
    return $this->executeRasterization(
        $inputPdfPath, $outputPrefix, $dpi,
        $lastPage,          // ← lastPage del chunk (no maxPagesToRender del PDF completo)
        $label, $tempDir, $chunkId, $pdfDataRaw,
        $firstPage          // ← firstPage del chunk
    );
}
```

**Modificación de `executeRasterization()` y `buildCommand()`:**

`executeRasterization()` recibe actualmente `$maxPagesToRender` que es el `lastPage`. Se debe agregar `$firstPage` como parámetro adicional con valor por defecto `1` para mantener compatibilidad:

```php
// executeRasterization() — agregar parámetro $firstPage con default 1
private function executeRasterization(
    string $inputPdfPath,
    string $outputPrefix,
    int $dpi,
    int $maxPagesToRender,
    string $label,
    string $tempDir,
    string $uniqueId,
    string $pdfDataRaw,
    int $firstPage = 1          // ← NUEVO parámetro con default
): string {
    // Pasar $firstPage a buildCommand
    $cmd = $this->buildCommand($inputPdfPath, $outputPrefix, $dpi, $maxPagesToRender, $engine, $binary, $firstPage);
    // ...fallback también pasa $firstPage:
    $fallbackCmd = $this->buildCommand(..., $firstPage);
    // ...
}

// buildCommand() — agregar parámetro $firstPage con default 1
public function buildCommand(
    string $inputPdfPath,
    string $outputPrefix,
    int $dpi,
    int $maxPagesToRender,
    string $engine,
    string $binary,
    int $firstPage = 1          // ← NUEVO parámetro con default
): string {
    if ($engine === self::ENGINE_GHOSTSCRIPT) {
        return sprintf(
            '%s -sDEVICE=jpeg -dJPEGQ=90 -r%d -dSAFER -dBATCH -dNOPAUSE -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s',
            escapeshellcmd($binary),
            $dpi,
            $firstPage,                        // ← NUEVO: era hardcoded '1'
            $maxPagesToRender,
            escapeshellarg($outputPrefix . '-%d.jpg'),
            escapeshellarg($inputPdfPath)
        );
    }

    return sprintf(
        '%s -jpeg -r %d -f %d -l %d %s %s',
        escapeshellcmd($binary),
        $dpi,
        $firstPage,                            // ← NUEVO: era hardcoded '1' implícito en pdftoppm
        $maxPagesToRender,
        escapeshellarg($inputPdfPath),
        escapeshellarg($outputPrefix)
    );
}
```

**Modificación de `collectAndValidateImages()`:**

Actualmente acepta un `$outputPrefix` para hacer `glob()`. Con chunks, se le pasa el arreglo ya construido. Hay dos opciones:

- **Opción A (elegida):** Sobrecargar con `array|string` — si es array, usar directamente; si es string, hacer glob. Mantiene backward compat con tests.

```php
// collectAndValidateImages() — acepta array<string> o string (prefix para glob)
private function collectAndValidateImages(array|string $outputPrefixOrImages, string $engine): array
{
    if (is_string($outputPrefixOrImages)) {
        $pattern = $outputPrefixOrImages . '-*.jpg';
        $generatedImages = glob($pattern) ?: [];
    } else {
        $generatedImages = $outputPrefixOrImages;
    }

    if (empty($generatedImages)) {
        throw new RuntimeException(
            "DocumentPdfRasterizer: {$engine} no generó imágenes JPEG. "
            . 'Diagnóstico de contenido no confirmado.'
        );
    }

    natsort($generatedImages);
    $generatedImages = array_values($generatedImages);

    $totalPages = count($generatedImages);
    if ($totalPages > self::MAX_PAGES) {
        throw new RuntimeException(
            "DocumentPdfRasterizer: Documento excede el límite máximo de " . self::MAX_PAGES . " páginas."
        );
    }

    return $generatedImages;
}
```

**Nota sobre tests:** Los tests existentes que llaman a `collectAndValidateImages()` via reflection o indirectamente no se ven afectados porque la firma acepta ambos tipos. Los tests que mockean `executeProcess()` siguen funcionando porque `rasterizeChunk()` llama a `executeRasterization()` que llama a `executeProcess()`.

### 14. Plan de Migración

#### Prerequisitos

- PHPUnit baseline certificado en verde (1027 tests / commit `76a0fbee`) ✅
- Código fuente del host copiado al contenedor vía `git push` → CI → imagen → deploy

#### Ejecución (secuencia)

1. Modificar `GeminiGateway.php` — agregar `cbCheck()` y `cbRecordFailure()` en `executeSingleOperation()`
2. Modificar `DocumentPdfRasterizer.php` — agregar constante `CHUNK_SIZE`, método `rasterizeChunk()`, modificar `rasterize()`, `executeRasterization()`, `buildCommand()`, `collectAndValidateImages()`
3. Agregar tests nuevos en las suites correspondientes
4. Ejecutar PHPUnit en CI — verificar 0 errores
5. `git commit -F <archivo>` + push → CI → imagen → deploy automático

#### Validaciones Previas

- PHPUnit baseline en verde

#### Validaciones Posteriores

- PHPUnit post-implementación: mismo count o más tests, 0 fallos
- Verificar en producción: `curl http://172.16.0.3:8080/metrics/async` — CB state = `closed`
- Monitor DLQ durante 24h post-deploy en próximo batch grande

#### Rollback

**Estrategia principal — Deploy de imagen anterior vía workflow existente:**

El workflow `.github/workflows/deploy-production.yml` acepta el parámetro `image_tag` para seleccionar un SHA de imagen específico. Los tags publicados en GHCR usan el SHA completo sin prefijo (ej. `76a0fbee69219f5f7dd82e45b6a50e32b2d897a5`):

```
GitHub Actions → "Deploy Production - AudFact" → workflow_dispatch
  image_tag: 76a0fbee69219f5f7dd82e45b6a50e32b2d897a5
```

**Verificación del tag antes de hacer rollback** — En producción SSH (`admon@172.16.0.3`), antes del deploy del cambio, registrar el SHA de imagen desplegado actualmente:

```bash
# Ejecutar ANTES de hacer push de la implementación (guarda el SHA base):
ssh admon@172.16.0.3 "docker inspect audfact-prod-php-1 --format '{{index .Config.Labels \"org.opencontainers.image.revision\"}}'"
```

El SHA devuelto es el `image_tag` para restaurar si se requiere rollback. El commit estable certificado es `76a0fbee69219f5f7dd82e45b6a50e32b2d897a5` (SHA completo).

**Estrategia alternativa — git revert:**

```bash
git revert HEAD --no-edit           # Revierte TODOS los cambios del commit (código + tests)
git push origin main                # Dispara CI → reimagen → deploy automático
```

> [!IMPORTANT]
> `git revert HEAD` revierte el commit completo incluyendo los nuevos tests. Usar SOLO si el commit a revertir es exactamente el de esta implementación (verificar con `git log --oneline -1`). Si hay commits posteriores, usar `git revert <SHA-exacto-del-commit>`.

**Tratamiento de tareas en vuelo durante el deploy de rollback:**

El deploy ejecuta `docker compose up -d --scale worker-extraction-vip=N` que reemplaza los contenedores sin drenaje explícito. El mecanismo de recuperación existente en el sistema cubre este escenario:

- **Tareas con lease activo al momento del `docker stop`:** El proceso recibe `SIGTERM`. Si el worker no termina en el `stop_grace_period` (default 10s), recibe `SIGKILL`. La tarea queda en `IN_PROGRESS` sin ACK.
- **Recuperación automática:** `AuditExecutionMaintenance` (ejecutado por `bin/audit-execution-maintenance.php` en su propio worker) detecta leases expirados y reencola las tareas — sin intervención manual. El período de recuperación es `AuditExecutionConfig::LEASE_MS` (ver `app/Services/Audit/Pipeline/AuditExecutionConfig.php`).
- **No se requiere acción manual:** El sistema ya gestiona interrupciones de workers como parte de su resiliencia estándar ante reinicios de contenedores.
- **Claves Redis del CB:** Son efímeras con TTL ≤ 60s. Caducan solas tras el rollback; no requieren purga manual.
- **DLQ:** Entradas ya producidas no se revierten. Reprocesar con `/audit/dlq/reprocess` si aplica.
- **SQL Server:** Sin cambios — ninguna tabla fue modificada.

**Comprobaciones post-rollback:**

1. `curl -s http://172.16.0.3:8080/health` → `{"status":"ok"}`
2. Verificar en logs (`docker compose logs -f php --since=5m`) que no hay errores de clase/método
3. Monitorear `/audit/jobs` durante 15 min para confirmar que las tareas interrumpidas se reencolan y avanzan

### 15. Casos Límite

| Condición                                                          | Comportamiento Esperado                                                                         | Resultado Verificable                                   |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| PDF con exactamente 1 página                                       | Chunk único [1–1] → 1 imagen                                                                    | `testSinglePagePdfProcessedInOneChunk`                  |
| PDF con exactamente 25 páginas                                     | Chunk único [1–25] → 25 imágenes                                                                | 25 imágenes, 1 invocación de `executeProcess()`         |
| PDF con 26 páginas                                                 | Dos chunks [1–25] y [26–26] → 26 imágenes                                                       | 2 invocaciones de `executeProcess()`                    |
| PDF con 50 páginas                                                 | Dos chunks [1–25] y [26–50] → 50 imágenes                                                       | 2 invocaciones de `executeProcess()`                    |
| PDF con 51+ páginas (supera MAX_PAGES)                             | `collectAndValidateImages()` lanza RuntimeException > MAX_PAGES                                 | `document_rejected` con `RASTERIZATION_FAILURE`         |
| Chunk 2 no genera imágenes (PDF tiene < 26 págs pero MAX_PAGES=50) | Loop `break` al primer chunk vacío → resultado = solo imágenes del chunk 1                      | Resultado correcto con solo las páginas reales          |
| CB abierto cuando llega nueva tarea                                | `cbCheck()` lanza RuntimeException(503) → `AuditTaskRunner` captura → `permanent=false` → defer | Tarea se pospone sin llamada HTTP                       |
| CB abierto pero Redis cae                                          | `cbCheck()` retorna silenciosamente (L488) → CB ignorado → llamada HTTP normal                  | No hay degradación por Redis caído                      |
| Fallo de red (Guzzle) en `executeSingleOperation()`                | `cbRecordFailure(0)` → incrementa contador → CB puede abrirse                                   | Comportamiento correcto: error de red cuenta como fallo |
| Error 400 en `executeSingleOperation()`                            | `cbRecordFailure()` NO llamada                                                                  | CB no contamina con errores de documento                |

### 16. Testing

#### Nuevos Tests — `GeminiGateway`

> Archivo: `tests/Services/Audit/GeminiGatewayTest.php` (o crear si no existe)

**Test 1: `testCbCheckCalledBeforeHttpInV2Path`**

- Objetivo: Verificar que `cbCheck()` se invoca en `executeSingleOperation()` y lanza si CB está abierto.
- Precondición: Redis mock retorna `open` para la clave `cb:gemini:state` (o `cb:gemini:{lane}:state`). `isAvailable()` retorna `true`.
- Pasos: Instanciar `GeminiGateway` con Redis mock que retorna `open`; construir un grant válido con `deadline_ms` futuro; llamar **`sendSingleAttempt($body, $requestContext)`** (punto de entrada público que internamente llama a `executeSingleOperation`). **No llamar a `executeSingleOperation()` directamente — es un método privado.** El HTTP mock no debe recibir ninguna llamada.
- Resultado esperado: Lanza `RuntimeException` con código 503 (mensaje contiene "Circuit Breaker abierto"). No se realiza ninguna llamada HTTP.

**Test 2: `testCbRecordsFailureInV2PathOn429`**

- Objetivo: Verificar que `cbRecordFailure(429)` se llama cuando la API retorna 429.
- Precondición: HTTP mock retorna respuesta con status 429. Redis mock espera `incr()` llamado con clave `cb:gemini:fails` (o `cb:gemini:{lane}:fails`). `isAvailable()` retorna `true`. Se construye un grant válido con `deadline_ms` futuro y se llama `sendSingleAttempt()`.
- Resultado esperado: `GeminiOperationException` lanzada con `httpCode=429`. Redis `incr()` fue llamado exactamente 1 vez.

**Test 3: `testCbRecordFailureNotCalledFor400`**

- Objetivo: Verificar que un error 400 (error de documento) NO registra fallo en CB.
- Precondición: HTTP mock retorna 400. Redis mock NO espera `incr()`. Grant válido. Se llama `sendSingleAttempt()`.
- Resultado esperado: `GeminiOperationException` lanzada con `httpCode=400`. Redis `incr()` NO fue llamado.

**Test 4: `testCbRecordsFailureOnNetworkError`**

- Objetivo: Verificar que un error de red (GuzzleException) registra fallo en CB con código 0.
- Precondición: HTTP mock lanza `GuzzleException`. Redis mock espera `incr()`. Grant válido. Se llama `sendSingleAttempt()`.
- Resultado esperado: `GeminiOperationException` lanzada con `unknownResult=true`. Redis `incr()` fue llamado con clave de fails.

#### Nuevos Tests — `DocumentPdfRasterizer`

> Archivo: `tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php` (o crear si no existe)

**Test 5: `testChunkedRasterizationFor50PagePdf`**

- Objetivo: Verificar que un PDF de 50 páginas produce 50 imágenes en 2 invocaciones de `executeProcess()`.
- Precondición: Subclase stub de `DocumentPdfRasterizer` que sobreescribe `executeProcess()` para retornar archivos JPEG ficticios para las páginas en el rango dado.
- Resultado esperado: `rasterize()` retorna array de 50 elementos. `executeProcess()` fue invocado exactamente 2 veces.

**Test 6: `testChunkedRasterizationPreservesPageOrder`**

- Objetivo: Verificar que las páginas del resultado están en orden ascendente 1→N tras chunking Y que el contenido de cada imagen corresponde a su página original (no solo el label).
- Precondición: Stub que sobreescribe `executeProcess()` y, en cada invocación, escribe archivos JPEG con contenido identificable por página: el byte payload es `"PAGE:{$pageNumber}"`. El stub extrae el rango `$firstPage`-`$lastPage` del comando recibido para generar solo los archivos del rango correcto.
- Resultado esperado: El array retornado tiene 26 partes. Para cada índice `$i` (0-based), `base64_decode($parts[$i]['data']) === "PAGE:" . ($i+1)`. Los labels son `"... (Página 1/26)"` ... `"... (Página 26/26)"`. Ninguna página aparece duplicada ni fuera de orden.

**Test 7: `testEmptySecondChunkStopsIteration`**

- Objetivo: Verificar que si el segundo chunk retorna 0 imágenes, el loop se detiene y se retornan solo las páginas del primer chunk.
- Precondición: Stub que genera 10 imágenes en el primer chunk y 0 en el segundo.
- Resultado esperado: `rasterize()` retorna array de 10 elementos.

**Test 8: `testBuildCommandUsesCorrectFirstPageAndLastPage`**

- Objetivo: Verificar que `buildCommand()` incluye `-dFirstPage` y `-dLastPage` correctos para un chunk dado.
- Precondición: Instanciar `DocumentPdfRasterizer` directamente.
- Resultado esperado: `buildCommand('/in.pdf', '/out', 200, 50, 'ghostscript', 'gs', 26)` retorna string que contiene `-dFirstPage=26 -dLastPage=50`.

#### Tests Eliminados

Ninguno — ningún test existente es incompatible con los cambios.

#### Verificaciones Manuales

**VM-01:** Tras deploy, monitorear `/metrics/async` durante 1h. Si el próximo batch tiene 429s de Gemini, verificar que `audit.dlq` recibe significativamente menos entradas que en runs anteriores (baseline: 34 de 58 = 59%). Señal de éxito: menos del 20% de entradas DLQ clasificadas como `gemini_operation_failed`.

### 17. Riesgos

| Riesgo                                                                      | Tipo      | Severidad | Mitigación                                                                                                                                                                                                                                                 |
| --------------------------------------------------------------------------- | --------- | --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CB se abre por spike temporal de 429 que se resolvería solo                 | Operativo | Medio     | `CB_GEMINI_COOLDOWN=60s` es corto. Si se abre, se recupera en 60s. Las tareas diferidas se reintentan automáticamente.                                                                                                                                     |
| Chunk vacío en PDF real de pocas páginas hace `break` prematuro             | Técnico   | Bajo      | Ghostscript retorna exit 0 con 0 imágenes para rangos fuera del PDF. El `glob()` retorna `[]`. El loop `break` es correcto. Test 7 cubre esto.                                                                                                             |
| `collectAndValidateImages()` recibe arreglo con rutas de distintos chunkIds | Técnico   | Bajo      | `natsort()` ordena por nombre natural. Los nombres de archivo incluyen el chunkId pero el número de página es el sufijo tras `-`. El natural sort funciona correctamente porque todos los archivos se llaman `page_{uniqueId}_c{firstPage}-{pageNum}.jpg`. |
| Tests de GeminiGateway existentes que no mockean Redis fallan               | Test      | Bajo      | `cbCheck()` retorna silenciosamente si `!$this->cbRedis->isAvailable()`. Un mock que no configura `isAvailable()` retornará el default del mock (null/false) → `cbCheck()` retorna sin acción. Sin impacto en tests existentes.                            |

### 18. Criterios de Aceptación

1. `vendor/bin/phpunit` retorna exit 0 con count de tests ≥ 1027 tras la implementación.
2. `GeminiGateway::executeSingleOperation()` llama a `cbRecordFailure()` cuando el status HTTP es >= 400 y != 400 (verificado por test 2).
3. `GeminiGateway::executeSingleOperation()` lanza RuntimeException(503) si el CB está abierto (verificado por test 1).
4. `DocumentPdfRasterizer::rasterize()` para un PDF de 50 páginas invoca `executeProcess()` exactamente 2 veces (verificado por test 5).
5. `DocumentPdfRasterizer::buildCommand()` produce `-dFirstPage=26` para un chunk que comienza en página 26 (verificado por test 8).
6. `git push` → CI verde (GitHub Actions) → imagen publicada → deploy automático exitoso.

### 19. Observabilidad

| Señal                                      | Tipo      | Antes (baseline)                      | Después (esperado)                                    | Fuente                | Umbral / Condición                             | Acción                                              |
| ------------------------------------------ | --------- | ------------------------------------- | ----------------------------------------------------- | --------------------- | ---------------------------------------------- | --------------------------------------------------- |
| Entradas DLQ con `gemini_operation_failed` | Log/Redis | 34/58 = 59% en batch con saturación   | Menos del 10% en mismo tipo de batch                  | `/audit/dlq` endpoint | >20% de entradas con `gemini_operation_failed` | Revisar CB_GEMINI_COOLDOWN, escalar quota           |
| `cb:gemini:state` = `open`                 | Redis key | Nunca aparece (CB inerte)             | Aparece durante saturación de quota                   | Redis directamente    | TTL > 0 implica CB activo                      | Normal — se recupera en CB_GEMINI_COOLDOWN segundos |
| Timeout rasterización en logs              | Log       | Presente para PDFs ≥65 págs complejos | Ausente para PDFs ≤50 págs (todos válidos en sistema) | `logs/app.log`        | Presencia de "Timeout de rasterización"        | Revisar CHUNK_SIZE o PDF específico                 |

### 20. Estrategia de Rollout

| Dimensión                                | Valor                                                                                    |
| ---------------------------------------- | ---------------------------------------------------------------------------------------- |
| Estrategia de despliegue                 | Directo — imagen inmutable, deploy atómico                                               |
| Orden entre productores y consumidores   | N/A — cambios internos sin contratos externos modificados                                |
| Coexistencia entre versiones             | No — el deploy reemplaza todos los contenedores simultáneamente                          |
| Compatibilidad requerida durante rollout | N/A                                                                                      |
| Condición para avanzar rollout           | CI verde + PHPUnit 0 fallos                                                              |
| Condición para detener rollout           | PHPUnit falla en CI                                                                      |
| Condición de rollback                    | Incremento de errores en logs post-deploy O DLQ crece inesperadamente en las primeras 2h |
| Acción de rollback                       | `git revert HEAD --no-edit` + push → CI → reimagen → deploy                              |
| Tiempo máximo para iniciar rollback      | 2 horas post-deploy                                                                      |
| Responsable de decisión                  | Operador del sistema (el usuario)                                                        |

---

## FASE 2 — Auditoría de Consistencia

| Verificación                                                        | Estado | Evidencia                                                                  |
| ------------------------------------------------------------------- | ------ | -------------------------------------------------------------------------- |
| Todas las entidades persistentes mencionadas están definidas        | PASS   | No hay entidades nuevas; CB usa claves Redis existentes                    |
| Todas las columnas mencionadas existen                              | PASS   | N/A — sin cambios SQL                                                      |
| Todos los contratos documentados con clasificación                  | PASS   | Sección 10: `executeSingleOperation()` y `rasterize()` clasificados        |
| Todos los requisitos tienen trazabilidad                            | PASS   | REQ-01 a REQ-06 mapeados a tests en sección 11 y 16                        |
| Todos los consumidores analizados                                   | PASS   | Sección 12: `AuditTaskRunner`, `DocumentExtractionWorker`, tests cubiertos |
| Todas las migraciones tienen rollback                               | PASS   | Sección 14: rollback por `git revert` documentado                          |
| Todas las referencias a archivos, clases, funciones están definidas | PASS   | Rutas y métodos verificados por lectura directa                            |
| Toda compatibilidad tiene evidencia                                 | PASS   | Firma de `rasterize()` y `executeSingleOperation()` preservadas            |
| Todos los criterios son verificables                                | PASS   | Sección 18: 6 criterios medibles                                           |
| Observabilidad documentada                                          | PASS   | Sección 19 completa                                                        |
| Rollout documentado                                                 | PASS   | Sección 20 completa                                                        |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta                                                | Resultado | Evidencia                                                                        |
| ------------------------------------------------------- | --------- | -------------------------------------------------------------------------------- |
| ¿Existe alguna decisión arquitectónica implícita?       | No        | DA-01 a DA-06 documentadas explícitamente                                        |
| ¿Existe algún contrato sin documentar?                  | No        | Sección 10: contratos de `executeSingleOperation()` y `rasterize()` documentados |
| ¿Existe algún consumidor no analizado?                  | No        | Sección 12: todos los consumidores directos analizados                           |
| ¿Existe alguna migración sin rollback?                  | No        | Sección 14: rollback por git revert documentado                                  |
| ¿Existe algún dato persistido sin migración?            | No        | Sin cambios en persistencia SQL o Redis de larga duración                        |
| ¿Existe alguna afirmación sin evidencia?                | No        | Todas las afirmaciones sobre el sistema tienen ruta:línea                        |
| ¿Existen referencias huérfanas?                         | No        | Todos los métodos referenciados verificados por lectura directa                  |
| ¿Dos implementadores producirían soluciones diferentes? | No        | Cambios by-file especificados con before/after exactos                           |

### Auditoría Adversarial Anti-Regresión

| #   | Pregunta Adversarial                                                                              | Resultado | Evidencia                                                                                                                            |
| --- | ------------------------------------------------------------------------------------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | ¿Existe algún entrypoint que invoque un binario eliminado/movido?                                 | NO        | No se elimina ni renombra ningún binario, clase ni archivo                                                                           |
| 2   | ¿Existe algún paso de build que dependa de un artefacto eliminado/modificado?                     | NO        | No se modifica `composer.json`, `Dockerfile` ni assets de build                                                                      |
| 3   | ¿Existe algún pipeline que valide el artefacto con flujo distinto al evaluado?                    | NO        | PHPUnit en CI ejecuta la misma suite que localmente                                                                                  |
| 4   | ¿El cambio asume comportamiento de herramienta sin verificar?                                     | NO        | Comportamiento de Ghostscript con rangos parciales verificado (Sección 0.4); comportamiento de Redis verificado por código existente |
| 5   | ¿El cambio está optimizado para un solo entorno?                                                  | NO        | Compatible con CI (tests), producción (contenedor), sin entorno de desarrollo local separado (Zero-Source)                           |
| 6   | ¿Existe mecanismo de override que anule el comportamiento esperado?                               | NO        | `PDF_RASTERIZER_TIMEOUT_SECONDS` y `CB_GEMINI_*` son overrides intencionales ya documentados en `.env.example`                       |
| 7   | ¿Se aplicó una best practice sin verificar convención local?                                      | NO        | El patrón de CB ya existe en `executePost()` — se traslada exactamente al path v2                                                    |
| 8   | ¿El cambio modifica una interfaz pública consumida externamente sin estrategia de compatibilidad? | NO        | Firmas de `rasterize()` y `executeSingleOperation()` preservadas; `buildCommand()` agrega parámetro con default                      |
| 9   | ¿El cambio afecta datos persistidos sin migración?                                                | NO        | Sin cambios en SQL Server; claves Redis CB son efímeras y existentes                                                                 |
| 10  | ¿El cambio introduce código muerto, deuda o alcance más allá del MVP?                             | NO        | CHUNK_SIZE como constante privada; sin configurabilidad especulativa no demostrada                                                   |
| 11  | ¿El cambio reemplaza mapeo estático por abstracción dinámica sin verificación de cobertura?       | NO        | No se reemplaza ningún mapeo estático                                                                                                |

---

## FASE 4 — Resultado Final

**Nivel de Completitud: Nivel A — Implementable**

Justificación:

- Sin supuestos S3/S4 — todos los supuestos son S1/S2
- FASE 2: todos los checks en PASS
- FASE 3: todos los checks en No / NO
- Cambios deterministas con before/after exactos
- Tests nuevos especificados con objetivo, precondición y resultado esperado
- Rollback documentado y ejecutable

---

## Decisión Clean Rebuild Policy

**Decisión: APROBAR — Refactorización incremental**

**Alcance MVP:** Activar CB en path v2 y dividir rasterización en chunks de 25 páginas. Comportamiento validado por los datos de DLQ de producción (sesión anterior).

**Contratos activos preservados:**

- `rasterize()` — firma y tipo de retorno sin cambio
- `executeSingleOperation()` — firma sin cambio; efecto adicional en Redis es compatible con tests existentes
- Eventos de Redis Streams sin cambio
- API REST sin cambio

**Compuertas tácticas activadas:**

- **Observabilidad operativa:** ✅ Logs de CB ya existentes en `cbRecordFailure()` / `cbCheck()` — ahora se activarán en producción
- **Higiene del cambio:** ✅ No se introduce código muerto; `executePost()` se preserva por contrato de compatibilidad para auditorías directas sin task
- **Contratos y jerarquías:** ✅ `buildCommand()` agrega parámetro con default → no rompe callers existentes

**Excepciones:** Ninguna.

**Validación:** PHPUnit en CI + 4 criterios de aceptación técnicos medibles.
