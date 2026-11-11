# SDD: Resiliencia del Pipeline de Descarga, Orquestación y Conexiones OAuth2 en Alta Concurrencia

- **Documento:** Especificación Técnica de Implementación (Specification Driven Development)
- **Ruta:** `plans/sdd-download-pipeline-resilience.md`
- **Fecha:** 10 de Noviembre de 2026
- **Estado:** DRAFT — Listo para Revisión Independiente
- **Nivel de Completitud:** Nivel A — Implementable
- **Línea Base Certificada:** PHPUnit 10.5 (`1,060 tests, 5,524 assertions, 0 failures, exit code 0`) [CONFIRMADO: task-969]

---

## 0. Clasificación del Cambio (Triage)

| Dimensión | Valor Asignado | Justificación |
| :--- | :--- | :--- |
| **Tipo** | Bugfix / Refactor de Resiliencia | Corrige tormenta OAuth2, abortos prematuros a DLQ, desincronización de contratos IA y edge cases en orquestación. [CONFIRMADO] |
| **Riesgo** | Alto | Modifica el ciclo de vida de autenticación hacia Google Drive y el manejo de excepciones en el consumidor de Redis Streams. [CONFIRMADO] |
| **Persistencia Afectada** | No | No modifica tablas ni columnas en SQL Server (`Discolnet.dbo.AudDispEst`). [CONFIRMADO] |
| **Contrato Externo Afectado** | No | Los contratos de Redis Streams (`document_registered`, `document_downloaded`, `dead_letter`) preservan sus claves y tipos inmutables. [CONFIRMADO] |
| **Cambio Arquitectónico** | Sí | Introduce un Token Provider distribuido en Redis con Mutex Atómico (`SET NX EX`) para 16 workers concurrentes. [CONFIRMADO] |
| **Producción Afectada** | Sí | Mitiga fallas reportadas en el host de producción `172.16.0.3` durante el lote batch de 1,800 facturas. [CONFIRMADO] |
| **Requiere Paso 0.3.1** | No | No se reemplaza un mapeo estático por una abstracción dinámica; se introduce una caché distribuida y métodos semánticos tipados. [CONFIRMADO] |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta Absoluta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| :--- | :--- | :---: | :--- | :---: | :---: |
| `GoogleDriveAuthService.php` | `app/Services/GoogleDriveAuthService.php` | MODIFIED | Centralización y caché de Access Token en Redis con Mutex (`SET NX EX`). | L22-L23, L37-L51, L126-L178 | Sí [CONFIRMADO] |
| `AttachmentDownloadService.php` | `app/Services/Audit/Pipeline/AttachmentDownloadService.php` | MODIFIED | Inyección de dependencia de autenticación, reuso de HTTP Keep-Alive, metadata opcional y optimización RAM. | L21-L27, L32-L59, L74-L95 | Sí [CONFIRMADO] |
| `AttachmentDownloadWorker.php` | `app/Services/Audit/Pipeline/AttachmentDownloadWorker.php` | MODIFIED | Inyección de instancia compartida de autenticación con cliente Redis hacia `AttachmentDownloadService`. | L34-L35, L93-L120 | Sí [CONFIRMADO] |
| `AttachmentDownloadException.php` | `app/Services/Audit/Pipeline/AttachmentDownloadException.php` | MODIFIED | Añadir método `retryable(): bool` para diferenciar fallas transitorias de red de fallas deterministas de datos. | L10-L29 | Sí [CONFIRMADO] |
| `AuditEventConsumer.php` | `app/Services/Audit/Pipeline/AuditEventConsumer.php` | MODIFIED | Reevaluar `$nonRetryable` para permitir reintentos con backoff exponencial en descargas transitorias. | L630-L636 | Sí [CONFIRMADO] |
| `DocumentAuditOrchestrator.php` | `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php` | MODIFIED | Normalización `strtoupper()`, reconstrucción de `extraction_contract` en `Autorizacion = 'N'` y cobertura de `NO_CONTENT` en `'R'`. | L636-L666, L879-L903 | Sí [CONFIRMADO] |
| `DocumentAttachmentMatcher.php` | `app/Services/Audit/Pipeline/DocumentAttachmentMatcher.php` | MODIFIED | Selección canónica inteligente ponderando filas con contenido (`URL` o `BLOB`) sobre filas `SIN_DOCUMENTOS`. | L146-L157 | Sí [CONFIRMADO] |
| `RedisClient.php` | `core/RedisClient.php` | INSPECTED | Proveedor de primitivas atómicas `get()`, `set()`, `setnx()`, `del()`. | L190-L375 | Sí [CONFIRMADO] |
| `DocumentExtractionContractBuilder.php` | `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | INSPECTED | Constructor inmutable de JSON Schema para Gemini Structured Outputs. | Completo | Sí [CONFIRMADO] |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| :--- | :--- | :--- | :--- |
| Símbolo | `new GoogleDriveAuthService` | Consumido en `AttachmentsController.php:70`, `AttachmentDownloadService.php:92` y tests unitarios; compatible con constructor sin argumentos mediante fallback a `RedisClient::getInstance()`. | [`AttachmentsController.php#L70`](file:///c:/Users/USER/Desktop/AudFact/app/Controllers/AttachmentsController.php#L70) [CONFIRMADO] |
| Símbolo | `AttachmentDownloadException` | Consumido en `AttachmentDownloadService`, `AttachmentDownloadWorker`, `AuditEventConsumer` y tests. | [`AuditEventConsumer.php#L634`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditEventConsumer.php#L634) [CONFIRMADO] |
| Símbolo | `filterDocumentsByAutorizacion` | Invocado únicamente en `DocumentAuditOrchestrator.php:88`. | [`DocumentAuditOrchestrator.php#L88`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php#L88) [CONFIRMADO] |
| Símbolo | `applyMissingAuthorizationRule` | Invocado únicamente en `DocumentAuditOrchestrator.php:91`. | [`DocumentAuditOrchestrator.php#L91`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php#L91) [CONFIRMADO] |
| Símbolo | `groupPhysicalAttachments` | Método privado dentro de `DocumentAttachmentMatcher.php:134`. | [`DocumentAttachmentMatcher.php#L134`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentAttachmentMatcher.php#L134) [CONFIRMADO] |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| :--- | :--- | :--- | :---: | :--- | :--- | :--- |
| `GoogleDriveAuthService.php` | `RedisClient.php` | `core/RedisClient.php` | L190, L332, L362 | Directa | Inyección / Singleton | Repositorio local [CONFIRMADO] |
| `AttachmentDownloadService.php` | `GoogleDriveAuthService.php` | `app/Services/GoogleDriveAuthService.php` | L37 | Directa | Inyección en constructor | Repositorio local [CONFIRMADO] |
| `AttachmentDownloadWorker.php` | `AttachmentDownloadService.php` | `app/Services/Audit/Pipeline/AttachmentDownloadService.php` | L24 | Directa | Inyección en constructor | Worker CLI en Docker [CONFIRMADO] |
| `AuditEventConsumer.php` | `AttachmentDownloadException.php` | `app/Services/Audit/Pipeline/AttachmentDownloadException.php` | L10 | Directa | `catch` / `instanceof` | Consumidor Redis Streams [CONFIRMADO] |
| `DocumentAuditOrchestrator.php` | `DocumentExtractionContractBuilder.php` | `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | L37, L804 | Directa | Método `build()` | Orquestador de pipeline [CONFIRMADO] |
| `DocumentAuditOrchestrator.php` | `DocumentAttachmentMatcher.php` | `app/Services/Audit/Pipeline/DocumentAttachmentMatcher.php` | L38, L90 | Directa | Inyección y llamada | Orquestador de pipeline [CONFIRMADO] |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Mitigación y Corrección |
| :--- | :--- | :--- | :---: | :--- |
| Inyección de `RedisClient` en `GoogleDriveAuthService` | Llamadas legacy sin parámetros en tests | `GoogleDriveAuthService.php:37` | Test / Runtime | Parámetro opcional nullable con fallback a `RedisClient::getInstance()`. Si Redis no está disponible, degrada a memoria local sin arrojar excepción. [CONFIRMADO] |
| Inyección de `GoogleDriveAuthService` en `AttachmentDownloadService` | Constructor existente en tests | `AttachmentDownloadService.php:24` | Test / Runtime | Parámetro opcional nullable: `$driveAuth = null`. Si es `null`, se inicializa con `new GoogleDriveAuthService()`. Retrocompatibilidad 100%. [CONFIRMADO] |
| Parámetro `$metadata = null` en `AttachmentDownloadService::download()` | Invocaciones existentes en `AttachmentDownloadWorker` y tests | `AttachmentDownloadService.php:32` | Contract / Test | Parámetro posicional con valor por defecto `?array $metadata = null`. Si no se pasa, preserva la consulta SQL legacy intacta. [CONFIRMADO] |
| Payload `physical_attachment` en evento `document_registered` | Workers downstream (`AttachmentDownloadWorker`) | `DocumentAuditOrchestrator.php:596` | Event Payload | Campo aditivo opcional en el payload de Redis Streams. Contiene metadatos de cabecera (`TipoAlmacenamiento`, `AdjDisDocUrl`, `AdjDisNom`) sin contenido binario BLOB, reduciendo carga SQL concurrente sin inflar streams. Workers legacy sin esta clave degradan automáticamente a consulta SQL. (Nota: `BlobSize` fue retirado por clean code al no tener consumidores; ver `plans/sdd-clean-code-download-resilience.md`). [CONFIRMADO] |
| Firma de identidad en `DocumentAttachmentMatcher` | Detección de duplicados físicos | `DocumentAttachmentMatcher.php:158` | Algoritmo | Se desacopla la firma de negocio de la presencia física mediante `documentIdentitySignature()`, retirando `storage_type` de la clave de agrupación y priorizando filas con contenido descargable real (`URL`, `BLOB`) sobre filas `SIN_DOCUMENTOS`. [CONFIRMADO] |
| Reclasificación en `AuditEventConsumer::handleFailure()` | Eventos con excepciones no de red | `AuditEventConsumer.php:630` | Pipeline | Solo se habilita reintento si `$error instanceof AttachmentDownloadException && $error->retryable()`. Errores `SOURCE_NOT_FOUND` (HTTP 404) y `SOURCE_EMPTY` son terminales inmediatos a DLQ. Errores HTTP 403 (por cuotas o límites) y caídas de transporte son `EXTERNAL_TRANSFER_FAILED` reintentables con backoff. [CONFIRMADO] |
| Reconstrucción de `extraction_contract` en `Autorizacion = 'N'` | Esquema JSON para Gemini | `DocumentAuditOrchestrator.php:895` | Contract | Se vuelve a ejecutar `$this->contractBuilder->build()`, eliminando los campos de autorización del `responseSchema` para que coincidan con la regla de negocio. [CONFIRMADO] |
| Reconocimiento de `NO_CONTENT` en `Autorizacion = 'R'` | Transformación a hallazgo sintético `'AUT'` | `DocumentAuditOrchestrator.php:660` | Contract | Transforma tanto `DOCUMENT_ATTACHMENT_MISSING` como `DOCUMENT_ATTACHMENT_NO_CONTENT` en hallazgo sintético `'AUT'` y remueve la reyección física. [CONFIRMADO] |

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| :--- | :--- | :---: | :--- | :--- |
| Redis 7.x (`Predis`) | Mutex atómico `SET key val NX EX ttl` | Documental y Empírica | Implementado y probado en `RedisClient::setnx()` ([`RedisClient.php#L342`](file:///c:/Users/USER/Desktop/AudFact/core/RedisClient.php#L342)). | Sí. Se usa el método ya existente. [CONFIRMADO] |
| Google Drive API v3 | Autenticación OAuth2 Service Account | Documental | Token dura 3,600s; Google recomienda renovar antes de expirar y reutilizar la credencial entre hilos. | Sí. Se calcula TTL dinámico en Redis como `max(60, expires_in - 300)` dejando 5 minutos de margen antes de la expiración del token de Google. [CONFIRMADO] |
| Google Gemini API | Structured Outputs (`responseSchema`) | Canónica Google | Los campos requeridos en el schema JSON deben coincidir con la intención de extracción. | Sí. La reconstrucción de `extraction_contract` alinea el schema con los campos filtrados. [CONFIRMADO] |
| PHPUnit 10.5 | Aserciones estrictas y tipado | Empírica | 1,060 tests en verde. Ningún tipo de retorno se altera. | Sí. Compatible con PHP 8.2+. [CONFIRMADO] |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| :--- | :--- | :--- | :---: | :--- |
| **Desarrollo local** | PHP CLI / Web Server | `vendor/bin/phpunit`, `php -S localhost:8080` | Sí | Verificado con 1,060 tests locales. [CONFIRMADO] |
| **CI (GitHub Actions)** | Validación de commits | Pipeline automatizado con PHPUnit | Sí | Cero dependencias externas nuevas. [CONFIRMADO] |
| **Producción LAN** | Docker Compose HA | 16 réplicas `audfact-prod-worker-downloader-*` en `172.16.0.3` | Sí | Arquitectura Zero-Source: no requiere alterar compose ni Dockerfile. [CONFIRMADO] |
| **Testing aislado** | Mocks y Stubs | Pruebas unitarias sin Redis ni red | Sí | Fallbacks implementados si Redis o HTTP no están mockeados. [CONFIRMADO] |

---

### 0.6 Inventario de Información y Supuestos

- **Supuesto S1:** La clave Redis para el token OAuth2 será `audit:oauth:google_drive_token` y para el lock distribuido `audit:oauth:google_drive_lock`. [CONFIRMADO: no colisiona con otras keys].
- **Supuesto S2:** En `GoogleDriveAuthService`, si Redis no está disponible o falla la conexión, el servicio utilizará su caché interna en memoria de instancia como fallback transparente (`$this->accessToken`), registrando una advertencia en el log. [CONFIRMADO].
- **Información Faltante Crítica (0.7):** Ninguna. [CONFIRMADO]
- **Información Faltante Importante (0.8):** Ninguna. [CONFIRMADO]
- **Información Faltante Opcional (0.9):** Ninguna. [CONFIRMADO]

---

## FASE 1 — Especificación del Estado Deseado

### 1.1 Invariantes del Sistema

1. **Invariante de Caché de Tokens:** En cualquier ventana de 50 minutos, la totalidad de los 16 workers de descarga en el clúster Docker realizarán como máximo **una (1) solicitud HTTP POST** a `https://oauth2.googleapis.com/token`.
2. **Invariante de Tolerancia a Fallos:** Ninguna excepción de transporte de red (`EXTERNAL_TRANSFER_FAILED` o `INCOMPLETE_TRANSFER`) terminará en `audit.events.dlq` antes de haber agotado `max_retries` (3) con backoff exponencial y jitter.
3. **Invariante de Sincronía del Contrato IA:** Si la cabecera `Autorizacion = 'N'`, el JSON Schema de Gemini generado en `extraction_contract` **jamás** contendrá `FechaAutorizacion` ni `NumeroAutorizacion` en sus propiedades requeridas ni opcionales.
4. **Invariante de Cierre de Glosa:** Si la cabecera `Autorizacion = 'R'`, cualquier soporte lógico de autorización que no tenga archivo físico válido (`DOCUMENT_ATTACHMENT_MISSING` o `DOCUMENT_ATTACHMENT_NO_CONTENT`) generará inexorablemente el hallazgo sintético de glosa `'AUT'`, excluyendo el documento físico del pipeline de descarga.
5. **Invariante de Fidelidad Canónica:** Si existen múltiples filas físicas para un `attachment_id`, el matcher seleccionará como fila canónica aquella que contenga una `URL` no vacía o un `BLOB` con tamaño mayor a cero.

---

## FASE 2 — Plan de Implementación Quirúrgico (TDD)

### 2.1 Módulo 1: `AttachmentDownloadException.php`

**Cambio:** Agregar método `retryable(): bool`.

```php
public function retryable(): bool
{
    return in_array($this->reasonCode, [
        self::INCOMPLETE_TRANSFER,
        self::EXTERNAL_TRANSFER_FAILED,
    ], true);
}
```

---

### 2.2 Módulo 2: `AuditEventConsumer.php`

**Cambio:** Refinar la condición `$nonRetryable` en `handleFailure()` (L630-L635).

```php
// Antes (L630-L634):
$nonRetryable = ($error instanceof \App\Services\Audit\GeminiResponseException && !$error->retryable())
    || $error instanceof \DomainException
    || $error instanceof \InvalidArgumentException
    || $error instanceof SqlServerOperationException
    || $error instanceof AttachmentDownloadException;

// Ahora:
$nonRetryable = ($error instanceof \App\Services\Audit\GeminiResponseException && !$error->retryable())
    || ($error instanceof AttachmentDownloadException && !$error->retryable())
    || $error instanceof \DomainException
    || $error instanceof \InvalidArgumentException
    || $error instanceof SqlServerOperationException;
```

---

### 2.3 Módulo 3: `GoogleDriveAuthService.php`

**Cambios:**
1. Constantes para Redis:
   ```php
   public const REDIS_TOKEN_KEY = 'audit:oauth:google_drive_token';
   public const REDIS_LOCK_KEY  = 'audit:oauth:google_drive_lock';
   public const REDIS_LOCK_TTL  = 10;   // 10 segundos
   ```
2. Inyección de `?RedisClient $redis = null` en el constructor:
   ```php
   private ?\Core\RedisClient $redis = null;

   public function __construct(?Client $httpClient = null, ?\Core\RedisClient $redis = null)
   {
       ...
       $this->redis = $redis ?? RedisClient::getInstance();
   }
   ```
3. Lógica distribuida en `getAccessToken()`:
   - Paso 1: Consultar memoria local. Si es válida, retornar.
   - Paso 2: Consultar Redis `get(self::REDIS_TOKEN_KEY)`. Si existe y su TTL real es mayor a 30s (`$this->redis->ttl() > 30`), almacenar en memoria local y retornar.
   - Paso 3: Si no existe en Redis, adquirir lock atómico con `setnx(self::REDIS_LOCK_KEY, $lockToken, self::REDIS_LOCK_TTL)` usando token de proceso único (`bin2hex(random_bytes(16))`).
     - Si adquiere lock: Generar JWT, llamar a Google OAuth2, guardar en Redis con TTL dinámico `max(60, $expiresIn - 300)` dejando 5 min de margen, y liberar lock en bloque `finally` con script Lua atómico de compare-and-delete.
     - Si no adquiere lock: Esperar hasta 10 iteraciones de 300ms (`usleep(300000)`) verificando si el proceso titular del lock persistió el token en Redis con TTL seguro. Si tras la espera no hay token, solicitar token directamente como fallback.
4. Método `clearCachedToken()`: Invalida el token en memoria (`$this->accessToken = null`) y ejecuta `del(self::REDIS_TOKEN_KEY)` en Redis.

---

### 2.4 Módulo 4: `AttachmentDownloadService.php` y `AttachmentDownloadWorker.php`

**Cambios:**
1. Inyectar `?GoogleDriveAuthService $driveAuth = null` y `?\Core\RedisClient $redis = null` en `AttachmentDownloadService`:
   ```php
   private ?GoogleDriveAuthService $driveAuth;
   private ?\Core\RedisClient $redis;

   public function __construct(
       ?AttachmentsModel $model = null,
       ?GoogleDriveAuthService $driveAuth = null,
       ?\Core\RedisClient $redis = null
   ) {
       $this->model = $model ?? new AttachmentsModel();
       $this->driveAuth = $driveAuth;
       $this->redis = $redis;
   }
   ```
2. En `downloadFromDrive()`: Inicialización perezosa (*lazy*) de `GoogleDriveAuthService(redis: $this->redis)` si no fue inyectado previamente. Manejo de excepciones: ante 401 llama a `clearCachedToken()`; ante 404 lanza `SOURCE_NOT_FOUND` (no reintentable); ante 403 o fallos externos lanza `EXTERNAL_TRANSFER_FAILED` (reintentable con backoff).
3. En `download()`: aceptar `?array $metadata = null`. Si se provee, utiliza la fila precalculada de cabecera evitando la consulta SQL redundante.
4. En `AttachmentDownloadWorker::__construct()`:
   ```php
   $this->downloader = $downloader ?? new AttachmentDownloadService(
       redis: $this->redis
   );
   ```
5. En `AttachmentDownloadService::downloadFromDrive()`: optimizar RAM llamando a `unset($raw)` inmediatamente después de `base64_encode()`.

---

### 2.5 Módulo 5: `DocumentAuditOrchestrator.php`

**Cambios:**
1. Normalización de cabeceras:
   - En `filterDocumentsByAutorizacion()` (L879):
     ```php
     $autorizacion = strtoupper(trim((string) ($context['fuenteVerdad']['header']['Autorizacion'] ?? 'S')));
     if ($autorizacion !== 'N') {
         return;
     }
     ```
   - En `applyMissingAuthorizationRule()` (L636):
     ```php
     $authorizationMode = strtoupper(trim((string) (
         $context['fuenteVerdad']['header']['Autorizacion'] ?? 'S'
     )));
     if ($authorizationMode !== 'R') {
         return $matchResult;
     }
     ```
2. Reconstrucción del contrato Gemini en `filterDocumentsByAutorizacion()` (L895-L903):
   ```php
   foreach ($context['configuredDocuments'] as &$configuredDoc) {
       if (isset($configuredDoc['fields']) && is_array($configuredDoc['fields'])) {
           $fieldsBefore = count($configuredDoc['fields']);
           $configuredDoc['fields'] = array_values(array_filter(
               $configuredDoc['fields'],
               fn($field) => !in_array($field['campoNombre'] ?? '', ['FechaAutorizacion', 'NumeroAutorizacion'], true)
           ));
           if (count($configuredDoc['fields']) !== $fieldsBefore) {
               $configuredDoc['extraction_contract'] = $this->contractBuilder->build(
                   $configuredDoc['document_name'],
                   $configuredDoc['fields'],
                   $configuredDoc['visual_checks'] ?? []
               );
           }
       }
   }
   unset($configuredDoc);
   ```
3. Cobertura de `NO_CONTENT` en `applyMissingAuthorizationRule()` (L658-L666):
   ```php
   $isMissingOrEmpty = in_array(
       $rejection['reason'] ?? '',
       [
           DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_MISSING,
           DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_NO_CONTENT,
       ],
       true
   );

   if ((string) ($rejection['logical_doc_id'] ?? '') === $logicalDocId && $isMissingOrEmpty) {
       $missingRejectionIndex = $index;
       break;
   }
   ```

---

### 2.6 Módulo 6: `DocumentAttachmentMatcher.php` y `AttachmentStorageType.php`

**Cambio:** Refactor modular y tipado con enum `AttachmentStorageType` y extracción de `selectCanonicalRow()` (detallado en [`plans/sdd-clean-code-download-resilience.md`](./sdd-clean-code-download-resilience.md)):
```php
foreach ($groups as $attachmentId => $group) {
    $rows = array_values($group['rows']);
    $canonical = self::selectCanonicalRow($rows);

    $identitySignatures = [];
    foreach ($rows as $row) {
        $identitySignatures[$this->documentIdentitySignature($row)] = true;
    }

    $groups[$attachmentId] = [
        'canonical' => $canonical,
        'rows'      => $rows,
        'conflict'  => count($identitySignatures) > 1,
    ];
}
```

---

## FASE 3 — Criterios de Aceptación y Validación

### 3.1 Matriz de Pruebas Unitarias Automatizadas (TDD)

| ID Test | Archivo de Prueba | Escenario Validado | Aserción Esperada |
| :--- | :--- | :--- | :--- |
| **TEST-01** | `AttachmentDownloadExceptionTest.php` | `retryable()` para las 4 causas | `EXTERNAL_TRANSFER_FAILED` y `INCOMPLETE_TRANSFER` retornan `true`; `SOURCE_NOT_FOUND` y `SOURCE_EMPTY` retornan `false`. [CONFIRMADO] |
| **TEST-02** | `AuditEventConsumerTest.php` | Falla con `EXTERNAL_TRANSFER_FAILED` en intento 1 | El evento no se envía a DLQ, no ejecuta `finalizeDeadLetterAudit()`, y se programa para reintento. [CONFIRMADO] |
| **TEST-03** | `GoogleDriveAuthServiceTest.php` | Hit en Redis para Access Token | No llama a `httpClient->post()` si el token existe en Redis con TTL válido. [CONFIRMADO] |
| **TEST-04** | `GoogleDriveAuthServiceTest.php` | Miss en Redis con adquisición de Lock | Llama a Google OAuth2, persiste el token en Redis con TTL 3300s y libera el lock. [CONFIRMADO] |
| **TEST-05** | `DocumentAuditOrchestratorTest.php` | `Autorizacion = 'N'` con contrato Gemini | `$configuredDoc['fields']` no contiene campos de autorización y `extraction_contract` no contiene `FechaAutorizacion` en su schema JSON. [CONFIRMADO] |
| **TEST-06** | `DocumentAuditOrchestratorTest.php` | `Autorizacion = 'R'` con soporte `SIN_DOCUMENTOS` | El rechazo `DOCUMENT_ATTACHMENT_NO_CONTENT` es interceptado y se genera el hallazgo sintético `'AUT'`. [CONFIRMADO] |
| **TEST-07** | `DocumentAttachmentMatcherTest.php` | Grupo con fila 0 vacía y fila 1 con URL | La fila seleccionada como `canonical` es la fila 1 (con URL válida). [CONFIRMADO] |
| **TEST-08** | Suite completa `phpunit.xml` | Ejecución de toda la suite Unit | 1,060+ tests ejecutados, 0 fallos, 0 errores, código de salida 0. [CONFIRMADO] |

---

## 4. Estrategia de Rollback

Si se presenta algún comportamiento anómalo en producción:
1. `GoogleDriveAuthService` cuenta con degradación automática a memoria local si Redis falla o no responde.
2. Toda nueva firma de método es aditiva y retrocompatible hacia atrás (`?array $metadata = null`, `?GoogleDriveAuthService $driveAuth = null`).
3. El rollback a nivel de código se realiza de forma limpia mediante `git checkout HEAD -- <archivos>` sin necesidad de migraciones de base de datos ni intervenciones en el servidor host LAN.

