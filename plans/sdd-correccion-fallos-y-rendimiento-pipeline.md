# SDD — Corrección de fallos y rendimiento del pipeline AudFact

[CONFIRMADO] Fecha: 2026-10-09; repositorio `C:/Users/USER/Desktop/AudFact`; base inspeccionada: HEAD `653378b` más el trabajo local existente, no solamente ese commit.

[INFERIDO] Estado de esta entrega: especificación para implementación; escribir este documento no acredita que sus cambios estén implementados, desplegados o medidos.

[INFERIDO] Convención: las decisiones normativas propuestas llevan `[INFERIDO]`; las observaciones del código llevan `[CONFIRMADO]`; los datos operativos pendientes llevan `[DESCONOCIDO]`.

## FASE 0 — Descubrimiento Obligatorio

### Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Solicitud | [CONFIRMADO] Especificación detallada de ajustes posteriores al diagnóstico | Usuario: «Crea la especificacion detallada de implementacion de los ajustes necesarios!» |
| E01: diagnóstico y alcance local | [CONFIRMADO] PDF, FIFO, SQL, Gemini y caché; producción no inspeccionada | `plans/diagnostico-performance-2026-10-09.md:5`, `:16`, `:55` |
| E02: defecto PDF | [CONFIRMADO] Clasificador limita códigos a 0/1; Poppler real devuelve 99 | `app/Services/Audit/Pipeline/RasterizationProcessResult.php:16`; diagnóstico `:63`; `logs/diagnostic-2026-10-09/empty-pdf-reproduction.json:12` |
| E03: recuperación y cierre | [CONFIRMADO] Rechazo documental tipado y fallo técnico tienen destinos diferentes; extractor libera BLOB en afterTaskCommitted, hook terminal v1 base vacío y runner terminal llama cleanup(false) | `DocumentExtractionWorker.php:158`, `:195`, `:278`, `:548`; `AuditEventConsumer.php:137`, `:595`; `AuditTaskRunner.php:70`, `:104`, todos bajo `app/Services/Audit/Pipeline/` |
| E04: SQL | [CONFIRMADO] PDO fresco por operación; configuración abre dos conexiones; pooling Linux no configurado en el corte | `core/SqlServerConnectionExecutor.php:46`; `core/Database.php:108`; `app/Models/AuditConfigModel.php:28`, `:124`; diagnóstico `:134` |
| E05: respuestas Gemini | [CONFIRMADO] Candidatos ausentes con bloqueo OTHER y respuestas MAX_TOKENS | diagnóstico `:164`; `app/Services/Audit/Pipeline/GeminiResponseParser.php:62`; `app/Services/Audit/SemanticMatchJudge.php:156` |
| E06: ejecución y almacenamiento | [CONFIRMADO] Runtime local v1; v2 tiene RR/cap1, cuota, artifacts, fences y outbox | diagnóstico `:112`; `FairAuditTaskQueue.php:212`; `GeminiGateway.php:175`, `:242`; SDD de homologación, todos en `app/Services/Audit/` o `plans/` |
| E07: requisito externo de pooling | [CONFIRMADO] Linux usa configuración unixODBC; ConnectionPooling del DSN no la habilita; no promete reset de sesión Linux | [Microsoft: Connection Pooling](https://learn.microsoft.com/en-us/sql/connect/php/connection-pooling-microsoft-drivers-for-php-for-sql-server?view=sql-server-ver17), consultado 2026-10-09 |
| E08: contrato externo Gemini | [CONFIRMADO] STOP, MAX_TOKENS y promptFeedback tienen significados distintos; low existe para 3.7/3.8 Flash | [GenerateContentResponse](https://ai.google.dev/api/generate-content#v1beta.GenerateContentResponse), [Thinking](https://ai.google.dev/gemini-api/docs/thinking), consultados 2026-10-09 |
| E09: caché acotada | [CONFIRMADO] Admisión/release Lua; recuperación con SHA256 del base64; métricas agregadas | `app/Services/Audit/Pipeline/AuditDocumentCache.php:37`, `:114`; `AuditDocumentInput.php:20`; `AuditDocumentMetrics.php:14`; diagnóstico, sección ARCH-003 |
| E10: polling | [CONFIRMADO] Cada jobsList consulta clientes; frontend admite documentCache abierto; listJobs absorbe fallos devolviendo [] aunque controller tenga catch503 | `app/Controllers/AuditController.php:477`, `:496`; `app/Services/Audit/Pipeline/BatchJobStore.php:118`, `:151`; `frontend/lib/schemas/domain.ts:102`; `core/Response.php:27` |
| E11: registro y documentación | [CONFIRMADO] Variables existentes, defaults de deploy, planes como fuente y navegación explícita | `.env.example:82`; `.github/workflows/deploy-production.yml:389`; `docker/docs.Dockerfile:10`; `website/sidebars.ts:47`; `.agent/skills/CATALOG.md:108` |
| E12: estado SQL por sesión | [CONFIRMADO] Hay SET NOCOUNT ON y cinco tablas temporales conocidas | `app/Models/InvoicesModel.php:120`, `:202`; `AuditResultPersistenceModel.php:145`, `:371` |

### Información Faltante Crítica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| [INFERIDO] Ningún dato crítico pendiente para codificar R01–R09 con los contratos de esta SDD | Las decisiones, firmas, algoritmos y pruebas quedan fijados abajo; los gates externos impiden habilitar optimizaciones sin evidencia | Implementación local nivel B; aceptación operativa sigue pendiente |
| [DESCONOCIDO] Cuotas reales y attestation de cutover de producción | No se inspeccionó AI Studio ni Redis productivo | Bloquea activación v2, que se rige por la SDD existente y queda fuera de la implementación de esta SDD |

### Información Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| [DESCONOCIDO] Corpus desidentificado con dictamen de referencia | El diagnóstico no incluye etiquetas clínicas completas | Bloquea adopción del perfil thinking/output nuevo y afirmaciones de exactitud |
| [DESCONOCIDO] DB_POOLING/DB2_POOLING y versiones efectivas en producción | Evidencia disponible corresponde a Docker local | Bloquea habilitar pooling allí sin M02/M03 |
| [DESCONOCIDO] Distribución representativa de tamaño BLOB y consumidores concurrentes | Los contadores agregados no equivalen a una distribución de bytes | Exige M04 antes de aceptar beneficio de prefetch |
| [DESCONOCIDO] Presupuesto económico de Gemini y modelo servido futuro | Se observó modelo solicitado 3.7 y servido 3.8; no prueba causalidad | M05 compara tokens/costo y registra ambas versiones; no cambia modelo automáticamente |

### Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| [DESCONOCIDO] Proyección de usuarios simultáneos a doce meses | No forma parte de las evidencias locales | No bloquea corrección; no permite prometer capacidad anual |
| [DESCONOCIDO] Cambio comercial de nombres de EPS | No hay política de actualización documentada | S03 limita obsolescencia visual a 30 segundos |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
| S01 | [INFERIDO] Se conserva el flujo incremental v1 y el protocolo durable v2 existente | Jobs activos y excepción E01 de la SDD de homologación; E03/E06 | Dos modos requieren cobertura hasta retiro verificable de v1 |
| S02 | [INFERIDO] 8192/low extracción y 4096/low semántica unitaria son candidatos de configuración, no mejoras clínicas probadas | E05/E08; límites actuales 4096/2048 | Exactitud y costo; adopción bloqueada por M05 |
| S03 | [INFERIDO] Los nombres usados solo para presentación admiten TTL de 30 s | Enriquecimiento aislado en jobsList, E10 | Nombre visual obsoleto hasta TTL; no cachear FDV/configuración clínica |
| S04 | [INFERIDO] Prefetch usa umbrales fijos 90%/70% y revisión por proceso cada 1000 ms | E09 y recuperaciones observadas | Concurrencia permite descargas ya iniciadas; el Lua de admisión sigue siendo la barrera dura |
| S05 | [INFERIDO] Pooling Linux tiene un modo global por imagen; flags iguales 1/1 o 0/0 | E07; env_file de Compose; entrada ejecuta antes de PHP | Valores mixtos deben fallar explícitamente al arrancar la imagen nueva |
| S06 | [INFERIDO] Aceptación usa replay aislado y comparaciones pareadas sin modificar la fuente legacy | No hay benchmark productivo equivalente, E01 | Resultados locales no se extrapolan a producción |

### Clasificación de Completitud Inicial

[INFERIDO] **Nivel B — Implementable con Supuestos Declarados** para R01–R09; S01–S06 fijan decisiones, mientras M01–M06 condicionan aceptación y rollout.

[DESCONOCIDO] La autorización operativa de v2 permanece incompleta por cuotas/corpus/recuperación; esta SDD no sustituye esos datos con inferencias.

## FASE 1 — Especificación

### 1. Objetivo

[CONFIRMADO] El defecto PDF generó 189 fallos sobre 73 eventos/auditorías y explicó 58 de 67 eventos DLQ en la ventana congelada; nueve DLQ adicionales fueron candidatos Gemini ausentes (E01/E02/E05).

[CONFIRMADO] La clasificación descarta exit 99 antes de leer el diagnóstico documental; el fallback convierte un PDF sin páginas en RuntimeException y reintenta el mismo contenido (E02/E03).

[INFERIDO] Corregir esa clasificación; distinguir fallos remotos permanentes de transitorios; reducir trabajo SQL repetido; limitar anticipación de descargas; demostrar resultados con fixtures reales, integración Redis y mediciones pareadas.

[INFERIDO] Un fallo remoto no equivale a una inconsistencia clínica; solo contenido PDF demostrado admite rechazo documental. La calidad y la recuperación son condiciones de aceptación anteriores a la velocidad.

### 2. Alcance

#### Incluido

- [INFERIDO] R01: PDF sin páginas/corrupto con códigos documentados y pruebas Ghostscript/Poppler reales.
- [INFERIDO] R02: clasificación tipada común de respuestas Gemini y política de retry en consumidores v1/v2.
- [INFERIDO] R03: perfiles candidatos de salida/thinking y rechazo de resultados semánticos incompletos antes de cachearlos.
- [INFERIDO] R04: dos lecturas de AuditConfigModel en una conexión por intento, sin alterar filtros ni shape.
- [INFERIDO] R05: pooling unixODBC global, reset de sesión conocido y gate de compatibilidad/beneficio.
- [INFERIDO] R06: caché de nombres en GET /audit/jobs con degradación sin duplicar consulta SQL.
- [INFERIDO] R07: backpressure del downloader, poda de reservas expiradas y contadores agregados.
- [INFERIDO] R08: benchmark de correcciones y prueba de equidad v2 existente; protocolo de comparación y gates.
- [INFERIDO] R09: documentación, variables existentes, CI, migración y rollback auditables.

#### Excluido

- [INFERIDO] Activar v2, cambiar cuotas reales, aumentar réplicas/cap1, migrar modelo Gemini o proveedor/API.
- [INFERIDO] Cambiar reglas clínicas, fuente de verdad FDV, catálogos, escritura transaccional o identidades FacNro/DisId.
- [INFERIDO] DDL permanente, nuevos endpoints, cambios visuales y eliminación de jobs/DLQ/eventos históricos.
- [INFERIDO] Cualquier push, despliegue, escritura remota o cambio efectivo de configuración durante esta entrega documental.

### 3. Non Goals

- [INFERIDO] Sustituir el pipeline por una reescritura completa, compartir PDO entre auditorías o introducir una capa genérica de repositorios.
- [INFERIDO] Tratar bloqueo Gemini como PDF corrupto; aumentar tokens en bucle después de MAX_TOKENS; reducir validación del schema para aceptar parciales.
- [INFERIDO] Prometer throughput por réplica, contar fallos técnicos como auditorías exitosas o interpretar duración de pared residual como CPU.

### 4. Estado Actual

| Área | Comportamiento | Evidencia |
| --- | --- | --- |
| PDF | [CONFIRMADO] Ghostscript puede reconocer NO_PAGES; fallback Poppler 99 pierde clasificación | E02; `DocumentPdfRasterizer.php:219`, `:246`, `:261` |
| Retry v1 | [CONFIRMADO] Excepciones genéricas esperan reclamación PEL; tres intentos en el corte, idle 600000 ms | E03; diagnóstico `:91`; `AuditEventConsumer.php:301`, `:614` |
| Gemini extracción | [CONFIRMADO] Parser exige STOP pero no distingue bloqueo/truncamiento; override 4096 | E05; `GeminiResponseParser.php:39`, `:72`; `DocumentExtractionWorker.php:417` |
| Gemini semántica | [CONFIRMADO] v1 no valida STOP antes de JSON; catch Throwable devuelve resultado no cacheable; batch valida STOP genéricamente | `SemanticMatchJudge.php:156`, `:202`, `:300`; `SemanticBatchContract.php:59` |
| SQL | [CONFIRMADO] Preparación invoca FDV, config, catálogo y adjuntos; config abre dos PDO | `DocumentAuditOrchestrator.php:322`, `:335`, `:336`, `:341`; E04 |
| HTTP | [CONFIRMADO] Cinco muestras /audit/jobs p50 962.666 ms; /metrics/async p50 23.382 ms | diagnóstico `:42`; no prueba capacidad máxima |
| BLOB | [CONFIRMADO] Cache final 256 MiB/1024 entradas; 2143 bypass, 539 recuperaciones sin fallo de recuperación/hash | E09; diagnóstico, sección ARCH-003 |
| Equidad | [CONFIRMADO] v1 FIFO dejó 3000 raíces NUEVA EPS detrás de SANITAS; runtime no ejecutaba v2 | E06; diagnóstico `:112` |

[CONFIRMADO] La extracción observada tuvo p50 9.949 s; preparación sumó aproximadamente 4.637 s por auditoría; persistencia p50 2.912 s frente a latencia integral p50 520.714 s (diagnóstico, sección métricas por etapa).

[INFERIDO] Esos cortes distintos fundamentan prioridades, pero no constituyen un baseline reproducible de una única carga; M06 construye ese baseline.

### 5. Estado Objetivo

[INFERIDO] Flujo PDF: clasificar señales operativas primero; probar fallback existente; si Poppler confirma ausencia/corrupción, publicar document_rejected mediante las rutas actuales y liberar BLOB tras publicación/commit. Un error operativo del fallback sigue siendo técnico.

[INFERIDO] Flujo Gemini: gateway conserva contabilización HTTP/cuota/artifact; los tres consumidores de contenido llaman al guard; respuestas no utilizables generan excepción tipada; v1/v2 aplican retry o cierre técnico; ningún parcial llega a reglas ni caché semántica.

[INFERIDO] Flujo SQL: adquirir PDO fresco por callback; resetear la sesión Linux cuando pooling esté habilitado; realizar ambas consultas de configuración en ese mismo callback; cerrar cursores; devolver PDO al pool al terminar la operación.

[INFERIDO] Flujo polling: Redis jobs → mapa de nombres cacheado → enriquecimiento → mismo envelope HTTP; el miss hace una consulta y el fallo de set no repite SQL.

[INFERIDO] Flujo downloader: recuperación PEL → evaluar puerta de prefetch → leer mensaje nuevo solo si está abierta → descargar → admisión Lua → publicar locator/hash. La saturación no crea un fallo técnico ni un ACK de trabajo no ejecutado.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| D01 | [INFERIDO] **APROBAR CON EXCEPCIÓN E01** evolución incremental; conservar v1 hasta cumplir retiro de la SDD de homologación | Rebuild completo; borrar consumidores activos | S01; clean-rebuild-policy y trabajos activos E03/E06 |
| D02 | [INFERIDO] PDF: códigos 0/1/99 y regex explícitas; señales operativas prevalecen | Todo exit 99 es rechazo; rescatar ciegamente error primario | Solo evidencia textual específica justifica rechazo |
| D03 | [INFERIDO] Un enum, excepción y guard compartidos para estado remoto; no framework de errores | Strings distribuidos; serializar respuesta bruta | Estados cerrados y evidencia sanitaria |
| D04 | [INFERIDO] Bloqueo/MAX_TOKENS/non-STOP son terminales técnicos; ausencia sin bloqueo admite tres intentos del mecanismo existente | Retry inmediato adicional; resultado clínico false por respuesta incompleta | Mismo request permanente consume cuota sin resolver causa |
| D05 | [INFERIDO] Perfiles S02 sujetos a corpus; no alterar tamaño batch ni modelo | Aumentar tokens adaptativamente; MINIMAL para modelos que no lo admiten | E05/E08 y límites agrupados actuales |
| D06 | [INFERIDO] Config usa un callback READ; pooling físico separado del ciclo de vida PDO | Singleton PDO entre jobs; caché clínica compartida | Conserva retry/frescura y reduce una apertura |
| D07 | [INFERIDO] Pooling global 1/1 o 0/0; CPTimeout 120; reset conocido por checkout Linux | Flags mixtos ignorados; asumir reset ODBC Linux | E07/E12; M02 bloquea sesión contaminada |
| D08 | [INFERIDO] Nombres TTL 30 s sin mutex nuevo; miss concurrente permite una consulta por request | Cache::remember con retry externo que duplica callback; caché infinita | S03; acotación simple y degradación predecible |
| D09 | [INFERIDO] Puerta local con histéresis 90/70; índice global atómico sigue controlando capacidad | Redescarga ilimitada; pausa que impide reclaim o limpiar expirados | S04; no añadir protocolo Redis de ejecución |
| D10 | [INFERIDO] Preservar RR/cap1 v2; exigir evidencia antes de activar | cap0/capN; más réplicas sin cuotas | E06; cambiar justicia/concurrencia exige otra SDD |
| D11 | [INFERIDO] Rollback de imagen en mismo protocolo; downgrade v2 solo mediante migración existente | Redis flush; alterar marcador con jobs activos | Conserva receipts, cuotas, fences y cierres |
| D12 | [INFERIDO] Priorizar correctitud, luego costo/latencia; rendimiento neto se calcula con cierres sin fallo | Aceptar velocidad mayor por rechazos/fallos adicionales | S06 y riesgos clínicos |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| [CONFIRMADO] PHP/PDO sqlsrv | runtime/driver | PHP local 8.2.33; driver instalado por Dockerfile | E01/E04; conservar PHP 8.2 y no usar enum features de versiones superiores |
| [CONFIRMADO] unixODBC/ODBC Microsoft | infraestructura | Local unixODBC 2.3.11; ODBC 18, biblioteca 18.7 | `logs/diagnostic-2026-10-09/odbc-runtime.txt`; comprobar versión productiva M02 |
| [CONFIRMADO] Ghostscript/Poppler | proceso | Instalados por `docker/Dockerfile:23`; versiones del contenedor se capturan M01 | Ejecutar fixture real; no asumir códigos de mocks |
| [CONFIRMADO] Redis | cola/estado | Versión efectiva se registra con INFO server en M04/M06 | Scripts Lua atómicos; namespace aislado de prueba |
| [CONFIRMADO] Gemini generateContent | API | Config local 3.7 Flash; respuestas observadas 3.8 Flash | E05/E08; capturar requested_model/modelVersion sin migración implícita |
| [CONFIRMADO] SQL Server legacy | base de datos | Versión productiva no inspeccionada | Tablas/queries existentes; pooling exige M02/M03 |
| [CONFIRMADO] PHPUnit/Docusaurus | validación/documentación | PHPUnit según composer.lock; Docusaurus 3.10.2 | Pruebas del repo y `website/package.json:16` |
| [DESCONOCIDO] Cuotas/corpus de producción | tercero/datos | Valores no verificados | Bloquean activación v2/adopción perfil; no bloquean fixtures del código |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| I01 | [INFERIDO] Solo señales PDF específicas producen rechazo; E02 y D02 fijan precedencia | T01–T03 |
| I02 | [INFERIDO] Ningún bloqueo/truncamiento produce rechazo documental o dictamen clínico | Guard + propagación tipada; T04–T08 |
| I03 | [INFERIDO] STOP sigue requiriendo schema completo; no se aceptan pares faltantes, duplicados o extras | Validadores existentes después del guard; T05/T07 |
| I04 | [INFERIDO] Cuotas registran cada HTTP una vez; invalidar artifact no devuelve cargos | Gateway existente; T08 |
| I05 | [INFERIDO] ACK/cierre/outbox siguen receipts/fences y contadores idempotentes | Consumer/committer actuales; T03/T06/T08 |
| I06 | [INFERIDO] READ mantiene db2; escritura default; AuditStatusModel conserva fallback propio | No cambiar executor/model routing; T09/T10/M02 |
| I07 | [INFERIDO] PDO fresco por operación/intento; no replay de escritura ambigua | Executor existente; T10/T11 |
| I08 | [INFERIDO] Hash BLOB es SHA256 del string base64; bypass/TTL siguen recuperables | Cache/input existentes; T14–T17 |
| I09 | [INFERIDO] Prefetch cerrado deja avanzar PEL, señales y extracción; no consume cuota nueva | Hook después de reclaim; T15/T16 |
| I10 | [INFERIDO] RR/cap1 y marcador Redis no cambian por estas correcciones | T18; revisión diff y M06 |
| I11 | [INFERIDO] Formato jobs, identities y datos SQL históricos permanecen compatibles | T12/T13/T19; no DDL permanente |
| I12 | [INFERIDO] No loguear contenido, prompts, nombres de pacientes ni salida de rasterizador | Contexto whitelisted; T20 |

### 9. Modelo de Datos

[INFERIDO] No agregar/modificar/eliminar tablas permanentes, columnas, índices, constraints, foreign keys, triggers, vistas o procedimientos; R04 conserva sus dos SELECT y R05 solo limpia estado temporal de la sesión (E04/E12).

| Almacén | Contrato objetivo | Retención/migración |
| --- | --- | --- |
| [INFERIDO] `query:clients:names:v1` vía Core\Cache | JSON C04; schema_version=1 | TTL 30 s; lazy fill; no datos clínicos |
| [INFERIDO] `telemetry:document_cache` | Hash actual + prefetch_pauses, prefetch_wait_ms | No TTL nuevo; campos ausentes equivalen a 0 |
| [INFERIDO] `audit:document-cache:{budget}:bytes/sizes/expires` | Tipos/keyspace actuales; usage poda máximo 64 expirados por llamada | No reindexación ni cambio del blob key |
| [INFERIDO] Cache semántica unitaria | producto v5→v6; persona v2→v3, misma serialización | Nuevas lecturas no reutilizan namespace anterior; TTL existente 30 días |
| [INFERIDO] Cache semántica durable | SemanticBatchContract::VERSION permanece semantic-batch-v1 | No cambio de body/schema/version; cache v2 ya exige STOP, E06 |
| [INFERIDO] Eventos/jobs/tareas/attempts/outbox | Estructura durable actual; strings de error nuevos en campos existentes | No reescribir históricos, active artifacts o original_event |

#### DDL

[INFERIDO] **DDL permanente: ninguna sentencia**, porque los cambios definidos no crean ni modifican objetos permanentes; no generar una migración SQL vacía.

[INFERIDO] R05 ejecuta el siguiente batch completo al adquirir cada PDO Linux con pooling habilitado, antes del callback; usar PDO::exec, capturar excepción y no devolver la conexión si falla:

```sql
IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
IF OBJECT_ID('tempdb..#DISP') IS NOT NULL DROP TABLE #DISP;
IF OBJECT_ID('tempdb..#FACT') IS NOT NULL DROP TABLE #FACT;
IF OBJECT_ID('tempdb..#FACT1') IS NOT NULL DROP TABLE #FACT1;
IF OBJECT_ID('tempdb..#CRUZE') IS NOT NULL DROP TABLE #CRUZE;
IF OBJECT_ID('tempdb..#Sopo') IS NOT NULL DROP TABLE #Sopo;
SET NOCOUNT OFF;
SET XACT_ABORT OFF;
SET IMPLICIT_TRANSACTIONS OFF;
SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
SET LOCK_TIMEOUT -1;
```

[INFERIDO] Ese batch no presume un reset general ODBC: limpia transacción, opciones y temporales concretos del alcance; M02 verifica DBCC USEROPTIONS y ausencia de temporales antes de aceptar pooling. No se ejecuta entre dos sentencias del mismo callback.

#### Orden de Ejecución

1. [INFERIDO] Reiniciar procesos con odbcinst configurado antes de cargar PDO.
2. [INFERIDO] Abrir PDO → reset R05 si Linux y pooling=1 → callback → cerrar todos los cursores → liberar PDO.
3. [INFERIDO] Crear caché de nombres y contadores nuevos solo al primer uso; no ejecutar scan/delete masivo.
4. [INFERIDO] Mantener eventos y claves clínicas/ejecución sin transformación.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
| [INFERIDO] SQL permanente | Identidad: ninguna escritura de migración | Mismos objetos/filas | T09/T19 y diff sin DDL |
| [INFERIDO] Catálogo de clientes | NitSec a clave string, NitCom a string, mismo cast actual | C04 | T12: igualdad de enriquecimiento |
| [INFERIDO] Cache semántica v1 antigua | No copiar resultados; dejar expirar | Namespace nuevo | T07: clave antigua no satisface nuevo hit |
| [INFERIDO] Hash métricas existente | Inicializar campos faltantes a 0 al leer | Mismo hash | T17/T19 |
| [INFERIDO] Jobs/DLQ activos | Ninguna reescritura automática; reproceso explícito usa endpoint existente | Identidades históricas intactas | T03/T19; M01 |

#### Rollback

[INFERIDO] SQL de reversión permanente: ninguna sentencia; no hay DDL ni datos permanentes migrados. Volver a imagen previa y modo 0/0 deshabilita pooling en procesos reiniciados; las sesiones nuevas no ejecutan reset R05.

[INFERIDO] Dejar expirar query:clients:names:v1 y caches semánticas; no eliminar globalmente índices BLOB, eventos, cuotas o DLQ. Los campos agregados de telemetría se ignoran en código anterior.

[INFERIDO] Antes de volver al código semántico anterior, forzar su caché a miss mediante un namespace de rollback `audfact:semantic:match:rollback-20261009:product/person`; no volver a consumir resultados antiguos no certificados. El rollback conserva el guard semántico de R02 o cierra admisión y drena antes de retirarlo.

### 10. Contratos

#### Antes

| ID | Contrato actual y consumidores | Evidencia |
| --- | --- | --- |
| C01 | [CONFIRMADO] contentFailure(): ?PdfContentFailure; salida del proceso privada; extractor distingue RasterizationFailureException | E02/E03 |
| C02 | [CONFIRMADO] Parser extracción RuntimeException ante candidato ausente/non-STOP; judge unitario puede convertir excepción a no-match | E05; `GeminiResponseParser.php:62`; `SemanticMatchJudge.php:300` |
| C03 | [CONFIRMADO] document_rejected y audit_failed/dead_letter usan AuditEvent con campos snake_case y payload abierto | `AuditEvent.php:34`, `:158`; `DocumentExtractionWorker.php:562`; `AuditTaskRunner.php:85` |
| C04 | [CONFIRMADO] GET /audit/jobs acepta limit 1..100/default50 y devuelve success/message/data; SQL de nombres falla sin impedir 200; un store que lanza llega al catch503, mientras listJobs actual retorna [] en su catch | E10; `BatchJobStore.php:151`, `:693`; `Response.php:27` |
| C05 | [CONFIRMADO] Cache put/read/release/configuration; métricas counters contienen ocho contadores y recovery_duration_ms | E09; `AuditDocumentMetrics.php:14`, `:57` |
| C06 | [CONFIRMADO] getConfig(string): ?array y getHeader(string): ?array; namespaced PDO READ por callback | `AuditConfigModel.php:28`, `:124`; E04 |
| C07 | [CONFIRMADO] DSN tiene ConnectionPooling=1/0; entrypoint no configura unixODBC | `Database.php:108`; `docker/docker-entrypoint.sh:5` |
| C08 | [CONFIRMADO] Defaults candidatos actuales extracción4096, unitario2048; batch fija8192 | E05; `SemanticBatchContract.php:42`; E11 |

[CONFIRMADO] Ejemplo mínimo completo de respuesta jobs vacía, igual antes y después; los jobs no vacíos conservan todos los campos de BatchJobStore::LIST_JOBS_LUA:

```json
{"success":true,"message":"Lista de jobs de auditoría","data":[]}
```

[INFERIDO] Ejemplo completo no vacío con cliente/fechas sintéticos, idéntico antes y después; corresponde a LIST_JOBS_LUA más calculatePerformance y enriquecimiento del controller:

```json
{
  "success":true,"message":"Lista de jobs de auditoría",
  "data":[{
    "job_id":"55555555-5555-4555-8555-555555555555",
    "fac_nit_sec":2624,"status":"processing","total":2,"done":1,"failed":0,
    "pending":1,"progress_percent":50,"avg_duration_ms":1000,
    "accumulated_duration_ms":1000,"elapsed_ms":10000,"throughput_per_sec":0.1,
    "created_at":"2026-10-09T12:00:00Z","updated_at":"2026-10-09T12:00:10Z",
    "completed_at":null,"date_from":"2026-10-01","date_to":"2026-10-09",
    "client_name":"EPS DE PRUEBA"
  }]
}
```

[CONFIRMADO] Ejemplo de respuesta remota bloqueada válida para la detección, con todos los campos utilizados por el nuevo guard; no contiene información clínica:

```json
{"promptFeedback":{"blockReason":"OTHER"},"usageMetadata":{"promptTokenCount":120,"candidatesTokenCount":0,"thoughtsTokenCount":0,"totalTokenCount":120},"modelVersion":"gemini-3.8-flash"}
```

#### Después

**C01 — clasificación PDF y fallback**

- [INFERIDO] Firma conservada: `RasterizationProcessResult::contentFailure(): ?PdfContentFailure`; admitir solo exit `[0, 1, 99]`, rechazar códigos negativos y no admitir cualquier código por contener una regex.
- [INFERIDO] Primero detectar regex operativa existente, luego validar código, luego NO_PAGES, luego CORRUPTED; agregar `invalid page count\s+0\b` a NO_PAGES conservando las expresiones actuales.
- [INFERIDO] Poppler 0 con salida JPEG válida vence diagnóstico primario; fallo operativo Poppler vence rechazo primario; diagnóstico Poppler específico produce excepción tipada; sin diagnóstico específico sigue RuntimeException. No rescatar la excepción primaria ante fallback operativo o desconocido.
- [INFERIDO] No agregar enum de rechazo ni campos públicos; utilizar EMPTY_PDF_NO_PAGES y CORRUPTED_DOCUMENT existentes. No almacenar stdout/stderr en excepciones, logs ni eventos.

**C02 — clasificación remota compartida**

[INFERIDO] Nuevas firmas exactas, namespace `App\Services\Audit`:

```php
enum GeminiResponseFailureReason: string {
    case PROVIDER_BLOCKED = 'GEMINI_RESPONSE_PROVIDER_BLOCKED';
    case MISSING_CANDIDATE = 'GEMINI_RESPONSE_MISSING_CANDIDATE';
    case OUTPUT_TRUNCATED = 'GEMINI_RESPONSE_OUTPUT_TRUNCATED';
    case UNSAFE_FINISH_REASON = 'GEMINI_RESPONSE_UNSAFE_FINISH_REASON';
}
final class GeminiResponseException extends \RuntimeException {
    public function __construct(
        public readonly GeminiResponseFailureReason $reason,
        private readonly array $safeContext = []
    );
    public function retryable(): bool;
    public function context(): array;
}
final class GeminiResponseGuard {
    public static function assertUsable(array $response, string $taskType): array;
}
```

[INFERIDO] Excepción: message=reason.value, code=0; retryable solo para MISSING_CANDIDATE; constructor rechaza contexto fuera del shape sanitario siguiente, sin guardar response original. Guard taskType solo `extraction` o `semantic`, otro valor lanza InvalidArgumentException.

| Prioridad | Condición | Resultado | Retry |
| --- | --- | --- | --- |
| [INFERIDO] 1 | promptFeedback.blockReason es string no vacío distinto de BLOCK_REASON_UNSPECIFIED | PROVIDER_BLOCKED, incluido OTHER; no se atribuye necesariamente a seguridad | No |
| [INFERIDO] 2 | candidates no es array, no contiene índice0 o índice0 no es array | MISSING_CANDIDATE | Tres intentos totales, mediante owner existente |
| [INFERIDO] 3 | Primer candidato finishReason === MAX_TOKENS | OUTPUT_TRUNCATED aunque JSON parezca completo | No; reproceso manual tras corregir perfil |
| [INFERIDO] 4 | finishReason distinto de STOP, incluso ausente | UNSAFE_FINISH_REASON | No |
| [INFERIDO] 5 | finishReason === STOP | Retornar candidato; parser de contenido mantiene validación actual | No decisión de retry en el guard |

[INFERIDO] Contexto completo de error: task_type obligatorio; finish_reason y block_reason son strings opcionales normalizados a `[A-Z0-9_]{1,64}`, valor inválido→UNKNOWN; model_version opcional cumple `[A-Za-z0-9._-]{1,128}`, inválido→UNKNOWN; prompt_tokens/candidates_tokens/thoughts_tokens opcionales enteros no negativos del usageMetadata, valores inválidos se omiten. No incluir responseId, finishMessage, safetyRatings, prompt, documento ni razonamiento.

[INFERIDO] Ejemplo completo del nuevo DTO sanitario para MAX_TOKENS:

```json
{"task_type":"extraction","finish_reason":"MAX_TOKENS","model_version":"gemini-3.8-flash","prompt_tokens":800,"candidates_tokens":149,"thoughts_tokens":3933}
```

- [INFERIDO] Extracción: sustituir solo selección/finishReason inicial; catch propaga excepción e invalida según el modo de conservación siguiente; mantener JSON/schema, extracción persistida y rehidratación.
- [INFERIDO] Semántica unitaria: guard antes de decodificar; `catch (GeminiResponseException $error) { throw $error; }` antes del catch Throwable; nunca buildFailedResult para estas cuatro razones. Caché producto namespace v6 y persona v3 para evitar hits anteriores no certificados; no alterar hashing ni TTL existente.
- [INFERIDO] En ambos logs de callGemini retirar expected/actual/finishMessage y error->getMessage; usar exclusivamente task_type, call_purpose validado, error_class y finish_reason normalizado. No emitir contenido remoto aunque STOP falle al decodificar; los cuatro estados tipados se registran una vez en consumer/runner con context(), nunca dentro del judge.
- [INFERIDO] Semántica batch: guard antes de validar pares; `evaluateBatch` invalida ante `GeminiOperationException|GeminiResponseException` con modo de conservación siguiente; preservar VERSION/schema/8192. No devolver cargos ya confirmados.
- [INFERIDO] Artifact v2: extender firma existente `GeminiGateway::invalidateTaskResponses(bool $discardResponses = true): void`; default true conserva contrato vigente. Tipada no reintentable pasa false: ejecutar failContract idempotente como hoy, conservar responses/indexado por request-body hash y confirmar artifact con el mismo fence. Otros fallos pasan true y descartan responses como hoy. Extracción y evaluateBatch eligen modo mediante `!($error instanceof GeminiResponseException && !$error->retryable())`.
- [INFERIDO] Esa conservación permite que un RETRY_PREPARE/reinicio vuelva a evaluar la misma respuesta permanente sin repetir HTTP ni inventar un artifact de fallo nuevo; el guard sigue rechazándola y ningún cache/result clínico la consume. Cambiar body/model modifica el hash y no reutiliza respuesta anterior; un reproceso administrativo conserva identidad nueva. No mover validación al transporte ni cambiar cobros, retries HTTP, shape de artifacts, expiración o fences.
- [INFERIDO] v1: extender nonRetryable con tipada&&!retryable; errorCode tipado=reason.value; conservar mecanismo PEL, maxRetries, DLQ receipts y limpieza. Una ausencia sin bloqueo no introduce sleep ni retry HTTP adicional.
- [INFERIDO] v2: tipada opera sobre `generate_content`, no sobre operación previa count_tokens; contador operation_attempt existente determina 0/1/2. Retry transitorio usa 1000/5000 ms + jitter CRC32 actual 0..250; terminal usa razón en last_error_code/message, conserva audit_failed.detail_error genérico y requires_manual_review=true.
- [INFERIDO] Limpieza terminal v1: extractor implementa protected afterTerminalFailure y libera blob_reference_key válido después de publicación/receipt DLQ mediante el hook existente; locator ausente→no-op, inválido→warning sanitario sin retirar otra clave. Liberación fallida conserva recuperación terminal existente, no borra receipt ni genera otra auditoría.
- [INFERIDO] Limpieza terminal v2, incluidas ramas de auditoría ya terminal: solo commit COMMITTED/ALREADY_COMMITTED permite cleanup(true), que libera BLOB mediante afterTaskCommitted existente. RETRY_PREPARE→repair/defer100ms `commit_contention` y cleanup(false), sin nuevo HTTP; STALE→cleanup(false) sin liberar bytes de otro lease. La rama de error deja de invocar cleanup(false) incondicionalmente después de commit terminal; no cambiar algoritmo/fences del committer.
- [INFERIDO] Gateway contabiliza primero cada respuesta/HTTP; guard ejecuta después. No cambiar TTL, créditos, unknown-result ni ventanas de cuota.

**C03 — eventos y compatibility**

[INFERIDO] Envelope exacto conservado: event_id UUIDv4 string; parent_event_id UUIDv4|null; event_type string; audit_id/job_id UUIDv4|null; document_id string|null; timestamp UTC ISO8601; version_extractor/version_normalizer/version_rules strings; payload objeto abierto. Eventos nuevos: ninguno; fields agregados/eliminados/deprecados: ninguno.

[INFERIDO] payload document_rejected conserva todos los campos del document_downloaded y superpone patch existente: rejection_class/reason/origin/document_type/mime strings; detected_mime string|null; size_bytes/download_duration_ms enteros; rejected_at UTC. followUp preserva source/is_priority exactamente, incluso ausencia/null.

[INFERIDO] Ejemplo completo de evento rechazado de fixture con contexto sintético mínimo; `rejection_class` usa el valor real existente, y el resto de campos de payload son extensibles:

```json
{
  "event_id":"11111111-1111-4111-8111-111111111111",
  "parent_event_id":"22222222-2222-4222-8222-222222222222",
  "event_type":"document_rejected",
  "audit_id":"33333333-3333-4333-8333-333333333333",
  "job_id":null,
  "document_id":"44444444-4444-4444-8444-444444444444",
  "timestamp":"2026-10-09T12:00:00Z",
  "version_extractor":"gemini-3.x-parallel-fc-v1",
  "version_normalizer":"1.0.0",
  "version_rules":"1.0.0",
  "payload":{
    "source":"single","is_priority":true,
    "rejection_class":"document_content",
    "rejection_reason":"EMPTY_PDF_NO_PAGES",
    "rejection_origin":"App\\Services\\Audit\\Pipeline\\DocumentExtractionWorker",
    "document_type":"FORMULA MEDICA","mime":"application/pdf",
    "detected_mime":"application/pdf","size_bytes":256,
    "download_duration_ms":3,"rejected_at":"2026-10-09T12:00:00Z"
  }
}
```

[INFERIDO] payload DLQ conserva original_event completo y los campos actuales; los códigos nuevos son strings en last_error_code, no una categoría clínica. v1 conserva campos de stream que ya incorpora sendToDeadLetter; v2 conserva failed_stage/attempts y outbox. Compatibilidad forward: lectores no interpretan código desconocido como aprobación; compatibilidad backward: T19 parsea fixtures históricos y T06 valida terminal con código nuevo.

[INFERIDO] Ejemplo completo del envelope DLQ v2; original_event mínimo completo representa una raíz sintética, no una respuesta clínica; campos SQL de negocio no participan en este ejemplo de transporte:

```json
{
  "event_id":"66666666-6666-4666-8666-666666666666",
  "parent_event_id":"22222222-2222-4222-8222-222222222222",
  "event_type":"dead_letter","audit_id":"33333333-3333-4333-8333-333333333333",
  "job_id":null,"document_id":"44444444-4444-4444-8444-444444444444",
  "timestamp":"2026-10-09T12:00:00Z",
  "version_extractor":"gemini-3.x-parallel-fc-v1","version_normalizer":"1.0.0","version_rules":"1.0.0",
  "payload":{
    "failed_event_type":"document_downloaded","failed_stage":"extraction",
    "attempts":1,"last_error_code":"GEMINI_RESPONSE_PROVIDER_BLOCKED",
    "last_error_message":"GEMINI_RESPONSE_PROVIDER_BLOCKED",
    "original_event":{
      "event_id":"22222222-2222-4222-8222-222222222222","parent_event_id":null,
      "event_type":"document_downloaded","audit_id":"33333333-3333-4333-8333-333333333333",
      "job_id":null,"document_id":"44444444-4444-4444-8444-444444444444",
      "timestamp":"2026-10-09T11:59:59Z",
      "version_extractor":"gemini-3.x-parallel-fc-v1","version_normalizer":"1.0.0","version_rules":"1.0.0",
      "payload":{"source":"single","is_priority":true}
    },
    "source":"single","is_priority":true
  }
}
```

**C04 — nombres y HTTP**

[INFERIDO] Nueva clase `ClientNameLookup`, constructor `__construct(ClientsModel $clients, ?\Closure $cacheGet=null, ?\Closure $cacheSet=null)`; closures default usan Core\Cache; método `names(): array` retorna mapa string→string. No extender clases final para test ni introducir contenedor de servicios.

[INFERIDO] Nuevo valor Redis completo; incluso vacío conserva names como objeto JSON:

```json
{"schema_version":1,"names":{"2624":"EPS DE PRUEBA"}}
```

1. [INFERIDO] Leer key sin prefijo de conexión `query:clients:names:v1`; aceptar solo envelope schema_version entero1 y names objeto/mapa con claves de entero positivo decimal y valores string; mapa vacío válido. En JSON debe ser `{}` mediante stdClass si vacío; Core\Cache devuelve array al leer.
2. [INFERIDO] Miss/corrupción/error de lectura: consultar getAllClients exactamente una vez; normalizar como jobsList actual, ignorar NitSec ≤ 0 o filas sin NitSec/NitCom; construir nombres.
3. [INFERIDO] Guardar envelope TTL30; set false/excepción registra warning con error_class y retorna los nombres ya obtenidos, sin consultar otra vez. No cachear errores SQL; error SQL propaga al catch actual de jobsList.
4. [INFERIDO] En jobsList reemplazar bloque de construcción del mapa por `buildClientNameLookup()->names()`; conservar default fallback `Cliente #N`/`Sin cliente`, limit, sort, performance y envelope; error SQL sigue HTTP200 sin client_name igual al contrato anterior.
5. [INFERIDO] Store de jobs que lanza conserva503; listJobs actual que absorbe fallo conserva200/data[]. Corregir esa absorción exige un cambio de contrato distinto y no se incorpora al cache de nombres. Cache de nombres degradada no modifica HTTP; no agregar stale fallback infinito ni locks nuevos.

**C05 — prefetch y métricas**

[INFERIDO] `AuditDocumentCache::usage(): array` devuelve exactamente `reserved_bytes`, `reserved_entries`, `max_bytes`, `max_entries` como enteros no negativos, límites positivos; Lua usa TIME Redis, poda hasta64 expirados idénticos a PUT_LUA y obtiene bytes/ZCARD atómicamente. No usa KEYS/SCAN global ni contenido BLOB.

[INFERIDO] Nueva clase `AuditDocumentPrefetchGate` en namespace Pipeline: constructor `__construct(AuditDocumentCache $cache, \Closure $record, ?\Closure $clockMs=null)`; clock default intdiv(hrtime(true),1000000); record recibe `(string $event,int $durationMs): void`, enum cerrado de eventos prefetch_pauses/prefetch_wait_ms; `canRead(): bool`. Downloader construye gate con su cache y closure sobre AuditDocumentMetrics de su Redis: pauses→record('prefetch_pauses'), wait→recordPrefetchWait(duration). Inyección `?AuditDocumentPrefetchGate $prefetchGate=null` al final del constructor existente; no exponer Redis privado de cache.

1. [INFERIDO] Primera llamada siempre consulta usage; siguientes devuelven estado previo antes de1000 ms; no sumar tiempo duplicado por polling ni mantener estado entre procesos.
2. [INFERIDO] Abierta→cerrada si bytes*100 >= max_bytes*90 **o** entries*100 >= max_entries*90; registrar prefetch_pauses una vez y guardar closedAt monotónico.
3. [INFERIDO] Cerrada→abierta solo si bytes*100 ≤ max_bytes*70 **y** entries*100 ≤ max_entries*70; registrar prefetch_wait_ms con duración desde closedAt, una sola vez. Mientras cerrada, usage sigue podando expirados en cada revisión.
4. [INFERIDO] `AuditEventConsumer::canReadNewMessages(): bool` protected default true; loop v1 llama después de reclaimPendingIfDue y antes de XREADGROUP. false→usleep100000 y siguiente iteración, conservando señales/control de pausa. Downloader override delega al gate; las etapas durables no cambian scheduler.
5. [INFERIDO] Fallo Redis/usage inválido: propagar fuera de run igual a fallos de lectura Redis actuales; el supervisor reinicia el proceso. No convertirlo a puerta abierta ni ACK/fallo de auditoría; no descargar contenido sin presupuesto observable. Registrar arranque del downloader una vez con consumer/lane/max_bytes/max_entries/high_percent90/low_percent70/check_ms1000; sin datos de documentos.
6. [INFERIDO] Ya iniciado/reclaim pendiente puede superar el umbral blando; put Lua conserva límites duros y bypass recuperable. Documento mayor al presupuesto permite bypass cuando la puerta está abierta; no bloquearlo indefinidamente ni reservar tamaño desconocido.

[INFERIDO] `AuditDocumentMetrics::record` acepta prefetch_pauses como incremento1 y agrega método `recordPrefetchWait(int $durationMs): void` que HINCRBY prefetch_wait_ms con duración no negativa, sin incrementar un contador de eventos oculto; ambos sanitarios/best effort como métricas actuales. snapshot agrega ambos a counters, defaults0.

[INFERIDO] Ejemplo completo del DTO interno usage; metadata endpoint conserva sus campos actuales y solo añade esos dos contadores:

```json
{"reserved_bytes":241591911,"reserved_entries":922,"max_bytes":268435456,"max_entries":1024}
```

[CONFIRMADO] Frontend documentCache es UnknownRecordSchema (E10); campos agregados no requieren cambio de componente ni schema.

**C06 — configuración SQL**

[INFERIDO] getConfig/getHeader mantienen firmas públicas y shape; introducir helpers privados `getHeaderOn(PDO $connection,string $nitSec): ?array` y `getConfigRowsOn(PDO $connection,string $nitSec): array`. getConfig hace un único `$this->read(...)`, llama header→null retorna inmediatamente, luego rows y agrupación actual. getHeader público usa su propio read con helper. Cada helper prepara/execute/fetch/closeCursor en finally.

[INFERIDO] SQL completo de header, preservado:

```sql
SELECT FacNitSec, SystemPrompt, Activo, FecCre, FecMod, FactorConv, DiasVigencia
FROM Discolnet.dbo.AudDisp WITH (NOLOCK)
WHERE FacNitSec = :nitSec
```

[INFERIDO] SQL completo de campos, preservado:

```sql
SELECT nd.NitMedDocId AS docId, nd.NitMedDocNom AS docNombre,
       cat.CampoNombre, COALESCE(ac.TipoCampoOverride, cat.TipoCampo) AS TipoCampo,
       cat.TipoDato, cat.CodigoCampo, cat.EsVisual,
       cat.Descripcion AS DescripcionDefault, cat.Severidad AS SeveridadDefault,
       ac.Orden, ac.DescripcionOverride, ac.SeveridadOverride,
       ac.AplicaServicio, ac.EsMultiItem
FROM Discolnet.dbo.AudDispCampo ac WITH (NOLOCK)
INNER JOIN Discolnet.dbo.AudDispCampoCatalogo cat WITH (NOLOCK)
        ON cat.CampoNombre = ac.CampoNombre
INNER JOIN NitDocumentos nd WITH (NOLOCK)
        ON nd.NitSec = ac.FacNitSec AND nd.NitMedDocId = ac.NitMedDocId
WHERE ac.FacNitSec = :nitSec AND ac.Activo = 1 AND nd.NitMedDocOpc = 'N'
ORDER BY nd.NitMedDocId ASC, cat.EsVisual ASC, ac.Orden ASC
```

[INFERIDO] Shape conserva nitSec string, activo bool, systemPrompt string|null, factorConv bool, diasVigencia int|null, documents mapa por nombre o stdClass vacío; el agrupamiento se realiza en el getConfig existente, sin inventar métodos buildField/buildVisualCheck. T09 compara serialización previa sin renombrar claves. Replay READ repite ambas consultas con PDO nuevo y descarta cualquier resultado parcial; no abre una transacción nueva ni elimina NOLOCK en este ajuste.

**C07 — imagen y pooling**

- [INFERIDO] Script POSIX `docker/configure-odbc-pooling.sh` invocado antes de PHP por entrypoint; variables existentes DB_POOLING/DB2_POOLING default1; solo strings 0 o1 válidos, iguales entre ambas conexiones. Mixtos/inválidos: stderr código `odbc_pooling_flags_inconsistent`, exit64, antes de PHP.
- [INFERIDO] Comprobar `odbcinst -j` unixODBC>=2.3.7 y sección `ODBC Driver 18 for SQL Server` mediante odbcinst; versión insuficiente/driver ausente→exit69 con código operativo sin credenciales.
- [INFERIDO] Conservar todas las secciones/propiedades existentes de odbcinst.ini; actualizar/agregar [ODBC] Pooling=Yes/No y CPTimeout=120/0 solo en driver18 mediante parser AWK por sección, sin hardcodear ruta Driver ni reemplazar otros drivers. Configuración real /etc/odbcinst.ini de la imagen; escritura temporal en mismo directorio y rename atómico; trap retira temporal propio; permisos0644.
- [INFERIDO] Flags0/0: Pooling=No, CPTimeout=0, omitir reset de sesión; flags1/1: Pooling=Yes, CPTimeout=120, Database::openConnection hace reset antes de retornar PDO en Linux. Windows conserva su comportamiento vigente.
- [INFERIDO] Log de arranque único `odbc_pooling_configured` con mode, unixodbc_version, driver_name, timeout_seconds; jamás imprimir DSN/host/usuario/password.
- [INFERIDO] Extender try/catch de apertura a reset: error en reset destruye PDO y genera excepción de conexión para executor; no ejecutar callback, no reusar PDO fallido. M02/M03 incluyen el costo del reset y no solo new PDO.

**C08 — perfiles y variables**

| Variable existente | Antes default | Después candidato | Consumidores a sincronizar |
| --- | --- | --- | --- |
| [INFERIDO] GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS | 4096 | 8192 | .env.example, extracción, workflow, GitHub production durante implementación |
| [INFERIDO] GEMINI_EXTRACTION_THINKING_LEVEL | Vacío | low | Los mismos; default en generationOverridesFromEnv del extractor |
| [INFERIDO] GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS | 2048 | 4096 unitario | .env.example, judge unitario, workflow, GitHub production |
| [INFERIDO] GEMINI_SEMANTIC_THINKING_LEVEL | Vacío | low | Judge unitario y batch, .env.example, workflow, GitHub production |
| [INFERIDO] DB_POOLING / DB2_POOLING | 1 / 1 | Mismos defaults; nuevos flags globales Linux explícitos | entrypoint, Database, env/doc; antes de rollout comparar valores remotos |

[INFERIDO] Overrides explícitos válidos conservan prioridad; batch sigue maxOutputTokens8192 independiente del override unitario. No crear variable, cambiar modelo, ajustar thinkingBudget ni escribir GitHub en esta entrega documental. La implementación sincroniza las cuatro variables Gemini después de M05 y antes de push a main, según AGENTS.md.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| R01 | [INFERIDO] Clasificar correctamente PDF99 y preservar errores operativos | D02/C01; F01/F02 | T01–T03/M01; A01 |
| R02 | [INFERIDO] Respuesta remota incompleta es técnica, con retry finito y códigos sanitarios | D03/D04/C02/C03; F03–F09/F30 | T04–T08/T20; A02/A03 |
| R03 | [INFERIDO] Certificar perfiles/cachés antes de adoptar configuración | D05/C08; F06/F10/F11/F24 | T07/T21/M05; A04 |
| R04 | [INFERIDO] Una apertura por getConfig/intento con mismas consultas/shape | D06/C06; F12 | T09/T10; A05 |
| R05 | [INFERIDO] Pooling con sesión limpia y mejora neta medida | D07/C07; F13–F16 | T11/M02/M03; A06 |
| R06 | [INFERIDO] Reducir SQL de polling sin cambiar API/degradación | D08/C04; F17/F18 | T12/T13/M03; A07 |
| R07 | [INFERIDO] Acotar anticipación sin bloquear recuperación | D09/C05; F07/F19–F22 | T14–T17/M04; A08/A09 |
| R08 | [INFERIDO] Evaluar equidad y throughput con mismo corpus y carga | D10/D12; F23 | T18/T22/M06; A10 |
| R09 | [INFERIDO] Dejar rollout/rollback, env y documentación coherentes | D01/D11; F24–F29 y archivos de prueba | T19/M01–M06; A11/A12 |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| [INFERIDO] Extracción v1/v2 | Rasterizador/parser/cache | PDF99 pasa de técnico a rechazo; bloqueo sigue técnico | C01/C02; no cambiar preparación/receipts | E02/E03 |
| [INFERIDO] Judge y batch | Guard + cache + policy | Propagar error remoto en lugar de no-match cuando aplique | C02; invalidación artifact/namespace unitario | E05/E06 |
| [INFERIDO] Consumer/runner | Terminales/retry | Menos retry de estados permanentes; mismo cierre durable | Código tipado, operación generate_content | E03/E06 |
| [INFERIDO] API single/async, policy y normalizer | Eventos de rechazo | Deben seguir obteniendo rechazo documental y revisión técnica diferenciados | Tests de consumidores; no nuevo endpoint | E03; `RulesEvaluationWorker.php`, `DocumentNormalizer.php` |
| [INFERIDO] Modelos SQL | PDO/executor/driver | Conexión física reutilizada, objeto PDO nuevo; costo reset | C06/C07, T10/T11 | E04/E07/E12 |
| [INFERIDO] Frontend/MCP | HTTP/events | Mismo jobs envelope; error_code string nuevo; métricas aditivas | T19; ninguna modificación de UI requerida; herramientas MCP actuales no consumen jobs | E10; inventario `app/wrap/core/tools/`: GetInvoices/GetDispensation/GetClients/GetAttachments; `MCPServer.php:16` |
| [INFERIDO] Downloader/extractor | Cache global | Pausa intake nuevo; pendiente sigue reclamable; menos bypass esperado | C05 y medición; no garantía absoluta de reducción | E09 |
| [INFERIDO] Infra/deploy | env_file/entrypoint | Flags mixtos fallan; cambio pooling requiere reinicio | C07/C08 y gates | E07/E11 |
| [INFERIDO] Históricos SQL/Redis | Terminales/caches | No reescritura; caches antiguas dejan de consultarse unitariamente | Rollback sección9/14 | E03/E06 |

### 13. Cambios por Archivo

[INFERIDO] Todas las rutas Fxx se resuelven bajo `C:/Users/USER/Desktop/AudFact/`; la tabla usa rutas absolutas. Líneas describen el código previo a implementación, no la ubicación final de los nuevos métodos.

| ID / estado | Ruta completa | Clase/método y antes → después |
| --- | --- | --- |
| F01 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/RasterizationProcessResult.php` | contentFailure:16; `[0,1]`→`[0,1,99]`, precedencia operativa y regex C01 |
| F02 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php` | test fallback:51; mock0→dataset gs0/poppler99; integrar motores y fixtures T01/T02 |
| F03 [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiResponseFailureReason.php` | Enum completo C02 |
| F04 [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiResponseException.php` | Excepción/whitelist/retryable completos C02 |
| F05 [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiResponseGuard.php` | assertUsable y normalización de contexto C02 |
| F06 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | resolveExtraction:417; defaults4096→8192/low; nuevo afterTerminalFailure para liberar BLOB confirmado; catch429 conserva respuesta permanente según C02 |
| F07 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditEventConsumer.php` | loop:206/229, handleFailure:595, errorCode:833; añadir hook/typed permanent/code/context C02/C05 |
| F08 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditTaskRunner.php` | ramas terminales:42/70; typed permanent/operation y DLQcode/context; cleanup según resultado de commit, sin cambiar algoritmo/fence/committer |
| F09 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/GeminiResponseParser.php` | parse:39; extraer/check→Guard; retirar dos helpers privados y constantes exclusivas ya sin uso |
| F10 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticMatchJudge.php` | namespaces20/21, evaluateBatch75, callGemini156; Guard/catch tipado/invalidación/default4096/low; namespace v6/v3; logs207/231 retiran contenido según C02 |
| F11 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticBatchContract.php` | body14/validate59; low default y Guard; preservar8192/VERSION/schema |
| F12 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Models/AuditConfigModel.php` | getConfig28/getHeader124; dos read→uno y helpers PDO; misma agrupación/SQL C06 |
| F13 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/core/Database.php` | openConnection112; newPDO→newPDO+reset Linux pooled; cleanup ante fallo; no singleton nuevo |
| F14 [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/docker/configure-odbc-pooling.sh` | Validación, preservación INI, actualización atómica y log sanitario C07 |
| F15 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/docker/docker-entrypoint.sh` | Antes de configuración PHP, invocar `/usr/local/bin/configure-odbc-pooling.sh`; set-e propaga fallo |
| F16 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/docker/Dockerfile` | COPY script/chmod ejecutable junto a entrypoint:60; no actualizar dependencia/driver |
| F17 [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/ClientNameLookup.php` | Constructor/names C04; default closures Cache, un SELECT por miss |
| F18 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Controllers/AuditController.php` | jobsList496 y nuevo protected buildClientNameLookup junto a buildClientsModel663; mantener fallbacks |
| F19 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditDocumentCache.php` | Nuevo usage + USAGE_LUA con poda64 y respuesta exacta C05; sin modificar put/release |
| F20 [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditDocumentPrefetchGate.php` | Constructor/canRead; histéresis, clock monotónico y record C05 |
| F21 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AttachmentDownloadWorker.php` | Constructor/hook; inyección opcional al final, Gate usa misma cache/Redis; log de arranque sanitario una vez, handle intacto |
| F22 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditDocumentMetrics.php` | COUNTERS14/snapshot; añadir prefetch_pauses y recordPrefetchWait; defaults0 C05 |
| F23 [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Support/benchmark-pipeline.php` | CLI M06; manifest/medición/estadística JSON; no endpoint productivo nuevo |
| F24 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/.env.example` y `C:/Users/USER/Desktop/AudFact/.github/workflows/deploy-production.yml` | Cuatro defaults C08; workflows env mappings actuales preservados; registrar gates antes de adoptar |
| F25 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/.github/workflows/ci.yml` | Unit job asegura gs/pdftoppm; tests de script POSIX; Redis suite añade regresiones; no acceso real Gemini/BD en CI |
| F26 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/AGENTS.md` | Catálogo: describir defaults perfil y semántica global de flags; prohibición de push antes de sync conserva vigencia |
| F27 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/plans/api-endpoints.md`, `C:/Users/USER/Desktop/AudFact/plans/architecture.md`, `C:/Users/USER/Desktop/AudFact/plans/docker-operations.md`, `C:/Users/USER/Desktop/AudFact/plans/deployment-and-ci.md`, `C:/Users/USER/Desktop/AudFact/plans/testing-strategy.md`, `C:/Users/USER/Desktop/AudFact/plans/database-schema.md`, `C:/Users/USER/Desktop/AudFact/plans/features/audit-workflow.md` | Documentar respectivamente TTL/jobs, Guard/Gate, pooling/reset, gates/env/rollback, pruebas, ausencia DDL+read compartido, rechazo frente a fallo; describir como implementado solo después de tests |
| F28 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-api-rest/SKILL.md`, `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-audit-gemini/SKILL.md`, `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-sqlsrv-models/SKILL.md`, `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-runtime-docker/SKILL.md`, `C:/Users/USER/Desktop/AudFact/.agent/skills/audfact-project-overview/SKILL.md`, `C:/Users/USER/Desktop/AudFact/.agent/skills/CATALOG.md`, `C:/Users/USER/Desktop/AudFact/.agent/skills/catalog.json` | Sync por dominio F27; registrar nuevos archivos en mapeo; no crear skill nueva ni cambiar framework DDF |
| F29 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/README.md`, `C:/Users/USER/Desktop/AudFact/plans/changelog.md`, `C:/Users/USER/Desktop/AudFact/website/sidebars.ts` | Enlazar esta SDD, registrar estado/validación real y navegación; build usa copia de plans según Docker docs |
| F30 [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/app/Services/Audit/GeminiGateway.php` | invalidateTaskResponses242; nuevo bool defaulttrue, false conserva responses después de failContract y confirmación fenced; transporte/cuota/body sin cambio |

[INFERIDO] Fragmentos normativos antes/después de los cambios de control, complementados por firmas/SQL/algoritmos completos C01–C08:

```php
// F09 antes
$candidate = $this->extractPrimaryCandidate($response);
$this->assertSuccessfulFinishReason($candidate);
// F09 después
$candidate = GeminiResponseGuard::assertUsable($response, 'extraction');

// F10/F11 después: antes del JSON y antes de cachear
$candidate = GeminiResponseGuard::assertUsable($response, 'semantic');

// F12 antes: getHeader() hace un read separado del read de fields.
// F12 después: un único callback; el agrupamiento completo conserva el shape.
return $this->read(function (PDO $connection) use ($nitSec) {
    $header = $this->getHeaderOn($connection, $nitSec);
    if ($header === null) { return null; }
    $rows = $this->getConfigRowsOn($connection, $nitSec);
    Logger::info('AuditConfigModel::getConfig', [
        'nitSec' => $nitSec, 'rowCount' => count($rows),
    ]);
    $documents = [];
    foreach ($rows as $row) {
        $docNombre = $row['docNombre'];
        if (!isset($documents[$docNombre])) {
            $documents[$docNombre] = [
                'docId' => (int) $row['docId'], 'fields' => [], 'visualChecks' => [],
            ];
        }
        $aplicaServicio = self::normalizeAplicaServicio($row['AplicaServicio'] ?? null);
        $description = $row['DescripcionOverride'] ?? $row['DescripcionDefault'] ?? '';
        $orden = (int) $row['Orden'];
        $codigoCampo = $row['CodigoCampo'];
        if (!$row['EsVisual']) {
            $documents[$docNombre]['fields'][] = [
                'campoNombre' => $row['CampoNombre'], 'tipoCampo' => $row['TipoCampo'],
                'tipoDato' => strtolower(trim((string) $row['TipoDato'])), 'orden' => $orden,
                'severity' => $row['SeveridadOverride'] ?? $row['SeveridadDefault'] ?? 'media',
                'codigoCampo' => $codigoCampo, 'description' => $description,
                'aplicaServicio' => $aplicaServicio,
                'esMultiItem' => (bool) ($row['EsMultiItem'] ?? false),
            ];
        } else {
            $documents[$docNombre]['visualChecks'][] = [
                'check' => $row['CampoNombre'], 'description' => $description,
                'severity' => $row['SeveridadOverride'] ?? $row['SeveridadDefault'] ?? 'alta',
                'orden' => $orden, 'codigoCampo' => $codigoCampo,
                'aplicaServicio' => $aplicaServicio,
            ];
        }
    }
    return [
        'nitSec' => $nitSec, 'activo' => (bool) $header['Activo'],
        'systemPrompt' => $header['SystemPrompt'],
        'factorConv' => (bool) ($header['FactorConv'] ?? false),
        'diasVigencia' => isset($header['DiasVigencia']) ? (int) $header['DiasVigencia'] : null,
        'documents' => $documents === [] ? new \stdClass() : $documents,
    ];
});

// F07 después: dentro del loop legado, después de reclaimPendingIfDue().
if (!$this->canReadNewMessages()) { usleep(100000); continue; }
```

[INFERIDO] Inventario de archivos de pruebas que también forman parte del cambio; cada fila fija su cobertura en sección16 y todos quedan bajo la raíz absoluta indicada:

| Estado | Ruta completa | Pruebas |
| --- | --- | --- |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/DocumentExtractionWorkerTest.php` | T03/T08 |
| [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/GeminiResponseGuardTest.php` | T04/T20 |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/GeminiResponseParserTest.php` | T05 |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/AuditEventConsumerTest.php` | T06/T15/T19 |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/SemanticMatchJudgeTest.php` y `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/SemanticBatchContractTest.php` | T07 |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Integration/GeminiTaskExecutionRedisTest.php` | T08: runner, artifact, cuota y fence reales |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Models/AuditConfigModelTest.php` | T09/T10 |
| [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Support/probe-sql-pooling.php` y `C:/Users/USER/Desktop/AudFact/tests/Support/test-odbc-pooling.sh` | T11/M02/M03; script INI prueba sin credenciales |
| [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/ClientNameLookupTest.php` | T12 |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Controllers/AuditControllerTest.php` | T13/T19 |
| [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/AuditDocumentPrefetchGateTest.php` | T14/T16 |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Integration/AuditDocumentCacheRedisTest.php` y `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/AttachmentDownloadWorkerTest.php` | T15–T17 |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Integration/AuditExecutionRedisTest.php` | T18; agregar escenario mixto cap1 sin cambiar scheduler |
| [MODIFY] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/GeminiConfigTest.php` | T21; parámetros y overrides |
| [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/PipelineBenchmarkTest.php` | T22; CLI/validación/deduplicación/estadística y outputs |
| [NEW] | [INFERIDO] `C:/Users/USER/Desktop/AudFact/tests/Fixtures/pdf/empty-pages.pdf` y `C:/Users/USER/Desktop/AudFact/tests/Fixtures/pdf/one-page.pdf` | Fixtures sintéticos; generar xref/objetos correctos; T02 valida SHA y motores |

[INFERIDO] Archivos eliminados: ninguno; retirar únicamente helpers privados muertos F09. No modificar SqlServerConnectionExecutor, FairAuditTaskQueue, transporte/cuotas de GeminiGateway, contratos clínicos o perfiles Compose por esta SDD.

### 14. Plan de Migración

#### Prerequisitos

1. [INFERIDO] Crear branch/checkout aislado desde el árbol actual con las dependencias v2 locales incluidas; registrar commit y diff SHA. No partir de HEAD limpio ignorando archivos nuevos de los que depende el diseño.
2. [INFERIDO] Consultar CATALOG y skills de cada archivo antes de editar; congelar manifest de fixtures/evidencias sanitarias.
3. [INFERIDO] Mantener AUDIT_PIPELINE_EXECUTION_VERSION=1 en rollout inicial; registrar marcador Redis/roles realmente activos sin imprimir secretos.
4. [INFERIDO] Usar SQL/Redis/corpus de staging para M01–M06; nuevos ensayos de auditoría no se ejecutan sobre datos de negocio productivos.
5. [INFERIDO] Capturar baseline config/imagen/pooling/modelo/cuotas; leer solo flags de conexión, no volcar .env. Para perfil nuevo, disponer de corpus y superar M05.
6. [INFERIDO] Construir y probar imagen de rollback que conserve R01/R02 y namespaces seguros; revertir optimizaciones/perfiles sin reintroducir rechazo PDF99 incorrecto ni dictámenes semánticos parciales. F03–F11 forman la base mínima de esa imagen, con defaults Gemini anteriores y namespace de rollback de sección9.

#### Ejecución

1. [INFERIDO] **Entrega P1a:** F01/F02 y T01–T03; desplegar corrección PDF con protocolos existentes, después de build/test. No modificar histórical DLQ.
2. [INFERIDO] **Entrega P1b:** F03–F11 y retry/invalidation; ejecutar T04–T08/T20. Conservar valores efectivos Gemini anteriores hasta M05; probar candidatos vía configuración aislada.
3. [INFERIDO] **Entrega P1c:** F12/F17/F18 y T09/T10/T12/T13; comprobar shapes/TTL; son independientes de habilitar pooling.
4. [INFERIDO] **Entrega P2a:** F13–F16. Primero ejecutar imagen nueva con flags0/0 y M02; luego staging1/1, M02/M03. Si reset/beneficio no cumplen A06, dejar0/0 y no declarar pooling aceptado.
5. [INFERIDO] **Entrega P2b:** F19–F22 y T14–T17/M04; pausar admisión, drenar/reiniciar downloaders de forma controlada; consumidores pendientes siguen sujetos a receipts.
6. [INFERIDO] Después de M05 PASS, adoptar C08 en código/defaults/workflow y GitHub production; registrar valores previos para rollback. Ejecutar comandos exactos de sincronización siguientes **durante implementación**, no en esta entrega:

```powershell
gh variable set GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS --body "8192" --env production
gh variable set GEMINI_EXTRACTION_THINKING_LEVEL --body "low" --env production
gh variable set GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS --body "4096" --env production
gh variable set GEMINI_SEMANTIC_THINKING_LEVEL --body "low" --env production
gh variable list --env production
```

7. [INFERIDO] Sincronizar F26–F29, validar skill registry/portal; solo entonces cerrar implementación. Registrar cada gate como PASS/FAIL con artifact SHA, sin sustituirlo por resultados de esta entrega documental.
8. [INFERIDO] **Activación v2 separada:** ejecutar procedimiento de `plans/sdd-homologacion-semantica-asincrona-ejecucion-justa.md` con cuotas verificadas, AOF/fsync/noeviction, snapshot/restore, drenaje y attestation. Esta SDD solo exige T18/M06; no permite editar marcador directamente ni saltar gates existentes.

#### Validaciones Previas

- [INFERIDO] Tests focalizados y suite existente pasan; skips por motores PDF no admitidos en CI de F25.
- [INFERIDO] Comparar configuración remota vs archivo generado; mixed DB flags impiden arranque de imagen nueva.
- [INFERIDO] Snapshot/backup y procedimiento restore certificados antes de cambios de protocolo; imagen de rollback conocida; estados/jobs/slots y contadores capturados.
- [INFERIDO] Revisar diff para descartar SQL DDL, prompts clínicos alterados, nuevo retry HTTP anidado o incremento de cap1.

#### Validaciones Posteriores

- [INFERIDO] /health, /audit/jobs y /metrics/async responden HTTP200; protocolo esperado coincide Redis/Env; contadores y terminales se conservan.
- [INFERIDO] M01 no genera DLQ técnico por fixture PDF vacío; M03 prueba miss/hit/TTL; M04 confirma extracción activa durante pausa y reservas ≤ límites duros.
- [INFERIDO] Vigilar en ventanas de15 minutos: technical_failure_rate, cierres/minuto sin fallo, p95 integral, bytes/entradas, recuperaciones, tokens/costos; aplicar gatillos de rollback abajo.

#### Rollback

1. [INFERIDO] Gatillo inmediato: aprobación falsa nueva, pérdida/duplicación de terminal o contaminación de sesión SQL. Gatillo de rendimiento: p95 integral >120% baseline o cierres sin fallo/min inferior a 90% baseline durante dos ventanas de 15 min con carga comparable.
2. [INFERIDO] Cerrar admisión mediante la pausa existente; dejar finalizar operaciones y outbox. Registrar estado/PEL/receipts/attempt ledger; no flush, no ACK masivo ni compensación de cuota.
3. [INFERIDO] Perfil Gemini: restaurar los cuatro valores exactos capturados, no defaults supuestos; conservar guard para evitar convertir fallos remotos a dictamen; reiniciar workers tras configuración coherente.
4. [INFERIDO] Pooling: escribir DB_POOLING=0 y DB2_POOLING=0 en configuración autorizada; sincronizar ambas variables existentes remotas si se modifican; recrear todos los PHP/workers para recargar ODBC. No editar INI dentro de imagen productiva inmutable.
5. [INFERIDO] Prefetch/config/jobs: volver a imagen anterior dentro del mismo protocolo solo tras drenaje; campos métricos/caches nuevos se ignoran/expiran. Si el paquete de rollback retira guard, mantener admisión cerrada hasta disponer de imagen con guard y namespaces seguros de sección9.
6. [INFERIDO] Downgrade v2→v1: procedimiento de la SDD de homologación con manifest/accept/restore; no inferir que rollback de imagen reconstruye continuaciones por sí solo.
7. [INFERIDO] Comprobar endpoint/marker, iguales terminales y slots, receipts reparables, cuota sin devolución; reabrir admisión únicamente después de esas verificaciones. Reprocesar DLQ por endpoint existente con nuevas identidades; conservar job original y evidencia.

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| [INFERIDO] PDF99 + no pages | Rechazo EMPTY_PDF_NO_PAGES | T01/T02, 0 solicitudes Gemini |
| [INFERIDO] PDF99 + permission denied/no space junto a no pages | Error técnico | T01; sin rechazo documental |
| [INFERIDO] gs rechazo + Poppler0 con JPEG | Extracción normal | T02/T03 |
| [INFERIDO] gs rechazo + fallback desconocido/exit137 | Técnico; no rescatar rechazo primario | T01/T02 |
| [INFERIDO] Gemini OTHER sin candidate | Técnico permanente | T04/T06; 1 intento generate_content |
| [INFERIDO] Sin candidate ni blockReason | Técnico transitorio acotado | T06/T08; máximo3 intentos owner |
| [INFERIDO] MAX_TOKENS con JSON válido o parcial | Técnico permanente | T05/T07; 0 resultados/cache clínicos |
| [INFERIDO] STOP con schema incorrecto | Validación estricta actual | T05/T07; ningún resultado parcial |
| [INFERIDO] Crash después de respuesta Gemini y antes de error terminal | Recuperación artifact/fence conserva cobro | T08 |
| [INFERIDO] Header nulo o segunda query falla | null o replay completo READ, sin mezcla de intentos | T09/T10 |
| [INFERIDO] Caché nombres corrupta/set fallido/SQL fallido | Una consulta por miss; error SQL no cacheado y misma degradación HTTP | T12/T13 |
| [INFERIDO] Flags DB mixtos/driver faltante | Arranque falla antes de PHP con código sanitario | T11 |
| [INFERIDO] Estado SQL heredado/no cleanup después de error | Reset checkout deja opciones y temporales conocidos limpios | M02 |
| [INFERIDO] Cache alta con 1000 reservas expiradas | Poda64 por revisión hasta liberar umbral; no deadlock por ausencia de put | T16 |
| [INFERIDO] Descargas concurrentes ya iniciadas/documento mayor al límite | Bypass recuperable; no superar límite duro ni rechazar contenido | T15/T17 |
| [INFERIDO] Redis caído o usage malformed | No nuevas descargas; dependencia recuperable, sin ACK ni terminal inventado | T16 |
| [INFERIDO] Job/scope duplicado/restart durante pausa | Receipt/fence y cap1 siguen existentes | T15/T18/T19 |

### 16. Testing

[INFERIDO] Pruebas deben ejercer contratos de comportamiento; no validar solo regex/reflection o copiar implementación. Mantener test doubles existentes y usar Redis/motores reales cuando el fallo depende del runtime; no llamar Gemini real desde CI.

#### Nuevos Tests

| ID / archivo en sección13 | Objetivo y precondiciones | Pasos | Resultado esperado |
| --- | --- | --- | --- |
| T04 | [INFERIDO] Clasificación/precedencia; respuesta sintética sin PHI | Dataset block OTHER/SAFETY/UNSPECIFIED, missing/null candidate, MAX_TOKENS, STOP, unknown finish, task inválido | Enum exacto, retry bool, candidato retornado solo STOP |
| T11 | [INFERIDO] Script INI seguro; fixture INI con dos drivers/ruta versionada | Ejecutar shell con flags1/1,0/0,1/0,2/1; simular driver/version inválidos; ejecutar dos veces | Propiedades ajenas idénticas, modo/timeout exactos, idempotencia, exit64/69, sin secretos |
| T12 | [INFERIDO] Lookup inyectado; closures/fakeModel cuentan llamadas | hit/miss/TTL/corrupción/read exception/set false/set exception/SQL exception/empty map | Hit0 SELECT; miss1 SELECT aunque set falle; error SQL no cacheado; TTL30 y shape C04 |
| T14 | [INFERIDO] Histéresis con clock falso y cache usage | 89→90→80→70%; bytes y entradas independientes; llamadas dentro/fuera1000 ms | Abre/cierra según ambos umbrales; una pausa y duración exacta por transición |
| T16 | [INFERIDO] Caducidad, malformed/Redis failure; Redis aislado | Insertar expirados en índices, cerrar puerta sin put; revisar64 por iteración; simular fallo | Índices se liberan hasta reapertura; errores no abren puerta; no espera bloqueante larga |
| T20 | [INFERIDO] Contexto contiene campos tóxicos sintéticos | Inyectar prompt/finishMessage/nombres/multiline/model inválido/tokens negativos | DTO/log solo whitelist; no substring tóxico; no salida de rasterización |
| T22 | [INFERIDO] CLI agregador; JSONL sintético y directorio temporal por test | Inputs válidos/inválidos, duplicates exactos/contradictorios, comparación distinta, cero done, salida yaexistente; repetir bootstrap mismo seed | Exit0/2/3 especificados; sin red/BD; output estable, percentiles/deltas exactos, nulos reason, deduplicación por request |

#### Tests Modificados

| ID | Objetivo y precondiciones | Pasos | Resultado esperado |
| --- | --- | --- | --- |
| T01 | [INFERIDO] Clasificador códigos reales; F02 | Dataset exits0/1/99/2/137/-1, regex vacío/corrupto/operativo/mixto | NO_PAGES/CORRUPTED solo señales permitidas; operativo/desconocido null |
| T02 | [INFERIDO] Fallback real; gs/pdftoppm instalados, fixtures sin PHI | Rasterizar empty-pages.pdf y one-page.pdf; capturar exit/typed/JPEG/SHA sin stdout clínico | Vacío tipado aun con exit99; una página produce imagen válida; sin skip CI |
| T03 | [INFERIDO] Extractor v1 y prepareTask v2 con empty PDF | Procesar/repetir evento, simular fallo publish/commit y recuperar | document_rejected permitido, 0 Gemini, terminal sin DLQ técnico por PDF; release después de confirmación |
| T05 | [INFERIDO] Parser con contratos actuales | STOP válido, MAX_TOKENS JSON válido, nonSTOP, campos faltantes | Misma extracción válida; tipada para estado remoto; schema inválido sigue inválido |
| T06 | [INFERIDO] Consumer v1 con publisher/store aislados | Bloqueo/truncamiento/missing; duplicar terminal y fallar receipt | Permanente1 intento; missing usa maxRetries; código tipado DLQ; cierre/ACK solo receipt confirmado |
| T07 | [INFERIDO] Judge unitario/batch | nonSTOP JSON válido, OTHER, STOP válido, pares duplicados/extras/faltantes; semilla namespace viejo | No cache/result clínico en incompleto; propagación; invalidación batch; old cache no hit; conservador intacto |
| T08 | [INFERIDO] Runner/cuota/artifact Redis real | HTTP stub produce bloqueo/truncamiento/missing; crash y fence obsoleto; retry count→generate; commit RETRY_PREPARE/STALE; retener response permanente y repetir failContract | Cargos una vez por HTTP; operation generate; missing máximo3; terminal/idempotencia/outbox; BLOB libera solo commit confirmado; mismo body permanente no repite HTTP al diferir/reiniciar, hash nuevo no reutiliza; fence viejo rechazado |
| T09 | [INFERIDO] Config fakePDO/fake executor cuentan aperturas | Header/rows múltiples, sinheader, documentosvacíos/dias/servicios/multiitem | 1 apertura/intento, mismos JSON previos, ambos cursores cerrados; sinheader no2ªSELECT |
| T10 | [INFERIDO] Fallo recuperable segunda query | PrimerPDO falla fields; segundo devuelve otro header+rows | Replay ambas SELECT; ningún header de primer intento; PDO nuevo; política READ existente |
| T13 | [INFERIDO] Controller jobs y errores | limit1/100/inválido/default, cache hit/miss, store que lanza, store actual que retorna [], SQL nombre fail | Mismo envelope/status/sort/fields;503 si lanza;200/data[] si store devuelve [];200 sinclient_name SQLfail |
| T15 | [INFERIDO] Loop/downloader bajo presión | Saturar cache, insertar nuevo y PEL; ejecutar ciclo con gate cerrado y señal stop | No XREAD nuevo/download nuevo; reclaim y señal avanzan; sinACK noejecutado |
| T17 | [INFERIDO] Cache Redis concurrente | Cuatro productores/consumidores, bypass oversize, expire/recovery/hashbad; snapshot antiguo | Reservas ≤ caps; SHA recuperado correcto; contadores aditivos con default0; wait ≥ 0 |
| T18 | [INFERIDO] Scheduler v2 existente, Redis aislado | Encolar scopes batch A3000/B3000/C69 y single; cuatro etapas; finalizaciones/defer/crash | Cap1 por scope/etapa; RR sin hambre de scopes elegibles; un cierre durable; no modificar protocolo |
| T19 | [INFERIDO] Compatibilidad histórica | Fixtures AuditEvent/DLQ/jobs antes y después; clients/schema frontend | Lectores actuales admiten payloads/stringcodes; fields jobs idénticos; original_event preservado |
| T21 | [INFERIDO] Config por tarea | env vacío, cuatro overrides explícitos, unitario y batch | Defaults C08; overrides prevalecen; batch8192; low válido; modelo/globalconfig intactos |

#### Tests Eliminados

[INFERIDO] Ningún archivo/suite eliminado; el test que solo simula fallback0 se amplía con dataset y T02 en el mismo archivo. Se conserva su escenario porque fallback exitoso continúa permitido.

#### Verificaciones Manuales

| ID | Objetivo/precondiciones | Pasos exactos | Resultado/artifact |
| --- | --- | --- | --- |
| M01 | [INFERIDO] Reproducción PDF; Docker staging con herramientas | Ejecutar `vendor/bin/phpunit tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php`; fixture vacío por ambos motores; extractor stub en ambas rutas | JSON con SHA/exit/reason; EMPTY_PDF_NO_PAGES, cero retry técnico; no invocar auditorías reales |
| M02 | [INFERIDO] Reuso/reset SQL; usuario staging autorizado, conexión default y db2 | En proceso PHP único:30 aperturas por nombre, destruir PDO entre rondas; guardar SPID/DBCC USEROPTIONS; establecer NOCOUNT/LOCK_TIMEOUT y crear tempfixture de los cinco nombres; siguiente checkout compara baseline/temporales; simular transacción fallida y nueva lectura | Sin tabla permanente ni PHI; reset exacto, @@TRANCOUNT0, temps conocidas ausentes; flags0/0 y1/1 probados; MARS/BLOB/cursor compatible |
| M03 | [INFERIDO] SQL/polling pareado; misma fuente/carga | Probe nuevo PDO+reset+3SELECT1 por30 rondas/conexión; calcular p50/p95 de open y ciclo total, warmup5 aparte. HTTP20 GET secuenciales en20 s hit30s y otro tras31s; repetir baseline | Pool sesión limpia; p50 ciclo total ≤ 85% baseline; jobs hit p95 ≤ 250 ms y reducción p50 ≥ 70%; no medir solo connect ignorando reset |
| M04 | [INFERIDO] Presión BLOB; Redis aislado/carga fija | Replay3000 docs de tamaños manifest con same replicas; comparar gate on/off en procesos independientes; muestrear1s presupuestos, bypass/recovery/latencia; pausar/reiniciar, expirar y recuperar | Sin pérdida/hasherror; caps duros respetados; recovery_rate reducido>=50%, cierres/min no inferior90% baseline; si falla no declarar mejora |
| M05 | [INFERIDO] Corpus y perfil; referencia acordada/documentos desidentificados | Manifest ≥ 200 auditorías con positivos/negativos, docs ilegibles/0p/multipágina/tablas y pares difíciles; ejecutar perfil anterior/candidato mismo modelo servido/corpus,3 repeticiones; excluir cache vieja, conservar costos por request | Cero aprobaciones falsas nuevas vs referencia; macroF1 campos caída ≤ 0.5 pp; schema válido no inferior baseline; truncamiento ≤ 0.5%; tokens totales/éxito ≤ 125% baseline; si falta corpus no adoptar perfil |
| M06 | [INFERIDO] Latencia/equidad; carga3000+3000+69 y 1single/30s, cuotas staging verificadas | CLI F23 con tres corridas por variante:basev1,fixv1,fixv2; mismos recursos/sourcequota/model/gold; warmup10%, capturar tiempos raíz/etapa/terminal y costos; intervalosbootstrap pareados95% con semilla20261009 | Integridad/gold; separar fairqueue/totalwait; no promise speedup si CI cruza0; criterios A10; v2solo después gates existentes |

[INFERIDO] CLI F23 es un agregador de evidencia exportada, no un productor de auditorías: `php tests/Support/benchmark-pipeline.php --manifest=<ruta> --records=<ruta-jsonl> --variant=<base-v1|fix-v1|fix-v2> --run-id=<UUIDv4> --output=<ruta-json>`; fix-v1/fix-v2 agregan argumentos obligatorios `--baseline-manifest=<ruta> --baseline-records=<ruta-jsonl>`, base-v1 no los acepta. Baseline de fix-v1 es base-v1; baseline de fix-v2 es fix-v1. Exportación de fixtures/test runner y operación controlada staging antecede al comando. No llamar API/SQL/Gemini desde el agregador. Exit0 evidencia válida; exit2 configuración/JSON/shape inválido; exit3 comparación no equivalente; nunca escribir encima de output existente.

[INFERIDO] Manifest exige schema_version entero1, run_id UUIDv4 igual a CLI, variant enum base-v1/fix-v1/fix-v2 igual a CLI; source_commit regex `[a-f0-9]{40}`, image_sha regex `sha256:[a-f0-9]{64}`, fixture_sha256/runtime_profile_sha256 regex `[a-f0-9]{64}`; execution_version entero1/2; replicas mapa rol→entero>=0; requested_model/served_model strings no vacíos; quota_scope string sin credenciales; rpm/tpm/rpd/inflight enteros positivos; jobs lista con scope_id string, count entero>0, lane priority/batch, document_set string de conjunto desidentificado. Variantes base/fix v1 exigen execution_version1; fixv2 exige2.

[INFERIDO] Antes de resultados comparar cuotas/modelos/fixtures/jobs y runtime_profile_sha256; ese hash proviene de JSON canónico con límites CPU/RAM totales, host, versiones PHP/ODBC/SQL y snapshot fixture, sin credenciales. Replica por rol igual entre basev1/fixv1; fixv2 admite distribución distinta de roles nuevos pero debe conservar presupuesto CPU/RAM total, registrado por operador. SHA de código/imagen identifica variante y no debe ser igual. Baseline manifiesto identifica su propio run_id, y sus records deben coincidir con él.

[INFERIDO] JSONL de records exige run_id UUIDv4, audit_id UUIDv4, fixture_id string estable sin PHI, scope_id y lane priority/batch; admitted_ms/terminal_ms enteros, terminal done/failed, stage_wait_ms mapa stage→entero>=0, recovered/documents enteros>=0, requests lista de request_id string, total_tokens/duration_ms enteros>=0, served_model string. Test de scheduler exporta claim stage/scope/time_ms/eligible_scopes para A10. Timestamps de cola provienen de enqueued_at_ms en v2 y timestamp del evento admitido en v1; primer lease se observa en test/telemetría staging, no se inventa de una muestra posterior. Exportar después de terminal completo, omitir contenido clínico. Requests repetidos de mismo id con usage distinto invalidan input; mismos datos se deduplican. Cada auditoría aparece una vez; terminal anterior a admitted invalida input. Falta de datos produce error, nunca valor0 supuesto.

[INFERIDO] Ejemplo completo de manifest para probar el agregador con un fixture; los valores de cuota son sintéticos y no autorizan llamadas. M06 usa los jobs/cantidad de su escenario completo, en lugar de este ejemplo mínimo:

```json
{
  "schema_version":1,"run_id":"77777777-7777-4777-8777-777777777777","variant":"base-v1",
  "source_commit":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "image_sha":"sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "fixture_sha256":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
  "runtime_profile_sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd",
  "execution_version":1,
  "replicas":{"orchestrator":1,"downloader":1,"extraction":1,"policy":1,"persistence":1},
  "requested_model":"gemini-3.7-flash","served_model":"gemini-3.8-flash",
  "quota_scope":"SYNTHETIC_TEST_ONLY","rpm":100,"tpm":100000,"rpd":1000,"inflight":4,
  "jobs":[{"scope_id":"test-A","count":1,"lane":"batch","document_set":"synthetic-one-page"}]
}
```

[INFERIDO] Ejemplo completo de una línea JSONL desidentificada; stage_wait_ms describe solo las etapas presentes, no supone que existan tareas durables v1:

```json
{"run_id":"77777777-7777-4777-8777-777777777777","audit_id":"88888888-8888-4888-8888-888888888888","fixture_id":"synthetic-one-page-001","scope_id":"test-A","lane":"batch","admitted_ms":1000,"terminal_ms":5000,"terminal":"done","stage_wait_ms":{"extraction":200,"policy":100},"recovered":0,"documents":1,"requests":[{"request_id":"synthetic-request-001","total_tokens":1000,"duration_ms":1500,"served_model":"gemini-3.8-flash"}]}
```

[INFERIDO] Intervalos de confianza M06: bootstrap pareado 10000 remuestreos de auditorías con mismo fixture entre variantes; usar `Random\Randomizer(new Random\Engine\Mt19937(20261009))`; percentiles2.5/97.5 nearest-rank; reportar deltas por réplica de corrida además de intervalo. Ordenar input por fixture_id antes de muestrear; identidades de auditoría diferentes se emparejan por fixture_id estable sin PHI. No mezclar intervalos de rendimiento v1/v2 con datos de distintos modelos servidos.

[INFERIDO] Métricas F23: technical_failure_rate=failed/(done+failed); completion_per_min=60*delta(done)/delta(wall_s); latency p50/p95 desde admisión raíz a cierre; espera desde enqueued_at a primer lease por etapa; recovery_rate=recovered/docs; tokens/éxito sumausage por request_id único/done. Warmup10% por orden admitted_ms con desempate fixture_id; mantenerlo en integridad pero excluirlo de métricas. Si denominador0, output null con reason `no_completed_audits`/`no_documents`, no0. Percentil nearest-rank `sorted[ceil(p*n)-1]`; no interpretar backlogDepth como auditorías únicas.

[INFERIDO] Warmup usa floor(0.1*n); en comparación pareada selecciona fixtures a excluir con el orden admitted_ms/fixture_id del baseline y excluye los mismos IDs en ambas variantes. Conjuntos de fixture_id distintos o conteos que no coinciden con jobs.count invalidan comparación con exit3. Esta regla prevalece sobre ordenar cada variante por su propia admisión para escoger calentamiento.

[INFERIDO] Output JSON F23 tiene exactamente schema_version=1, run_id, variant, manifest_sha256, records_sha256, samples (`total`, `warmup`, `measured`, `done`, `failed` enteros), metrics, comparison. metrics contiene technical_failure_rate, completion_per_min, latency_ms (`p50`,`p95`), stage_wait_ms (mapa de etapa→p50/p95), recovery_rate y tokens_per_done. Rates/medidas son float no negativo o null; reasons es mapa opcional dentro de metrics para campos nulos. Ventana wall_s=(max terminal_ms-min admitted_ms)/1000 de cohort medido; wall_s0 produce completion_per_min=null/reason zero_elapsed.

[INFERIDO] comparison=null en base-v1; en fix-v1/fix-v2 contiene baseline_run_id, baseline_variant y deltas, mapa de métrica escalar incluyendo latency_ms.p50/p95 y cada stage_wait_ms: cada valor tiene absolute, relative y ci95 (`lower`,`upper`) para diferencia absoluta pareada. relative=(candidate-baseline)/baseline, baseline0→null; valores no calculables mantienen null. Bootstrap remuestrea pares completos con reemplazo y recalcula cada métrica incluyendo ventana wall_s; CI para relative no se agrega. Emitir JSON_UNESCAPED_SLASHES/UNICODE|PRETTY_PRINT más newline y usar archivo temporal del mismo directorio con rename, rechazando destino existente.

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| [INFERIDO] Regex convierte operación en rechazo | Consistencia de datos | Alta | D02/T01 precedencia; salida privada |
| [INFERIDO] Bloqueo OTHER se presenta como seguridad comprobada | Operativo | Media | Código PROVIDER_BLOCKED genérico; sin atribuir causa no informada |
| [INFERIDO] Menos thinking modifica exactitud | Consistencia de datos | Alta | S02/M05; perfiles anteriores hasta gate; cero falso aprobado nuevo |
| [INFERIDO] MAX_TOKENS terminal aumenta manual review | Operativo/costo | Media | Perfil certificado y reproceso explícito; nunca aprobación parcial |
| [INFERIDO] Session pooling conserva estado/temporal | Consistencia de datos | Alta | Batch reset conocido y M02; mantener0/0 si falla; no pooling lógico PDO |
| [INFERIDO] Reset SQL absorbe ganancia de conexión | Rendimiento | Media | M03 mide ciclo completo; gate70%, no afirmar ahorro teórico |
| [INFERIDO] Pausa global retrasa carril prioritario o PEL acelera bypass | Rendimiento | Media | Pausa solo nuevaslecturas, capduro/recovery, M04; no prometer justicia del downloader |
| [INFERIDO] Reserva expirada bloquea gate | Operativo | Alta | Poda por usage64 aun sin put; T16 |
| [INFERIDO] Misses concurrentes de nombres consultan simultáneamente | Escalabilidad | Media | TTL30, una consulta/request; medir; mutex distribuido queda fuera, no garantizar una consulta global por TTL |
| [INFERIDO] Rollback recupera cache semántica vieja no certificada | Consistencia de datos | Alta | Namespace rollback seguro y conservar guard; admisión cerrada si faltan |
| [INFERIDO] Confundir docs propuestas con operación efectiva | Gobernanza | Media | Registrar protocolo/runtime y gates pendientes en changelog |
| [DESCONOCIDO] Capacidad/cuotas productivas y referencia clínica | Operativo | Alta | Gates existentes v2 y M05/M06; bloquear activación sin evidencia |

### 18. Criterios de Aceptación

| ID | Criterio objetivo | Evidencia obligatoria |
| --- | --- | --- |
| A01 | [INFERIDO] PDF vacío real gs→Poppler99 produce rechazo tipado; no llamada IA ni DLQ técnico por PDF; errores operativos siguen técnicos | T01–T03/M01 PASS; SHAfixture/exit/result |
| A02 | [INFERIDO] Tras primer HTTP200, OTHER/MAX_TOKENS/nonSTOP generan técnico terminal con una solicitud generate; missing sin bloqueo usa ≤ 3 por owner; ningún resultado parcial clínico | T04–T08 PASS en v1/v2 |
| A03 | [INFERIDO] Cada HTTP tiene un cargo/attempt; retry/terminal/fence no duplican cierre ni contadores; contextos sanitarios | T08/T20 PASS con crash/replay |
| A04 | [INFERIDO] Perfiles solo se adoptan con todos los umbrales M05 satisfechos y cuatro variables remotas verificadas | Corpusmanifest/resultados/snapshot no sensible; de otro modo aceptación R03 pendiente |
| A05 | [INFERIDO] getConfig válido abre1PDO por intento; mismasqueries/JSON; headernull no ejecuta fields; replay no mezcla resultados | T09/T10 PASS |
| A06 | [INFERIDO] Pooling pasa reset/temporales/cursor/BLOB; ciclo SQL p50 ≤ 85% baseline con reset; flags mixtos fallan | T11/M02/M03 PASS; de otro modo pooling sigue0/0 y R05 pendiente |
| A07 | [INFERIDO] Cache nombres hit0SQL/miss1SQL/request, TTL30s, setfailure no duplica; jobs hit p95 ≤ 250 ms y p50 reduce ≥ 70% | T12/T13/M03 PASS; limit/status/envelope iguales |
| A08 | [INFERIDO] Gate cerrado no lee nuevos mensajes, reclaim/señales continúan, expirar reservas permite reapertura; hardcaps nunca excedidos | T14–T17 PASS |
| A09 | [INFERIDO] Recovery_rate cae>=50% en M04 con cierres/min>=90% baseline y cero pérdida/hashincorrecto | Manifest y tres corridas pareadas; de otro modo R07 no aceptado como mejora |
| A10 | [INFERIDO] En T18 ningún scope elegible queda sin turno por más de N claims exitosos de la misma etapa, N=scopes elegibles al empezar ciclo; cap1 siempre. En M06 p95 cola por job menor reduce ≥ 50% v2 vs fixv1, cierres/min ≥ 90%, single p95 ≤ 120% | Logs claims/scopes; separar restricciones cuotas/defer de elegibilidad; resultado no equivale a autorizaciónv2 |
| A11 | [INFERIDO] Compatibility T19 PASS; ningún DDL permanente ni modificación de IDs históricas; rollback practicado sin resetRedis | Fixtures y manifests pre/post rollback |
| A12 | [INFERIDO] CI/tests/envcontract/skills registrados/portal pasan; changelog distingue implementación, medidas y pendientes | Salidas, commitSHA y diff; fallos preexistentes ajenos identificados sin falso PASS |

## FASE 2 — Auditoría de Consistencia

[INFERIDO] Auditoría siguiente evalúa completitud y coherencia del diseño, no acredita pruebas de implementación pendientes; las compatibilidades operativas nuevas se someten a T/M/A y no se declaran demostradas.

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas están definidas | [INFERIDO] PASS | No tablas permanentes nuevas; SQL C06/E04; temporales conocidas E12/batch completo |
| Todas las columnas existen | [CONFIRMADO] PASS | SELECT completos reproducen columnas de AuditConfigModel; no columna nueva |
| Todos los contratos documentados | [INFERIDO] PASS | C01–C08 y archivo CLI/manifest/JSONL de16; envelope abierto conserva extensión histórica |
| Todos los requisitos tienen trazabilidad | [INFERIDO] PASS | R01–R09→F/T/M/A; cada nuevo archivo aparece en13 |
| Todos los consumidores analizados | [INFERIDO] PASS | Extracción/judge/batch, consumer/runner, policy/normalizer, HTTP/frontend/SQL/ops en12 |
| Todas las migraciones tienen rollback | [INFERIDO] PASS | Redis lazyfill/caches, estado de sesión, env/imagen y protocolo en9/14 |
| Todas las referencias están definidas | [INFERIDO] PASS | E01–E12/S01–S06/D01–D12/I01–I12/C01–C08/R01–R09/F01–F30/T01–T22/M01–M06/A01–A12 |
| Toda compatibilidad tiene evidencia | [INFERIDO] PASS | Shapes actuales E03/E10/E11 conservados; cambios semánticos explícitos C02/C03; compatibilidad operativa condicionada T19/M02, no asumida |
| Todos los criterios son verificables | [INFERIDO] PASS | A01–A12 contienen límites/outputs/artifacts; dato desconocido bloquea gate, no genera PASS |

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado |
| --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | [INFERIDO] No; D01–D12 y S01–S06 especifican controles, prioridades y límites |
| ¿Existe algún contrato sin documentar? | [INFERIDO] No dentro de cambios; C01–C08 preservan extensión funcional de eventos y validators actuales |
| ¿Existe algún consumidor no analizado? | [INFERIDO] No dentro de alcance; sección12 y T19 cubren transportes/lectores afectados |
| ¿Existe alguna migración sin rollback? | [INFERIDO] No;9/14 cubren env, caches, pooling, imágenes y protocolo existente |
| ¿Existe algún dato persistido sin migración? | [INFERIDO] No; cambios Redis son aditivos/lazy, históricos inmutables, sin DDL permanente |
| ¿Existe alguna afirmación sin evidencia? | [INFERIDO] No presentada como confirmada; propuestas y pendientes se clasifican, E01–E12 sustentan hechos |
| ¿Existen referencias huérfanas? | [INFERIDO] No;11/13/16/18 cierran R/F/T/M/A y todos los supuestos aparecen aquí |
| ¿Dos implementadores producirían soluciones diferentes? | [INFERIDO] No en contratos/algoritmos/efectos; C01–C08 y decisiones fijan salidas, retry, umbrales, reset, TTL y rollback; layout interno no altera equivalencia |

## FASE 4 — Resultado Final

### Nivel de Completitud

[INFERIDO] **Nivel B — Implementable con Supuestos Declarados (S01–S06)** para las correcciones especificadas; las auditorías de diseño cierran trazabilidad y decisiones, y los gates de implementación permanecen pendientes hasta ejecutar pruebas.

[DESCONOCIDO] Cuotas/corpus/recuperación productiva aún impiden autorizar activación v2 o certificar ganancia neta en producción; esa activación conserva sus propios prerrequisitos y no queda aprobada por este documento.

### Definición de Completitud

- [INFERIDO] Diseño completo: mismas decisiones/salidas verificables, todas las referencias resueltas, sin consultas de diseño adicionales; revisión técnica independiente mediante E/R/F/T/A.
- [INFERIDO] Implementación completa: todos R01–R09 y A01–A12 aceptados, archivos/tests/docs/env sincronizados, rollback ensayado y artifacts fechados; saltar M05/M02 mantiene los ajustes respectivos sin aceptación operativa.
- [INFERIDO] Release completo: además, cumplir controles de despliegue/autorización existentes; no activar v2 sin completar su SDD de homologación y evidencia externa.
- [CONFIRMADO] Esta entrega crea documentación de implementación; no modifica el funcionamiento actual de la aplicación ni demuestra mejoras de rendimiento futuras.

**Matriz de impacto documental de esta entrega**

| Archivo | ¿Requirió Cambio? | Razón | Estado |
| --- | --- | --- | --- |
| [CONFIRMADO] plans/sdd-correccion-fallos-y-rendimiento-pipeline.md | Sí | Especificación solicitada | Creado |
| [CONFIRMADO] README.md, plans/changelog.md, website/sidebars.ts | Sí | Enlace, registro de propuesta y navegación | Hecho |
| [CONFIRMADO] plans/api-endpoints.md, architecture.md, database-schema.md, docker-operations.md, deployment-and-ci.md, testing-strategy.md, AGENTS.md | No en esta entrega | No se implementaron cambios de runtime/modelo/env; F26/F27 fijan su actualización futura | N/A |

**Matriz de impacto en skills de esta entrega**

| Skill | ¿Requirió Cambio? | Razón | Estado |
| --- | --- | --- | --- |
| [CONFIRMADO] audfact-api-rest, audfact-audit-gemini, audfact-sqlsrv-models, audfact-runtime-docker | No en esta entrega | Contratos implementados siguen vigentes; F28 fija sincronización posterior a implementación | N/A |
| [CONFIRMADO] audfact-project-overview, CATALOG.md, catalog.json | No en esta entrega | Plan nuevo cubierto por planes existentes; no se creó módulo/skill ejecutable | N/A |

[CONFIRMADO] La validación global del registro de skills reporta discrepancias preexistentes de catálogo/baseline y metadatos de docker-build-strategies, docker-compose-patterns y docker-destructive-guardrails; no se atribuye un PASS global a esta entrega ni se modifica ese trabajo ajeno.
