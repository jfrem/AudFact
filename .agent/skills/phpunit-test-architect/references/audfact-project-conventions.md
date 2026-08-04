# Convenciones de pruebas del proyecto AudFact

## Contenido

1. [Alcance y fuentes de verdad](#alcance-y-fuentes-de-verdad)
2. [Estructura de directorios](#estructura-de-directorios)
3. [Namespaces y ubicación de tests](#namespaces-y-ubicación-de-tests)
4. [Clase base y helpers de tests](#clase-base-y-helpers-de-tests)
5. [Clases base y contratos comunes](#clases-base-y-contratos-comunes)
6. [Resolución de dependencias](#resolución-de-dependencias)
7. [Excepciones, validación y respuestas HTTP](#excepciones-validación-y-respuestas-http)
8. [Dominio, aplicación e infraestructura](#dominio-aplicación-e-infraestructura)
9. [Patrones de prueba por componente](#patrones-de-prueba-por-componente)
10. [Checklist AudFact](#checklist-audfact)

## Alcance y fuentes de verdad

Aplicar esta referencia únicamente a suites destinadas al repositorio AudFact. Para una especificación PHP ajena al proyecto, aplicar solo `test-design-contract.md`.

Antes de generar una suite AudFact, contrastar estas fuentes cuando estén disponibles:

- `composer.json`: PSR-4 `App\` → `app/`, `Core\` → `core/` y `Tests\` → `tests/`.
- `phpunit.xml`: bootstrap `vendor/autoload.php` y suite sobre `tests/`.
- clase pública bajo prueba y sus colaboradores reales;
- test más reciente del mismo módulo;
- `app/Controllers/Controller.php`, `app/Models/Model.php` o `AuditEventConsumer.php` si existe herencia.

Si el código actual contradice esta referencia, el código y Composer prevalecen. No conservar una convención histórica sin evidencia.

## Estructura de directorios

| Ruta | Responsabilidad para pruebas |
|---|---|
| `app/Controllers/` | Adaptadores de entrega HTTP; validan entrada, coordinan modelos/servicios y emiten `Core\Response`. |
| `app/Models/` | Adaptadores de persistencia SQL Server; todos los modelos actuales extienden `App\Models\Model`. |
| `app/Services/` | Integraciones generales, actualmente Google Drive, y raíz de servicios de auditoría. |
| `app/Services/Audit/` | Mezcla servicios de dominio, configuración/value objects y adaptadores Gemini o filesystem. Clasificar por responsabilidad, no solo por carpeta. |
| `app/Services/Audit/Pipeline/` | Mezcla objetos de dominio, orquestadores, workers y adaptadores Redis. Clasificar cada clase por I/O y conducta pública. |
| `app/Services/Audit/Telemetry/` | Publicación de telemetría Redis; tratar como infraestructura. |
| `app/Routes/` | Registro central de rutas; no contiene reglas de negocio. |
| `app/wrap/` | Adaptador MCP/JSON-RPC hacia la API interna. |
| `core/` | Framework e infraestructura transversal: Router, Response, Validator, Database, Redis, Logger, Cache, Env, RateLimit y Middleware. |
| `public/` | Bootstrap HTTP, CORS, rate limiting y serialización final de excepciones HTTP. |
| `bin/` | Bootstrap CLI de workers; el registry instancia consumers sin contenedor. |
| `tests/` | Pruebas PHPUnit organizadas por módulo y namespace `Tests\`. |

Mantener las pruebas unitarias dentro de `tests/`. Reservar `tests/Integration/` para pruebas opt-in que accedan de manera explícita a infraestructura real.

## Namespaces y ubicación de tests

Usar el mapeo PSR-4 de Composer y reflejar el namespace productivo bajo `Tests\`:

| Producción | Test nuevo | Ruta canónica |
|---|---|---|
| `App\Controllers\InvoicesController` | `Tests\Controllers\InvoicesControllerTest` | `tests/Controllers/InvoicesControllerTest.php` |
| `App\Models\InvoicesModel` | `Tests\Models\InvoicesModelTest` | `tests/Models/InvoicesModelTest.php` |
| `Core\Validator` | `Tests\Core\ValidatorTest` | `tests/Core/ValidatorTest.php` |
| `App\Services\Audit\DocumentDuplicationEvaluator` | `Tests\Services\Audit\DocumentDuplicationEvaluatorTest` | `tests/Services/Audit/DocumentDuplicationEvaluatorTest.php` |
| `App\Services\Audit\Pipeline\DocumentPolicyEngine` | `Tests\Services\Audit\Pipeline\DocumentPolicyEngineTest` | `tests/Services/Audit/Pipeline/DocumentPolicyEngineTest.php` |
| `App\Services\Audit\Telemetry\TelemetryPublisher` | `Tests\Services\Audit\Telemetry\TelemetryPublisherTest` | `tests/Services/Audit/Telemetry/TelemetryPublisherTest.php` |

Reglas:

- Respetar mayúsculas y minúsculas de directorios y namespaces para que el autoload funcione en Linux.
- Crear tests nuevos del pipeline en `tests/Services/Audit/Pipeline/`.
- No copiar el desajuste histórico de `tests/Services/Audit/Events/`, cuyos archivos declaran `Tests\Services\Audit\Pipeline`; mantener esos archivos solo al modificar pruebas ya existentes.
- Para `app/wrap`, conservar el casing real actualmente usado: `Tests\wrap\core\tools` bajo `tests/wrap/core/tools/`.
- Usar `Tests\Integration` únicamente para integraciones opt-in.

## Clase base y helpers de tests

AudFact no declara una clase base propia para pruebas ni traits comunes de testing. Cada clase de test actual extiende directamente:

```php
use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
}
```

- Preferir clases de test `final`.
- No inventar `Tests\TestCase`, `BaseTestCase` ni un bootstrap adicional.
- Se permiten fakes, stubs y clases testables locales después de la clase principal cuando sirven solo a ese archivo.
- Extraer un helper compartido únicamente si existe reutilización real entre varios archivos y el usuario solicita escribirlo.
- Los métodos auxiliares no son métodos de prueba y no requieren comentarios AAA.

## Clases base y contratos comunes

### Controladores

- `App\Controllers\Controller` es la base de los controladores HTTP.
- Expone la propiedad protegida `$model` y seams protegidos como `getBody()`, `validate()`, `validateArray()` y `validateQuery()`.
- Los controladores simples construyen su modelo en un constructor sin argumentos.
- Los controladores complejos pueden exponer factories protegidas `build*()` para crear modelos, stores o publishers.
- En tests se puede extender el controlador y sobrescribir esos seams para inyectar dobles, pero nunca invocarlos o probarlos directamente.

### Modelos

- `App\Models\Model` es la base de todos los modelos actuales.
- Su constructor acepta `?Core\SqlServerConnectionExecutor` y crea uno real solo cuando no se inyecta.
- `read()`, `idempotentWrite()` y `nonReplayableWrite()` son helpers protegidos; probar el método público del modelo, no estos helpers.
- Las lecturas usan `db2`; las escrituras usan `default`.

### Pipeline

- `App\Services\Audit\Pipeline\AuditEventConsumer` es la base abstracta de los workers.
- Los workers implementan `stream()`, `group()`, `consumer()` y `handle()` como métodos protegidos.
- Probar el contrato público mediante `processEvent()`; probar `run()` solo cuando el comportamiento de consumo/retry sea el objetivo y Redis esté completamente doblado.

### Request, Response e interfaces

- No existe una clase `Request` propia. Los controladores consumen `$_GET`, `$_SERVER`, headers y `php://input` mediante métodos de `Controller`.
- `Core\Response` es una facade estática; no es una clase base de DTO ni una respuesta retornable.
- `Core\Validator` es estático y retorna un arreglo de errores.
- No existen interfaces de aplicación propias declaradas actualmente. No inventar `AuditDataServiceInterface`, `AttachmentDownloadServiceInterface` ni interfaces de repositorio para compilar una prueba contra código AudFact existente.
- Cuando una especificación nueva declare explícitamente una interfaz futura, la suite puede asumir su existencia como parte del contrato solicitado.

## Resolución de dependencias

AudFact no usa contenedor DI, autowiring ni service locator general.

### Runtime

- `Core\Router` resuelve el nombre `App\Controllers\{Controller}` y ejecuta `new $class()` sin argumentos.
- Los controladores deben conservar construcción pública sin argumentos para ser despachables por el router.
- `bin/audit-worker.php` selecciona la clase del consumer desde un registry y ejecuta `new $config['class']()`.
- Varias clases aceptan colaboradores anulables y crean defaults de producción con `new` o `RedisClient::getInstance()`.

### Tests

- Para servicios con inyección por constructor, pasar todos los colaboradores que contacten infraestructura.
- Para modelos, inyectar `SqlServerConnectionExecutor` con `connector` y `sleeper` controlados; usar `PDO`/`PDOStatement` falsos y no SQL Server real.
- Para controladores con factories `build*()`, crear una subclase testable que sobrescriba factories o `getBody()` y devuelva dobles.
- Si el controlador simple solo usa `$model`, una subclase testable puede asignar un fake a la propiedad protegida sin ejecutar su constructor productivo.
- Usar `createStub()` cuando solo se controlan retornos y `createMock()` cuando se verifican interacciones.
- Cuando no exista interfaz y la clase sea extensible, se permite un fake local que extienda la clase concreta, omita su constructor productivo y sobrescriba solo la API pública necesaria.
- No inventar un contenedor ni modificar el diseño productivo desde una suite de pruebas.
- No activar defaults que abran Redis, SQL Server, Google Drive, Gemini, filesystem o red.

## Excepciones, validación y respuestas HTTP

### Respuestas HTTP

`Response::success()`, `Response::error()` y `Response::paginated()` siempre lanzan `Core\Exceptions\HttpResponseException`. La excepción contiene:

- código HTTP en `getCode()`;
- payload serializable en `getData()`;
- `success`, `message`, `data`, `errors` o `meta` según la variante.

Para tests de controlador, capturar esta excepción como el resultado HTTP observable y afirmar estrictamente código y payload:

```php
private static function captureResponse(callable $callback): HttpResponseException
{
    try {
        $callback();
    } catch (HttpResponseException $response) {
        return $response;
    }

    self::fail('Se esperaba HttpResponseException');
}
```

Esta captura es una excepción deliberada a `expectException()`: se necesita inspeccionar el contrato completo de respuesta. Para excepciones de dominio o infraestructura que no representen una respuesta HTTP, usar las expectativas PHPUnit del contrato general.

### Validación

- `Controller::getBody()` exige `application/json`, limita el payload con `MAX_JSON_SIZE`, retorna `[]` para body vacío y rechaza JSON inválido.
- `validate()` procesa body; `validateArray()` procesa datos entregados; `validateQuery()` procesa `$_GET`.
- Errores de `Core\Validator` se convierten en respuesta 422 con mensaje `Errores de validación` y mapa `errors`.
- Content-Type inválido produce 415, payload excesivo 413 y JSON inválido 400.
- Reglas presentes: `required`, `nullable`, `optional`, `string`, `min`, `min_length`, `max`, `email`, `numeric`, `integer`, `alpha`, `date`, `min_value` y `max_value`.

### Estado global y bootstrap

- Si una prueba modifica `$_GET` o `$_SERVER`, guardar su valor en `setUp()` y restaurarlo en `tearDown()`.
- Para body JSON unitario, sobrescribir `getBody()` en una subclase testable; no leer realmente `php://input`.
- No probar headers reales ni el `set_exception_handler` desde una prueba unitaria de controlador.
- `public/index.php` serializa `HttpResponseException`; para excepciones no controladas registra el error y oculta el mensaje en producción.
- `Core\Router` conserva `HttpResponseException`, transforma otras `Exception` del controlador en respuesta 500 y usa 404/405 para ruta o método ausente.
- Cuando un controlador traduzca una excepción técnica a 404, 409, 422, 500 o 503, probar el código y payload públicos, no el stack trace.

## Dominio, aplicación e infraestructura

No clasificar únicamente por directorio: `app/Services/Audit/Pipeline/` contiene las tres categorías.

### Dominio

Componentes deterministas sin I/O propio. Instanciarlos directamente y probar entradas/salidas:

- enums y value objects: `AuditComparisonType`, `AuditFieldValueType`, `AuditFindingResult`, `AuditSeverity`, `DocumentQuality`, `ExtractionState`, `ExtractedEvidence`, `ResolvedAuditValue`, `AuditEvent`, `DocumentAttachmentMatchResult`;
- reglas y normalización: `AuditFindingRules`, `DeliveryValidityEvaluator`, `TextNormalization`, `IdentityDocNormalizer`, `DocumentDuplicationEvaluator`, `FieldValueResolver`, `VisualCheckEvaluator`;
- políticas deterministas: `DocumentAttachmentMatcher`, `DocumentIntegrityValidator`, `DocumentExtractionContractBuilder` y el núcleo de `DocumentPolicyEngine`.

Si `DocumentPolicyEngine` usa el fallback semántico, doblar `ArticleSemanticMatchJudge` porque esa colaboración termina en Gemini/Redis.

### Aplicación y orquestación

Coordinan casos de uso, eventos y transiciones. Probar resultados, eventos publicados, estado terminal e interacciones necesarias:

- `AuditBatchOrchestrator`;
- workers concretos derivados de `AuditEventConsumer`;
- `DocumentAuditOrchestrator`;
- `AuditDataService` como facade de acceso para el pipeline;
- controladores como adaptadores que coordinan un caso de uso HTTP.

### Infraestructura y entrega

Tratar siempre como fronteras sustituibles en pruebas unitarias:

- `app/Models/*`, `Core\Database`, `SqlServerConnectionExecutor` y PDO;
- `Core\RedisClient`, `AuditEventPublisher`, `AuditStateStore`, `BatchJobStore`, `AuditPersistenceQueue` y `TelemetryPublisher`;
- `GeminiGateway`, `ArticleSemanticMatchJudge`, `GoogleDriveAuthService`, `AttachmentDownloadService` y `ResponseIADiskStore` cuando acceden a red o filesystem;
- `Core\Router`, `Core\Response`, `Core\Validator`, `Logger`, `Env`, `Cache`, `RateLimit` y `Middleware`;
- `app/wrap`, `public/index.php`, `bin/`, Docker y Nginx.

Una prueba unitaria no debe conectarse a estas fronteras. La única integración real existente está aislada en `tests/Integration/AuditPersistenceQueueRedisTest.php` y exige `RUN_REDIS_INTEGRATION=1`; no copiar ese patrón a una suite unitaria.

## Patrones de prueba por componente

### Servicio de dominio puro

- Instanciar directamente.
- Usar valores explícitos y deterministas.
- Afirmar el resultado completo o sus invariantes públicas.
- Evitar mocks si no existe colaboración externa.

### Controlador

- Preparar body/query y dobles en una subclase testable.
- Invocar la acción pública.
- Capturar `HttpResponseException` como respuesta.
- Afirmar código, `success`, mensaje, datos/errores y ausencia de interacciones tras validación fallida.
- Restaurar superglobales modificadas.

### Modelo SQL Server

- Inyectar un `SqlServerConnectionExecutor` controlado.
- Usar fake PDO y statement; verificar SQL parametrizado, binds, retorno, cierre de cursor y modo/retry solo cuando sean observables del contrato público.
- No abrir una conexión real.

### Worker u orquestador

- Inyectar stores, publishers, servicios y Redis doblados.
- Invocar `processEvent()` o el método público del caso de uso.
- Afirmar eventos, payloads, estado y manejo terminal observable.
- No ejecutar el loop infinito ni hacer `sleep()`.

### Integración

- Generarla solo si el usuario la solicita explícitamente.
- Colocarla en `tests/Integration/` y protegerla con una variable opt-in.
- Mantenerla fuera de la expectativa de una suite unitaria aislada.

## Checklist AudFact

- [ ] La ruta del archivo coincide con el namespace `Tests\` y el módulo productivo.
- [ ] La clase extiende directamente `PHPUnit\Framework\TestCase`.
- [ ] No se inventó una clase base, `Request`, contenedor DI o interfaz inexistente.
- [ ] El controlador conserva el seam real: `$model`, `getBody()` o factory `build*()`.
- [ ] Las respuestas HTTP se verifican mediante `HttpResponseException` con código y payload.
- [ ] Las superglobales se restauran después de cada prueba.
- [ ] Los modelos usan executor y PDO falsos, nunca SQL Server real.
- [ ] Los workers usan `processEvent()` y dependencias dobladas, no loops reales.
- [ ] Cada dependencia fue clasificada como dominio, aplicación o infraestructura antes de elegir el doble.
- [ ] Redis, Gemini, Drive, filesystem, red y reloj no controlado están ausentes de la prueba unitaria.
