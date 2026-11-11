# SDD: Clean Code sobre el cambio de resiliencia del pipeline de descarga

- **Ruta:** `plans/sdd-clean-code-download-resilience.md`
- **Fecha:** 10 de noviembre de 2026
- **Spec padre:** [`sdd-download-pipeline-resilience.md`](./sdd-download-pipeline-resilience.md)
- **Estado:** Aprobada e implementada (certificada en verde)
- **Tipo:** Refactorización incremental sin cambio funcional (`clean-rebuild-policy` → *Refactorización incremental*)

## 0. Triage

| Dimensión | Valor | Justificación |
| --- | --- | --- |
| Tipo | Refactor de legibilidad e higiene | Se reorganiza el diff aún **no commiteado** de la spec padre. No cambia comportamiento observable. |
| Riesgo | Bajo-Medio | Toca autenticación OAuth y clasificación de fallos; el riesgo queda acotado por la suite existente y nuevas pruebas. |
| Persistencia | No | Sin cambios de SQL ni tablas. |
| Contrato externo | No | `physical_attachment` todavía no está desplegado (diff local); se elimina `BlobSize` porque ningún consumidor lo lee. |
| Producción | No (todavía) | El cambio padre aún no se ha desplegado. |

## 1. Alcance MVP (comportamiento a preservar)

Fuente: spec padre + suites `tests/Unit/**`, `tests/Services/Audit/**`.

1. Token OAuth compartido en Redis (`audit:oauth:google_drive_token`), mutex `SET NX EX 10` (`audit:oauth:google_drive_lock`), espera de 10 × 300 ms, TTL Redis `max(60, expires_in - 300)`, reutilización solo con TTL > 30 s, degradación a memoria si Redis falla.
2. 401 de Drive → purga token; 404 → `SOURCE_NOT_FOUND` (terminal); resto → `EXTERNAL_TRANSFER_FAILED` (reintentable).
3. Metadata precalculada evita la consulta SQL redundante.
4. Matcher prioriza fila con contenido (`URL`/`BLOB`) como canónica; conflicto solo por identidad documental.
5. `Autorizacion` normalizada (`trim`+`strtoupper`); `N` elimina campos de autorización y reconstruye `extraction_contract`; `R` convierte `MISSING` o `NO_CONTENT` en hallazgo `AUT`.

## 2. Hallazgos y cambios

| ID | Archivo | Hallazgo | Cambio |
| --- | --- | --- | --- |
| CC-01 | `GoogleDriveAuthService` | `getAccessToken()` de ~120 líneas; lectura de token Redis duplicada (paso 2 y bucle); `redis !== null && isAvailable()` repetido 4 veces; números mágicos (`10`, `300000`, `300`, `60`, `3590`); Lua inline. | Extraer `hasValidMemoryToken()`, `sharedCacheAvailable()`, `fetchSharedToken()` (**lanza**), `readSharedToken()` (registra `Error consultando token OAuth en Redis` y devuelve `null`), `awaitConcurrentRefresh()`, `requestNewToken()`, `storeSharedToken()`, `releaseRefreshLock()`, `resolveDefaultRedis()`. Constantes privadas con nombre. Mismo orden y número de llamadas Redis. **Reglas de error obligatorias (ver §2.1).** |
| CC-02 | `AttachmentDownloadService` | `extractHttpStatusCode()` usa `method_exists` duplicado y un fallback a `getCode()` que solo existe para que los tests pasen con `RuntimeException(…, 404)`; en producción Drive lanza `GuzzleHttp\Exception\RequestException` (`http_errors` por defecto) y el resto de excepciones de la ruta tiene código 0. | Leer el status **solo** desde `RequestException::getResponse()`. Constantes `HTTP_UNAUTHORIZED`/`HTTP_NOT_FOUND`. Tests usan `ClientException` reales. |
| CC-03 | `AttachmentDownloadService` | `?? storage_type` es inalcanzable (si falta `TipoAlmacenamiento` se reconsulta SQL, que siempre lo trae). `?? physical_document_name` **sí es alcanzable** en la ruta SQL cuando `AdjDisNom` es NULL, pero es incorrecto: es el nombre del catálogo (`NitMedDocNom`), nunca tiene extensión y solo añade ruido a `mimeFromName()`; retirarlo restaura el comportamiento de `HEAD` (cae a `detectMimeFromContent`). `unset($raw)` antes de `return` no reduce el pico de memoria (el pico ocurre durante `base64_encode`). | Eliminar ambos fallbacks y los `unset`. Extraer `resolveAttachmentRow()` y accesor perezoso `driveAuth()`. **Reglas de error obligatorias (ver §2.2).** |
| CC-04 | Pipeline | Literales `'URL'`/`'BLOB'` controlan el flujo en matcher y servicio de descarga (conjunto cerrado definido por el `CASE` SQL de `AttachmentsModel`). | Nuevo enum respaldado `AttachmentStorageType: string` con **solo** `Url = 'URL'` y `Blob = 'BLOB'` (los únicos valores que deciden flujo; `SIN_DOCUMENTOS` y cualquier valor desconocido se representan con `null`). Único método: `fromTransport(mixed $raw): ?self` = `tryFrom(strtoupper(trim((string) $raw)))`. `AttachmentsController` queda fuera de alcance. |
| CC-05 | `DocumentAttachmentMatcher` | Bucle inline de selección canónica. | Extraer `selectCanonicalRow()`; `rowHasContent()` = `AttachmentStorageType::fromTransport($row['storage_type'] ?? null) !== null`. |
| CC-06 | `DocumentAuditOrchestrator` | Normalización de `Autorizacion` duplicada; literales `'R'`, `'N'`, `'S'`, `'AUTORIZACION'`-campos; array local `$allowedReasons`; variable `$oldCount`; `visual_checks ?? []` innecesario; metadata de descarga inline con fallback `TipoAlmacenamiento ?? storage_type` y campo `BlobSize` sin consumidores. | `authorizationMode()` + constantes `AUTHORIZATION_*`; `AUTHORIZATION_FIELD_NAMES`; `AUTHORIZATION_SYNTHETIC_REASONS`; `withoutAuthorizationFields()`; `downloadSourceMetadata()` construida desde `storage_type` (columna normalizada del matcher) sin `BlobSize`. |
| CC-07 | `AttachmentDownloadWorker` | `isset(...) && is_array(...)` verboso. | `is_array($payload['physical_attachment'] ?? null)`. |

### 2.1 Reglas de error de `GoogleDriveAuthService::getAccessToken()` (preservan `HEAD` del diff)

1. **Paso 2 (lectura inicial):** usa `readSharedToken()`; ante excepción registra `Error consultando token OAuth en Redis` y continúa al lock.
2. **Lock + espera:** `setnx` y **todo** el bucle `awaitConcurrentRefresh()` se ejecutan dentro de **un único** `try`. El bucle llama a `fetchSharedToken()` (que **lanza**, no registra). Una excepción en `setnx` o en cualquier iteración corta la espera de inmediato, registra una sola vez `Error gestionando lock OAuth en Redis` y pasa directamente a OAuth (sin iteraciones ni `get` adicionales).
3. Si la espera agota los 10 intentos sin token, registra `OAuth lock wait timeout, proceeding to direct token request` y solicita token.
4. Liberación del lock en `finally` solo si este proceso lo adquirió; error de liberación → `Error liberando lock OAuth en Redis`.
5. Fallo al persistir el token → `Error guardando token OAuth en Redis`; no interrumpe la respuesta.

### 2.2 Reglas de error de `AttachmentDownloadService::downloadFromDrive()`

1. El accesor perezoso `driveAuth()` (`$this->driveAuth ??= new GoogleDriveAuthService(redis: $this->redis)`) se invoca **dentro** del `try`, de modo que un fallo de construcción (p. ej. credenciales ausentes) se sigue clasificando como `EXTERNAL_TRANSFER_FAILED`.
2. En el `catch`, la purga por 401 usa la **propiedad** `$this->driveAuth?->clearCachedToken()`, nunca el accesor (evita instanciar un servicio nuevo dentro del `catch` y escapar sin clasificar).
3. El mensaje `SOURCE_EMPTY` de `download()` sigue interpolando el string normalizado original: `"Adjunto '{id}' sin contenido (TipoAlmacenamiento='{valor}')"`, donde `{valor}` = `strtoupper(trim((string) $row['TipoAlmacenamiento']))`.

## 3. Contratos

- `physical_attachment` en `document_registered`: claves `TipoAlmacenamiento`, `AdjDisDocUrl`, `AdjDisNom`. Se retira `BlobSize` (no leído por `AttachmentDownloadService`). Sin migración: el campo no está en producción.
- Firmas públicas sin cambios: `GoogleDriveAuthService::__construct(?Client, ?RedisClient)`, `clearCachedToken()`, `AttachmentDownloadService::__construct(...)`, `download(string, string, ?array)`, `AttachmentDownloadException::retryable()`.

## 4. Pruebas

| ID | Prueba | Cambio |
| --- | --- | --- |
| T-01 | `tests/Unit/Services/Audit/Pipeline/AttachmentDownloadServiceTest.php` (casos 401/403/404) | Lanzar `GuzzleHttp\Exception\ClientException` con `GuzzleHttp\Psr7\Request`/`Response` reales. |
| T-02 | `tests/Unit/Services/Audit/Pipeline/AttachmentDownloadServiceTest.php` | Nuevo: `RuntimeException('...', 404)` (no HTTP) → `EXTERNAL_TRANSFER_FAILED` y **sin** `clearCachedToken()` (prueba retiro del fallback). |
| T-03 | `tests/Unit/Services/Audit/Pipeline/AttachmentStorageTypeTest.php` | Nuevo: `' url '`→`Url`, `'blob'`→`Blob`, `'SIN_DOCUMENTOS'`/`''`/`null`/`'X'` → `null`. |
| T-04 | `tests/Services/Audit/Events/DocumentAuditOrchestratorTest.php` | Claves esperadas de `physical_attachment` = `TipoAlmacenamiento`, `AdjDisDocUrl`, `AdjDisNom` (sin `BlobSize`); `TipoAlmacenamiento` = `'BLOB'` desde `storage_type`. |
| T-05 | `tests/Unit/Services/Audit/Pipeline/AttachmentDownloadServiceTest.php` | Nuevo: metadata `TipoAlmacenamiento='SIN_DOCUMENTOS'` → `SOURCE_EMPTY` con mensaje que contiene `TipoAlmacenamiento='SIN_DOCUMENTOS'`. |
| T-06 | Suites existentes `GoogleDriveAuthServiceTest`, matcher, worker, consumer | Sin cambios; deben seguir verdes (contrato de llamadas Redis). |
| T-07 | Suite completa | `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` → 0 fallos. |

## 5. Documentación a sincronizar

- `plans/sdd-download-pipeline-resilience.md` (§0.3, fila `physical_attachment`; §2.4 punto 5 `unset`): retirar `BlobSize`, anotar que el `unset` se eliminó y referenciar esta spec.
- `plans/changelog.md`: entrada del refactor.
- `.agent/skills/audfact-audit-gemini/SKILL.md`: registrar `AttachmentStorageType`.

## 6. Rollback

Refactor local sin despliegue ni datos: `git checkout -- <archivos>` / `git stash`.

## 7. Excepciones

Ninguna.

