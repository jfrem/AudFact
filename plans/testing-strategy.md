# Testing Strategy — AudFact

## Estado actual

- PHPUnit está configurado en el proyecto (`phpunit/phpunit` en `require-dev`).
- Configuración activa en `phpunit.xml` con bootstrap de Composer.
- Suite actual distribuida en 31 archivos PHP de prueba:
  - Controladores: `AuditControllerTest.php`, `AuditDlqControllerTest.php`, `InvoicesControllerTest.php`
  - Core: `CacheTest.php`, `RedisClientTest.php`, `RedisClientStreamParsingTest.php`
  - Modelos: `AttachmentsModelTest.php`, `DispensationModelTest.php`, `InvoicesModelTest.php`
  - Pipeline/eventos: `DocumentAuditOrchestratorTest.php`, `DocumentExtractionWorkerTest.php`, `DocumentIntegrityValidatorTest.php`, `DocumentNormalizerTest.php`, `RulesEvaluationWorkerTest.php`, `AuditAggregationWorkerTest.php`, `AuditEvent*`, `AuditStateStoreTest.php`
  - Policy/scoring: `DocumentPolicyEngineTest.php`, `AuditFindingRulesNormalizationTest.php`, `AuditFieldValueTypeTest.php`, `DeliveryValidityEvaluatorTest.php`, `TextNormalizationTest.php`
  - MCP tools: `GetAttachmentsTest.php`, `GetDispensationTest.php`
- CI ejecuta pruebas unitarias con `vendor/bin/phpunit --configuration phpunit.xml --testdox --colors=always`.

## Ejecución local

1. Instalar dependencias:
   - `composer install`
2. Ejecutar pruebas:
   - `composer test`
   - o `vendor/bin/phpunit --configuration phpunit.xml`

## Alcance actual de cobertura

- Cobertura unitaria en reglas críticas del pipeline IA previo a Gemini.
- Cobertura unitaria en persistencia de resultados y reglas de actualización por hallazgos.
- No hay (aún) suite formal de integración end-to-end en `phpunit` para llamadas reales a SQL Server/Gemini.

## Guía para nuevas pruebas

- Mantener pruebas unitarias en `tests/` por dominio funcional (ej. `tests/Services/Audit/*`).
- Priorizar casos de borde en:
  - prevalidaciones de adjuntos/documentos requeridos,
  - mapeo de severidad y estado detallado,
  - persistencia de `AudDispEst` y actualización documental relacionada.
- Si se agregan pruebas de integración con servicios externos, separarlas explícitamente de la suite unitaria para no bloquear CI por dependencias de red.
