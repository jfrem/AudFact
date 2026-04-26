## [2026-04-26]

### fix
- **Ámbito**: normalización canónica de fechas documentales en el Golden Case `T38250701547`
  - Archivos modificados: `app/Services/Audit/Events/DocumentNormalizer.php`, `app/Services/Audit/Events/DocumentPolicyEngine.php`, `tests/Services/Audit/Events/DocumentNormalizerTest.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`
  - Hallazgo resuelto: ninguno
  - Impacto: `FechaEntrega`, `FechaAutorizacion`, `FechaFormula` y `FechaVencimiento` se canonalizan a `YYYY-MM-DD` y la policy compara formatos documentales equivalentes como coincidencia, evitando falsos `VALOR_DISTINTO` por diferencias `DD/MM/YYYY` vs `YYYY-MM-DD`

### feat
- **Ámbito**: evaluación semántica de productos mediante 'LLM as a Judge'
  - Archivos modificados: `app/Services/Audit/GeminiGatewayFactory.php`, `app/Services/Audit/SemanticMatchJudge.php`, `app/Services/Audit/Events/DocumentPolicyEngine.php`, `app/Services/Audit/Events/RulesEvaluationWorker.php`, `app/Services/Audit/Events/DocumentExtractionWorker.php`
  - Hallazgo resuelto: ninguno
  - Impacto: se implementa una evaluación determinística y asíncrona de equivalencia clínica/comercial usando function calling de Gemini para evitar el rechazo léxico rígido de productos homologables (ej. genéricos vs marcas), soportado con caché a 30 días para evitar latencia o peticiones redundantes.

### refactor
- **Ámbito**: clean code del módulo de persistencia debug `responseIA` sin cambios funcionales
  - Archivos modificados: `app/Services/Audit/GeminiGateway.php`, `app/Services/Audit/Debug/ResponseIADiskStore.php`
  - Hallazgo resuelto: ninguno
  - Impacto: se reduce duplicación, se encapsula la extracción de contexto debug y se separan responsabilidades de construcción/serialización/escritura atómica del snapshot para mejorar mantenibilidad y trazabilidad operativa

### fix
- **Ámbito**: persistencia robusta de snapshots request/response de Gemini en `responseIA/` para monitoreo y debug del pipeline
  - Archivos modificados: `app/Services/Audit/Debug/ResponseIADiskStore.php`, `app/Services/Audit/GeminiGateway.php`, `app/Services/Audit/Events/DocumentExtractionWorker.php`
  - Hallazgo resuelto: ninguno
  - Impacto: se reemplaza la escritura directa no verificada por una persistencia atómica y trazable con validación de directorio, validación de `json_encode`, validación de bytes escritos y metadata de correlación (`audit_id`, `document_id`, `dis_det_nro`, `document_type`, `status`)

### fix
- **Ámbito**: trazabilidad diagnóstica de pipeline de auditoría (sin cambio de lógica de negocio)
  - Archivos modificados: `app/Services/Audit/Events/DocumentExtractionWorker.php`, `app/Services/Audit/Events/InternalAuditApiClient.php`, `app/Services/Audit/Events/AuditEventConsumer.php`, `app/Models/AuditStatusModel.php`
  - Hallazgo resuelto: ninguno
  - Impacto: se agrega telemetría correlacionable por `auditId/eventId/documentId` en puntos críticos de extracción y persistencia para identificar causa raíz de cortes silenciosos, incluyendo clase/archivo/línea de excepción en fallos de consumer y transacción SQL

## [2026-04-25]

### fix
- **Ámbito**: resolución de timeout de red al conectar a la base de datos de escritura (default) desde contenedores Docker
  - Archivos modificados: core/Database.php
  - Hallazgo resuelto: ninguno
  - Impacto: se asegura la conexión TCP sobre el puerto explícito especificado en la configuración, omitiendo la resolución por instancia nombrada (SQL Browser) que fallaba desde la red del contenedor, restableciendo así la persistencia final de las auditorías.

## [2026-04-25]

### fix
- **Ámbito**: estabilización operativa del pipeline event-driven y salud Redis autenticada
  - Archivos modificados: `app/Services/Audit/Events/AuditEventConsumer.php`, `app/Services/Audit/Events/AuditAggregationWorker.php`, `docker-compose.yml`
  - Hallazgo resuelto: `ARCH-001`, `ARCH-002`, `SEC-002`
  - Impacto: el reclaim de PEL ya tolera cursores no válidos sin romper el consumer, los batch locks se liberan al cierre terminal del job y el healthcheck de Redis valida correctamente con `requirepass` activo

## [2026-04-24]

### fix
- **Ámbito**: materialización completa de respuestas Redis Streams con Predis 2.x
  - Archivos modificados: `core/RedisClient.php`, `tests/Core/RedisClientStreamParsingTest.php`
  - Hallazgo resuelto: ninguno
  - Impacto: `XREADGROUP` y `XRANGE` ya consumen de forma segura árboles de respuesta con iteradores anidados, evitando pérdida silenciosa de mensajes y desconexiones por `MultiBulk` no consumido

### refactor
- **Ámbito**: clean code sobre el pipeline de auditoría event-driven
  - Archivos modificados: `app/Services/Audit/Events/AuditFindingRules.php`, `app/Services/Audit/Events/DocumentPolicyEngine.php`, `app/Services/Audit/Events/AuditResultAggregator.php`, `app/Services/Audit/Events/RulesEvaluationWorker.php`, `app/Services/Audit/Events/DocumentExtractionWorker.php`, `app/Services/Audit/Events/DocumentNormalizer.php`
  - Hallazgo resuelto: `CC-PIPE-01`, `CC-PIPE-02`, `CC-PIPE-03`, `CC-PIPE-04`
  - Impacto: el pipeline centraliza reglas de hallazgos y métricas, reduce duplicación en policy/agregación, simplifica validaciones de extracción y normalización, y mantiene intacto el comportamiento contractual del golden case

### fix
- **Ámbito**: convergencia final del Golden Case `T38250701547`
  - Archivos modificados: `app/Services/Audit/Events/SchemaBuilder.php`, `app/Services/Audit/Events/ExtractionCache.php`, `app/Services/Audit/Events/DocumentPolicyEngine.php`, `app/Services/Audit/Events/AuditResultAggregator.php`, `app/Models/AuditStatusModel.php`, `.env.example`
  - Hallazgo resuelto: `GC-11`, `GC-12`, `GC-13`
  - Impacto: `DISPENSA` ya segmenta y consolida ítems correctamente, `/audit/results` invalida caché tras persistencia, el documento fallido prioriza el hallazgo correcto y la auditoría real de `T38250701547` converge a `manual_review` con `18/16/0/0/2` y `risk_score=20`

### fix
- **Ámbito**: autoridad documental, naming contractual y versionado de cache para el Golden Case `T38250701547`
  - Archivos modificados: `app/Services/Audit/FieldClassifier.php`, `app/Services/Audit/Events/DocumentPolicyEngine.php`, `app/Services/Audit/Events/DocumentExtractionWorker.php`, `app/Services/Audit/Events/ExtractionCache.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`, `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php`
  - Hallazgo resuelto: `GC-08`, `GC-09`, `GC-10`
  - Impacto: el policy engine expone nombres de campo contractuales, prioriza el hallazgo correcto por documento, deja de tratar `NombreArticulo` de fórmula como inconsistencia automática y fuerza reextracción de `DISPENSA` cuando el cache anterior quedó incompatible con la nueva segmentación por ítems

### fix
- **Ámbito**: limpieza documental del pipeline legacy eliminado
  - Archivos modificados: `README.md`, `AGENTS.md`
  - Hallazgo resuelto: `DOC-LEGACY-01`
  - Impacto: la documentación operativa deja de referenciar `POST /audit` y `AuditOrchestrator`, alineándose con el pipeline event-driven vigente

### fix
- **Ámbito**: corrección del consumo de Redis Streams para evitar auditorías congeladas al inicio
  - Archivos modificados: `core/RedisClient.php`, `app/Services/Audit/Events/AuditEventConsumer.php`, `tests/Services/Audit/Events/AuditEventConsumerTest.php`
  - Hallazgo resuelto: `INFRA-REDIS-01`, `INFRA-REDIS-02`
  - Impacto: el consumer group ya no nace en `'$'` dejando eventos invisibles, `NOGROUP` deja de silenciarse, y el worker falla de forma explícita en runtime ante pérdida del group en vez de quedar girando en vacío

### fix
- **Ámbito**: cierre semántico final del Golden Case para matching de `NombreArticulo`
  - Archivos modificados: `app/Services/Audit/Events/DocumentPolicyEngine.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`
  - Hallazgo resuelto: `GC-06`, `GC-07`
  - Impacto: el policy engine trata el producto no verificable en `FORMULA MEDICA` como `NO_CONCLUYENTE` y mantiene el matching prudente en `AUTORIZACION`, sin degradarlo a `NO_ENCONTRADO` o `VALOR_DISTINTO`

### fix
- **Ámbito**: alineación semántica del policy engine para el Golden Case `T38250701547`
  - Archivos modificados: `app/Services/Audit/Events/DocumentPolicyEngine.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`
  - Hallazgo resuelto: `GC-03`, `GC-04`, `GC-05`
  - Impacto: el policy engine deja de auditar `NumeroAutorizacion` en `FORMULA MEDICA`, trata `CodigoDiagnostico` faltante como incertidumbre documental, acepta nombres con el mismo set de tokens y evita discrepancias falsas sobre campos itemizados no determinísticos del MVP

### fix
- **Ámbito**: compatibilidad del schema Gemini para destrabar el Golden Case `T38250701547`
  - Archivos modificados: `app/Services/Audit/Events/SchemaBuilder.php`, `tests/Services/Audit/Events/SchemaBuilderTest.php`, `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php`, `tests/Services/Audit/Events/DocumentAuditOrchestratorTest.php`
  - Hallazgo resuelto: `GC-01`, `GC-02`
  - Impacto: el extractor deja de enviar claves incompatibles (`additionalProperties`, `response_template`) a Gemini y el pipeline vuelve a completar extracción, normalización, policy y persistencia final
