# SDD — Corrección de saturación, arbitraje semántico y contabilidad de intentos LLM

[CONFIRMADO] Solicitud: crear la especificación detallada de implementación de los hallazgos verificados, aplicando `write-sdd-spec` y `clean-rebuild-policy`, sin asumir que la documentación describe el runtime.

[CONFIRMADO] Fecha de descubrimiento: 2026-10-14. Checkout: `653378bc842286322820f10614e57f378a3de42e`, con modificaciones locales y archivos sin seguimiento. Evidencia congelada: `plans/evidence/saturacion-llm-2026-10-14.json`, con SHA256 de 28 archivos y consultas locales de cinco facturas.

[CONFIRMADO] El worker local observado ejecuta `ghcr.io/jfrem/audfact-php:16b7a7557f726f1e27882d3ee3667fcad95abfc0`; el checkout no acredita el código ejecutado por esa imagen. `/metrics/async` reporta ejecución 1. La consulta LAN a `172.16.0.3:8080` agotó su timeout; esta especificación no certifica producción.

[CONFIRMADO] Insumo externo analizado: `C:/Users/USER/.gemini/antigravity-ide/brain/9e6a6cf2-59d0-40a7-97c6-8dd1ed7567b5/diagnostico_integral_saturacion_llamadas_llm.md`. Sus afirmaciones se contrastaron con las fuentes anteriores; ni ese documento ni las SDD preexistentes sustituyen evidencia del código/runtime.

[INFERIDO] Identificador de cambio: `SDD-LLM-SAT-2026-10-14`. Todos los comportamientos objetivo, archivos nuevos y decisiones de esta especificación se clasifican como propuestas `[INFERIDO]`, no como implementación existente.

## FASE 0 — Descubrimiento Obligatorio

### Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Motor de artículos: cascada léxica y barrido de candidatos | [CONFIRMADO] | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php:778-872`. |
| Cobertura muchos-a-uno, sin consumo exclusivo de una línea documental | [CONFIRMADO] | Comentario del motor y `tests/Services/Audit/Events/DocumentPolicyEngineTest.php:1322`. |
| Clave v1 incluye contexto completo y ordena expected/actual | [CONFIRMADO] | `app/Services/Audit/SemanticMatchJudge.php:92-107`. |
| Contexto incluye cabecera y notas libres | [CONFIRMADO] | `DocumentPolicyEngine.php:974-999`; snapshots contienen indicadores de identidad y fecha. |
| Planificación y resolución v2 sin HTTP en policy | [CONFIRMADO] | `DocumentPolicyEngine.php:35-49`; `RulesEvaluationWorker.php:174-244`. |
| Caché v2 todavía incluye contexto completo | [CONFIRMADO] | `SemanticCandidateCollector.php:16-33`; `SemanticEvaluationStore.php:48-51`. |
| Agrupación, single-flight y límites 16 pares/32768 bytes/8192 tokens de entrada | [CONFIRMADO] | `SemanticEvaluationWorker.php`, `SemanticFlightStore.php`, `AuditExecutionConfig.php:12-16`. |
| Tareas, leases, fences, artifacts y continuaciones durables locales | [CONFIRMADO] | `FairAuditTaskQueue.php`, `AuditTaskRunner.php`, `AuditTaskCommitter.php`, `AuditTaskOutbox.php`. Existencia no equivale a prueba integral. |
| Reserva/finish/recuperación de cuota y recibos por intento | [CONFIRMADO] | `GeminiQuotaBudget.php:35-91,105-121,147-162,170-289`; `GeminiGateway.php:172-237`. |
| Costo SQL se obtiene de métricas agregadas del resultado | [CONFIRMADO] | `AuditResultPersistenceModel.php:240-301`; `AuditCostModel.php:51-149`. |
| Esquema SQL versionado en un archivo de migración | [CONFIRMADO] | `app/Models/migration/migration_AudDispCosto.sql`. No se inspeccionó `sys.columns` del servidor. |
| Dos procesos evaluaron la misma fórmula de la factura de referencia | [CONFIRMADO] | `logs/app-0bfd3cfe02b7-2026-10-14.log:1878`, 709711 ms; `logs/app-fa5890352ba3-2026-10-14.log:2141`, 94186 ms; mismo audit/document ID. |
| XAUTOCLAIM como causa exacta de esa duplicación | [INFERIDO] | Reclaim configurado a 600000 ms; evaluación supera ese límite; no existe un recibo del reclamo que demuestre la secuencia exacta. |
| Configuración y procedimientos de migración en código | [CONFIRMADO] | `AuditExecutionMigration.php`; `bin/audit-execution-maintenance.php`; `.github/workflows/deploy-production.yml:571-620`. |
| Modelo configurado frente a modelo devuelto | [CONFIRMADO] | Configuración/costos: `gemini-3.7-flash`; snapshots: `gemini-3.8-flash`. No se afirma que ambos identificadores sean idénticos. |
| Cuotas reales del proyecto, aplicaciones que las comparten e infraestructura productiva | [DESCONOCIDO] | Sin consulta autenticada a AI Studio ni diagnóstico LAN exitoso. |

### Información Faltante Crítica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| K01 — equivalencia funcional del contexto reducido y del arbitraje agrupado | [DESCONOCIDO] El código unitario y los resultados históricos no son un corpus clínico etiquetado independiente. | Bloquea aprobar la adopción productiva; T12 exige corpus y replay. No se altera un resultado para cumplir una meta de llamadas. |
| K02 — protocolo, imagen, jobs y planes activos reales en producción | [DESCONOCIDO] LAN inaccesible; el checkout incluye cambios no publicados. | Bloquea seleccionar imagen de rollback y ejecutar M04. |
| K03 — proyecto/modelo/tier y RPM/TPM/RPD compartidos | [DESCONOCIDO] Las cuotas no se deducen del modelo ni del histórico. | Bloquea iniciar v2; conservar rechazo de scope vacío/UNCONFIGURED y cuotas cero. |
| K04 — esquema, permisos DDL/DML y versión SQL reales | [DESCONOCIDO] Solo se leyó la migración y el consumidor SQL. | Ejecutar el preflight de §9 antes de DDL; discrepancia bloquea M02, sin adaptar tipos de forma silenciosa. |
| K05 — backup/restauración y durabilidad reales del volumen | [DESCONOCIDO] Hay procedimientos en código, sin ensayo productivo observado. | Bloquea el corte; evidencia externa exigida en M03. |

### Información Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| I01 — clasificación exacta de las respuestas adicionales de la factura de referencia | [DESCONOCIDO] Snapshots semánticos carecen de audit_id/document_id; logs correlacionan doble evaluación. | Generar conciliación histórica sin insertar costos históricos inventados. |
| I02 — coste de countTokens para la cuenta concreta | [DESCONOCIDO] La documentación consultada no acredita una tarifa específica de esa operación. | Separar medición de generación; no aplicar tarifas de generateContent al contador. |
| I03 — capacidad y latencia SQL/Redis bajo nueva proyección | [DESCONOCIDO] Sin benchmark comparable. | T13 y T14 miden proyección y backlog; no prometer p95 antes del ensayo. |

### Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| O01 — visualización administrativa del ledger | [DESCONOCIDO] No fue solicitada. | El MVP expone JSON paginado; no agrega pantallas. |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
| S01 | [INFERIDO] La alternativa incremental usa la infraestructura v2 existente después de auditarla y probarla. | Código descubierto; no existe ejecución v2 acreditada en el runtime consultado. | Gates G01-G05 bloquean su adopción si falla. |
| S02 | [INFERIDO] El emparejamiento vigente es el oráculo contractual para el replay, sujeto al corpus etiquetado de K01. | Prueba N:1 y resultados públicos. | Un cambio funcional exige revisar esta SDD; no bajar umbrales para hacerlo pasar. |
| S03 | [INFERIDO] Redis standalone/Sentinel durable conserva el registro financiero hasta SQL. | Protocolo actual exige AOF always/noeviction y rechaza Cluster. | Persistencia física y restauración requieren K05; pérdida de disco sigue siendo riesgo. |
| S04 | [INFERIDO] Esquema SQL esperado coincide con la migración leída. | Migración local de AudDispCosto. | Preflight K04 aborta ante discrepancias. |
| S05 | [INFERIDO] Tarifas de generación STANDARD se congelan por versión y período, con cotización verificable. | Constantes locales 0.75/3.75; precio oficial observado para gemini-3.8-flash. | Modelo/tier/período no cubierto queda sin precio; no atribuir tarifa por nombre aproximado. |

### Clasificación de Completitud Inicial

[INFERIDO] **Nivel C — Diseño Parcial para aprobación integral**, por K01-K05. El diseño de código propuesto se fija en las secciones siguientes; no se presenta el corte productivo como ejecutable sin resolver esos datos. No se inventan cuotas, etiquetas clínicas, permisos ni una imagen de rollback.

## FASE 1 — Especificación

### 1. Objetivo

[CONFIRMADO] En `V12260406614`, SQL expone 156 generaciones, 153 semánticas, 156283 tokens, USD 0.30988425 y policy máximo de 709711 ms. El resultado es `completed`, 26 coincidencias y cero discrepancias. El conjunto de snapshots registra 175 respuestas y 173943 tokens; ambos conteos tienen fuentes diferentes.

[CONFIRMADO] El replay aislado de los 17 artículos reales produjo 151 comparaciones de productos. El conteo persistido de 153 incluye comparaciones de personas. Un escenario sintético sin matches produce 289 comparaciones para 17 por 17; cuatro artículos idénticos producen cero llamadas. No existe garantía general triangular ni límite N.

[INFERIDO] El objetivo es reducir generaciones evitables, impedir efectos funcionales de propietarios vencidos, reutilizar equivalencias con contexto suficiente y conservar todos los intentos conocidos en un ledger durable. El fallo observado es operativo/financiero; esta muestra no demuestra un error clínico final.

### 2. Alcance

#### Incluido

- [INFERIDO] R01: normalización conservadora de formato de artículos y ordenamiento estable de candidatos, preservando N:1.
- [INFERIDO] R02: contexto mínimo por propósito y caché nueva versionada con identidad direccional.
- [INFERIDO] R03: preparación de policy sin HTTP y agrupación v2 con límites existentes.
- [INFERIDO] R04: verificación de ownership/deadline antes de cada HTTP y conservación de uso conocido aun si el fence vence después de la respuesta.
- [INFERIDO] R05: presupuesto único de extracción/semántica y backpressure por proyección financiera pendiente.
- [INFERIDO] R06: journal por intento, proyección SQL idempotente y resumen por auditoría, incluyendo auditorías fallidas.
- [INFERIDO] R07: API de intentos paginada y metadatos aditivos de completitud de costos.
- [INFERIDO] R08: métricas diferenciadas de pares, generaciones, mediciones, uso desconocido y espera; reconciliación histórica sin reescribir importes.
- [INFERIDO] R09: migración, retiro E01, rollback, pruebas de recuperación y evidencia de aprobación.

#### Excluido

- [INFERIDO] Configuración de clientes, severidades, vigencia, extracción multimodal, reconciliación de adjuntos y reglas cuantitativas existentes.
- [INFERIDO] Cambios de proveedor/modelo, aumento de réplicas, pantallas de costos, autenticación general y limpieza de deuda ajena al cambio.
- [INFERIDO] Recalcular automáticamente la facturación histórica desde snapshots o certificar importes cobrados por Google.
- [INFERIDO] Ejecutar producción, modificar credenciales o sincronizar variables durante esta entrega documental.

### 3. Non Goals

- [INFERIDO] No imponer N llamadas como garantía algorítmica; el peor caso conserva U×M pares.
- [INFERIDO] No excluir una línea por haber respaldado otra fila FDV; no usar umbrales arbitrarios 0.25/0.30 para descartar equivalencias.
- [INFERIDO] No aprobar equivalencias por marcas, sales, dosis o liberación inferidas a partir de un diccionario nuevo.
- [INFERIDO] No convertir falta de cuota, caída de SQL o timeout en discrepancia clínica.
- [INFERIDO] No agregar otro scheduler, otro perfil de ejecución ni una capa genérica de repositorios.

### 4. Estado Actual

[CONFIRMADO] Flujo v1: `document_normalized → RulesEvaluationWorker::handle → DocumentPolicyEngine::evaluate → SemanticMatchJudge::evaluate → GeminiGateway → markDocumentEvaluated → AuditPersistenceQueue → SQL`. El worker queda esperando HTTP durante policy.

[CONFIRMADO] Flujo local v2: intake guarda tarea antes del ACK; policy prepara snapshot/candidatos; semantic ejecuta ondas; `semantic_evaluation_resolved` retoma policy. `SemanticEvaluationStore::waves` elige el candidato en el cursor de cada fila, conservando el orden del motor. No existe ordenamiento léxico de candidatos en esos componentes.

[CONFIRMADO] `GeminiGateway::executeTaskRequest` guarda artifact antes de finish; si `saveArtifact` retorna false tras recibir respuesta, lanza `gemini_task_stale` antes de terminar el recibo de cuota. `AuditTaskRunner::attemptMetrics` no conserva thoughts/modelVersion/serviceTier. `AuditExecutionMaintenance::gc` elimina recibos terminales por horizonte y no comprueba una proyección SQL financiera, todavía inexistente.

[CONFIRMADO] Contrato de coste actual: `AudDispCosto` tiene PK `AudCostoId`, unicidad `(FacNro,Intento)` y modelo/tarifas por defecto. `recordCost` calcula MAX(Intento)+1; la llamada del resultado no transmite el modelo devuelto. Los endpoints de costos consultan ese resumen, no un ledger HTTP.

[CONFIRMADO] Los endpoints actuales de costos no registran middleware auth en `app/Routes/web.php:51-55`; la autorización del endpoint propuesto conserva la política existente. La remediación de autenticación está fuera del alcance declarado, sin afirmar que exista protección adicional.

### 5. Estado Objetivo

[INFERIDO] Flujo final v2:

```text
document_normalized → tarea policy con snapshot congelado
  → matches léxicos existentes + igualdad canónica de formato
  → filas pendientes con candidatos ordenados y reutilizables
  → caché v7 / single-flight → ondas semánticas ≤16 pares
  → reserva de cuota + recibo y journal durables
  → marca de dispatch → un HTTP autorizado → artifact y finish
  → continuación semántica completa → evaluación PHP final → SQL funcional
journal financiero → proyección SQL independiente → resumen por AuditId
```

[INFERIDO] Responsabilidades: `ArticleCandidatePlanner` ordena y canoniza formato; `SemanticComparisonContext` define el contexto; el motor conserva las decisiones; `SemanticEvaluationStore` congela orden e identidad; `GeminiQuotaBudget` posee recibos/dispatch/dirty; `GeminiGateway` conserva respuesta/uso; `GeminiAttemptJournal` proyecta; modelos escriben SQL; controladores serializan allowlists.

[INFERIDO] Una mejora en tiempo CPU de policy no acredita reducción de latencia total. El benchmark registra espera semántica y duración total junto con generaciones y tokens.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| D01 | [INFERIDO] Refactorización incremental; v2 es base condicionada a G01-G05. | Reconstrucción completa y tercer protocolo. | Contratos HTTP/SQL/colas y trabajos activos. |
| D02 | [INFERIDO] Aplicar el nuevo emparejamiento solo al perfil `candidates_v2`; v1 conserva su comportamiento durante E01. | Cambiar reglas de tareas en vuelo. | Replay y rollback requieren interpretar snapshots de forma estable. |
| D03 | [INFERIDO] Ordenar todos los candidatos, sin poda por asignación ni eliminación por score. | Consumo exclusivo de índices; top-K irreversible. | Preserva cobertura N:1 y casos genérico/comercial. |
| D04 | [INFERIDO] Nueva caché `semantic:match:v7`; hash direccional, contexto mínimo, modelo solicitado, versiones. | Reutilizar v5/v6 o ordenar expected/actual. | Contexto y roles forman parte del contrato de inferencia. |
| D05 | [INFERIDO] Ledger Redis durable con proyección SQL independiente del resultado funcional. | Escritura SQL sincrónica previa a cada HTTP; costo únicamente en auditoría exitosa. | No bloquear el HTTP con la transacción SQL; conservar consumo de fallos y respuestas tardías. |
| D06 | [INFERIDO] Nuevas columnas aditivas en AudDispCosto y tabla AudDispGeminiIntento, sin FK del ledger al resultado. | Reescribir filas históricas o exigir resultado SQL antes de registrar gasto. | El intento existe antes de terminar la auditoría. |
| D07 | [INFERIDO] Costos numéricos v2 son suma conocida/preciada; metadatos indican parcialidad. Histórico v1 declara completitud desconocida. | Convertir unknown en cero definitivo. | Evita falsa certeza y mantiene tipos públicos. |
| D08 | [INFERIDO] count_tokens se registra separadamente sin monetización por tarifas de generación. | Duplicar su totalTokens en consumo de generate_content. | K/I02 no acredita tarifa; es medición de entrada. |
| D09 | [INFERIDO] Capturar tarifa por versión/fecha/modelo reportado/tier; caso no cubierto queda sin precio. | Default silencioso del modelo de costos. | Alias configurado no prueba modelo ejecutado. |
| D10 | [INFERIDO] Journal limita dirty a 100000 registros; proyección corre en proceso independiente `worker-cost-projection`, con máximo 20 por iteración y 2 s de presupuesto para comenzar escrituras. | SQL dentro del mantenimiento de intake/heartbeat. | Una operación SQL conserva su timeout/retry; su bloqueo no detiene el mantenimiento. No agregar una etapa al scheduler de auditorías. |
| D11 | [INFERIDO] No afirmar exactly-once del proveedor: un timeout o caída tras envío conserva unknown. | Prometer eliminación absoluta de reintentos físicos. | No hay transacción atómica Redis/API. |
| D12 | [INFERIDO] Preservar nueva imagen de diagnóstico financiero al volver a execution=1. | Rollback inmediato a imagen incapaz de proyectar journal pendiente. | El consumo registrado debe poder terminar de proyectarse aun durante rollback. |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| PHP / PHPUnit | [CONFIRMADO] Runtime / pruebas | CLI observado 8.2.12 / 10.5.63; contenedor definido en Dockerfile. | Verificar también imagen PHP destino. |
| Predis / Guzzle | [CONFIRMADO] Librerías | Composer require `^2.0` / `^7.10`; resolver build desde composer.lock. | Lua, HTTP y mocks existentes. |
| Redis | [CONFIRMADO] Cola / infraestructura | Imagen definida `redis:7-alpine`; versión productiva exacta desconocida. | v2 exige standalone/Sentinel, AOF always/noeviction, sin expiración de dirty. |
| SQL Server / PDO sqlsrv | [CONFIRMADO] Base / driver | SQL físico desconocido K04. | Preflight y prueba SQL aislada obligatorios. |
| Gemini Developer API | [CONFIRMADO] API | v1beta; respuesta local observada 3.8 Flash. | Smoke valida requested/returned/tier y Structured Outputs. |
| Google cuotas | [CONFIRMADO] Servicio | Por proyecto; TPM mide entrada. | [Fuente oficial](https://ai.google.dev/gemini-api/docs/rate-limits); scope y límites efectivos desconocidos K03. |
| Tarifas STANDARD | [CONFIRMADO] Fuente de precios observada | 3.8 Flash: USD 0.75 entrada / 3.75 salida incl. thinking por millón hasta 2026-12-31. | [Fuente oficial](https://ai.google.dev/gemini-api/docs/pricing); congelar evidencia al release, no extrapolar a otros tiers. |
| CurrencyExchangeService | [CONFIRMADO] Servicio de dominio | Código local. | TRM del primer resumen por AuditId; conservarla en actualizaciones tardías. |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| [INFERIDO] Cada artículo FDV necesita respaldo; una línea puede reutilizarse. | Motor/planner sin assignedDocIndices. | T01, T02, A01. |
| [INFERIDO] Dosis/decimales/unidades/porcentajes/combinaciones/liberación se preservan. | Canonicalizador limitado a formato, igualdad completa para el nuevo match. | T01, T12. |
| [INFERIDO] Resultado provisional no se publica. | Continuación solo con plan ready, validación de generación/fingerprint. | T03, T04. |
| [INFERIDO] Cache key representa exactamente evidencia/modelo/protocolo y dirección. | Un único builder usado por collector, store y resolver. | T02, T03. |
| [INFERIDO] Un propietario vencido no empieza HTTP ni confirma negocio. | Validación owner/fence/deadline en dispatch y commit. | T05, T06. |
| [INFERIDO] Uso conocido de una respuesta tardía no se descarta por fence. | Finish financiero independiente del commit de negocio. | T06, T07. |
| [INFERIDO] Cada AttemptId cuenta una vez; el mayor Revision prevalece. | PK SQL + upsert serializable + ACK CAS. | T07-T10. |
| [INFERIDO] Unknown nunca se convierte en costo cero confirmado. | Nullable usage/cost y costo_completo=false. | T08, T11. |
| [INFERIDO] No GC con proyección pendiente. | Referencia por intento + guard dirty/revision en GC. | T09, T10. |
| [INFERIDO] Entidades funcionales y slots SQL se conservan durante migración/rollback. | Manifiesto y verify existentes, ampliados al journal. | T10, T15. |
| [INFERIDO] No secretos/PII libre en journal, métricas o logs. | Allowlists; EPS enmascarada; hashes en errores. | T02, T11. |

### 9. Modelo de Datos

[CONFIRMADO] El esquema base esperado de AudDispCosto procede del archivo de migración, no de una introspección productiva. La nueva tabla y columnas siguientes son propuestas `[INFERIDO]`.

[INFERIDO] Tabla nueva: `Discolnet.dbo.AudDispGeminiIntento`. Modificación aditiva: `AudDispCosto.AuditId`, `ContabilidadVersion`, `CostoCompleto`, `AuditoriaTerminal`, `IntentosUsoDesconocido`, `IntentosSinTarifa`, `FechaActualizacionCostoUtc`. No eliminar tablas/columnas. No crear triggers, vistas, procedimientos ni FK al ledger; AuditId representa una raíz Redis, no una PK de AudDispEst. `AudDispGeminiIntento.AttemptId` es UUID; RequestId/TaskId/BodyHash son SHA256; identidades siguen `FacNro=dis_det_nro`, `FacSec=dis_id`.

[INFERIDO] Preflight exacto, antes de DDL, por conexión default. Si falta una columna base, cambia un tipo/tamaño/nullability o falla permiso, terminar con error y conservar admission paused:

```sql
USE [Discolnet];
SELECT SERVERPROPERTY('ProductVersion') AS ProductVersion,
       HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'CREATE TABLE') AS CanCreateTable,
       HAS_PERMS_BY_NAME('dbo.AudDispCosto', 'OBJECT', 'ALTER') AS CanAlterCost;
SELECT c.name, t.name AS type_name, c.max_length, c.precision, c.scale, c.is_nullable
FROM sys.columns c JOIN sys.types t ON c.user_type_id=t.user_type_id
WHERE c.object_id=OBJECT_ID(N'dbo.AudDispCosto') ORDER BY c.column_id;
SELECT name FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.AudDispCosto');
```

#### DDL

[INFERIDO] Archivo futuro `app/Models/migration/migration_AudDispGeminiIntento.sql`. Primera ejecución en BD de pruebas; no ejecutado durante esta especificación. Reejecutar es válido solo si el esquema existente coincide exactamente; el migrador compara las columnas/constraints esperadas antes de omitir creación.

```sql
USE [Discolnet];
SET XACT_ABORT ON;
BEGIN TRANSACTION;
IF OBJECT_ID(N'dbo.AudDispCosto',N'U') IS NULL
    THROW 51000, 'AudDispCosto base ausente', 1;
IF COL_LENGTH(N'dbo.AudDispCosto',N'AuditId') IS NULL
    ALTER TABLE dbo.AudDispCosto ADD AuditId UNIQUEIDENTIFIER NULL;
IF COL_LENGTH(N'dbo.AudDispCosto',N'ContabilidadVersion') IS NULL
    ALTER TABLE dbo.AudDispCosto ADD ContabilidadVersion SMALLINT NOT NULL
        CONSTRAINT DF_AudDispCosto_ContabilidadVersion DEFAULT(1);
IF COL_LENGTH(N'dbo.AudDispCosto',N'CostoCompleto') IS NULL
    ALTER TABLE dbo.AudDispCosto ADD CostoCompleto BIT NULL;
IF COL_LENGTH(N'dbo.AudDispCosto',N'AuditoriaTerminal') IS NULL
    ALTER TABLE dbo.AudDispCosto ADD AuditoriaTerminal BIT NOT NULL
        CONSTRAINT DF_AudDispCosto_AuditoriaTerminal DEFAULT(0);
IF COL_LENGTH(N'dbo.AudDispCosto',N'IntentosUsoDesconocido') IS NULL
    ALTER TABLE dbo.AudDispCosto ADD IntentosUsoDesconocido INT NOT NULL
        CONSTRAINT DF_AudDispCosto_UsoDesconocido DEFAULT(0);
IF COL_LENGTH(N'dbo.AudDispCosto',N'IntentosSinTarifa') IS NULL
    ALTER TABLE dbo.AudDispCosto ADD IntentosSinTarifa INT NOT NULL
        CONSTRAINT DF_AudDispCosto_SinTarifa DEFAULT(0);
IF COL_LENGTH(N'dbo.AudDispCosto',N'FechaActualizacionCostoUtc') IS NULL
    ALTER TABLE dbo.AudDispCosto ADD FechaActualizacionCostoUtc DATETIME2(3) NULL;
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.AudDispCosto') AND name=N'UQ_AudDispCosto_AuditId')
    EXEC(N'CREATE UNIQUE INDEX UQ_AudDispCosto_AuditId ON dbo.AudDispCosto(AuditId) WHERE AuditId IS NOT NULL');
IF OBJECT_ID(N'dbo.AudDispGeminiIntento',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.AudDispGeminiIntento(
        AttemptId UNIQUEIDENTIFIER NOT NULL,
        AuditId UNIQUEIDENTIFIER NOT NULL,
        DocumentId VARCHAR(100) NULL,
        RequestId CHAR(64) NOT NULL,
        TaskId CHAR(64) NOT NULL,
        BodyHash CHAR(64) NOT NULL,
        FacNro VARCHAR(100) NOT NULL,
        FacNitSec VARCHAR(100) NOT NULL,
        FacSec NVARCHAR(320) NULL,
        Stage VARCHAR(16) NOT NULL,
        Operation VARCHAR(24) NOT NULL,
        State VARCHAR(16) NOT NULL,
        Revision BIGINT NOT NULL,
        RequestedModel VARCHAR(100) NOT NULL,
        ReturnedModel VARCHAR(100) NULL,
        ServiceTier VARCHAR(32) NULL,
        StartedAtMs BIGINT NOT NULL,
        DispatchStartedAtMs BIGINT NULL,
        FinishedAtMs BIGINT NULL,
        ProviderCode INT NULL,
        UsoConocido BIT NOT NULL,
        TokensEntrada BIGINT NULL,
        TokensSalida BIGINT NULL,
        TokensPensamiento BIGINT NULL,
        TotalTokens BIGINT NULL,
        MeasuredInputTokens BIGINT NULL,
        VersionTarifa VARCHAR(100) NULL,
        TarifaEntrada1M DECIMAL(10,4) NULL,
        TarifaSalida1M DECIMAL(10,4) NULL,
        CostoUSD DECIMAL(18,8) NULL,
        CapturadoUtc DATETIME2(3) NOT NULL CONSTRAINT DF_AudDispGeminiIntento_Capturado DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_AudDispGeminiIntento PRIMARY KEY(AttemptId),
        CONSTRAINT CK_AudDispGeminiIntento_Stage CHECK(Stage IN('extraction','semantic')),
        CONSTRAINT CK_AudDispGeminiIntento_Operation CHECK(Operation IN('count_tokens','generate_content')),
        CONSTRAINT CK_AudDispGeminiIntento_State CHECK(State IN('reserved','inflight','completed','failed','unknown','not_sent')),
        CONSTRAINT CK_AudDispGeminiIntento_Revision CHECK(Revision>0),
        CONSTRAINT CK_AudDispGeminiIntento_Tokens CHECK(
            (TokensEntrada IS NULL OR TokensEntrada>=0) AND
            (TokensSalida IS NULL OR TokensSalida>=0) AND
            (TokensPensamiento IS NULL OR TokensPensamiento>=0) AND
            (TotalTokens IS NULL OR TotalTokens>=0) AND
            (MeasuredInputTokens IS NULL OR MeasuredInputTokens>=0)),
        CONSTRAINT CK_AudDispGeminiIntento_Uso CHECK(
            (UsoConocido=0 AND TokensEntrada IS NULL AND TokensSalida IS NULL AND TokensPensamiento IS NULL AND TotalTokens IS NULL) OR
            (UsoConocido=1 AND TokensEntrada IS NOT NULL AND TokensSalida IS NOT NULL AND TokensPensamiento IS NOT NULL AND TotalTokens IS NOT NULL)),
        CONSTRAINT CK_AudDispGeminiIntento_Costo CHECK(CostoUSD IS NULL OR CostoUSD>=0)
    );
    CREATE INDEX IX_AudDispGeminiIntento_Audit ON dbo.AudDispGeminiIntento(AuditId,Operation)
        INCLUDE(State,UsoConocido,TotalTokens,CostoUSD,Revision);
    CREATE INDEX IX_AudDispGeminiIntento_Factura ON dbo.AudDispGeminiIntento(FacNro,StartedAtMs,AttemptId);
END;
COMMIT TRANSACTION;
```

[INFERIDO] Validación PHP adicional: longitudes exactas de hashes/UUID, campos monetarios consistentes, tiempos ordenados, inmutabilidad de identidad y estado, uso conocido solo para respuestas con metadata válida. `TotalTokens` conserva el valor reportado; no exigir igualdad con la suma de subcontadores si el proveedor la contradice: registrar `token_breakdown_mismatch`, conservar todos los valores y bloquear la conciliación completa.

#### Orden de Ejecución

1. [INFERIDO] M01: resolver K04 y comparar esquema base con la migración versionada.
2. [INFERIDO] M02: aplicar DDL aditivo; comparar esquema final; verificar INSERT/UPDATE/SELECT con rollback de transacción en BD aislada.
3. [INFERIDO] Publicar imagen con código nuevo manteniendo execution=1; verificar lecturas históricas y schema del ledger.
4. [INFERIDO] Habilitar v2 únicamente por M04 y G01-G05. No insertar ledger ficticio para v1.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
| [INFERIDO] AudDispCosto histórico | Agregar defaults: versión 1, AuditId null, CostoCompleto null. No modificar importes. | Misma tabla. | Conteo/checksum de columnas anteriores intactos. |
| [INFERIDO] Recibos v2 futuros con journal_version=1 | Upsert por AttemptId/Revision. | AudDispGeminiIntento. | SQL revision igual a recibo ACKed. |
| [INFERIDO] Recibos v2 anteriores al cambio | Sin identidad suficiente o sin dispatch/modelo: conservar evidencia, marcar no importable. | Manifiesto de diagnóstico; no inventar ledger. | El corte exige cero recibos activos de ese formato; desconocidos bloquean. |
| [INFERIDO] Snapshots históricos | Recuento/hash y conciliación por fuente. | Informe de evidencia, sin DML financiero. | Diferencias explícitas entre agregado y respuestas observadas. |

#### Rollback

[INFERIDO] Rollback operativo conserva tablas y columnas nuevas para no destruir evidencia. El rollback físico siguiente solo se ejecuta con admisión detenida, journal vacío y cero registros v2; su protección rechaza retirar evidencia financiera. Archivo futuro `app/Models/migration/rollback_AudDispGeminiIntento.sql`:

```sql
USE [Discolnet];
SET XACT_ABORT ON;
BEGIN TRANSACTION;
IF OBJECT_ID(N'dbo.AudDispGeminiIntento',N'U') IS NOT NULL
   AND EXISTS(SELECT 1 FROM dbo.AudDispGeminiIntento)
    THROW 51001,'Rollback fisico bloqueado: ledger contiene evidencia',1;
IF COL_LENGTH(N'dbo.AudDispCosto',N'ContabilidadVersion') IS NOT NULL
BEGIN
    DECLARE @hasV2 BIT=0;
    EXEC sys.sp_executesql N'SELECT @v=CASE WHEN EXISTS(SELECT 1 FROM dbo.AudDispCosto WHERE ContabilidadVersion=2) THEN 1 ELSE 0 END',N'@v BIT OUTPUT',@v=@hasV2 OUTPUT;
    IF @hasV2=1 THROW 51002,'Rollback fisico bloqueado: costos v2 presentes',1;
END;
IF OBJECT_ID(N'dbo.AudDispGeminiIntento',N'U') IS NOT NULL DROP TABLE dbo.AudDispGeminiIntento;
IF EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.AudDispCosto') AND name=N'UQ_AudDispCosto_AuditId')
    DROP INDEX UQ_AudDispCosto_AuditId ON dbo.AudDispCosto;
IF OBJECT_ID(N'dbo.DF_AudDispCosto_ContabilidadVersion',N'D') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP CONSTRAINT DF_AudDispCosto_ContabilidadVersion;
IF OBJECT_ID(N'dbo.DF_AudDispCosto_AuditoriaTerminal',N'D') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP CONSTRAINT DF_AudDispCosto_AuditoriaTerminal;
IF OBJECT_ID(N'dbo.DF_AudDispCosto_UsoDesconocido',N'D') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP CONSTRAINT DF_AudDispCosto_UsoDesconocido;
IF OBJECT_ID(N'dbo.DF_AudDispCosto_SinTarifa',N'D') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP CONSTRAINT DF_AudDispCosto_SinTarifa;
IF COL_LENGTH(N'dbo.AudDispCosto',N'AuditId') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP COLUMN AuditId;
IF COL_LENGTH(N'dbo.AudDispCosto',N'ContabilidadVersion') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP COLUMN ContabilidadVersion;
IF COL_LENGTH(N'dbo.AudDispCosto',N'CostoCompleto') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP COLUMN CostoCompleto;
IF COL_LENGTH(N'dbo.AudDispCosto',N'AuditoriaTerminal') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP COLUMN AuditoriaTerminal;
IF COL_LENGTH(N'dbo.AudDispCosto',N'IntentosUsoDesconocido') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP COLUMN IntentosUsoDesconocido;
IF COL_LENGTH(N'dbo.AudDispCosto',N'IntentosSinTarifa') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP COLUMN IntentosSinTarifa;
IF COL_LENGTH(N'dbo.AudDispCosto',N'FechaActualizacionCostoUtc') IS NOT NULL ALTER TABLE dbo.AudDispCosto DROP COLUMN FechaActualizacionCostoUtc;
COMMIT TRANSACTION;
```

### 10. Contratos

#### Antes

- [CONFIRMADO] `SemanticMatchResolver::evaluate(string $expected,string $actual,array $context=[]): array` devuelve `is_match`, `reasoning` y métricas/caché opcionales; collector/resolver implementan la misma firma.
- [CONFIRMADO] `DocumentPolicyEngine::plan(array $documentState,array $normalizedPayload,string $facNro=''): array` devuelve requires_semantics/candidates/provisional_result; evaluateResolved usa un snapshot.
- [CONFIRMADO] Plan schema_version=2 guarda snapshot, pairs, rows/cursor, requests y generation; eventos semánticos viajan en `audit.semantic.priority|batch` y continuación en documents.
- [CONFIRMADO] Recibo `gemini:attempt:v2:{AttemptId}` guarda identidad de auditoría/tarea/operación y usage. No tiene FacNro/FacNitSec/FacSec/modelo/tier/Revision financiero.
- [CONFIRMADO] `/audit/costs/{facNro}` devuelve resumen e intentos de auditoría; no enumera solicitudes HTTP. Ejemplo persistido completo, sin identidad clínica; EPS e identidad de dispensación son metadata operacional:

```json
{"success":true,"message":"Operación exitosa","data":{"fac_nro":"V12260406614","total_intentos":1,"gran_total_tokens":156283,"costo_total_acumulado_usd":0.30988425,"costo_total_acumulado_cop":989.9066,"intentos":[{"aud_costo_id":3684,"fac_nro":"V12260406614","fac_nit_sec":"2624","cliente":"NUEVA EPS SA","fac_sec":"DIS26-4-23-16-33-7-2456A54A0AB3CB925690652FB16996151B9","intento":1,"tokens_entrada":92059,"tokens_salida":20729,"tokens_pensamiento":43495,"total_tokens":156283,"costo_usd":0.30988425,"trm_aplicada":3194.44,"costo_cop":989.9066,"docs_procesados":3,"docs_cache_hit":0,"es_cache_hit_total":false,"modelo_ia":"gemini-3.7-flash","fecha_auditoria":"2026-10-14 03:30:50"}]}}
```

#### Después

[INFERIDO] C01 — mantener la firma del resolver. Agregar al constructor del motor `SemanticPlanningProfile $profile = SemanticPlanningProfile::LEGACY_V1`. Enum nuevo con `legacy_v1` y `candidates_v2`. `plan` instancia CANDIDATES_V2; `evaluateResolved` exige snapshot.versions.matching=`article-candidates-v1` y snapshot.versions.context=`semantic-context-v1`. Un valor desconocido lanza DomainException. v1 no cambia durante E01. No conservar lector especulativo de planes antiguos: el preflight bloquea si existen planes activos sin estas versiones.

[INFERIDO] C02 — `ArticleCandidatePlanner::canonicalize(string): string` aplica en este orden: stripAccents/uppercase; elimina token de empaque con regex `/(?<![A-Z0-9])C\*[0-9]+(?![A-Z0-9])/u`; inserta espacio entre número/unidad con `/(?<=\d)(?=(?:MCG|MG|GR|G|ML|UI)\b)/u`; colapsa whitespace y trim. Preserva `+ / % . ,`, paréntesis y términos restantes. No convierte unidades ni elimina sales, formulación, envase o liberación. El nuevo match exige igualdad completa de textos canónicos no vacíos. Para sets, conservar fases léxicas anteriores y sus umbrales; luego igualdad canónica de cada fila pendiente contra documentos en orden original; luego fallback ordenado. Para artículo escalar, igualdad canónica antes del fallback, preservando comparaciones previas. `rank(string $expected,array $actuals): array` devuelve índices ordenados por TextNormalization::similarity(canonical), descendente; empate por índice original ascendente. Reutilización de índices permitida. Score solo ordena: nunca acepta ni descarta un par.

[INFERIDO] C03 — `SemanticComparisonContext::forPair(SemanticMatchPurpose $purpose,string $documentType): self`, `toArray(): array`, `toCanonicalString(): string`. Producto usa exclusivamente `{schema_version:1,purpose:article_homologation,document_type:<tipo>,evidence_source:pair_values}`; nombres usan `{schema_version:1,purpose:person_name_match,evidence_source:pair_values}`. No copiar cabecera, quality_notes, identidad ni fechas. La evidencia es expected/actual originales; ausencia de concentración no se completa desde otros artículos. En profile CANDIDATES_V2, ambos puntos de fallback (`evaluateArticleSetField` y `evaluateSemanticField`, personas/artículos) usan esta factory y document_context es su JSON canónico; profile LEGACY_V1 conserva buildDocumentContextSummary. K01 verifica la equivalencia de este cambio antes de adoptarlo.

[INFERIDO] C04 — identidad y caché: pair_id=SHA256(JSON canónico `[purpose,field,expected_original,actual_original,context_canon,context_version,matching_version]`), row_id conserva purpose/field/semantic_row_index. No ordenar expected/actual. Store hash agrega requested_model, prompt/schema/extractor/normalizer/rules versions y batch contract version=`semantic-batch-v2`; cacheKey usa namespace v7. Collector y resolver llaman el mismo builder. No leer cachés v5/v6; no borrar esas claves globalmente. Snapshot congela el orden usado para recolectar y resolver. No recalcular FDV desde SQL al retomar.

[INFERIDO] C05 — conservar enum/eventos/carriles y payload de solicitud semántica. No agregar eventos funcionales para costos. El snapshot agrega dos versiones internas; la proyección financiera usa recibos independientes. Un ejemplo completo del contexto nuevo es:

```json
{"schema_version":1,"purpose":"article_homologation","document_type":"FORMULA MEDICA","evidence_source":"pair_values"}
```

[INFERIDO] C06 — extender cada recibo desde RESERVE con journal_version=1, revision=1, identidad inmutable `{fac_nro,fac_nit_sec,fac_sec}`, requested_model, dispatch_started_at_ms=null y SQL projection receipt. Obtener identidad desde estado audit (`dis_det_nro`, `fac_nit_sec`, `dis_id`) antes de reservar; datos faltantes impiden HTTP con fallo técnico `gemini_attempt_identity_missing`. Los modelos/longitudes inválidos fallan en factory, sin truncar.

[INFERIDO] C07 — journal Redis standalone: `gemini:journal:v1:dirty` ZSET AttemptId→due_at_ms, `gemini:journal:v1:acks` HASH AttemptId→Revision proyectada, `gemini:journal:v1:blocked` STRING sin TTL y referencia `attempt:<AttemptId>` en `audit:execution:refs:{audit_id}`. RESERVE guarda recibo, dirty y referencia en el mismo Lua, después de validar tipos y antes de retornar GRANTED. Dirty≥100000 retorna DEFERRED due=now+1000/reason=journal_capacity sin consumir cuota ni crear intento. Blocked=1 retorna DEFERRED reason=journal_conflict y cierra nueva admisión v2. Conflicto de identidad/revision/recibo corrupto fija blocked=1; un error de conectividad SQL solo reprograma. `journal-check --clear-block` elimina blocked únicamente tras verificación sin conflictos/missing/revision_mismatches y pending=0; falla no lo elimina. No TTL de recibo/dirty/ACK pendientes. Payload JSON completo de ejemplo; UUID/hash son datos sintéticos válidos de transporte, no facturas reales:

```json
{"journal_version":1,"revision":3,"attempt_id":"11111111-1111-4111-8111-111111111111","request_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","task_id":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","audit_id":"22222222-2222-4222-8222-222222222222","document_id":"doc-1","job_id":null,"stage":"semantic","operation":"generate_content","body_hash":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","started_at_ms":1791986400000,"expires_at_ms":1791987000000,"state":"completed","reserved_input_tokens":420,"fac_nro":"TEST-LLM-001","fac_nit_sec":"TEST-CLIENT","fac_sec":"TEST-DIS-001","requested_model":"gemini-3.7-flash","dispatch_started_at_ms":1791986400010,"finished_at_ms":1791986404000,"usage":{"promptTokenCount":400,"candidatesTokenCount":80,"thoughtsTokenCount":20,"totalTokenCount":500,"modelVersion":"gemini-3.8-flash","serviceTier":"STANDARD","http_status":200,"duration_ms":3990}}
```

[INFERIDO] C08 — dispatch: `GeminiQuotaBudget::markDispatchStarted(string $attemptId,AuditTask $task,int $deadlineMs): void` valida recibo, matching task/owner/fence, estado active, raíz audit existente/no terminal y Redis TIME menor a lease/deadline; luego fija dispatch_started_at_ms una sola vez, incrementa revision y dirty. RESERVE también verifica raíz existente/no terminal. Gateway invoca este método inmediatamente antes de `http->post`. Vencido/raíz terminal: no HTTP, rethrow técnico. Reclaim conserva la cuota reservada: recibo sin dispatch termina `not_sent` en el DTO financiero; con dispatch y sin respuesta termina unknown. La ventana entre marca y socket es explícitamente unknown, no se promete saber si Google recibió bytes.

[INFERIDO] C07.1 — agregar índice `gemini:journal:v1:invoice:{sha256(fac_nro)}` SET de AuditId en el mismo RESERVE, antes de conceder HTTP. C16 toma la unión de AuditId de SQL y de este índice para contar pendientes incluso antes de la primera proyección. Sin filas SQL y pending>0, basis=`attempt_ledger_pending`, known sums=0 como suma vacía, complete=false. Reconcile reconstruye índice desde recibos con identidad, sin inventarla; GC retira un AuditId del índice solo al retirar su raíz/recibos terminales con ACK confirmado y horizonte completo. No TTL mientras haya referencia/dirty. Un índice ausente con SQL histórico no prueba uso adicional cero; la completitud depende del resumen y del snapshot SQL/Redis descritos en C12-C17.

[INFERIDO] C09 — finish: preservar usageMetadata, modelVersion, serviceTier y http_status real (`GeminiOperationException::httpCode`, no getCode). Respuesta válida: guardar artifact; en ambos resultados de saveArtifact ejecutar finish con usage conocido; si saveArtifact=false, después de finish lanzar gemini_task_stale y prohibir commit funcional. Redis inaccesible impide asegurar respuesta/usage: conservar recibo previo como unknown y registrar clase/AttemptId; no loguear respuesta. FINISH, RECOVER_EXPIRED y failContract incrementan revision y dirty atómicamente; failContract conserva usage/costo. unknown→completed/failed con respuesta tardía permitido; completed/failed con mismo contenido es idempotente; contenido contradictorio genera error financiero, nunca overwrite silencioso.

[INFERIDO] C10 — `GeminiAttemptRecord::fromReceipt(array): self` valida tipos/estados mediante enums `GeminiOperation` (count_tokens/generate_content) y `GeminiAttemptState` (reserved/inflight/completed/failed/unknown/not_sent). Estado reserved corresponde a recibo inflight sin dispatch; vencido sin dispatch es not_sent. Usage conocido exige metadata entera no negativa, incluidos prompt/output/thoughts/total; campo ausente no se sustituye por 0 salvo thoughtsTokenCount ausente en respuesta válida, que se normaliza a 0. Gateway añade `measured_input_tokens` desde countTokens.totalTokens al usage del recibo; count_tokens lo proyecta en MeasuredInputTokens y API measured_input_tokens, no en TokensEntrada/Salida/Pensamiento/TotalTokens de generación; columnas de uso permanecen null y costo de generación no aplica. Generate_content tiene MeasuredInputTokens null.

[INFERIDO] C11 — tarifas: `GeminiTariffPolicy::quote(GeminiAttemptRecord): ?array` soporta inicialmente returned_model exactamente `gemini-3.8-flash`, service_tier STANDARD y fecha UTC de inicio 2026-10-14..2026-12-31. Retorna versión `gemini-standard-2026-10-14`, input=0.7500/output=3.7500, coste decimal a ocho posiciones. Otra combinación devuelve null, incrementa intentos_sin_tarifa y expone costo_completo=false. No cotizar desde requested_model. La fórmula existente usa salida+thinking; calcular en SQL DECIMAL dentro de la proyección para evitar error binario acumulado. No afirmar costo de factura Google, sino estimación tarifada sobre uso observado.

[INFERIDO] C12 — `GeminiAttemptJournal::flush(int $limit=20): array` obtiene dirty con score≤now, captura recibo/revision y raíz/estado del AuditId, valida DTO, llama `GeminiAttemptModel::project(GeminiAttemptRecord $record,array $auditSnapshot,array $receiptRevisions): void`. Máximo 20 y no comienza otro SQL si han transcurrido 2 s; una operación SQL mantiene timeouts/retries del executor. SQL upsert: transacción SERIALIZABLE, bloquear primero rango de FacNro en AudDispCosto con UPDLOCK/HOLDLOCK y después AttemptId; insert si ausente; update solo Revision mayor; igualdad con payload diferente aborta `attempt_revision_conflict`; menor revision no altera fila. Comparar inmutables identidad/operación/modelo solicitado aun si Revision es mayor; diferencia bloquea C07. Confirmar dirty/ACK/ref solo si revision Redis sigue siendo la proyectada. Actualización concurrente conserva dirty. Error SQL reprograma 1/5/30 s (30 s para siguientes fallos), con un log correlacionado por intento y sin DLQ funcional. El cálculo de summary usa el snapshot de revisiones capturado al comienzo y cuenta la revision SQL recién escrita como proyectada, sin exigir su ACK Redis antes del commit. Si el snapshot cambia, el ACK CAS conserva dirty y la siguiente proyección corrige el summary. API complete=true exige además pending=0 en la consulta; SQL flag se identifica como estado a FechaActualizacionCostoUtc.

[INFERIDO] C13 — resumen: dentro de esa misma transacción, localizar AudDispCosto por AuditId. Si ausente, asignar MAX(Intento)+1 bajo el bloqueo de FacNro, obtener TRM con CurrencyExchangeService antes de comenzar transacción, insert con DocsProcesados/DocsCacheHit=0 y EsCacheHitTotal=false provisional. En updates conservar TrmAplicada/FechaAuditoria/Intento/AudCostoId. Sumar solo filas generate_content con uso conocido; CostoUSD suma filas tarifadas; CostoCOP=round(CostoUSD*TrmAplicada,4). CostoCompleto=true solo con raíz terminal, todas las generaciones terminales con uso/tarifa conocidos y todas las revisiones SQL iguales o mayores al snapshot de recibos capturado por C12; else false. Reserved/inflight/unknown cuentan en IntentosUsoDesconocido; known sin tarifa en IntentosSinTarifa. not_sent tiene gasto cero demostrado por no dispatch y no incrementa unknown. count_tokens no altera tokens/importes de generación. ModeloIA es el único returned_model conocido si todos coinciden; múltiples valores=`mixed`; ninguno=`unknown`.

[INFERIDO] C14 — `AuditCostModel::upsertExecutionCost(PDO $pdo,string $auditId,array $identity,array $totals,array $documentMetrics): int` reutiliza AuditId y nunca crea otro Intento al persistir nuevamente el resultado. `AuditResultPersistenceModel::persistAuditCostRecord` usa esta rama solo para execution_version=2; v1 conserva recordCost. El resultado funcional actualiza DocsProcesados/DocsCacheHit; EsCacheHitTotal=true solo si no hubo dispatch generate_content y auditoría terminal sin unknown. Persistir AuditoriaTerminal como OR lógico del valor SQL con el snapshot terminal, nunca regresar a false por un proyector atrasado; DocsProcesados/DocsCacheHit mantienen el dato final conocido, sin volver a cero provisional. El cierre terminal exitoso o fallido marca todos los intentos del AuditId dirty para recalcular completitud. Auditoría fallida tiene costo aun si AudDispEst no recibe resultado exitoso. Una auditoría v2 sin intentos remotos crea su resumen desde persistencia funcional con modelo `none`, costo/tokens cero y completitud true; no necesita inventar recibo. Al pasar a terminal, la capacidad de registrar intentos nuevos está cerrada por C08; respuestas de intentos previos siguen actualizando el ledger.

[INFERIDO] C15 — proyección/GC: agregar CLI `journal` (una iteración), `journal-run` (loop 100 ms, señales SIGTERM/SIGINT) y `journal-check` (comparación SQL/Redis) que funcionan con desired/marker 1 o 2, incluso paused. `worker-cost-projection` ejecuta journal-run desde la imagen nueva; no hace intake ni HTTP Gemini ni interpreta planes. Log de inicio: instance, protocol observado, limit=20, start_budget_ms=2000, dirty_cap=100000; errores SQL sanitizados se registran por el proyector. `AuditExecutionMaintenance::tick` no llama SQL financiero. GC rechaza retirar un intento si dirty contiene ID o ACK&lt;revision; no retirar root/job/task con referencia attempt pendiente. ACK financiero no elimina evidencia Redis activa. Borrar ACK terminal solo conjuntamente con recibo después del horizonte y SQL confirmado. Reconcile reconstruye dirty desde recibos journal_version=1 con ACK faltante/menor, usando SCAN paginado 100, sin fabricar datos faltantes. journal-check emite pending, conflicts, missing_receipts, revision_mismatches y exit 1 ante corrupción/missing; pending normal se informa sin borrar registros.

[INFERIDO] C16 — HTTP aditivo: agregar GET `/audit/costs/{facNro}/llm-attempts`, acción `AuditCostController::llmAttempts`. page/pageSize enteros (page≥1, pageSize 1..100), defaults 1/20; inválidos=400. SELECT preparado, lectura default sin fallback a db2 para esta evidencia; fallo de default=503 con mensaje sanitizado. ORDER BY StartedAtMs,AttemptId; paginación offset, sin promesa de snapshot estable durante nuevos inserts. totalPages=ceil(total/pageSize), cero cuando total=0. Totales calculados sobre todas las filas de FacNro, no solo la página. `measured_input_tokens` es campo nullable por item (null en ejemplo de generación). projection_pending se obtiene de recibos dirty de los AuditId de la factura; si Redis falla, null y complete=null, sin exponer error interno ni convertirlo en cero. Factura sin ledger=200 lista vacía/basis legacy_or_absent, sin afirmar cero gasto. Ejemplo completo:

```json
{"success":true,"message":"Operación exitosa","data":{"fac_nro":"TEST-LLM-001","basis":"attempt_ledger_v1","pagination":{"page":1,"pageSize":20,"total":1,"totalPages":1},"totals":{"generation_attempts":1,"measurement_attempts":0,"known_total_tokens":500,"known_cost_usd":0.000675,"unknown_usage_attempts":0,"unpriced_attempts":0,"projection_pending":0,"complete":true},"items":[{"attempt_id":"11111111-1111-4111-8111-111111111111","audit_id":"22222222-2222-4222-8222-222222222222","document_id":"doc-1","request_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","stage":"semantic","operation":"generate_content","state":"completed","revision":3,"requested_model":"gemini-3.7-flash","returned_model":"gemini-3.8-flash","service_tier":"STANDARD","started_at_ms":1791986400000,"dispatch_started_at_ms":1791986400010,"finished_at_ms":1791986404000,"provider_code":200,"usage_known":true,"prompt_tokens":400,"output_tokens":80,"thoughts_tokens":20,"total_tokens":500,"pricing_version":"gemini-standard-2026-10-14","cost_usd":0.000675,"measured_input_tokens":null}]}}
```

[INFERIDO] C17 — añadir en cada item de costos actual `contabilidad_version`, `costo_completo` (null histórico), `intentos_uso_desconocido`, `intentos_sin_tarifa`, `fecha_actualizacion_costo_utc`. En todos los totales de index/byInvoice/byClients agregar `costo_completo`: false si hay un parcial v2, null si hay legacy sin completitud y ningún parcial, true si todos son v2 completos; lista vacía=null. Conservar todos los campos previos y sus tipos. No cambiar total_intentos: sigue contando auditorías, no HTTP. Con execution=2 las lecturas de costos usan default sin fallback y error=503; con execution=1 conservar readWithFallback, usando SELECT histórico si db2 no tiene columnas nuevas, y marcar metadatos legacy/null. El ledger C16 siempre usa default. Este fallback de lectores v1 pertenece a E01 y no demuestra completitud de gasto. Frontend actual no consume el endpoint nuevo; verificar schemas de timings passthrough y no cambiar pantallas.

[INFERIDO] C18 — métricas aditivas: conservar schema de timings 1/2 y semantic_calls como generaciones exitosas únicas por request del plan; agregar `generation_http_attempts`, `measurement_http_attempts`, `unknown_generation_attempts`, `known_generation_tokens`, `cost_projection_pending`. HTTP attempts cuentan dispatch_started_at_ms no null; reserva sin dispatch no es HTTP. Source para intentos: journal/SQL, no arrays que se duplican por fila. `/metrics/async` agrega `geminiJournal` con pending/oldest_pending_ms/last_success_ms/conflicts/blocked; heartbeat de proyector en `gemini:journal:v1:heartbeat` con timestamp UTC/TIME y TTL 30 s, refrescado antes/después de cada iteración. Capture límite dirty en logs de inicio; no agregar variables de entorno nuevas.

[INFERIDO] Compatibilidad backward: lectores antiguos siguen usando campos históricos; nuevas columnas nullable/default mantienen filas v1. Forward: workers anteriores no ejecutan planes con nuevos context/matching; el corte drena productores y bloquea planes activos antiguos. El rollback a v1 usa replay original y mantiene proyector nuevo; v1 ignora caché v7. No crear un adaptador ilimitado de planes antiguos.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| R01 | [INFERIDO] Ranking y formato conservador N:1. | D02-D03; C01-C02; F01-F02. | T01-T02,T12,A01-A02. |
| R02 | [INFERIDO] Contexto y caché acotados/versionados. | D04; C03-C05; F03-F06. | T02-T04,T12,A03. |
| R03 | [INFERIDO] Policy sin HTTP/ondas acotadas. | D01; C01,C05; F01,F05,F06. | T03-T04,T12,A04. |
| R04 | [INFERIDO] Ownership/recuperación/uso tardío. | D11; C08-C10; F07-F09. | T05-T07,A05. |
| R05 | [INFERIDO] Cuotas/backpressure. | D10; C07,C15; F08,F13. | T05,T09,T14,A06. |
| R06 | [INFERIDO] Ledger y resumen completo por AuditId. | D05-D09; C06-C15; §9; F08-F14. | T07-T10,T16,A07-A09. |
| R07 | [INFERIDO] API y metadatos aditivos. | C16-C17; F15-F16. | T11,T16,A10. |
| R08 | [INFERIDO] Métricas/reconciliación por fuente. | C18; F09,F17,F21. | T11-T14,A11. |
| R09 | [INFERIDO] Corte/rollback/retiro verificables. | D12; §14; F13,F18-F20,F24. | T10,T15,G01-G05,A12. |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| [INFERIDO] Motor / collector / resolver | Identidad/orden | Match y reanudación deben usar el mismo protocolo. | F01-F06 coordinados. | Firmas leídas en §0. |
| [INFERIDO] Gateway / cuota / runner | Fences y metadata | Nuevo journal y preservación tardía. | F07-F09. | Artifact/finish existentes. |
| [INFERIDO] Mantenimiento / migración | GC/outbox/refs | Proteger y drenar evidencia financiera. | F13,F18-F20. | GC actual sin proyección SQL. |
| [INFERIDO] SQL / costos / resultado | AuditId y resumen | Upsert idempotente, metadatos, filas para fallos. | F10-F16. | Esquema local + consumidor leído. |
| [INFERIDO] Observabilidad | Intentos versus pares | Métricas nuevas y compatibilidad de timings. | F17. | Frontend acepta campos extra en timings. |
| [INFERIDO] Workflows / Compose | Imagen/protocolo | Gates financieros y proceso independiente para proyección. | F19-F20/F24. | Perfil actual execution-v2; nuevo servicio propuesto. |
| [INFERIDO] Configuración | Variables existentes | Reutilizar AUDIT_PIPELINE_EXECUTION_VERSION/GEMINI_QUOTA_*; no crear claves. | Verificar contrato env CI. | .env.example y lectores actuales. |
| [INFERIDO] Docs/skills | Descripción de runtime | Sincronizar solo cuando se implemente. | F22. | Esta entrega es diseño. |
| [CONFIRMADO] Frontend/MCP | Contratos HTTP antiguos | No se encontró consumo frontend del endpoint nuevo; antes de merge repetir búsqueda. | T11 contractual, sin UI nueva. | rg en frontend y app/wrap; no declarar impacto cero para consumidores externos desconocidos. |

### 13. Cambios por Archivo

[INFERIDO] Raíz de todas las rutas siguientes: `C:/Users/USER/Desktop/AudFact/`. La columna ruta usa la ruta absoluta completa. Los estados [NEW]/[MODIFY] son futuros. No se modifica código de aplicación en esta entrega.

| ID | Estado / ruta | Clase / métodos / líneas actuales | Antes → Después |
| --- | --- | --- | --- |
| F01 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | __construct/plan/evaluateResolved/evaluateArticleSetField/evaluateSemanticField/buildDocumentContextSummary; 30-49,778-999. | Barrido original → perfil C01, igualdad canónica C02 y ranking; contexto C03 en ambos fallbacks; legacy intacto. |
| F02 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/ArticleCandidatePlanner.php`; [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticPlanningProfile.php` | Planner::canonicalize/rank; enum. | Ausentes → contratos C01-C02. |
| F03 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticComparisonContext.php` | forPair/toArray/toCanonicalString. | Cabecera/notas → C03. |
| F04 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticCandidateCollector.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/ResolvedSemanticResolver.php` | identity/evaluate; 16-44 / 15-28. | Identidad contextual anterior → builder único C04. |
| F05 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/SemanticEvaluationStore.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | buildPlan/cacheKey; prepareTask/aggregate; 34-74,141-145 /174-244. | v6/versions actuales → v7/versions matching-context/snapshot congelado. |
| F06 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticBatchContract.php` | VERSION/body/validate; 12-110. | semantic-batch-v1 → semantic-batch-v2 con contexto C03 y validación existente preservada. |
| F07 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiGateway.php` | executeTaskRequest/executeSingleOperation;172-310. | Pérdida de finish por fence → C08-C09; HTTP code/returned model completos. |
| F08 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/GeminiQuotaBudget.php` | reserve/finish/failContract/recoverExpired y Lua;35-289. | Recibo mínimo → journal/Revision/dispatch/dirty/refs/capacity. |
| F09 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditTaskRunner.php` | attemptMetrics;108-140. | Métrica parcial → metadata y origen journal C18; no costo descartado por commit stale. |
| F10 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiAttemptRecord.php`; [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiOperation.php`; [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiAttemptState.php`; [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Finance/GeminiTariffPolicy.php` | factories/enums/quote. | Ausentes → C10-C11. |
| F11 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/GeminiAttemptJournal.php`; [NEW] `C:/Users/USER/Desktop/AudFact/app/Models/GeminiAttemptModel.php` | flush/reconcile/metrics; project/listByInvoice/upsert summary. | Ausentes → C12-C16, SQL preparado/default. |
| F12 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/app/Models/migration/migration_AudDispGeminiIntento.sql`; [NEW] `C:/Users/USER/Desktop/AudFact/app/Models/migration/rollback_AudDispGeminiIntento.sql` | DDL/up-down. | Ausentes → SQL completo §9. |
| F13 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditExecutionMaintenance.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditAdmissionGuard.php` | tick/flush/gc/reconcile; capacity checks. | GC sin ACK financiero → C15 y backpressure. |
| F14 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Models/AuditCostModel.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Models/AuditResultPersistenceModel.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditFailureFinalizer.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | recordCost/queries/mapCostRecord; persistAuditCostRecord; finalize/terminal. | Resumen resultado → C13-C14/C17 y terminal dirty. |
| F15 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Controllers/AuditCostController.php` | nueva llmAttempts; index/byClients/byInvoice;100-179. | Reportes previos → C16-C17 aditivo. |
| F16 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Routes/web.php` | costos;51-55. | Agregar ruta llm-attempts antes del wildcard de costos. |
| F17 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditTimingSummarizer.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Controllers/ObservabilityController.php` | buildPhaseTimings/asyncMetrics. | Contadores actuales → C18 y geminiJournal. |
| F18 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditExecutionMigration.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/bin/audit-execution-maintenance.php` | inventory/verify/rollback; match comandos. | Manifiesto actual → versiones/estado journal; comandos journal y journal-check. |
| F19 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/.github/workflows/deploy-production.yml` | prepare/cutover/rollback;529-620. | Corte actual → conservar imagen financiera/preflight de schema/journal gates §14. |
| F20 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/.github/workflows/ci.yml` | PHPUnit/Redis/env contract. | Agregar T01-T16 sin credenciales reales; job SQL aislado explícito. |
| F21 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/scripts/reconcile-llm-attempts.mjs` | CLI main. | Inventario histórico → reportes por fuente/hashes, sin DML ni PII. |
| F22 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/plans/api-endpoints.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/plans/database-schema.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/plans/architecture.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/plans/deployment-and-ci.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/plans/testing-strategy.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/plans/features/audit-workflow.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-audit-gemini/SKILL.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-sqlsrv-models/SKILL.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-runtime-docker/SKILL.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/.agent/skills/CATALOG.md`; [MODIFY] `C:/Users/USER/Desktop/AudFact/.agent/skills/catalog.json` | Docs/registro al implementar. | Descripción antigua → cambios reales y evidencia; validar skills. |
| F23 | [INFERIDO] [NEW]/[MODIFY] Pruebas enumeradas con rutas completas en §16. | Tests/fixtures/manifest. | Casos actuales → T01-T16; ninguna eliminación en este cambio. |
| F24 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/docker-compose.yml` | servicios execution-v2; junto a maintenance. | Agregar worker-cost-projection con misma imagen/env/red/log mounts/usuario/restart del worker de mantenimiento, command `php bin/audit-execution-maintenance.php journal-run`, replicas=1, perfil execution-v2; sin variable nueva ni puerto. El workflow incorpora nombre a stop/start y conserva el proyector durante rollback a v1. |

[INFERIDO] Fragmentos normativos antes/después adicionales para F01 y F07; el algoritmo detallado lo definen C01-C18, no estos fragmentos aislados:

```php
// Antes: foreach ($docArticles as $di => $docOriginal) { evaluate(...); }
// Después, únicamente CANDIDATES_V2:
foreach ($planner->rank($fdvOriginal, $docArticles) as $di) {
    $result = $resolver->evaluate($fdvOriginal, $docArticles[$di], $pairContext);
    if ($result['is_match']) { $matchedFdv[$fi] = $di; break; }
}
// Antes: si saveArtifact=false, throw antes de finish.
// Después: conservar uso aunque el propietario ya no pueda confirmar negocio.
$saved = $queue->saveArtifact($task, $artifact);
$quota->finish($attemptId, $usage);
if (!$saved) { throw new RuntimeException('gemini_task_stale'); }
```

### 14. Plan de Migración

#### Prerequisitos

| Gate | Requisito | Evidencia exigida |
| --- | --- | --- |
| G01 | [INFERIDO] K01/corpus. | Manifest etiquetado y replay unitario/agrupado; decisiones finales equivalentes o revisión de esta SDD. |
| G02 | [INFERIDO] K02/imagen/protocolo. | Inventario de imágenes, marcador y desired version; no planes v2 activos de contexto anterior. |
| G03 | [INFERIDO] K03/cuota. | Proyecto, modelo, tier y límites efectivos, otras aplicaciones consumidoras y presupuesto asignado. |
| G04 | [INFERIDO] K04/SQL. | Preflight, DDL probado, esquema introspectado y DML preparado real sobre default. |
| G05 | [INFERIDO] K05/durabilidad. | INFO/CONFIG, backup/restauración, inventario de trabajos y ensayo de rollback. |

[INFERIDO] `prepare` publica una imagen SHA que soporta v1 y los comandos financieros. Esa imagen es `ROLLBACK_COMPAT_SHA` en el manifiesto (campo nuevo de archivo, no variable de entorno nueva). `TARGET_SHA` incluye todos los cambios. ROLLBACK_COMPAT_SHA puede ser TARGET_SHA arrancada con ejecución 1; la compatibilidad se prueba en T10/T15. El workflow añade `execution_rollback_image_tag` como input explícito obligatorio en rollback (sin variable de entorno nueva), verifica su igualdad con el manifiesto y lo usa para el proyector/stack v1. La imagen anterior productiva se conserva como evidencia, pero no sustituye al recuperador del journal.

#### Ejecución

1. [INFERIDO] M01: congelar fuentes/hashes y corpus; ejecutar T01-T16; resolver G01-G05. Un FAIL bloquea corte.
2. [INFERIDO] M02: aplicar DDL aditivo bajo autorización de operación; verificar checksum de columnas históricas. No insertar snapshots como intentos reales.
3. [INFERIDO] M03: construir/publicar TARGET_SHA y ROLLBACK_COMPAT_SHA; ejecutar prepare con versión 1, sin activar semantic/maintenance ni modificar marcador. Guardar volumen respaldado y prueba de restauración.
4. [INFERIDO] M04: pause de admisión/ejecución; detener cron/productores; drenar HTTP y todos los consumidores. Verificar procesos detenidos; si la terminación forzada deja operaciones HTTP inciertas, inventariarlas, sin afirmar cero gasto. Capturar slots/terminal hashes/PEL/unread/snapshots.
5. [INFERIDO] M05: validar modelo/tier/quotas y durabilidad; desired=2; ejecutar CLI migrate dry-run/apply/verify existentes desde TARGET_SHA. Perfil execution-v2 solo después de marker=2 y validaciones. Manifiesto incorpora versiones matching/context/cache y estado financiero.
6. [INFERIDO] M06: iniciar mantenimiento/consumidores en observe con admisión cerrada; probar fuentes importadas y journal; medir canary con datos sintéticos y corpus autorizado. Ejecutar journal-check: pending=0, conflicts=0, SQL revision alcanza recibos y costo parcial solo por unknown/unpriced explícitos.
7. [INFERIDO] M07: accept manifiesto; resume únicamente tras A01-A12 y G01-G05. Registrar hora/imagen/cuotas/modelo/tarifa/resultados. Falla conserva pausa.
8. [INFERIDO] M08: iniciar cron/admisión regular; observar primer lote completo y prioridades tardías; si aparece cambio funcional o conflicto financiero, ejecutar rollback siguiente.

#### Validaciones Previas

- [INFERIDO] Ninguna tarea/plan activo usa versiones de matching/context no soportadas. Si existen, drenar con su imagen antes de continuar; no reinterpretar el snapshot.
- [INFERIDO] DDL y CLI journal funcionan desde ROLLBACK_COMPAT_SHA en execution=1.
- [INFERIDO] Nuevas variables de entorno: ninguna. Si la implementación introduce una, revisar esta SDD, actualizar `.env.example`/AGENTS y sincronizar GitHub antes de push; no ocultarla como supuesto.

#### Validaciones Posteriores

- [INFERIDO] Identidades, slots, hashes de terminales y contadores conservados; nuevas generaciones/costos concilian con ledger; resultado funcional de referencia preservado.
- [INFERIDO] Publicar imagen, timestamp UTC y tiempos comparables por fuente. No afirmar saturación actual de policy desde PEL agregado ni extrapolar un corte a capacidad sostenida.

#### Rollback

1. [INFERIDO] Pause y detener productores/HTTP/workers nuevos; conservar Redis durable y SQL.
2. [INFERIDO] Desde TARGET_SHA ejecutar `journal` hasta dirty=0 o informar fallo SQL/unknown explícito; ejecutar `journal-check`. Si SQL está caído, mantener journal durable y recuperar con CLI nueva antes de avanzar.
3. [INFERIDO] Ejecutar rollback dry-run/apply/verify con el manifiesto, preservando fuentes/slots/terminales y la evidencia financiera. La verificación incluye dirty/ACK y versiones; no restaurar un snapshot Redis antiguo sobre resultados SQL nuevos.
4. [INFERIDO] desired/marker=1; arrancar ROLLBACK_COMPAT_SHA en observe. El CLI journal nuevo sigue disponible y procesa recibos que lleguen tarde; no hace intake v2.
5. [INFERIDO] Confirmar continuidad de v1 y lecturas de costos aditivos; resume tras evidencia. Caché v7 y ledger se conservan; rollback físico SQL está prohibido si contienen datos.

[INFERIDO] Excepción E01 clean-rebuild: conservar `SemanticMatchJudge::evaluate`, perfil legacy y dispatch/retry v1 por sus consumidores activos. Responsable: autor del PR y responsable del corte registrado en manifiesto. Retiro: cero jobs/PEL/outbox/planes v1 activos, horizonte `max(AUDIT_STATE_TTL,AUDIT_JOB_TTL,AUDIT_PERSISTENCE_QUEUE_TTL)` desde último terminal v1, rollback cerrado y búsqueda de referencias. Validar consumidores estáticos/dinámicos y CLI. No eliminar contratos activos en esta entrega; el PR de retiro debe eliminar código reemplazado, no deprecarlo indefinidamente.

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| [INFERIDO] N FDV respaldadas por una misma línea | Reutilizar línea; cada fila tiene match. | COINCIDE conserva N:1. |
| [INFERIDO] Genérico/comercial sin tokens comunes | Conservar candidato; ordenar no filtra. | Fallback disponible; ningún rechazo por score bajo. |
| [INFERIDO] 40MG/40 MG; C*100; dosis 0.25/25 | Canonizar formato; conservar decimal/dosis. | Primer par puede ser igual; segundo no se vuelve igual. |
| [INFERIDO] Ningún candidato coincide | Exhausted y regla funcional vigente; CPU puede recorrer U×M. | No promesa N; requests agrupados acotados. |
| [INFERIDO] Caché igual entre pacientes distintos | Contexto producto no incluye paciente. | Cache hit sin HTTP; diferencias de evidencia/modelo invalidan. |
| [INFERIDO] Timeout tras dispatch | Uso desconocido, cuota conservada. | Ledger unknown y costo_completo=false. |
| [INFERIDO] Crash entre reserva y dispatch | No hubo HTTP autorizado. | Ledger not_sent; cuota reservada conservadora no reembolsada automáticamente. |
| [INFERIDO] Respuesta llega tras fence vencido | Finish económico con uso, sin commit funcional. | Mayor revision en SQL; propiedad vieja rechazada. |
| [INFERIDO] Respuesta de contrato semántico inválido con usage | Fallo técnico y costo retenido. | failed con tokens conocidos; retry es otro AttemptId. |
| [INFERIDO] SQL confirma y proceso cae antes del ACK | Replay idempotente. | Una fila/costo, ACK siguiente. |
| [INFERIDO] SQL está inaccesible | Dirty/ref permanecen; retry; cap detiene nuevas reservas. | Nada se borra ni se disfraza como discrepancia. |
| [INFERIDO] Journal actualizado mientras se proyecta | ACK CAS falla para revision anterior. | Nueva revision continúa dirty. |
| [INFERIDO] Modelo/tier/tarifa no cubierto | Guardar metadata, costo null. | intentos_sin_tarifa>0, resumen parcial. |
| [INFERIDO] Página inválida/schema corrupto | 400 HTTP / excepción técnica en factory. | Sin truncamiento ni fuga de paths. |
| [INFERIDO] Lectura histórica sin ledger | Mantener importes; completitud null. | No afirmar ausencia de gastos adicionales. |
| [INFERIDO] Plan de versión previa activo | Bloquear despliegue; drenar con imagen compatible. | No reinterpretar pair IDs. |

### 16. Testing

#### Nuevos Tests

[INFERIDO] Raíz de pruebas: `C:/Users/USER/Desktop/AudFact/`. Cada fila define objetivo, precondición, pasos y resultado; fixtures son sintéticos o desidentificados y no conservan nombres/identificaciones médicas reales.

| ID | Ruta completa / objetivo | Precondiciones y pasos | Resultado esperado |
| --- | --- | --- | --- |
| T01 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/ArticleCandidatePlannerTest.php` | Canonizar empaques/unidades/decimales/combinaciones/LP; ordenar candidatos con empate. | Igualdad solo de formato; índices estables; dosis/LP conservadas. |
| T02 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/SemanticComparisonContextTest.php` | Cabecera/quality_notes con sentinelas PII; producto/persona; invertir par; cambiar versiones. | Contexto sin sentinelas; hashes direccionales/versionados; N:1 no afecta caché. |
| T07 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/GeminiAttemptRecordTest.php`; [NEW] `C:/Users/USER/Desktop/AudFact/tests/Services/Finance/GeminiTariffPolicyTest.php` | DTO válido/invalid/unknown/not_sent y período/modelo/tier. | Rechazos tipados; unknown null; quote exacto 0.000675 del ejemplo; otro modelo sin tarifa. |
| T08 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Models/GeminiAttemptModelTest.php` | Executor/PDO inyectados; insertar revision 1/3/2/3 conflictiva. | Upsert parametrizado; no duplicación; conflicto explícito; rollback primary exception preservada. |
| T09 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Integration/GeminiAttemptJournalRedisTest.php` | Redis dedicado AOF; reserve/finish/late/SQL fake; crash puntos críticos; dirty capacity; consultar factura antes de primera proyección. | Dirty/ref/índice factura atómicos, CAS, recuperación, sin GC previo al ACK; pending visible aun sin ledger SQL. |
| T11 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Controllers/GeminiAttemptControllerTest.php` | Modelo inyectado; páginas inválidas/vacías/SQL error/PII sentinelas. | 400/200/503 correctos; allowlist; totals no mezclan count_tokens. |
| T12 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/ArticleSetMatchingScalabilityTest.php`; [NEW] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Fixtures/llm_saturation_v1.json`; [NEW] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Fixtures/llm_saturation_v1.manifest.json` | Fixtures 4/8/9/16/17 filas, N:1, reversed, genérico/comercial, sin matches. Resolver por inyección; benchmark de planner vía evaluate público. | Resultados validados preservados; caso 17 mock ≤20 pares remotos tras ranking; ninguna afirmación de cota universal N. Manifest incluye hashes/etiquetas/autor de validación, no inferidos desde is_match solamente. |
| T13 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/scripts/benchmark-llm-saturation.mjs`; [NEW] `C:/Users/USER/Desktop/AudFact/tests/Support/replay-llm-corpus.php` | Transporte fake para CI; proveedor real solo corpus autorizado/K03. Comparar 30 ejecuciones cold y 30 warm con mismo modelo/config/entrada por escenario. | Requests count_tokens y generateContent separados; tokens/latencias/costos por fuente; ≥80% menos generaciones semánticas en referencia, cero regresiones etiquetadas. |
| T16 | [INFERIDO] [NEW] `C:/Users/USER/Desktop/AudFact/tests/Integration/GeminiAttemptSqlServerTest.php` | BD SQL aislada, sin tablas legacy médicas; crear AudDispCosto mínima idéntica a migración y aplicar §9; dos conexiones concurrentes. | PK/revision/AuditId/Intento correctos; dos AuditId misma FacNro distintos Intento; summary tardío no duplica; DDL/rollback vacío pasan y con datos bloquea. |

[INFERIDO] T16 lleva grupo PHPUnit `sqlsrv-ledger`. Se ejecuta explícitamente con `--group sqlsrv-ledger` y contrato DB_* existente de BD aislada cuyo DB_NAME empieza exactamente por `audfact_sdd_test_`; cualquier otro nombre aborta antes de abrir PDO. La suite general CI usa `--exclude-group sqlsrv-ledger` y el job SQL dedicado ejecuta ese grupo; no agregar variable de entorno opt-in nueva. CI no consulta BD productiva. Estado Env mutado en tests se restaura en finally/tearDown.

#### Tests Modificados

| ID | Ruta completa / objetivo | Precondiciones y pasos | Resultado esperado |
| --- | --- | --- | --- |
| T03 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/SemanticEvaluationStoreTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/DocumentPolicyEngineTest.php` | Perfiles legacy/v2; versiones desconocidas; plan/resolver; N:1. | v1 estable; v2 sin HTTP; mismo orden/identidad en resolución. |
| T04 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/SemanticBatchContractTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Integration/SemanticExecutionRedisTest.php` | Wave límites, response IDs inválidos, concentración ausente/contradictoria, retoma duplicada. | Contrato estricto; sin evaluación provisional; errores técnicos con usage retenido. |
| T05 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Integration/AuditExecutionRedisTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Integration/GeminiQuotaConcurrencyRedisTest.php` | Dos procesos, lease/fence vencido y cola quota/dirty llena. | No dispatch de owner viejo; shared budget y capacity respetados. |
| T06 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Integration/GeminiTaskExecutionRedisTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/GeminiGatewayTest.php` | Respuesta real simulada; saveArtifact false/finish fail/timeout/HTTP 429 Retry-After. | Tokens económicos persistidos; artifact reutilizado sin nuevo HTTP; HTTP code exacto; unknown explícito. |
| T10 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Integration/AuditExecutionMigrationRedisTest.php` | Journal pendiente/ACK perdido; rollback a v1 con proyector financiero; reinicio AOF dedicado. | Manifiesto protege evidencia; replay/rollback idempotentes; journal no se elimina. |
| T14 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/AuditTimingSummarizerTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Controllers/ObservabilityControllerTest.php` | Pares versus HTTP, SQL lento/fallido, heartbeat. | Métricas separadas; backlog correcto; no heartbeat saludable falso mientras mantenimiento bloqueado. |
| T15 | [INFERIDO] [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Models/AuditCostModelTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Models/AuditResultPersistenceModelTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Controllers/AuditCostControllerTest.php`; [MODIFY] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/AuditPersistenceWorkerTest.php` | Legacy/v2, repeat terminal, fallo técnico, summary parcial/modelos mixtos. | Una fila por AuditId; legacy intacto; campos previos estables; completitud explícita. |

#### Tests Eliminados

[INFERIDO] Ninguno en este cambio. Las pruebas de contratos v1 siguen activas durante E01; el retiro posterior exige cobertura v2 equivalente y búsqueda de consumidores.

#### Verificaciones Manuales

- [INFERIDO] V01 — CLI `php bin/audit-execution-maintenance.php journal`: con namespaces aislados y execution=1/2 proyectar ledger sin consumir trabajo ni HTTP; devuelve projected/pending/conflicts y código 1 si hay corrupción.
- [INFERIDO] V02 — `/audit/costs/{facNro}/llm-attempts`: comparar PK/revision/tokens con SQL/recibos; no confundir una página con totales globales ni con sumas de snapshots históricos.
- [INFERIDO] V03 — corte/rollback: ejecutar §14 en réplica del inventario, registrar SHA/manifiesto/UTC/checksums; mantener pausada admisión ante cualquier fallo.
- [INFERIDO] V04 — benchmark: criterios T13; comparar latencia completa, no solamente policy CPU. Modelos/config distintos invalidan la comparación.
- [INFERIDO] V05 — búsqueda de PII y secretos: sentinelas de fixture no aparecen en journal, API, logs ni manifest; solo metadata operacional permitida.

[INFERIDO] Comandos de implementación previstos; no ejecutados como comprobación de código todavía inexistente:

```powershell
php vendor/bin/phpunit --configuration phpunit.xml --no-coverage --do-not-cache-result
php scripts/lint-php.php
node .agent/skills/_shared/scripts/validate-skills.mjs
npm.cmd run build --prefix website
```

[INFERIDO] Integración Redis: ejecutar suite `tests/Integration` con RUN_REDIS_INTEGRATION=1 en contenedor/volumen dedicados; SQL T16 requiere selección de grupo y BD aislada. Nunca usar Redis/SQL compartidos para limpieza de pruebas. PHPUnit skip de integración no es PASS de recuperación.

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| [INFERIDO] Contexto reducido cambia homologación | Consistencia de datos | Alta | K01/G01; bloquear adopción; conservar snapshot/base y no forzar resultado. |
| [INFERIDO] Canonización borra una especificación | Técnico | Alta | C02 igualdad completa y T01 negativos; no conversiones/sinónimos. |
| [INFERIDO] Timeout/caída tras envío origina retry físico adicional | Operativo | Alta | D11: unknown tarifado parcialmente, no garantía exactly-once externa. |
| [INFERIDO] Proyección SQL lenta acumula dirty | Rendimiento | Alta | D10/C15 fijan proceso independiente; T14 confirma que el heartbeat/intake sigue progresando mientras SQL financiero falla. Cap limita nueva reserva. |
| [INFERIDO] Scope solicitado/modelo alias no coincide con cuota efectiva | Operativo | Alta | K03, validar modelo retornado y consumidores compartidos; presupuesto conservador. |
| [INFERIDO] Imagen antigua pierde journal nuevo | Migración | Alta | D12 y ROLLBACK_COMPAT_SHA; dirty/refs conservados. |
| [INFERIDO] Backlog financiero agota memoria | Rendimiento | Alta | Cap100000, noeviction, bloqueo de reservas/admisión y recuperación SQL; medir bytes en T14. |
| [INFERIDO] Tarifa/modelo/tier desconocidos | Financiero | Alta | Cost null y summary parcial; snapshot/version, sin default silencioso. |
| [INFERIDO] API sin auth adicional expone metadata operacional | Seguridad | Media | Allowlist sin PII/prompts/body; política actual explícita; remediación general separada. |
| [INFERIDO] Restauración física falla | Infraestructura | Alta | K05/G05, ensayo y bloqueo del corte. |

### 18. Criterios de Aceptación

| ID | Criterio observable | Prueba / evidencia |
| --- | --- | --- |
| A01 | [INFERIDO] N:1 y outcomes etiquetados se conservan, incluida referencia completed/26 coincidencias. | T01-T04,T12/G01. Si baseline resulta erróneo por etiqueta clínica, revisar SDD en vez de preservarlo a ciegas. |
| A02 | [INFERIDO] Igualdad canónica nunca convierte 0.25 en 25, ni LP en liberación inmediata; ranking no excluye candidatos. | T01/T12. |
| A03 | [INFERIDO] Contexto/cache producto no contiene sentinelas de paciente/médico/fecha; igual par/contexto/modelo reutiliza caché entre auditorías. | T02-T03. |
| A04 | [INFERIDO] Policy v2 realiza cero HTTP; request respeta 16 pares/32768 bytes/8192 entrada y schema. | T03-T04. |
| A05 | [INFERIDO] Owner/fence vencido no dispatch ni commit; respuesta ya almacenada no se vuelve a enviar; uso tardío permanece en ledger. | T05-T07. |
| A06 | [INFERIDO] Dos procesos comparten límites; cap dirty devuelve DEFERRED antes de HTTP y no crea recibo adicional. | T05/T09. |
| A07 | [INFERIDO] Repetir proyección/terminal produce una fila por AttemptId y una por AuditId; revision SQL nunca retrocede. | T08-T10/T16. |
| A08 | [INFERIDO] Fallo de contrato con uso suma gasto conocido; timeout expone unknown; count_tokens no duplica tokens/costos de generación. | T06-T08/T11. |
| A09 | [INFERIDO] Crash entre SQL commit/ACK y late response se recuperan; dirty/ref bloquean GC. | T09-T10/T16. |
| A10 | [INFERIDO] Endpoint nuevo responde 200 paginado/400 inválido/503 SQL; campos públicos previos estables; histórico completitud null. | T11/T15. |
| A11 | [INFERIDO] Benchmark de referencia registra ≥80% menos generaciones semánticas que la base comparable, cold/warm separados, y todos los fallos/retries en ledger; no se impone tiempo futuro no medido. | T13, modelo/config/entrada fijados. |
| A12 | [INFERIDO] Corte/rollback conservan identidades, slots, terminales y ledger; G01-G05 resueltos antes de resume. | T10/T15,V03. |

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas están definidas | [INFERIDO] PASS de diseño | §9 define tabla nueva/base esperada; K04 identifica validación física pendiente. |
| Todas las columnas existen | [INFERIDO] PASS de trazabilidad / no certificado físico | Base desde migración leída; propuestas en DDL completo; no se afirma que estén desplegadas. |
| Todos los contratos documentados | [INFERIDO] PASS de diseño | C01-C18, HTTP/Redis/Lua/SQL/snapshots/compatibilidad. |
| Todos los requisitos tienen trazabilidad | [INFERIDO] PASS de diseño | R01-R09→C/F/T/A en §11. |
| Todos los consumidores analizados | [INFERIDO] FAIL de aprobación integral | Workers/controladores internos identificados; consumidores externos y runtime productivo K02 no verificados. |
| Todas las migraciones tienen rollback | [INFERIDO] PASS de diseño | SQL conservador §9; operativo §14; retiro E01. |
| Todas las referencias están definidas | [INFERIDO] PASS de diseño | F01-F23, T01-T16, K/I/O/S/G/M/V/A; rutas verificadas contra checkout. |
| Toda compatibilidad tiene evidencia | [INFERIDO] FAIL de aprobación integral | Legacy activo comprobado; rollback compat SHA futuro y K02 pendientes. |
| Todos los criterios son verificables | [INFERIDO] PASS de diseño | A01-A12 y métricas separadas; ensayos todavía no ejecutados. |

[INFERIDO] El diseño no alcanza aprobación integral: los FAIL se conservan explícitos. Un checklist documental no acredita runtime, replay ni SQL físico.

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado |
| --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | [INFERIDO] No en los cambios definidos; D01-D12 y proyector independiente explícitos. |
| ¿Existe algún contrato sin documentar? | [DESCONOCIDO] Sí para consumidores externos no inventariados; K02. |
| ¿Existe algún consumidor no analizado? | [DESCONOCIDO] Sí: producción/consumidores externos; K02. |
| ¿Existe alguna migración sin rollback? | [INFERIDO] No; §9/§14 cubren reversión y conservación de datos. |
| ¿Existe algún dato persistido sin migración? | [INFERIDO] No en propuestas; SQL/journal tienen tratamiento explícito. |
| ¿Existe alguna afirmación sin evidencia? | [INFERIDO] No presentada como confirmada; desconocidos/supuestos se declaran. |
| ¿Existen referencias huérfanas? | [INFERIDO] No en los identificadores definidos; validación mecánica en evidencia de cierre. |
| ¿Dos implementadores producirían soluciones diferentes? | [INFERIDO] Sí en selección de corpus/validación externa pendiente; código C01-C18 tiene decisiones fijadas. |

[INFERIDO] Las respuestas Sí conservan la clasificación C; no se interpreta que el componente v2 local está aprobado íntegramente.

## FASE 4 — Resultado Final

### Nivel de Completitud

[INFERIDO] **Nivel C — Diseño Parcial para aprobación integral.** K01-K05 impiden afirmar que la solución completa, incluida adopción productiva, es implementable sin información crítica adicional. Están definidos los cambios locales, SQL, contratos, pruebas, migración y rollback; no se han implementado ni validado sus resultados futuros.

[INFERIDO] **Decisión clean-rebuild: APROBAR CON EXCEPCIÓN la estrategia incremental; RECHAZAR la declaración de solución implementada o activación productiva aprobada.** Alcance MVP: R01-R09. Contratos activos: N:1, HTTP previo, identidad, resultados, v1/jobs y esquemas históricos. Compuertas: dominio, contratos, pruebas, observabilidad e higiene. Excepción: E01, responsable/retiro/rollback en §14. S01-S05 siguen declarados; no hay otra excepción implícita.

### Definición de Completitud

- [INFERIDO] Promover a Nivel B/A exige cerrar K01-K05, inventariar consumidores externos, registrar ROLLBACK_COMPAT_SHA y aprobar el ensayo de proyección/heartbeat D10; luego repetir FASE 2 y FASE 3 sin FAIL/Sí.
- [INFERIDO] Implementación completa exige R01-R09/A01-A12 aprobados, migraciones probadas con SQL real aislado, recuperación Redis/AOF y corpus etiquetado con proveedor. No usar tests simulados como prueba de cuota o equivalencia clínica.
- [CONFIRMADO] Durante la conversación se ejecutaron 96 pruebas focalizadas existentes con 306 aserciones: motor 58/181, juez 11/45, contrato batch 17/35, planes 4/18 y cuota 6/27. Son comprobaciones del checkout actual, no de los cambios propuestos.
- [INFERIDO] La evidencia de validación documental de esta entrega se incorpora al cierre después de verificar plantilla, etiquetas, referencias, ejemplos JSON, hashes y build del portal. No ejecutar DDL, modificar variables ni desplegar al crear esta SDD.

[CONFIRMADO] Matriz de impacto documental de la entrega actual:

| Archivo | ¿Requirió Cambio? | Razón | Estado |
| --- | --- | --- | --- |
| [CONFIRMADO] `plans/sdd-correccion-saturacion-arbitraje-costos-llm.md` y copia `website/docs/sdd-correccion-saturacion-arbitraje-costos-llm.md` | Sí | Especificación solicitada, fuente y publicación documental. | Creado. |
| [CONFIRMADO] `plans/evidence/saturacion-llm-2026-10-14.json` y `plans/evidence/validacion-sdd-saturacion-llm-2026-10-14.json` | Sí | Congelar observaciones sanitizadas y validación documental. | Creado. |
| [CONFIRMADO] `README.md`, `website/sidebars.ts` | Sí | Enlazar la nueva SDD como propuesta Nivel C. | Actualizado. |
| [CONFIRMADO] `CHANGELOG.md`, `plans/changelog.md`, `website/docs/changelog.md` | Sí | Registrar diseño, alcance y límites reales. | Actualizado solo con esta entrada. |
| [CONFIRMADO] `plans/api-endpoints.md`, `plans/architecture.md`, `plans/database-schema.md`, `AGENTS.md` | No | La entrega no cambia código, tablas, endpoints ni variables efectivas. | N/A; actualización futura F22. |

[CONFIRMADO] Matriz de impacto en skills de la entrega actual:

| Skill | ¿Requirió Cambio? | Razón | Estado |
| --- | --- | --- | --- |
| [CONFIRMADO] `write-sdd-spec`, `clean-rebuild-policy`, `audfact-docs-sync` | No | Se aplican sus instrucciones; no cambia su contrato. | Consultadas. |
| [CONFIRMADO] `audfact-audit-gemini`, `audfact-sqlsrv-models`, `audfact-runtime-docker` | No | Código/runtime no se modificaron; no convertir una propuesta en estado operativo. | Consultadas; futura actualización F22. |
| [CONFIRMADO] `CATALOG.md` / `catalog.json` | No | El mapeo existente de especificaciones `plans/*` ya asigna write-sdd-spec; no hay skill nueva ni archivo de aplicación creado. | N/A. |

[CONFIRMADO] Validaciones ejecutadas: los 48 encabezados obligatorios están en orden; cuatro ejemplos JSON parsean; 28 hashes de código/configuración coinciden con el inventario; 50 rutas existentes y 25 propuestas se distinguieron; no hay afirmaciones de proyecto sin etiqueta ni lenguaje prohibido. `git diff --check` de los archivos documentales modificados pasa. `npm.cmd run build` en website generó el portal con exit 0; los avisos del comprobador de actualizaciones no invalidan el build. Esta comprobación documental no cambia los FAIL/Sí de aprobación integral ni acredita el DDL.
