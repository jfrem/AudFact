# Diagnóstico verificable de rendimiento y fallos — AudFact

Fecha: 2026-10-09. Entorno inspeccionado: backend Docker local en `http://localhost:8080`. Repositorio: `C:\Users\USER\Desktop\AudFact`, rama `main`, HEAD `653378b`, con cambios locales previos.

## Resultado

**La falla principal confirmada está en la clasificación del resultado de `pdftoppm`: un PDF sin páginas devuelve código 99, pero el clasificador solo considera los códigos 0 y 1. El fallback pierde el rechazo documental que Ghostscript ya había identificado y lanza una excepción técnica.** Esto provoca reintentos innecesarios y termina auditorías en DLQ. Se reprodujo con un PDF sintético, sin documentos de pacientes ni llamadas a Gemini.

En los logs congelados se encontraron **189 fallos de rasterización sobre 73 eventos/auditorías diferentes**. Los 189 fallbacks registran `EMPTY_PDF_NO_PAGES`. Al cierre de esa ventana, **58 de 67 eventos DLQ corresponden a este defecto: 86.57%**. Los otros nueve corresponden a respuestas de Gemini sin candidato. Fuente: `logs/diagnostic-2026-10-09/report-evidence.json:25`, `:46`, `:49`.

Hay causas independientes de la lentitud: FIFO entre jobs, apertura repetida de conexiones SQL sin pooling ODBC configurado, tiempo remoto de Gemini y recuperación de documentos cuando se llena la caché. La persistencia SQL final no presenta el mayor atraso en los cortes observados.

**El diff conserva esta falla preexistente.** Mejora varios contratos de recuperación y añade ejecución justa v2, pero el runtime observado ejecuta **v1**. La mejora neta de velocidad de v2 requiere una comparación equivalente; no está demostrada por este diagnóstico.

## Alcance, método y tiempos

Se inspeccionaron el diff, changelog, controladores, consumidores, rasterización, extracción, conexiones SQL, caché y scheduler v2. Se consultaron Redis y los endpoints existentes en modo lectura. No se activó v2, no se crearon auditorías reales, no se escribieron datos de negocio y no se inspeccionó producción LAN.

| Evidencia | Ventana / instante UTC | Equivalencia en Bogotá |
| --- | --- | --- |
| Logs congelados: 53,484 registros seleccionados de 125 archivos | 04:40:03–05:11:53 | 23:40:03 del 08-oct a 00:11:53 del 09-oct |
| Snapshot Redis | 05:13:34 | 00:13:34 |
| Reproducción PDF con motores reales | 05:14:44 | 00:14:44 |
| Metadatos de 2,751 respuestas IA conservadas | 04:41:50–05:15:28 | 23:41:50 del 08-oct a 00:15:28 del 09-oct |
| Prueba SQL: diez aperturas, treinta `SELECT 1` | 05:21:23–05:21:38 | 00:21:23–00:21:38 |
| Diez GET finales: cinco por endpoint | 05:24:55–05:25:00 | 00:24:55–00:25:00 |

Los cortes tienen tiempos distintos; no se mezclan sus conteos como si fueran una transacción única. Los timestamps de logs se normalizaron a UTC para calcular la ventana, incluyendo registros con offset `+02:00`.

PHP real: **8.2.33**. Se compararon hashes SHA256 del workspace y del contenedor en diez archivos críticos: todos coinciden, incluyendo clasificador PDF, rasterizador, extractor, consumer, orquestador, gateway, caché, runner y executor SQL. Evidencia: `logs/diagnostic-2026-10-09/container-source-checksums.json:1` y `final-summary.json:222`. Esto acredita código local empaquetado; no acredita despliegue productivo ni activación de v2.

## Estado de la aplicación

Última respuesta de `/audit/jobs`, dentro del corte HTTP final:

| Job | Total | Cerradas sin fallo técnico (`done`) | Fallidas | Pendientes | Estado | Duración media informada |
| --- | ---: | ---: | ---: | ---: | --- | ---: |
| SANITAS | 2,629 | 600 | 67 | 1,962 | processing | 575.023 s |
| POSITIVA | 165 | 150 | 0 | 15 | processing | 890.145 s |
| NUEVA EPS | 3,000 | 0 | 0 | 3,000 | pending | 0; todavía sin cierres |

`done` significa cierre técnico de la auditoría, no necesariamente aprobación documental. Identidades de jobs: SANITAS `36cb605b-1dea-492d-adb4-f7ad83be929d`; POSITIVA `b59828ea-6cd2-49f0-8e28-3fe3107a5bdd`; NUEVA EPS `551fad3f-088b-433e-bd90-e59af126bd4b`. Fuente: `logs/diagnostic-2026-10-09/final-summary.json:21`.

Los diez GET devolvieron HTTP 200. `/audit/jobs` tuvo p50 **962.666 ms**, rango 955.853–1073.995 ms; `/metrics/async`, p50 **23.382 ms**, rango 22.995–88.730 ms. Son cinco muestras por endpoint bajo carga existente, no un benchmark de capacidad. Fuente: `final-summary.json:8` y `:14`.

En el corte final, métricas reporta **backlogDepth=11,790**, **terminalFailures=67**, **deadLetterDepth=67**, **executionVersion=1**. Son contadores de mensajes/etapas; el backlog no equivale a 11,790 auditorías diferentes. Fuente: `final-summary.json:168` y `:198`.

La tasa mostrada para SANITAS, `0.2524/s`, incluye `done + failed`: `BatchJobStore.php:185`. Con 600 cierres sin fallo y 2,642.719 segundos de pared, su tasa acumulada de cierres sin fallo es aproximadamente **13.62/min**, frente a **15.14/min** contando también los fallidos. Una medición anterior de 121.709 segundos observó 38 cierres SANITAS, **18.73/min**; esa tasa de intervalo no es la misma medida que el promedio desde creación del job. Fuente anterior: `logs/performance-review-2026-10-09.md:3`.

## Hallazgos y prioridad

La severidad sigue la matriz del repositorio: **Media** para los defectos operativos locales demostrados y deuda sin evidencia de incidente productivo; prioridad **P1** cuando afectan cierres correctos o espera de lotes. No se asigna puntaje global de calidad a partir de una revisión focalizada.

| ID | Severidad | Prioridad | Hallazgo | Confianza | Responsable sugerido / momento |
| --- | --- | --- | --- | --- | --- |
| QUAL-001 | Media | P1 | PDF sin páginas se convierte en error técnico y DLQ | Alta: código + logs + reproducción | Backend/pipeline; primero |
| ARCH-001 | Media | P1 | FIFO concentra trabajo de un job antes de admitir el siguiente | Alta: cursor Redis + orden real + código | Backend/pipeline; antes de aceptar equidad |
| ARCH-002 | Media | P1 | Cada operación paga apertura SQL y falta configuración de pooling Linux | Alta para costo/configuración; beneficio futuro por medir | Infraestructura/backend; después del defecto PDF |
| QUAL-002 | Media | P1 | Reintentos no distinguen bloqueo de Gemini y agotamiento de tokens | Alta para respuestas observadas | Backend/IA; calibración antes de release |
| QUAL-003 | Media | P1 | El test del fallback simula código 0 y omite el código real 99 | Alta: test + motor real | QA/backend; junto con QUAL-001 |
| ARCH-003 | Media | P2 | Caché acotada evita pérdida, pero su saturación agrega redescargas | Alta para counters y recuperación | Backend/pipeline; después de cerrar P1 |

### QUAL-001: causa raíz del fallo PDF

Cadena comprobada:

1. Ghostscript reconoce `PDF document has no pages` y produce `RasterizationFailureException(EMPTY_PDF_NO_PAGES)`.
2. `DocumentPdfRasterizer.php:219` captura esa excepción mediante `catch (RuntimeException $e)` y ejecuta fallback en `:246`.
3. `pdftoppm` devuelve **99** y `Invalid page count 0` / `first page (1) ... last page (0)`.
4. `RasterizationProcessResult.php:16` retorna `null` antes de evaluar la expresión de páginas vacías de `:23`.
5. `DocumentPdfRasterizer.php:261` lanza `RuntimeException`, reemplazando el diagnóstico documental.
6. El extractor maneja rechazo tipado en `DocumentExtractionWorker.php:278`; la excepción genérica sale por `:321` y llega al consumer.
7. `AuditEventConsumer.php:597` incrementa intentos y `:619` finaliza en DLQ al alcanzar tres. El error PDF genérico no pertenece a las excepciones no reintentables de `:614`.

```mermaid
flowchart LR
  A[PDF sin páginas] --> B[Ghostscript: rechazo tipado]
  B --> C[Fallback pdftoppm: exit 99]
  C --> D[Clasificador descarta 99]
  D --> E[RuntimeException]
  E --> F[Reintentos técnicos]
  F --> G[Auditoría fallida y DLQ]
```

Evidencia operativa de un mismo evento, `caafca7a-e890-4a23-84f9-e2a55dfa1f42`, documento `6fcba454-1566-426d-ab31-b26e14b54a8d`:

| Momento UTC | Evidencia original | Resultado |
| --- | --- | --- |
| 04:42:29 | `logs/app-9965386784a0-2026-10-09.log:15` | Fallback; causa primaria `EMPTY_PDF_NO_PAGES` |
| 04:42:29 | mismo archivo, `:16` | Intento 1, error genérico en rasterizador `:261` |
| 04:52:31 | mismo archivo, `:187` | Intento 2; mismo evento, stream ID y error |
| 05:02:34 | `logs/app-91afce231d85-2026-10-09.log:385` | Intento 3; mismo error |
| 05:02:34 | mismo archivo, `:387` | Enviado a DLQ |

La cadena tarda **20 minutos y 5 segundos** entre primer fallo y DLQ. El consumidor recupera pendientes con `XAUTOCLAIM` y `pendingReclaimIdleMs`, en `AuditEventConsumer.php:301`. Configuración real: idle **600,000 ms**, revisión cada **30,000 ms**, tres intentos (`report-evidence.json:8`). Esto describe v1 observado; no se extrapola el mismo calendario a v2.

Los originales pueden rotar. La cadena está congelada y sanitizada en `report-evidence.json:63` y en `log-evidence.jsonl:2655`, `:22534`, `:39130`, `:39132`.

Reproducción real: `empty-pdf-reproduction.json:7` registra Ghostscript exit 0 y rechazo tipado; `:12` registra Poppler exit 99 y clasificación nula; `:17` registra la excepción real del rasterizador; `:22`, `typed_document_rejection=false`. Se construyó un PDF válido con catálogo, árbol de páginas de tamaño cero y xref; SHA256 `e33d2c29b10fe10764f780b556257d37e445a7b6cdfe99e4d10db020d76b913c`.

Comprobación del código y reproducción, desde la raíz:

```powershell
rg -n 'in_array|NO_PAGES|contentFailure' app/Services/Audit/Pipeline/RasterizationProcessResult.php
rg -n 'catch|executeAndValidateOutput|sin salida utilizable' app/Services/Audit/Pipeline/DocumentPdfRasterizer.php
wsl docker compose exec -T --index 1 php php logs/diagnostic-2026-10-09/reproduce_empty_pdf.php
git diff -- app/Services/Audit/Pipeline/RasterizationProcessResult.php app/Services/Audit/Pipeline/DocumentPdfRasterizer.php
```

El último comando no muestra cambios en esos dos archivos: el defecto es preexistente. Tanto v1 como v2 reutilizan la extracción/rasterización: `DocumentExtractionWorker.php:158` y `:278`. Incorporar el scheduler v2 no corrige esta condición.

### ARCH-001: NUEVA EPS espera por orden FIFO

`AuditEventConsumer.php:171` entra al runner justo solamente con versión 2 y etapa definida. En v1, `:229` usa `XREADGROUP`, count 1, y procesa el mensaje antes del siguiente. No hay reparto por job en ese recorrido.

Redis mostró esta secuencia efectiva en `audit.inbox.batch`:

| Racha | Job | Entradas consecutivas |
| --- | --- | ---: |
| 1 | SANITAS | 501: 500 raíces y un `batch_created` |
| 2 | POSITIVA | 166: 165 raíces y un `batch_created` |
| 3 | SANITAS | 2,129 raíces |
| 4 | NUEVA EPS | 3,001: 3,000 raíces y un `batch_created` |

En el snapshot 05:13:34, el cursor del grupo `orchestrator` era **1791520968590-0**, con tres consumidores, tres pendientes en proceso y **lag 4,570**. Después del cursor quedaban **1,569 entradas SANITAS y las 3,001 NUEVA EPS**. NUEVA EPS no tenía eventos documentales; sus auditorías muestreadas seguían `pending`, `docs_total=0`. Fuente: `report-evidence.json:315`, `:341`, `:368`; estado muestreado completo en `analysis-summary.json`.

Esto identifica dónde espera NUEVA EPS: **todavía no llega a orquestación**, porque sus raíces están detrás de SANITAS. No hay evidencia para atribuirle un error SQL específico ni un consumidor detenido. En el mismo corte, los doce extractores tenían actividad reciente y el grupo extractor acumulaba lag 5,532; la cola documental también tiene orden de llegada.

```powershell
rg -n 'version === 2|xReadGroupMulti|dispatchMessage' app/Services/Audit/Pipeline/AuditEventConsumer.php
Get-Content logs/diagnostic-2026-10-09/report-evidence.json -Encoding UTF8 | Select-Object -Skip 314 -First 65
```

### ARCH-002: apertura repetida de conexiones SQL

La preparación hace cuatro lecturas secuenciales de dominio: FDV, configuración, catálogo y adjuntos (`DocumentAuditOrchestrator.php:322`, `:335`, `:336`, `:341`). Configuración abre dos operaciones: cabecera en `AuditConfigModel.php:30`/`:131` y campos en `:52`. En total, cinco aperturas SQL por preparación exitosa en esta ruta.

`Model.php:23` delega las lecturas al executor. `SqlServerConnectionExecutor.php:27` utiliza `Database::openConnection`; `:46` abre una conexión por intento y `:111` elimina la referencia al terminar. `Database.php:112` construye un PDO nuevo. El DSN incluye `ConnectionPooling=1` en `:108`, pero eso no habilita pooling en Linux por sí solo.

La configuración real `/etc/odbcinst.ini` solo contiene el driver y `UsageCount`; **no tiene `[ODBC] Pooling=Yes` ni `CPTimeout` positivo**. Driver 18.7, unixODBC 2.3.11. Evidencia congelada: `logs/diagnostic-2026-10-09/odbc-runtime.txt:1`. `docker/Dockerfile:23` instala unixODBC/driver, sin añadir dicha configuración. Microsoft documenta que en Linux la habilitación depende de `odbcinst.ini`, y no del atributo `ConnectionPooling` del DSN. [Documentación oficial de pooling SQL Server](https://learn.microsoft.com/en-us/sql/connect/php/connection-pooling-microsoft-drivers-for-php-for-sql-server).

La medición usó el constructor actual, cinco aperturas por conexión y tres consultas preparadas `SELECT 1` por apertura:

| Conexión | Apertura p50 | Rango apertura | `SELECT 1` p50 | Rango consulta |
| --- | ---: | ---: | ---: | ---: |
| default | 662.088 ms | 646.837–666.998 ms | 257.752 ms | 251.183–261.047 ms |
| db2 | 656.457 ms | 642.964–664.760 ms | 254.238 ms | 250.581–259.611 ms |

Fuente: `sql-probe.json:1` y `final-summary.json:200`. El costo también existe para la consulta trivial; no se justifica culpar exclusivamente a índices o complejidad del SELECT de FDV. El diagnóstico no separa aún red, protocolo y tiempo interno del servidor durante ese `SELECT 1`.

Los logs de preparación corroboran el patrón: FDV p50 **944 ms**; configuración **1,839 ms**; catálogo **924 ms**; adjuntos **925 ms**, con aproximadamente 1,158 muestras por método. La suma de promedios es **4.637 s** por preparación; no es un percentil calculado del total. Fuente: `report-evidence.json:163`.

`/audit/jobs` además consulta todos los clientes en cada GET (`AuditController.php:496`), coherente con su p50 cercano a un segundo y muy superior al endpoint de métricas Redis. No se instrumentó el tiempo exacto de esa consulta dentro del endpoint.

```powershell
rg -n 'openConnection|connector|unset' core/SqlServerConnectionExecutor.php
rg -n 'ConnectionPooling|new PDO' core/Database.php
wsl docker compose exec -T --index 1 php php logs/diagnostic-2026-10-09/probe_sql.php
Get-Content logs/diagnostic-2026-10-09/odbc-runtime.txt -Encoding UTF8
```

La corrección debe conservar PDO fresco por operación y los límites de replay. Evaluar pooling ODBC efectivo y configuración/catálogo compartidos con invalidación explícita. Aún no se midió cuánto mejora cada opción ni se validó su comportamiento con desconexiones y BLOBs.

### QUAL-002: respuestas Gemini bloqueadas y presupuesto consumido por thinking

En los logs congelados: **33 errores `MISSING_CANDIDATE` sobre 13 eventos** y **13 errores `MAX_TOKENS` sobre diez eventos**. Nueve eventos sin candidato ya terminaron en DLQ. Estos conteos incluyen reintentos y no deben sumarse como auditorías únicas sin deduplicar. Fuente: `report-evidence.json:31`, `:40`, `:56`.

Los metadatos conservados permiten identificar la respuesta concreta:

- Sin candidato: `logs/responseIA/T48260801686_success_20261009_044408954001_a4c7da0d.json:336` contiene `promptFeedback.blockReason=OTHER`. Registro sanitizado: `response-metadata-compact.jsonl:225`. Los 33 archivos sin candidato encontrados en la ventana IA tienen `OTHER`. No hay evidencia suficiente para precisar el motivo interno de ese bloqueo.
- Truncamiento de extracción: `logs/responseIA/T48260801686_success_20261009_044421527168_f4b0c729.json:50` fija `maxOutputTokens=4096`; `:196` registra `MAX_TOKENS`; `:214`, **3,933 tokens de thinking**; `:202`, **149 tokens de candidato**. Registro sanitizado: `response-metadata-compact.jsonl:248`. El límite está ocupado principalmente por thinking y la respuesta queda truncada.
- En las 2,751 respuestas conservadas se registran 19 `MAX_TOKENS`: 15 de extracción y cuatro de semántica. Esa ventana termina después de la ventana de logs, por lo que no contradice los trece fallos de extracción registrados en los logs anteriores.

El límite de extracción se establece en `DocumentExtractionWorker.php:417`. `GeminiResponseParser.php:66` convierte ausencia de candidato en un error genérico; `:77` rechaza toda terminación distinta de `STOP`. Rechazar una respuesta truncada es correcto; falta decidir de forma específica qué hacer con bloqueo y presupuesto antes de repetir la misma solicitud como fallo genérico.

La documentación oficial confirma que `max_output_tokens` incluye thinking y salida, y que el corte puede truncar/vaciar la respuesta; propone reducir el nivel de thinking cuando se busca bajar costo/latencia. Debe calibrarse con corpus etiquetado y un nivel soportado por el modelo, conservando el schema y la validación. [Guía oficial Gemini para generateContent](https://ai.google.dev/gemini-api/docs/generate-content/thinking#token-limits-and-max_output_tokens).

Configuración observada: modelo solicitado `gemini-3.7-flash`; las 2,751 respuestas reportan `modelVersion=gemini-3.8-flash`; sin `thinkingConfig` explícito en las muestras. Se registra esta diferencia sin atribuirle por sí sola la falla ni afirmar un cambio deliberado del deployment. Debe conservarse el modelo servido en cualquier comparación.

```powershell
rg -n 'maxOutputTokens|MISSING_CANDIDATE|finishReason' app/Services/Audit/Pipeline/DocumentExtractionWorker.php app/Services/Audit/Pipeline/GeminiResponseParser.php
Get-Content logs/diagnostic-2026-10-09/response-metadata-compact.jsonl -Encoding UTF8 | Select-Object -Skip 224 -First 1
Get-Content logs/diagnostic-2026-10-09/response-metadata-compact.jsonl -Encoding UTF8 | Select-Object -Skip 247 -First 1
```

### QUAL-003: el test verde omite el código real del fallback

`DocumentPdfRasterizerTest.php:51` pretende validar el rechazo tipado después de fallback, pero el mock de `:69`/`:70` devuelve **código 0 para ambos motores**, incluso cuando simula el mensaje de Poppler. El test verifica el mensaje bajo un código permitido y no reproduce el exit 99 real.

La suite focalizada ejecutada pasa: **14 tests, 41 aserciones, un skip**. La reproducción con motores reales falla el contrato de rechazo tipado. Esto explica por qué una suite verde no detectó QUAL-001. Los archivos de este test y del clasificador/rasterizador no tienen cambios en el diff actual.

```powershell
rg -n 'testRecognizedNoPages|new RasterizationProcessResult|Wrong page range' tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php
php vendor/bin/phpunit --no-coverage --do-not-cache-result tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php
```

### ARCH-003: límite de caché y redescarga

La caché final está cerca del límite de **256 MiB**, con **1,024 entradas de 1,024**. Counters: **2,143 bypasses**, **541 misses**, **539 recuperaciones**, cero fallos de recuperación y cero hash mismatches. Las diferencias entre misses y recuperaciones pueden incluir trabajo en curso; no se etiquetan como pérdida de documentos. Fuente: `final-summary.json:179`.

`AuditDocumentCache.php:29`/`:30` define los límites y `:56` registra bypass de admisión. Ante miss, `AuditDocumentInput.php:30` vuelve a descargar desde el origen y verifica el hash antes de continuar. Se conserva la corrección documental, pero existe costo de I/O repetido. En los logs: 243 recuperaciones, p50 **5.342 s**, p95 **14.413 s**. Fuente: `report-evidence.json:303`.

La diferencia de capacidad entre descargadores y extractores permite llenar el buffer antes de consumirlo. Se recomienda medir admisión ligada al avance de extracción y costo de caché por bytes, antes de aumentar memoria. Un límite acotado y recuperación son mejoras de confiabilidad; no garantizan por sí solos mejor throughput.

## Dónde se consume el tiempo

Percentiles calculados por orden estadístico sobre observaciones de los logs; excluyen operaciones sin log de éxito y no son un benchmark controlado.

| Operación | Muestras | p50 | p95 | Interpretación |
| --- | ---: | ---: | ---: | --- |
| Extracción completa por documento | 1,803 | 9.949 s | 20.418 s | Incluye trabajo local, recuperación y Gemini |
| Gemini dentro de extracción | 1,803 | 9.319 s | 16.497 s | Mayor parte del tiempo medio del extractor |
| Descarga BLOB | 3,413 | 3.502 s | 10.012 s | I/O significativo antes de extracción o en recuperación |
| Recuperación por cache miss | 243 | 5.342 s | 14.413 s | Se superpone con descarga; no sumar como etapas independientes |
| Persistencia SQL de cierre | 506 | 2.912 s | 3.160 s | Operación final mucho más corta que el tiempo total |
| Duración completa de auditoría al persistir | 506 | 520.714 s | 874.150 s | Tiempo total con espera en etapas; no tiempo de SQL |

Fuente: `report-evidence.json:213`, `:243`, `:273`, `:283`, `:293`, `:303`. En el corte final, scheduler de persistencia tiene un activo y cero pendientes internos (`final-summary.json:163`), mientras existe lag en orquestación y extracción. **Los datos no sostienen que aumentar slots de persistencia resuelva el atraso actual.**

El promedio de extracción es 11.111 s por documento, de los que 9.905 s corresponden a Gemini: aproximadamente **89% del tiempo medio informado**. Como referencia aritmética, doce extractores continuos a ese promedio atenderían unas 64.8 extracciones/minuto; no es capacidad garantizada y no equivale a auditorías/minuto, pues una auditoría puede tener varios documentos, rechazos, semántica y reintentos.

`local_cpu_duration_ms` se calcula restando duración Gemini al tiempo de pared en `DocumentExtractionWorker.php:530`; **no mide CPU realmente** y puede incluir I/O/Redis/recuperación. El snapshot Docker muestra actividad desigual, con extractores entre 0% y 77.54% de CPU y 23.52–63.42 MiB de memoria. Es una observación instantánea, insuficiente para certificar ausencia de saturación sostenida. Evidencia: `docker-stats.jsonl:1` y `final-summary.json`.

## Evaluación honesta del diff

| Aspecto | Evidencia favorable | Límite / conclusión |
| --- | --- | --- |
| Rendimiento actual | Caché acotada recuperable y reutilización de resultados extraídos; slots de persistencia observables | Persisten QUAL-001, SQL repetido, misses y FIFO v1. No hay comparativa antes/después que pruebe mejora neta |
| Equidad | v2 incorpora round robin por scope/job y fases separadas | v2 está apagado en este runtime; la espera FIFO de NUEVA EPS permanece |
| Escalabilidad | Presupuesto Gemini compartido, leases/fences, outbox y límites de admisión | `FairAuditTaskQueue.php:212` permite un activo por scope/etapa/carril. Con uno o pocos jobs batch puede reducir paralelismo respecto de doce extractores v1; requiere medición |
| Mantenibilidad | Responsabilidades separadas, contratos y pruebas de recuperación | Coexisten v1/v2 y transiciones PHP/Lua. El defecto común del rasterizador y su test incompleto permanecen; retirar v1 según E01 es parte del mantenimiento |

`AuditTask.php:43` define el scope por job; `FairAuditTaskQueue.php:212` bloquea claim cuando ese scope ya tiene activo. La equidad mejora como propiedad del diseño; **no equivale automáticamente a terminar antes el mismo volumen**. Los ensayos Redis y mocks acreditan contratos, no la latencia final con SQL/Gemini reales. Ver medición/revisión inicial en `logs/performance-review-2026-10-09.md` y condiciones M06/E01 en la SDD de homologación.

## Corrección propuesta y criterios verificables

1. **Corregir QUAL-001 junto con QUAL-003.** Reconocer diagnósticos documentales positivos de Poppler exit 99 conservando la prioridad de errores operativos. Preservar la evidencia tipada del motor primario cuando el fallback no aporta salida válida ni evidencia que la invalide. No convertir todos los exit 99 en PDF inválido.
2. Regresiones mínimas: PDF válido de cero páginas con Ghostscript→Poppler 99; corrupción con diagnóstico confirmado; permiso/espacio/timeout siguen siendo técnicos; PDF válido produce imágenes; fallback válido prevalece. En extractor, verificar `document_rejected`, contador una vez y ausencia de retry/DLQ para cero páginas.
3. Calibrar Gemini: distinguir `promptFeedback.blockReason`, registrar categoría sin documentos/prompt, y ajustar thinking/presupuesto de salida con corpus etiquetado. Mantener rechazo de JSON truncado y validación estricta; nunca aceptar truncamiento ni tratar un bloqueo ambiguo como contenido inválido confirmado.
4. Verificar pooling ODBC en imagen Linux y repetir el probe de apertura/consulta con desconexión y BLOB. Evaluar reutilización de configuración/catálogo por versión para evitar cinco aperturas por auditoría, preservando el contrato SQL.
5. Medir equidad/throughput con uno, tres y diez jobs, misma mezcla documental, caché fría/caliente, cuotas, réplicas y modelo servido. Validar espera p50/p95 por job, cierres sin fallo/minuto, generaciones/tokens, retries y consumo. La activación v2 requiere además las compuertas existentes de cuota, durabilidad y cutover; no se ejecutó en este diagnóstico.
6. Después de corregir y verificar, reprocesar DLQ mediante el contrato administrativo idempotente existente; no repetir masivamente los mismos documentos mientras persista la causa.

Orden recomendado: defecto PDF/test → estrategia Gemini → costo SQL → capacidad/equidad/caché. Responsables: backend/pipeline y QA para defectos; infraestructura/backend para pooling y pruebas de capacidad. El criterio de cierre no es solo suite verde: exige reproducción real correcta y reducción verificable de fallos/espera bajo carga equivalente.

## Evidencia faltante y límites

- No se compararon tiempos finales v1/v2 con el mismo corpus, cuotas y modelo servido. No se atribuye una regresión medida a cap1 ni una mejora neta al batching.
- No se cambió configuración ODBC ni se ejecutó benchmark después de pooling: su beneficio exacto está pendiente.
- `OTHER` no identifica de forma verificable el motivo interno del proveedor. No se diagnostica como un tipo clínico concreto.
- No se inspeccionaron planes de ejecución, tiempos del servidor SQL ni RTT de red por separado. Se midió el costo total de apertura y consulta trivial.
- No se inspeccionó producción LAN ni se ejecutó la suite completa/integración destructiva sobre el Redis de trabajo. Las pruebas de reproducción fueron sintéticas y la suite ejecutada fue focalizada.
- Los artefactos de evidencia están bajo `logs/`, ignorado por Git. Deben conservarse junto al reporte si este se comparte o versiona; sus hashes están en `manifest.json:1`. Los documentos IA originales no se copian al reporte.

## Verificación y entrega documental

Entregados este reporte, entrada en `plans/changelog.md` y scripts/metadatos sanitizados en `logs/diagnostic-2026-10-09/`. Corregido drift documental en dos skills: overview distinguía todavía propuesta e implementación v2; SQL no aclaraba la habilitación de pooling en Linux. No se modificaron código de aplicación, variables de entorno, secretos ni despliegue durante este diagnóstico.

| Matriz documental | Cambio | Razón / estado |
| --- | --- | --- |
| `plans/diagnostico-performance-2026-10-09.md` | Sí | Reporte con causas, evidencia, comandos y criterios de corrección |
| `plans/changelog.md` | Sí | Registro del diagnóstico; conserva entradas anteriores |
| `plans/api-endpoints.md`, `plans/architecture.md`, `AGENTS.md`, `README.md` | No | Sin cambio de contratos, arquitectura implementada o variables; N/A |

| Matriz de skills | Cambio | Razón / estado |
| --- | --- | --- |
| `audfact-project-overview` | Sí | Distinguir v2 implementado, protocolo activo y despliegue |
| `audfact-sqlsrv-models` | Sí | Documentar configuración real de pooling Linux y probe |
| `audfact-audit-gemini`, `audfact-runtime-docker`, `CATALOG.md` | No | Sin implementación nueva ni cambio de mapeo; N/A |

El validador de skills se ejecutó y devuelve FAIL por inconsistencias preexistentes de catálogo/baseline y metadatos de tres skills ajenas: `docker-build-strategies`, `docker-compose-patterns`, `docker-destructive-guardrails`. No informa errores específicos en las dos skills editadas. Registro: `logs/diagnostic-2026-10-09/skills-validation.txt:1`. No se presenta como PASS global.

Verificación de entrega: comprobadas automáticamente **27 referencias exactas de archivo/línea**, consistencia de conteos, hashes comparados y JSON estructurado del reporte (`evidence-verification.json:1`). Lint de los tres scripts PHP de diagnóstico sin errores. `git diff --check` de la documentación/skills editadas sin errores de espacios. **Build Docusaurus PASS**, con generación de archivos estáticos; el reporte canónico permanece en `plans/` y no se publicó el portal. Las advertencias del chequeo de actualización de Docusaurus no impidieron el build.

## Salida estructurada

```json
{
  "environment": "local_docker",
  "head": "653378b",
  "execution_version": 1,
  "global_score": null,
  "confidence": "High",
  "root_cause": "PDF_NO_PAGES_POPPLER_EXIT_99_DISCARDED",
  "root_cause_preexisting_in_diff": true,
  "frozen_pdf_failure_attempts": 189,
  "frozen_pdf_failure_events": 73,
  "frozen_dlq_total": 67,
  "frozen_dlq_pdf": 58,
  "frozen_dlq_missing_candidate": 9,
  "fix_implemented": false,
  "v2_performance_improvement_proven": false,
  "findings": [
    {"id": "QUAL-001", "priority": "P1", "evidence": "app/Services/Audit/Pipeline/RasterizationProcessResult.php:16"},
    {"id": "ARCH-001", "priority": "P1", "evidence": "app/Services/Audit/Pipeline/AuditEventConsumer.php:229"},
    {"id": "ARCH-002", "priority": "P1", "evidence": "core/SqlServerConnectionExecutor.php:46"},
    {"id": "QUAL-002", "priority": "P1", "evidence": "app/Services/Audit/Pipeline/GeminiResponseParser.php:66"},
    {"id": "QUAL-003", "priority": "P1", "evidence": "tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php:70"},
    {"id": "ARCH-003", "priority": "P2", "evidence": "app/Services/Audit/Pipeline/AuditDocumentInput.php:30"}
  ]
}
```
