# Testing Strategy — AudFact

## Estado actual

- PHPUnit está configurado en el proyecto (`phpunit/phpunit` en `require-dev`).
- Configuración activa en `phpunit.xml` con bootstrap de Composer.
- Suite actual con 407 tests, 1431 aserciones y 1 integración opt-in omitida en la ejecución del 2026-08-03:
  - Controladores: `AuditControllerTest.php`, `AuditDlqControllerTest.php`, `InvoicesControllerTest.php`, `ObservabilityControllerTest.php`
  - Core: `CacheTest.php`, `RedisClientTest.php`, `RedisClientStreamParsingTest.php`, `SqlServerConnectionExecutorTest.php`
  - Modelos: `AttachmentsModelTest.php`, `AuditResultPersistenceModelTest.php`, `DispensationModelTest.php`, `InvoicesModelTest.php`
  - Pipeline/eventos: `DocumentAuditOrchestratorTest.php`, `DocumentAttachmentMatcherTest.php`, `AttachmentDownloadWorkerTest.php`, `AttachmentDownloadServiceTest.php`, `DocumentExtractionWorkerTest.php`, `DocumentIntegrityValidatorTest.php`, `DocumentNormalizerTest.php`, `RulesEvaluationWorkerTest.php`, `AuditPersistenceQueueTest.php`, `AuditPersistenceWorkerTest.php`, `AuditEvent*`, `AuditStateStoreTest.php`
  - Integración opt-in: `AuditPersistenceQueueRedisTest.php`, ejecutable con `RUN_REDIS_INTEGRATION=1` contra Redis real
  - Policy/scoring: `DocumentPolicyEngineTest.php`, `AuditFindingRulesNormalizationTest.php`, `AuditFieldValueTypeTest.php`, `DeliveryValidityEvaluatorTest.php`, `TextNormalizationTest.php`
  - MCP tools: `GetAttachmentsTest.php`, `GetDispensationTest.php`
- CI ejecuta pruebas unitarias con `vendor/bin/phpunit --configuration phpunit.xml --testdox --colors=always`.

## Diseño de contratos PHPUnit

- La skill `phpunit-test-architect` convierte dominios, interfaces públicas, firmas o requisitos funcionales en suites PHPUnit 10+ completas que actúan como contrato ejecutable para TDD.
- La skill genera exclusivamente pruebas: no implementa código de producción, no prueba detalles internos y sustituye SQL Server, Redis, filesystem, red y servicios externos con dobles controlados.
- Toda suite generada usa tipos estrictos, PSR-12, sintaxis moderna de PHPUnit, aserciones estrictas, secciones AAA explícitas y data providers para tres o más escenarios equivalentes.
- Para AudFact, la skill consulta `references/audfact-project-conventions.md`: refleja los namespaces PSR-4, extiende directamente `PHPUnit\Framework\TestCase`, respeta los seams reales de controladores/modelos/workers y no inventa una clase `Request`, interfaces propias ni un contenedor DI inexistente.
- Los tests de controlador capturan `HttpResponseException` como contrato HTTP; los modelos reciben un `SqlServerConnectionExecutor` controlado; los workers se prueban mediante `processEvent()` con Redis, publishers, stores y servicios doblados.
- La selección de dobles parte de tres categorías: dominio determinista, aplicación/orquestación e infraestructura/entrega. La carpeta `app/Services/Audit/Pipeline` contiene componentes de las tres y debe clasificarse por I/O y responsabilidad, no solo por ubicación.
- Para contratos propios de AudFact, combinarla con la skill del dominio correspondiente en `CATALOG.md`; por ejemplo, `audfact-audit-gemini` para el pipeline o `audfact-sqlsrv-models` para modelos.
- Invocación sugerida: `$phpunit-test-architect` seguida del dominio, interfaz, reglas de negocio o requisito que funcionará como fuente del contrato.

## Ejecución local

1. Instalar dependencias:
   - `composer install`
2. Ejecutar pruebas:
   - `composer test`
   - o `vendor/bin/phpunit --configuration phpunit.xml`

## Alcance actual de cobertura

- Cobertura unitaria en reglas críticas del pipeline IA previo a Gemini.
- Cobertura unitaria en persistencia transaccional, actualización set-based de hallazgos y scheduling justo por job.
- Cobertura determinista de cortes SQL virtuales de 1, 6 y 30 segundos, PDO distinto por intento, `HYT00` por fase, `SHUTDOWN`, deadlock y operaciones no reproducibles.
- Cobertura de BLOB completo/parcial/ausente/vacío y taxonomía de descarga, más productores segregados de `document_rejected`: mapping en orquestación y contenido en extracción.
- Cobertura contractual de reconciliación global 1:1: caso 2624, nombre exacto, ID corroborado, alias único, ambigüedad, ausencia, falta de contenido, reutilización y DTO sin IDs duplicados.
- Cobertura de hallazgo `MAP`, persistencia de trazabilidad física, deduplicación por `attachment_id`, guardas de policy/persistencia y DLQ+ACK terminal ante agotamiento SQL.
- No hay (aún) suite formal de integración end-to-end en `phpunit` para llamadas reales a SQL Server/Gemini.

## Guía para nuevas pruebas

- Mantener pruebas unitarias en `tests/` por dominio funcional (ej. `tests/Services/Audit/*`).
- Priorizar casos de borde en:
  - prevalidaciones de adjuntos/documentos requeridos,
  - mapeo de severidad y estado detallado,
  - persistencia de `AudDispEst` y actualización documental relacionada.
- Si se agregan pruebas de integración con servicios externos, separarlas explícitamente de la suite unitaria para no bloquear CI por dependencias de red.
