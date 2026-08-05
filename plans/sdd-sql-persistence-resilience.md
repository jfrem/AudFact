# SDD - Resiliencia SQL/PDO y barrera contra falsos rechazos

| Metadato | Valor |
| --- | --- |
| Estado | `[CONFIRMADO]` Implementada y validada en la rama `staging` con dos rondas de tres jobs simultaneos; fault injection ODBC y comparacion contra baseline permanecen como gates previos a produccion. |
| Fecha | `[CONFIRMADO]` 2026-07-30. |
| Repositorio | `[CONFIRMADO]` `C:\Users\USER\Desktop\AudFact`. |
| Baseline | `[CONFIRMADO]` Estado local inspeccionado el 2026-07-30, incluido el refactor concurrente de persistencia aun no consolidado en Git. |
| Implementacion | `[CONFIRMADO]` `core/SqlServerConnectionExecutor.php`, modelos SQL, pipeline de adjuntos/policy/persistencia y pruebas asociadas. |
| Politicas | `[CONFIRMADO]` `write-sdd-spec`, `clean-rebuild-policy`, `audfact-project-overview`, `audfact-sqlsrv-models`, `audfact-audit-gemini` y `audfact-docs-sync`. |
| Alcance normativo | `[CONFIRMADO]` Prevencion de falsos rechazos y recuperacion acotada de conexiones SQL/PDO en el pipeline. |
| Saneamiento historico | `[CONFIRMADO]` Se especifica por separado en `plans/sdd-sql-incident-remediation.md`; no forma parte del despliegue preventivo. |

## FASE 0 - Descubrimiento Obligatorio

### Inventario de Informacion

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Necesidad de negocio de la persistencia dual | `[CONFIRMADO]` | El usuario establecio que `AudDispEst` conserva la auditoria de la factura y `AdjuntosDispensacion` conserva la decision sobre cada soporte; `AuditResultPersistenceModel.php:34-74,115-386` las ejecuta en una transaccion. |
| Falso rechazo originado por una falla tecnica | `[CONFIRMADO]` | `AttachmentDownloadWorker.php:83-102,164-196` captura cualquier `Throwable`, escribe `DOWNLOAD_ERROR`, publica `document_rejected` y retorna. |
| ACK posterior al falso rechazo | `[CONFIRMADO]` | `AuditEventConsumer.php:207-225` hace `XACK` cuando `handle()` retorna sin excepcion. |
| Conversion a hallazgo de negocio | `[CONFIRMADO]` | `RulesEvaluationWorker.php:344-379` convierte cualquier `rejection_reason` en severidad alta, `INTEGRIDAD_DOCUMENTO`, `RECHAZADO` y `approved=false`. |
| Reutilizacion de PDO `db2` | `[CONFIRMADO]` | `Model.php:10-39` conserva `$readDb`; `AttachmentsModel.php:113-175` usa esa referencia para metadata y BLOB. |
| Otros modelos `db2` de workers largos | `[CONFIRMADO]` | `AuditDataService.php:20-37` conserva `DispensationModel`, `ClientsModel`, `AuditConfigModel` y `AttachmentsModel`; `DocumentAuditOrchestrator.php:32` conserva el servicio durante la vida del worker. |
| Lectura batch de larga vida | `[CONFIRMADO]` | `BatchRequestedWorker.php:108-118` crea `InvoicesModel` por evento; `AuditBatchOrchestrator.php:65-96` puede reutilizarlo durante varias paginas SQL del mismo evento. |
| Reutilizacion de PDO `default` | `[CONFIRMADO]` | `AuditResultPersistenceModel.php:25-44` conserva `$writeDb` heredado y lo reutiliza en persistencia y timings. |
| Cache PDO estatica | `[CONFIRMADO]` | `core/Database.php:12-94` retorna la misma instancia por fingerprint hasta `closeConnection()`. |
| Espera de mensajes pending | `[CONFIRMADO]` | `AuditEventConsumer.php:35-50,159-192,422-451` deja sin ACK un fallo no terminal; `.env.example:107` define `AUDIT_PENDING_RECLAIM_IDLE_MS=600000`. |
| Timeout de apertura | `[CONFIRMADO]` | `core/Database.php:73-83,211` agrega `LoginTimeout`; el valor por defecto es 30 segundos. |
| Evidencia productiva de corte SQL | `[CONFIRMADO]` | La auditoria externa aportada por el usuario registra `SHUTDOWN`, `08S01`, `TCP Provider 0x68` y `Communication link failure` en el log productivo rotado `app-458ab34617ff-2026-07-30.log`. |
| Causa originaria del `SHUTDOWN` | `[DESCONOCIDO]` | No se dispone de error log de SQL Server, Windows Event Viewer ni metricas del host del motor. |
| Tamano esperado del BLOB | `[CONFIRMADO]` | `AttachmentsModel.php:113-139` obtiene `DATALENGTH(a.AdjDisDoc) AS BlobSize`. |
| Validacion actual de BLOB | `[CONFIRMADO]` | `AttachmentDownloadService.php:99-136` solo exige que `stream_get_contents()` no sea falso ni vacio; no compara bytes contra `BlobSize`. |
| Productor legitimo de rechazo por contenido | `[CONFIRMADO]` | `DocumentExtractionWorker.php:142-193,446-499` valida bytes ya descargados y publica `document_rejected` por integridad o por errores de contenido confirmados de Gemini. |
| Razones de integridad actuales | `[CONFIRMADO]` | `DocumentIntegrityValidator.php:46-91` produce `EMPTY_DOCUMENT`, `DOCUMENT_TOO_SMALL`, `UNSUPPORTED_MIME`, `UNKNOWN_FILE_SIGNATURE`, `MIME_MISMATCH`, `ENCRYPTED_DOCUMENT` y `EMPTY_PDF_NO_PAGES`; `DocumentExtractionWorker.php:860-872` agrega `GEMINI_DECODE_FAILURE`. |
| Idempotencia de persistencia final | `[CONFIRMADO]` | `AuditResultPersistenceModel.php:115-176,204-386` usa upsert por `FacNro` y asignaciones deterministas; no incrementa acumuladores ni inserta una bitacora append-only. |
| Segundo update de timings | `[CONFIRMADO]` | `AuditPersistenceWorker.php:295-326` ejecuta `updateFinalTimings()` dentro de un `try/catch`; su fallo no bloquea ni revierte la transaccion de negocio. |
| Endpoint de descarga publica | `[CONFIRMADO]` | `AttachmentsController.php:82-100` consume `getAttachmentBlobStreamByIdForDisDetNro()` para streaming HTTP; ese contrato no es exclusivo del pipeline. |
| Driver real | `[CONFIRMADO]` | `docker/Dockerfile` instala Microsoft ODBC Driver 18 y `pdo_sqlsrv`/`sqlsrv` 5.11.1 sobre PHP 8.2. |
| Prueba real de `HYT00` por fase | `[DESCONOCIDO]` | No existe una prueba automatizada que diferencie `HYT00` de apertura frente a `HYT00` de statement con ODBC 18. |
| Resultados historicos contaminados | `[INFERIDO]` | El mecanismo de contaminacion esta confirmado, pero el repositorio local ya no conserva el log rotado ni una correlacion completa por `audit_id`; la poblacion exacta requiere descubrimiento SQL. |

### Informacion Faltante Critica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Ninguno para el cambio preventivo | `[CONFIRMADO]` Los contratos, archivos, clasificacion, intentos, guardas y rollback del runtime se definen en este documento. | `[CONFIRMADO]` La implementacion preventiva no requiere una decision adicional. |
| Ventana, inventario y estado previo del incidente | `[DESCONOCIDO]` Son datos necesarios para una reparacion historica segura. | `[CONFIRMADO]` Bloquean `plans/sdd-sql-incident-remediation.md`, pero no bloquean este SDD porque no modifica datos historicos. |

### Informacion Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Comportamiento real de `HYT00` por fase | `[DESCONOCIDO]` El mismo SQLSTATE puede representar apertura o ejecucion segun el driver. | `[CONFIRMADO]` El despliegue productivo queda condicionado a una prueba con ODBC 18; la implementacion usa la fase conocida por el ejecutor. |
| Valores efectivos de `DB_TIMEOUT`, `DB2_TIMEOUT` y pooling | `[DESCONOCIDO]` Produccion puede sobrescribir los defaults del repositorio. | `[INFERIDO]` Cambian el tiempo total de recuperacion y el costo de abrir PDO, no la semantica. |
| p50/p95 de apertura PDO sana | `[DESCONOCIDO]` No existe benchmark por contenedor. | `[CONFIRMADO]` Se exige un gate de rendimiento antes de desplegar. |
| Timeout de query y `commit()` | `[DESCONOCIDO]` El codigo no configura `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` ni un limite explicito para `commit()`. | `[CONFIRMADO]` Este SDD evita la espera de 600 segundos despues de un fallo retornado; no promete interrumpir una llamada nativa que nunca retorna. |

### Informacion Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Causa administrativa del apagado | `[DESCONOCIDO]` Requiere telemetria del servidor SQL. | `[CONFIRMADO]` No cambia la separacion entre error tecnico y decision documental. |
| Version exacta del motor SQL Server | `[DESCONOCIDO]` No esta declarada en el repositorio. | `[CONFIRMADO]` La implementacion depende del contrato PDO/ODBC observado, no de una funcion exclusiva del motor. |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
| A-01 | `[INFERIDO]` El entorno productivo conserva pooling ODBC habilitado. | `.env.example` y `core/Database.php:80-82` usan `DB_POOLING=1` por defecto. | Medio: si esta deshabilitado, el costo de cuatro aperturas logicas aumenta; el benchmark bloquea el despliegue. |
| A-02 | `[INFERIDO]` El despliegue puede realizarse sin eventos `document_rejected` legacy pendientes. | Redis Streams permite inspeccionar `pending` y `lag`; no existe evidencia de una ventana operativa ya reservada. | Medio: el gate exige detener intake y drenar antes del reemplazo. |
| A-03 | `[INFERIDO]` El replay de la transaccion final despues de `08S01` conserva el mismo resultado. | El upsert y los updates son asignaciones deterministas por claves estables. | Bajo: una respuesta incierta de `commit()` se resuelve repitiendo la misma imagen de estado. |

### Clasificacion de Completitud Inicial

`[CONFIRMADO] Nivel B - Implementable con Supuestos Declarados`.

- `[CONFIRMADO]` A-01 se controla con benchmark.
- `[CONFIRMADO]` A-02 se controla con un gate de drenaje.
- `[CONFIRMADO]` A-03 se verifica con replay despues de commit incierto.
- `[CONFIRMADO]` La reparacion historica conserva su propio Nivel C y no se presenta como resuelta por este documento.

## FASE 1 - Especificacion

### 1. Objetivo

- `[CONFIRMADO]` Problema 1: una interrupcion SQL puede dejar inutilizable un PDO retenido por un worker CLI y las operaciones siguientes reutilizan esa referencia.
- `[CONFIRMADO]` Problema 2: el downloader convierte cualquier excepcion, incluida `PDOException`, en `DOWNLOAD_ERROR` y luego en un hallazgo de integridad de negocio.
- `[CONFIRMADO]` Problema 3: un fallo retornado y no terminal deja el evento pending hasta `XAUTOCLAIM`, con un minimo vigente de 600 segundos.
- `[CONFIRMADO]` Problema 4: una lectura BLOB parcial puede superar la validacion actual si entrega al menos un byte.
- `[CONFIRMADO]` Resultado esperado: las operaciones SQL cubiertas usan un PDO nuevo por intento y una politica unica de clasificacion y backoff.
- `[CONFIRMADO]` Resultado esperado: ninguna falla SQL, de red, timeout o transferencia produce `document_rejected`, `INTEGRIDAD_DOCUMENTO` ni una decision `approved=false`.
- `[CONFIRMADO]` Resultado esperado: solo contenido completamente descargado y validado puede producir un rechazo documental.
- `[CONFIRMADO]` Resultado esperado: al agotar la politica SQL, el evento pasa a DLQ, la auditoria termina como fallo tecnico y el turno del job se libera sin esperar otro ciclo de 600 segundos.
- `[CONFIRMADO]` Resultado esperado: las escrituras duales del dominio permanecen atomicas y `updateFinalTimings()` permanece vigente.

### 2. Alcance

#### Incluido

- `[CONFIRMADO]` Extraer la creacion de PDO de `Database::getConnection()` y exponer `Database::openConnection()` sin cache.
- `[CONFIRMADO]` Crear un ejecutor SQL compartido con cuatro intentos totales y pausas exactas de 1000, 5000 y 30000 ms.
- `[CONFIRMADO]` Abrir un PDO nuevo en cada intento para `db2` y `default`.
- `[CONFIRMADO]` Clasificar fallos por fase `connect` u `operation`, SQLSTATE, `errorInfo` y cadena `previous`.
- `[CONFIRMADO]` Reemplazar el almacenamiento de PDO en `Model` por ejecucion mediante callbacks; ningun modelo conserva PDO entre operaciones.
- `[CONFIRMADO]` Migrar los siete modelos que heredan `Model` al nuevo contrato en el mismo cambio atomico.
- `[CONFIRMADO]` Repetir lecturas y la transaccion idempotente de auditoria ante fallos transitorios permitidos.
- `[CONFIRMADO]` Ejecutar rollback best-effort sin ocultar la excepcion que origino el fallo transaccional.
- `[CONFIRMADO]` Invalidar cache una sola vez despues del commit definitivo, fuera del callback reintentable.
- `[CONFIRMADO]` No repetir una escritura no declarada idempotente despues de iniciar su callback.
- `[CONFIRMADO]` Materializar el BLOB del pipeline dentro del intento SQL y comparar `strlen(bytes)` con `DATALENGTH`.
- `[CONFIRMADO]` Conservar el metodo de streaming usado por el controlador publico.
- `[CONFIRMADO]` Eliminar `DOWNLOAD_ERROR`, `rejectDownload()` y la publicacion de `document_rejected` desde `AttachmentDownloadWorker`.
- `[CONFIRMADO]` Introducir una taxonomia tipada para fuente inexistente, fuente vacia, transferencia incompleta y transferencia externa fallida.
- `[CONFIRMADO]` Restringir `document_rejected` a una lista cerrada de razones de contenido.
- `[CONFIRMADO]` Agregar una guarda en policy y otra antes de persistencia.
- `[CONFIRMADO]` Tratar el agotamiento SQL y los errores tipados de descarga como fallos terminales ya clasificados en `AuditEventConsumer`.
- `[CONFIRMADO]` Mantener `AUDIT_PENDING_RECLAIM_IDLE_MS=600000` para procesos caidos o llamadas que no retornan.
- `[CONFIRMADO]` Mantener la transaccion dual y `updateFinalTimings()`.
- `[CONFIRMADO]` Agregar pruebas deterministas para cortes simulados de 1, 6 y 30 segundos.
- `[CONFIRMADO]` Sincronizar documentacion y skills gobernantes cuando se implemente.

#### Excluido

- `[CONFIRMADO]` Mutacion, invalidacion o borrado de resultados historicos.
- `[CONFIRMADO]` Restauracion manual de valores previos en `AdjuntosDispensacion` o `DispensacionDetalleServicio`.
- `[CONFIRMADO]` Diagnostico del motivo por el cual SQL Server ejecuto `SHUTDOWN`.
- `[CONFIRMADO]` Cambios de replicas, topologia Docker, Redis Streams o scheduler por job.
- `[CONFIRMADO]` Cambios de tablas, columnas, indices, constraints, triggers, vistas o procedimientos.
- `[CONFIRMADO]` Reintento automatico de deadlocks `1205`, errores de datos, constraints o `HYT00` ocurrido durante un statement.
- `[CONFIRMADO]` Circuit breaker, outbox, saga, cola retrasada o un servicio nuevo.
- `[CONFIRMADO]` Modificacion del endpoint de descarga HTTP o de su forma de respuesta.
- `[CONFIRMADO]` Reintento de Google Drive en este cambio; sus fallos quedan tipados como tecnicos y nunca como rechazo de contenido.

### 3. Non Goals

- `[CONFIRMADO]` No atribuir el incidente exclusivamente a SQL Server ni exclusivamente a la aplicacion.
- `[CONFIRMADO]` No ocultar una indisponibilidad tecnica mediante un resultado `manual_review`.
- `[CONFIRMADO]` No reducir el umbral global de `XAUTOCLAIM`, porque protege etapas largas como Gemini.
- `[CONFIRMADO]` No mantener propiedades PDO legacy, adaptadores ni constructores duales en `Model`.
- `[CONFIRMADO]` No eliminar `updateFinalTimings()` dentro de una correccion de resiliencia.
- `[CONFIRMADO]` No forzar reextraccion Gemini de registros historicos en este SDD.
- `[CONFIRMADO]` No introducir configuracion de retry por variables de entorno; el MVP usa una politica fija y probada.

### 4. Estado Actual

#### Flujo que genera el falso positivo

```text
document_registered
  -> AttachmentDownloadWorker
  -> AttachmentDownloadService
  -> AttachmentsModel::$readDb (PDO db2 retenido)
  -> PDOException / timeout / enlace roto
  -> catch Throwable
  -> rejection_reason = DOWNLOAD_ERROR
  -> document_rejected
  -> handle() retorna
  -> XACK
  -> RulesEvaluationWorker
  -> HIGH + INTEGRIDAD_DOCUMENTO + approved=false
  -> rules_evaluated
  -> persistencia SQL de un rechazo tecnico como resultado de negocio
```

#### Flujo de espera actual

```text
fallo no terminal
  -> mensaje queda en PEL sin ACK
  -> turno de persistencia o reserva de auditoria sigue ocupado
  -> XAUTOCLAIM despues de 600000 ms
  -> siguiente entrega
```

#### Limitaciones confirmadas

- `[CONFIRMADO]` `Database::closeConnection()` elimina el objeto del cache, pero no invalida referencias PDO ya almacenadas en modelos.
- `[CONFIRMADO]` El constructor de `Model` abre `db2` aun cuando un metodo solo requiere escritura.
- `[CONFIRMADO]` `Database` envuelve `PDOException` de apertura en `RuntimeException`; el clasificador debe recorrer `previous`.
- `[CONFIRMADO]` Los modelos no cierran todos los cursores de lectura de manera uniforme.
- `[CONFIRMADO]` `HYT00` no identifica por si solo si el timeout ocurrio al conectar o ejecutar.
- `[CONFIRMADO]` El downloader no diferencia ausencia, vacio, corrupcion confirmada y transporte.
- `[CONFIRMADO]` El contrato vigente permite que cualquier productor publique `document_rejected`.
- `[CONFIRMADO]` El segundo update de timings falla de forma best-effort y no es la causa directa del bloqueo de la transaccion principal.

### 5. Estado Objetivo

#### Arquitectura objetivo

```text
Worker / Controller
        |
        v
Model sin propiedades PDO
        |
        v
SqlServerConnectionExecutor
  intento 1: Database::openConnection(name) -> callback
  espera 1 s
  intento 2: PDO nuevo -> callback
  espera 5 s
  intento 3: PDO nuevo -> callback
  espera 30 s
  intento 4: PDO nuevo -> callback
        |
        +--> exito: retorna resultado
        |
        +--> agotado/no reintentable:
             SqlServerOperationException
             -> AuditEventConsumer terminal
             -> audit_failed + DLQ + XACK
             -> liberacion de reserva/turno
```

#### Modos del ejecutor

| Modo | Conexion | Operacion | Replay |
| --- | --- | --- | --- |
| `READ` | `[CONFIRMADO]` Reintenta fallos de conexion permitidos. | `[CONFIRMADO]` Reintenta `08*` y `SHUTDOWN`; no reintenta `HYT00`. | `[CONFIRMADO]` Callback completo. |
| `IDEMPOTENT_WRITE` | `[CONFIRMADO]` Reintenta fallos de conexion permitidos. | `[CONFIRMADO]` Reintenta `08*` y `SHUTDOWN`; no reintenta `HYT00`, `1205`, constraint ni datos. | `[CONFIRMADO]` Transaccion completa. |
| `NON_REPLAYABLE_WRITE` | `[CONFIRMADO]` Realiza una sola apertura fresca. | `[CONFIRMADO]` Realiza una sola ejecucion. | `[CONFIRMADO]` Ninguno. |

#### Clasificacion cerrada

| Fase | Evidencia | Resultado |
| --- | --- | --- |
| `connect` | SQLSTATE con prefijo `08` | `[CONFIRMADO]` Reintentable. |
| `connect` | SQLSTATE `HYT00` | `[CONFIRMADO]` Reintentable. |
| `connect` u `operation` | Mensaje contiene `SHUTDOWN is in progress` | `[CONFIRMADO]` Reintentable para `READ` e `IDEMPOTENT_WRITE`. |
| `operation` | SQLSTATE con prefijo `08` | `[CONFIRMADO]` Reintentable para `READ` e `IDEMPOTENT_WRITE`. |
| `operation` | SQLSTATE `HYT00` | `[CONFIRMADO]` No reintentable; puede representar query timeout o bloqueo. |
| `operation` | Codigo nativo `1205` | `[CONFIRMADO]` No reintentable en este MVP. |
| cualquier fase | Constraint, sintaxis, autenticacion, conversion o datos | `[CONFIRMADO]` No reintentable. |
| cualquier fase | Excepcion sin evidencia de la lista | `[CONFIRMADO]` No reintentable. |

`[CONFIRMADO]` El clasificador inspecciona cada `Throwable` de la cadena y obtiene SQLSTATE en este orden: `PDOException::$errorInfo[0]`, `Throwable::getCode()` si tiene cinco caracteres y patrones `SQLSTATE[...]` del mensaje.

`[CONFIRMADO]` Para un `LoginTimeout=T`, el limite teorico exclusivo de aperturas es `4*T + 36` segundos. Con el default de 30 segundos son 156 segundos. El calculo no incluye ejecucion de statements ni `commit()`.

`[CONFIRMADO]` En `AuditResultPersistenceModel`, cada intento abre y cierra su propia transaccion. Si el callback falla, intenta `rollBack()` solo cuando `inTransaction()` es verdadero; un fallo de rollback se registra y se vuelve a lanzar la excepcion original. La invalidacion de cache ocurre fuera del loop, una sola vez, despues del retorno exitoso del ejecutor.

#### Flujo documental objetivo

```text
document_registered
  -> descarga SQL/Drive
     -> falla tecnica/fuente:
        excepcion tipada
        NO markDocumentRejected
        NO document_rejected
        NO hallazgo de negocio
        retry SQL local o fallo tecnico terminal
     -> bytes completos:
        document_downloaded
        -> DocumentIntegrityValidator
           -> contenido invalido confirmado:
              document_rejected(rejection_class=document_content)
           -> contenido valido:
              Gemini
```

#### Regla de completitud BLOB

- `[CONFIRMADO]` La query materializada del pipeline selecciona `AdjDisDoc` y `DATALENGTH(AdjDisDoc)` en la misma fila.
- `[CONFIRMADO]` El stream se consume dentro del callback del ejecutor SQL.
- `[CONFIRMADO]` El cursor se cierra en `finally`.
- `[CONFIRMADO]` `expected_size <= 0` produce `AttachmentDownloadException::SOURCE_EMPTY`.
- `[CONFIRMADO]` `strlen(raw) !== expected_size` produce `AttachmentDownloadException::INCOMPLETE_TRANSFER`.
- `[CONFIRMADO]` Ninguna de esas excepciones publica `document_rejected`.

#### Guardas de dominio

1. `[CONFIRMADO]` `RulesEvaluationWorker` acepta `document_rejected` solo si:
   - `rejection_class === 'document_content'`;
   - `rejection_origin === DocumentExtractionWorker::class`;
   - `rejection_reason` pertenece a la lista cerrada.
2. `[CONFIRMADO]` `AuditPersistenceWorker::validateOutcome()` rechaza cualquier decision con `rejection_reason` que no cumpla el mismo contrato.
3. `[CONFIRMADO]` `AuditPersistenceWorker::validateOutcome()` rechaza si `Hallazgos` o `document_decisions` contienen el valor exacto `DOWNLOAD_ERROR`.
4. `[CONFIRMADO]` La violacion de una guarda lanza `DomainException`, produce DLQ y no ejecuta SQL de dominio.

#### Lista cerrada de rechazo de contenido

```text
EMPTY_DOCUMENT
DOCUMENT_TOO_SMALL
UNSUPPORTED_MIME
UNKNOWN_FILE_SIGNATURE
MIME_MISMATCH
ENCRYPTED_DOCUMENT
EMPTY_PDF_NO_PAGES
GEMINI_DECODE_FAILURE
```

### 6. Decisiones Arquitectonicas

| ID | Decision | Alternativas Rechazadas | Justificacion |
| --- | --- | --- | --- |
| ADR-01 | `[CONFIRMADO]` Separar error tecnico de rechazo documental. | Convertir excepciones en `DOWNLOAD_ERROR`. | Evita resultados de negocio sin evidencia documental. |
| ADR-02 | `[CONFIRMADO]` Abrir PDO nuevo por intento mediante un ejecutor unico. | Ping previo, `closeConnection()` aislado o retry duplicado por modelo. | Un ping no garantiza la siguiente operacion y las referencias retenidas sobreviven al cache. |
| ADR-03 | `[CONFIRMADO]` Reemplazar las propiedades PDO de `Model` en un cambio atomico. | Conservar `$readDb/$writeDb` como compatibilidad. | La compatibilidad preservaria la causa estructural. |
| ADR-04 | `[CONFIRMADO]` Usar cuatro intentos con 1/5/30 segundos. | Pausas acumuladas de 5,25 segundos. | La secuencia cubre pruebas de cortes de 1, 6 y 30 segundos. |
| ADR-05 | `[CONFIRMADO]` Clasificar `HYT00` segun fase. | Excluirlo o incluirlo globalmente. | El significado cambia entre apertura y statement. |
| ADR-06 | `[CONFIRMADO]` Repetir solo lecturas y la transaccion final idempotente; toda escritura no replayable tiene un intento. | Repetir toda escritura SQL. | Limita duplicacion y evita backoff posterior al commit de negocio. |
| ADR-07 | `[CONFIRMADO]` Materializar BLOB dentro del modelo para el pipeline y conservar streaming para HTTP. | Eliminar el metodo de stream. | El controlador publico requiere streaming; el pipeline requiere validar completitud antes de continuar. |
| ADR-08 | `[CONFIRMADO]` Dejar un solo productor de `document_rejected`: `DocumentExtractionWorker`. | Permitir rechazo desde downloader. | Solo extraction posee bytes completos y evidencia de integridad. |
| ADR-09 | `[CONFIRMADO]` Aplicar guardas en policy y persistencia. | Confiar solo en el productor. | Bloquea eventos legacy, manuales o corruptos antes de escribir negocio. |
| ADR-10 | `[CONFIRMADO]` Terminalizar errores SQL ya agotados sin esperar `XAUTOCLAIM`. | Reducir globalmente los 600 segundos. | Libera el job sin duplicar etapas Gemini largas. |
| ADR-11 | `[CONFIRMADO]` Mantener `updateFinalTimings()`. | Eliminarlo dentro del hotfix. | Su error ya esta aislado y su contrato externo no esta inventariado. |
| ADR-12 | `[CONFIRMADO]` Mantener las escrituras duales en una transaccion. | Eliminar o desacoplar una escritura. | Ambas son requisitos confirmados del dominio. |
| ADR-13 | `[CONFIRMADO]` Separar remediacion historica en otro SDD. | Mezclar codigo preventivo y mutacion de datos. | Permite desplegar la barrera sin inventar estados legacy previos. |
| ADR-14 | `[CONFIRMADO]` No agregar variables de retry. | Hacer todos los valores configurables. | Una politica fija reduce combinaciones no probadas en el MVP. |

### 7. Dependencias

| Dependencia | Tipo | Version | Impacto |
| --- | --- | --- | --- |
| PHP | runtime | `[CONFIRMADO]` 8.2 | Enums, tipos union, `Throwable` y closures. |
| PDO SQLSRV | extension | `[CONFIRMADO]` 5.11.1 | `PDOException::errorInfo`, BLOB LOB y transacciones. |
| Microsoft ODBC Driver | infraestructura | `[CONFIRMADO]` 18 | SQLSTATE y pooling de conexiones. |
| SQL Server | base de datos | `[DESCONOCIDO]` Build exacto | `db2` para lectura y `default` para escritura en la misma instancia declarada. |
| Redis Streams | cola | `[CONFIRMADO]` Runtime vigente | ACK, PEL, DLQ y liberacion de auditoria. |
| Google Drive API | tercero | `[CONFIRMADO]` v3 | Sus fallos dejan de convertirse en rechazo; no reciben retry nuevo. |
| Gemini API | tercero | `[CONFIRMADO]` Multimodal | Solo recibe contenido descargado y validado. |
| PHPUnit | testing | `[CONFIRMADO]` 10.x | Pruebas unitarias con conector, reloj y sleeper falsos. |

### 8. Invariantes

| Invariante | Enforcement | Validacion |
| --- | --- | --- |
| `[CONFIRMADO]` `AudDispEst`, adjuntos y detalle se confirman o revierten juntos. | `IDEMPOTENT_WRITE` envuelve la transaccion completa. | Falla inducida en cada statement deja cero commits parciales. |
| `[CONFIRMADO]` Un error tecnico nunca es hallazgo de integridad. | Productor unico, allowlist y dos guardas. | Tests con PDO, timeout, Drive e evento `DOWNLOAD_ERROR`. |
| `[CONFIRMADO]` Cada retry usa otro objeto PDO. | `Database::openConnection()` dentro del loop. | Identidades distintas de cuatro PDO falsos. |
| `[CONFIRMADO]` `HYT00` de statement no se repite. | Clasificador recibe fase `operation`. | Test de una sola invocacion al callback. |
| `[CONFIRMADO]` El BLOB completo precede a Gemini. | Comparacion exacta de bytes. | BLOB truncado no publica `document_downloaded`. |
| `[CONFIRMADO]` Un rollback fallido no reemplaza el error original. | Catch anidado y rethrow del primer `Throwable`. | Clase y mensaje propagados corresponden al fallo inicial. |
| `[CONFIRMADO]` Cache se invalida solo tras commit definitivo. | Invalidacion fuera del callback reintentable. | Cero invalidaciones en fallos; una en exito. |
| `[CONFIRMADO]` Un fallo SQL agotado no espera 600 segundos. | Excepcion terminal reconocida por consumer. | DLQ y ACK en la misma entrega. |
| `[CONFIRMADO]` Jobs distintos conservan independencia. | No cambia `AuditPersistenceQueue`. | Integracion Redis existente mas fallo terminal en un job. |
| `[CONFIRMADO]` Timings finales conservan el comportamiento best-effort. | `updateFinalTimings()` usa una apertura fresca y un solo intento. | Su fallo se registra sin retry, rollback ni espera adicional. |
| `[CONFIRMADO]` No se exponen credenciales ni datos del paciente. | Logs solo incluyen conexion logica, fase, intento, SQLSTATE y clase. | Inspeccion de payloads/logs; no DSN, SQL, password ni BLOB. |

### 9. Modelo de Datos

`[CONFIRMADO]` No se agregan, modifican ni eliminan tablas, columnas, indices, constraints, foreign keys, triggers, vistas o procedimientos.

#### DDL

`[CONFIRMADO]` No aplica. Este SDD no ejecuta DDL.

#### Orden de Ejecucion

`[CONFIRMADO]` No aplica una secuencia SQL de esquema.

#### Migracion de Datos

| Origen | Transformacion | Destino | Validacion |
| --- | --- | --- | --- |
| No aplica | `[CONFIRMADO]` El cambio preventivo no modifica filas existentes. | No aplica | Conteo y checksum de tablas sin cambio por despliegue. |

#### Rollback

`[CONFIRMADO]` No existe rollback SQL porque no existe migracion de esquema ni de datos. El rollback es de imagen de aplicacion.

### 10. Contratos

#### 10.1 `Database`

##### Antes

```php
public static function getConnection(string $name = 'default'): PDO;
```

`[CONFIRMADO]` Retorna y cachea un PDO por fingerprint.

##### Despues

```php
public static function getConnection(string $name = 'default'): PDO;
public static function openConnection(string $name = 'default'): PDO;
```

- `[CONFIRMADO]` `getConnection()` conserva su contrato para health checks.
- `[CONFIRMADO]` `openConnection()` usa la misma resolucion de DSN/opciones, crea un PDO y no toca `self::$connections` ni aliases.
- `[CONFIRMADO]` La construccion DSN existe en un unico metodo privado.
- `[CONFIRMADO]` El contrato garantiza un objeto PDO nuevo; con `ConnectionPooling=1`, ODBC conserva la decision sobre reutilizacion de una conexion fisica sana.

#### 10.2 Ejecutor SQL

##### Despues

```php
enum SqlServerOperationMode: string
{
    case READ = 'read';
    case IDEMPOTENT_WRITE = 'idempotent_write';
    case NON_REPLAYABLE_WRITE = 'non_replayable_write';
}

final class SqlServerConnectionExecutor
{
    public function execute(
        string $connectionName,
        SqlServerOperationMode $mode,
        callable $operation
    ): mixed;
}
```

`[CONFIRMADO]` El constructor admite `Closure $connector`, `Closure $sleeper` y `Closure $clock` opcionales exclusivamente para pruebas; produccion usa `Database::openConnection()`, `usleep()` y `hrtime()`.

#### 10.3 `Model`

##### Antes

```php
protected PDO $readDb;
protected ?PDO $writeDb = null;
```

##### Despues

```php
public function __construct(
    ?SqlServerConnectionExecutor $executor = null
);

protected function read(callable $operation): mixed;
protected function idempotentWrite(callable $operation): mixed;
protected function nonReplayableWrite(callable $operation): mixed;
```

`[CONFIRMADO]` No quedan propiedades PDO ni `getWriteDb()`. Las subclases que definen constructor reciben y propagan el mismo ejecutor; no se conserva el constructor legacy `?PDO`.

#### 10.4 BLOB del pipeline

##### Antes

```php
getAttachmentBlobStreamByIdForDisDetNro(...): array{
    stream: resource|null,
    close: callable
}
```

##### Despues

```php
getAttachmentBlobBytesByIdForDisDetNro(...): array{
    bytes: string,
    expected_size: int
}
```

- `[CONFIRMADO]` El metodo nuevo es consumido por `AttachmentDownloadService`.
- `[CONFIRMADO]` El metodo stream existente permanece para `AttachmentsController`.

#### 10.5 Evento `document_rejected`

##### Antes

```json
{
  "event_type": "document_rejected",
  "payload": {
    "attachment_id": "41",
    "document_type": "FORMULA MEDICA",
    "rejection_reason": "DOWNLOAD_ERROR",
    "message": "SQLSTATE[08S01] Communication link failure"
  }
}
```

##### Despues

```json
{
  "event_type": "document_rejected",
  "payload": {
    "attachment_id": "41",
    "document_type": "FORMULA MEDICA",
    "rejection_class": "document_content",
    "rejection_reason": "UNKNOWN_FILE_SIGNATURE",
    "rejection_origin": "App\\Services\\Audit\\Pipeline\\DocumentExtractionWorker",
    "mime": "application/pdf",
    "detected_mime": null,
    "size_bytes": 18742
  }
}
```

- `[CONFIRMADO]` `message` tecnico se elimina.
- `[CONFIRMADO]` `rejection_class` se agrega y es obligatorio.
- `[CONFIRMADO]` Solo `DocumentExtractionWorker` publica el evento.
- `[CONFIRMADO]` El cambio no es backward compatible con eventos pendientes sin `rejection_class`; el despliegue exige drenaje.

#### 10.6 Decision documental rechazada

##### Despues

```json
{
  "documentName": "FORMULA MEDICA",
  "approved": false,
  "rejection_class": "document_content",
  "rejection_reason": "UNKNOWN_FILE_SIGNATURE",
  "payload": {
    "hallazgos": [
      {
        "Codigo": "INTEGRIDAD",
        "Descripcion": "Documento no procesable: UNKNOWN_FILE_SIGNATURE"
      }
    ]
  }
}
```

`[CONFIRMADO]` Las decisiones regulares de policy sin rechazo de contenido no agregan estos dos campos.

#### 10.7 Error SQL terminal

##### Despues

```text
Core\SqlServerOperationException
  connection_name: db2 | default
  phase: connect | operation
  mode: read | idempotent_write | non_replayable_write
  attempts: 1..4
  sql_state: valor sanitizado o null
  retry_exhausted: true | false
```

`[CONFIRMADO]` La excepcion conserva el error original en `previous`, pero su mensaje publico no contiene DSN, SQL ni credenciales.

#### 10.8 APIs REST

`[CONFIRMADO]` No cambia ningun request ni response REST. Los fallos tecnicos terminales siguen representados por el estado de auditoria `failed`, evento `audit_failed` y DLQ.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementacion | Validacion |
| --- | --- | --- | --- |
| R-01 | Error tecnico no genera rechazo de negocio. | `AttachmentDownloadWorker` propaga; productor unico. | PDO/Drive no publican `document_rejected`. |
| R-02 | PDO `db2` roto se reemplaza. | `Model::read()` + executor + `openConnection()`. | Primer PDO falla, segundo completa. |
| R-03 | PDO `default` roto se reemplaza. | `idempotentWrite()`. | Replay transaccional exitoso. |
| R-04 | Cubrir cortes de 1, 6 y 30 segundos. | Backoff 1/5/30. | Reloj falso observa exito en intentos 2, 3 y 4. |
| R-05 | `HYT00` depende de fase. | Clasificador con `connect/operation`. | Dos tests opuestos. |
| R-06 | No esperar 600 segundos tras agotamiento retornado. | Consumer terminaliza `SqlServerOperationException`. | DLQ y ACK en primera entrega. |
| R-07 | Validar BLOB completo. | DATALENGTH y bytes en la misma query. | Diferencia de un byte falla. |
| R-08 | Diferenciar estados de descarga. | `AttachmentDownloadException` con cuatro codigos. | Un test por codigo. |
| R-09 | Restringir rechazo por contenido. | `DocumentRejectionReason` allowlist. | Razon desconocida va a DLQ. |
| R-10 | Bloquear eventos contaminados en policy. | Guarda en `buildRejectedPolicyResult()`. | `DOWNLOAD_ERROR` no encola persistencia. |
| R-11 | Bloquear payload contaminado en persistencia. | Guarda en `validateOutcome()`. | Modelo SQL recibe cero llamadas. |
| R-12 | Preservar persistencia dual. | Transaccion existente en modo idempotente. | Pruebas de commit/rollback. |
| R-13 | Preservar timings finales sin crear otro bloqueo. | `updateFinalTimings()` en modo no replayable de un intento. | Test actual permanece y comprueba una sola apertura. |
| R-14 | Eliminar PDO retenido de modelos. | Rebuild atomico de `Model` y subclases. | `rg` no encuentra propiedades PDO. |
| R-15 | Conservar streaming HTTP. | Metodo stream no se elimina. | Test/controlador descarga BLOB. |
| R-16 | No mutar historia. | SDD de remediacion separado. | Cero DML de migracion. |
| R-17 | Logs sanitizados. | Contexto cerrado del executor. | No aparece DSN/password/BLOB. |
| R-18 | Mantener independencia por job. | Scheduler sin cambios. | Integracion de jobs concurrentes. |
| R-19 | Preservar error original si rollback falla. | Rollback best-effort en modelo. | Test con fallo primario y fallo de rollback. |
| R-20 | Invalidar cache una sola vez. | Invalidacion posterior al executor. | Conteo cero/uno segun resultado. |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `Database` | PDO | Alto | Fabrica cacheada y no cacheada. | Cache actual centralizada. |
| `Model` | Todos los modelos | Alto | Eliminar estado PDO. | Siete subclases directas. |
| Modelos de lectura | `db2` | Alto | Ejecutar queries en callbacks y cerrar cursores. | 28 usos directos de `$readDb` en el baseline inspeccionado. |
| Persistencia final | `default` | Alto | Replay transaccional permitido. | Upsert y updates deterministas. |
| Downloader | SQL/Drive | Critico | Excepciones tecnicas, sin rechazo. | Catch indiscriminado vigente. |
| Extractor | `document_rejected` | Medio | Agregar clase y allowlist. | Productor de contenido vigente. |
| Policy | evento documental | Alto | Validacion previa a hallazgo. | Actualmente acepta cualquier razon. |
| Persistence worker | `rules_evaluated` | Alto | Defensa antes de SQL. | Actualmente valida solo forma general. |
| Consumer | errores terminales | Alto | ACK/DLQ inmediato tras politica local. | Solo Domain/InvalidArgument son terminales. |
| Controller adjuntos | stream BLOB | Bajo | Conservar contrato. | Uso directo confirmado. |
| Frontend | APIs | Ninguno confirmado | Sin cambio. | Forma REST no cambia. |
| Datos historicos | SQL | Ninguno en este SDD | Documento separado. | No hay DML de migracion. |

### 13. Cambios por Archivo

`[CONFIRMADO]` Todas las rutas tienen raiz `C:\Users\USER\Desktop\AudFact`.

#### Codigo

| Estado | Ruta completa | Clases/metodos | Cambio determinista |
| --- | --- | --- | --- |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\core\Database.php` | `getConnection`, nueva `openConnection`, fabrica privada | Compartir construccion; `openConnection` no cachea. |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\core\SqlServerOperationMode.php` | enum | Definir tres modos exactos. |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\core\SqlServerConnectionExecutor.php` | executor y clasificador privado | Cuatro intentos, fases y logs. |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\core\SqlServerOperationException.php` | excepcion final | Transportar resultado sanitizado de clasificacion. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\Model.php` | constructor y helpers | Eliminar PDO retenido y `getWriteDb()`. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\AttachmentsModel.php` | todos los queries; nuevo metodo materializado | Usar `read()`, cerrar cursores y agregar bytes+tamanio. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\DispensationModel.php` | queries | Usar `read()` sin PDO retenido. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\ClientsModel.php` | queries | Usar `read()` sin PDO retenido. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\InvoicesModel.php` | queries | Usar `read()` sin PDO retenido. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\AuditConfigModel.php` | reads y `saveConfig` | `READ` para consultas; `NON_REPLAYABLE_WRITE` para guardado. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\AuditStatusModel.php` | reads y fallback | Ejecutar PDO por operacion; preservar fallback `default -> db2`. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Models\AuditResultPersistenceModel.php` | constructor, `persist`, `updateFinalTimings` | Transaccion idempotente; timing no replayable; sin PDO inyectado. |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\AttachmentDownloadException.php` | excepcion y codigos | Taxonomia tecnica/de fuente. |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\DocumentRejectionReason.php` | constantes y `isAllowed()` | Lista cerrada de contenido. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\AttachmentDownloadService.php` | `download`, `downloadFromBlob`, Drive | Usar bytes materializados, validar tamano y tipar errores. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\AttachmentDownloadWorker.php` | `handle`; eliminar `rejectDownload` | Telemetria `failed`, rethrow, sin evento de rechazo. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\DocumentIntegrityValidator.php` | razones | Consumir constantes compartidas. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\DocumentExtractionWorker.php` | `handleRejectedDocument` | Agregar `rejection_class` y validar allowlist. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\RulesEvaluationWorker.php` | `buildRejectedPolicyResult`, normalizacion | Guarda de evento y propagacion de metadata. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\AuditPersistenceWorker.php` | `validateOutcome` | Guarda final contra razones tecnicas. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\AuditEventConsumer.php` | `handleFailure` | Tratar excepciones SQL/descarga ya agotadas como terminales. |

#### Fragmentos normativos

```php
// Antes: downloader convierte cualquier Throwable en negocio.
catch (\Throwable $error) {
    $this->rejectDownload($event, $payload, $error);
    return;
}

// Despues: downloader conserva la naturaleza tecnica.
catch (\Throwable $error) {
    $this->telemetryPublisher->failed(/* contexto sanitizado */);
    throw $error;
}
```

```php
// Antes
$writeDb = $this->getWriteDb();
$writeDb->beginTransaction();

// Despues
$this->idempotentWrite(function (PDO $writeDb) use ($data, $documentDecisions): void {
    $writeDb->beginTransaction();
    // upsert + adjuntos + detalle + commit
});
```

```php
// Guarda final obligatoria.
if (self::containsExactValue($rulesPayload, 'DOWNLOAD_ERROR')) {
    throw new \DomainException(
        'rules_evaluated contiene una razon tecnica prohibida'
    );
}
```

#### Tests

| Estado | Ruta completa | Cambio |
| --- | --- | --- |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\tests\Core\SqlServerConnectionExecutorTest.php` | Matriz de clasificacion, backoff y PDO nuevo. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\tests\Models\AttachmentsModelTest.php` | BLOB completo, parcial, vacio y cursor. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\tests\Models\AuditResultPersistenceModelTest.php` | Inyectar executor falso y probar replay. |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\tests\Services\Audit\Pipeline\AttachmentDownloadServiceTest.php` | Taxonomia y bytes exactos. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\tests\Services\Audit\Events\AttachmentDownloadWorkerTest.php` | Reemplazar expectativa de falso rechazo por propagacion. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\tests\Services\Audit\Events\DocumentExtractionWorkerTest.php` | Contrato de rechazo de contenido. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\tests\Services\Audit\Events\RulesEvaluationWorkerTest.php` | Allowlist y bloqueo `DOWNLOAD_ERROR`. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\tests\Services\Audit\Events\AuditPersistenceWorkerTest.php` | Guarda antes de modelo y timings preservados. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\tests\Services\Audit\Events\AuditEventConsumerTest.php` | DLQ/ACK inmediato de excepcion agotada. |

#### Documentacion y skills

| Estado | Ruta completa | Cambio |
| --- | --- | --- |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\architecture.md` | Ejecutor, productor unico y errores terminales. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\architecture-diagrams.md` | Flujo tecnico frente a rechazo de contenido. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\data-flows.md` | El downloader deja de emitir `document_rejected`. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\features\audit-workflow.md` | Taxonomia, allowlist y retry SQL. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\high-availability.md` | Diferenciar retry local de `XAUTOCLAIM`. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\database-schema.md` | Aclarar que no hay DDL; documentar validacion DATALENGTH. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\testing-strategy.md` | Matriz 1/6/30 y ODBC real. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\docker-operations.md` | Diagnostico de retries y DLQ tecnico. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\BUSINESS.md` | Distinguir indisponibilidad de rechazo documental y corregir drift de identidad ya detectado. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\changelog.md` | Registrar implementacion y validaciones reales. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\.agent\skills\audfact-sqlsrv-models\SKILL.md` | PDO por operacion, fases y BLOB completo. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\.agent\skills\audfact-audit-gemini\SKILL.md` | Productor unico y guardas. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\.agent\skills\audfact-project-overview\SKILL.md` | Flujo objetivo. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\.agent\skills\audfact-runtime-docker\SKILL.md` | Operacion y diagnostico. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\.agent\skills\CATALOG.md` | Registrar archivos Core/Pipeline nuevos. |

### 14. Plan de Migracion

#### Prerequisitos

1. `[CONFIRMADO]` Implementar todos los archivos de `Model` y sus subclases en una sola rama; no existe estado intermedio compatible.
2. `[CONFIRMADO]` Ejecutar suite completa y benchmark con SQL sano.
3. `[CONFIRMADO]` Ejecutar prueba ODBC 18 en staging para `HYT00` de connect y operation.
4. `[CONFIRMADO]` Crear imagen inmutable y conservar el SHA de rollback.
5. `[CONFIRMADO]` Detener intake de nuevos jobs.
6. `[CONFIRMADO]` Esperar cero `pending` y cero `lag` en los grupos `downloaders`, `policy` y `persistence`.

#### Ejecucion

1. `[CONFIRMADO]` Desplegar la imagen nueva sin cambiar variables ni replicas.
2. `[CONFIRMADO]` Reiniciar todos los workers para eliminar procesos con codigo/contratos anteriores.
3. `[CONFIRMADO]` Validar `/health`.
4. `[CONFIRMADO]` Ejecutar una auditoria control sin falla.
5. `[CONFIRMADO]` Ejecutar cortes controlados de `db2` de 1, 6 y 30 segundos en staging.
6. `[CONFIRMADO]` Ejecutar un corte controlado de `default` durante persistencia en staging.
7. `[CONFIRMADO]` Reabrir intake solo si todos los criterios de aceptacion pasan.

#### Validaciones Previas

- `[CONFIRMADO]` `rg -n "\$readDb|\$writeDb|getWriteDb" app/Models` retorna cero referencias de runtime.
- `[CONFIRMADO]` `rg -n "DOWNLOAD_ERROR|rejectDownload" app tests` retorna cero usos productivos; la cadena solo puede aparecer en tests de guarda y SDDs.
- `[CONFIRMADO]` No existe DDL ni DML de migracion en el diff.
- `[CONFIRMADO]` El benchmark p50 y p95 no excede 125% de la linea base sana.

#### Validaciones Posteriores

- `[CONFIRMADO]` Un corte recuperable registra intentos y termina la auditoria sin DLQ.
- `[CONFIRMADO]` Un corte superior al presupuesto produce `audit_failed`, DLQ, ACK y liberacion de reserva/turno.
- `[CONFIRMADO]` Ningun `Hallazgos` nuevo contiene `DOWNLOAD_ERROR`.
- `[CONFIRMADO]` Los eventos de rechazo contienen `rejection_class=document_content`.
- `[CONFIRMADO]` Los jobs independientes siguen progresando.

#### Rollback

1. `[CONFIRMADO]` Detener intake.
2. `[CONFIRMADO]` Drenar eventos creados por la imagen nueva.
3. `[CONFIRMADO]` Desplegar el SHA inmutable anterior.
4. `[CONFIRMADO]` Reiniciar todos los workers.
5. `[CONFIRMADO]` Validar `/health`, grupos Redis y una auditoria control.
6. `[CONFIRMADO]` No ejecutar SQL de rollback.
7. `[CONFIRMADO]` Mantener intake cerrado si reaparece `DOWNLOAD_ERROR`; el rollback restaura el defecto conocido y solo se permite para una regresion mas severa.

### 15. Casos Limite

| Condicion | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Corte `db2` de 1 s | Intento 2 usa PDO nuevo y completa. | Sin rechazo ni DLQ. |
| Corte `db2` de 6 s | Intento 3 usa PDO nuevo y completa. | Delays `[1000,5000]`. |
| Corte `db2` de 30 s | Intento 4 usa PDO nuevo y completa. | Delays `[1000,5000,30000]`. |
| SQL no vuelve | Cuarto fallo lanza excepcion terminal. | DLQ+ACK en esa entrega; job liberado. |
| `HYT00` al abrir | Retry. | Segundo conector invocado. |
| `HYT00` al ejecutar | Sin replay. | Un callback; DLQ inmediato en worker. |
| `08S01` durante lectura | Retry de lectura completa. | Cursor anterior cerrado best-effort. |
| `08S01` durante commit | Replay de transaccion idempotente. | Un unico estado final por `FacNro`. |
| Rollback tambien falla | Se registra el rollback y se conserva el fallo inicial. | Excepcion exterior corresponde al fallo inicial. |
| Primer intento falla y segundo confirma | Cache se invalida al final. | Una sola invalidacion. |
| Deadlock `1205` | No retry local. | Error terminal clasificado. |
| BLOB esperado 100, recibido 99 | Transferencia incompleta. | No `document_downloaded`. |
| BLOB esperado 0 | Fuente vacia. | No rechazo de contenido. |
| Adjunto no encontrado | Fuente inexistente. | Auditoria tecnica fallida, sin hallazgo. |
| PDF completo sin paginas | Rechazo de contenido permitido. | `EMPTY_PDF_NO_PAGES`. |
| Evento legacy `DOWNLOAD_ERROR` | Policy lo rechaza. | Cero enqueue a persistencia. |
| Payload `rules_evaluated` contaminado | Persistence lo rechaza. | Cero llamadas al modelo. |
| Falla de `updateFinalTimings()` | Un intento, log y continuidad actual. | Auditoria ya completada; sin retry ni rollback. |
| Dos jobs persisten a la vez | Scheduler vigente los separa por job. | Ambos avanzan salvo disponibilidad SQL global. |
| Llamada nativa no retorna | Permanece proteccion de `XAUTOCLAIM`. | Este caso no se presenta como resuelto por retry retornado. |

### 16. Testing

#### Nuevos Tests

1. `[CONFIRMADO]` `SqlServerConnectionExecutorTest::testOutagesAtOneSixAndThirtySeconds`.
   - Precondicion: conector y reloj virtual.
   - Pasos: fallar hasta tiempos virtuales 1, 6 y 30; ejecutar `READ`.
   - Resultado: exito en intentos 2, 3 y 4 con delays exactos.
2. `[CONFIRMADO]` `SqlServerConnectionExecutorTest::testHyt00DependsOnPhase`.
   - Precondicion: dos `PDOException` con `HYT00`.
   - Pasos: una en connector y otra en callback.
   - Resultado: connector se repite; callback no.
3. `[CONFIRMADO]` `SqlServerConnectionExecutorTest::testEveryAttemptUsesDifferentPdo`.
   - Resultado: cuatro `spl_object_id` distintos.
4. `[CONFIRMADO]` `AttachmentDownloadServiceTest::testRejectsPartialBlobAsTechnicalTransfer`.
   - Resultado: codigo `INCOMPLETE_TRANSFER`; cero base64 publicado.
5. `[CONFIRMADO]` `AttachmentDownloadServiceTest::testClassifiesNotFoundAndEmptySeparately`.
   - Resultado: codigos exactos y no `document_rejected`.
6. `[CONFIRMADO]` `AuditEventConsumerTest::testSqlRetryExhaustionDeadLettersAndAcksImmediately`.
   - Resultado: una entrega, una DLQ, un ACK y auditoria failed.
7. `[CONFIRMADO]` `AuditResultPersistenceModelTest::testRollbackFailurePreservesOriginalError`.
   - Resultado: el error inicial se propaga y el error de rollback solo se registra.
8. `[CONFIRMADO]` `AuditResultPersistenceModelTest::testCacheInvalidatesOnceAfterRetriedCommit`.
   - Resultado: dos intentos SQL, un commit final y una invalidacion.

#### Tests Modificados

1. `[CONFIRMADO]` Reemplazar `AttachmentDownloadWorkerTest::testDownloadFailurePublishesDocumentRejectedWithoutRethrow` por una prueba que exige rethrow, telemetria `failed`, cero state rejection y cero publish.
2. `[CONFIRMADO]` Agregar a `RulesEvaluationWorkerTest` una razon permitida completa y tres negativas: clase ausente, origen incorrecto y `DOWNLOAD_ERROR`.
3. `[CONFIRMADO]` Agregar a `AuditPersistenceWorkerTest` payload contaminado y comprobar cero SQL.
4. `[CONFIRMADO]` Adaptar modelos a executor falso; no conservar constructor `?PDO` de compatibilidad.
5. `[CONFIRMADO]` Mantener la prueba de `updateFinalTimings()` y comprobar una apertura, una ejecucion y cero retries.

#### Tests Eliminados

| Test | Motivo | Cobertura de reemplazo |
| --- | --- | --- |
| `testDownloadFailurePublishesDocumentRejectedWithoutRethrow` | `[CONFIRMADO]` Codifica el defecto. | Nueva prueba de propagacion tecnica. |

#### Verificaciones Manuales

1. `[CONFIRMADO]` ODBC real `HYT00`:
   - Precondicion: staging con la misma imagen y driver de produccion.
   - Paso: provocar timeout de apertura y timeout de statement por separado.
   - Resultado: el log reporta fases distintas y solo apertura se repite.
2. `[CONFIRMADO]` Fault injection SQL:
   - Precondicion: ventana autorizada no productiva.
   - Paso: interrumpir `db2` por 1, 6 y 30 segundos.
   - Resultado: no aparece `document_rejected`; las auditorias completan.
3. `[CONFIRMADO]` Agotamiento:
   - Paso: mantener `default` indisponible mas alla de los cuatro intentos.
   - Resultado: el evento no queda pending 600 segundos; pasa a DLQ y libera turno.
4. `[CONFIRMADO]` Rendimiento sano:
   - Paso: ejecutar al menos 30 persistencias equivalentes antes y despues.
   - Resultado: p50/p95 nuevos no superan 125% de baseline.

Comandos:

```powershell
php -l core\Database.php
php -l core\SqlServerConnectionExecutor.php
php -l app\Services\Audit\Pipeline\AttachmentDownloadWorker.php
php vendor\bin\phpunit --configuration phpunit.xml
rg -n "\$readDb|\$writeDb|getWriteDb" app\Models
rg -n "DOWNLOAD_ERROR|rejectDownload" app tests
```

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigacion |
| --- | --- | --- | --- |
| Reintento aumenta latencia con SQL caido. | Rendimiento | Media | Cuatro intentos fijos y terminalizacion posterior. |
| Apertura PDO sin pooling es costosa. | Operativo | Alta | Verificar A-01 y benchmark. |
| Replay tras commit incierto. | Consistencia de datos | Alta | Solo modo idempotente y prueba de estado unico. |
| `HYT00` del driver difiere de lo esperado. | Tecnico | Alta | Gate ODBC real antes de produccion. |
| Evento legacy sin clase llega tras deploy. | Compatibilidad | Alta | Drenaje obligatorio y reemplazo conjunto de workers. |
| Refactor de siete modelos rompe una query no cubierta. | Tecnico | Alta | Suite completa, lint y despliegue atomico. |
| Llamada SQL nativa no retorna. | Operativo | Alta | Riesgo residual declarado; conservar `XAUTOCLAIM` y observar procesos. |
| Resultado historico contaminado sigue visible. | Consistencia de datos | Alta | Ejecutar SDD de remediacion separado; no declarar incidente cerrado antes. |
| Fallo de Drive termina auditoria sin retry nuevo. | Integracion | Media | Queda tecnico/DLQ, nunca hallazgo; retry Drive se evalua aparte. |

### 18. Criterios de Aceptacion

1. `[CONFIRMADO]` Todos los tests nuevos y modificados pasan.
2. `[CONFIRMADO]` `rg` no encuentra propiedades PDO ni `getWriteDb()` en `app/Models`.
3. `[CONFIRMADO]` `AttachmentDownloadWorker` no contiene `rejectDownload`, `DOWNLOAD_ERROR` ni publica `TYPE_DOCUMENT_REJECTED`.
4. `[CONFIRMADO]` Solo `DocumentExtractionWorker` publica `TYPE_DOCUMENT_REJECTED` en codigo productivo.
5. `[CONFIRMADO]` Todo rechazo documental nuevo contiene clase, origen y razon permitida.
6. `[CONFIRMADO]` Un BLOB parcial no publica `document_downloaded`.
7. `[CONFIRMADO]` Cortes simulados de 1, 6 y 30 segundos completan en intentos 2, 3 y 4.
8. `[CONFIRMADO]` `HYT00` de apertura se reintenta y `HYT00` de statement no.
9. `[CONFIRMADO]` Agotamiento SQL genera DLQ y ACK sin esperar `AUDIT_PENDING_RECLAIM_IDLE_MS`.
10. `[CONFIRMADO]` La transaccion sigue escribiendo `AudDispEst`, `AdjuntosDispensacion` y `DispensacionDetalleServicio`.
11. `[CONFIRMADO]` `updateFinalTimings()` y su prueba permanecen.
12. `[CONFIRMADO]` Ningun test o diff agrega DDL/DML historico.
13. `[CONFIRMADO]` p50 y p95 sanos cumplen el limite de 125%.
14. `[CONFIRMADO]` La prueba ODBC real confirma la clasificacion por fase.
15. `[CONFIRMADO]` Documentacion, changelog, skills y catalogo quedan sincronizados con el codigo implementado.
16. `[CONFIRMADO]` El cierre del incidente historico no se declara hasta completar `plans/sdd-sql-incident-remediation.md`.
17. `[CONFIRMADO]` Un fallo de rollback no sustituye la excepcion original.
18. `[CONFIRMADO]` Una persistencia que requiere replay invalida cache exactamente una vez despues del commit final.

## FASE 2 - Auditoria de Consistencia

| Verificacion | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas estan definidas | PASS | No hay DDL; las tres tablas vigentes estan identificadas. |
| Todas las columnas existen | PASS | `AdjDisDoc`, `BlobSize/DATALENGTH`, `FacNro` y campos de decision aparecen en queries actuales. |
| Todos los contratos documentados | PASS | Database, executor, Model, BLOB, eventos, decisiones, errores y REST. |
| Todos los requisitos tienen trazabilidad | PASS | R-01 a R-20 mapean implementacion y test. |
| Todos los consumidores analizados | PASS | Downloader, extractor, policy, persistence, consumer y controller HTTP. |
| Todas las migraciones tienen rollback | PASS | No hay migracion SQL; rollback por imagen esta definido. |
| Todas las referencias estan definidas | PASS | Archivos nuevos y modificados figuran en la seccion 13. |
| Toda compatibilidad tiene evidencia | PASS | Streaming se conserva; evento legacy requiere drenaje explicito. |
| Todos los criterios son verificables | PASS | Cada criterio tiene prueba, busqueda, metrica o observacion. |

## FASE 3 - Auditoria Arquitectonica

| Pregunta | Resultado |
| --- | --- |
| Existe alguna decision arquitectonica implicita? | No |
| Existe algun contrato sin documentar? | No |
| Existe algun consumidor no analizado? | No |
| Existe alguna migracion sin rollback? | No |
| Existe algun dato persistido sin migracion? | No |
| Existe alguna afirmacion sin evidencia? | No; inferencias y desconocidos estan etiquetados. |
| Existen referencias huerfanas? | No |
| Dos implementadores producirian soluciones diferentes? | No |

## FASE 4 - Resultado Final

### Nivel de Completitud

`[CONFIRMADO] Nivel B - Implementable con Supuestos Declarados`.

### Definicion de Completitud

- `[CONFIRMADO]` La implementacion preventiva es determinista y no requiere decisiones posteriores.
- `[CONFIRMADO]` Los supuestos A-01 a A-03 tienen gates objetivos.
- `[CONFIRMADO]` La especificacion obtiene `PASS` en FASE 2 y `No` en FASE 3.
- `[CONFIRMADO]` El Nivel B no incluye ni oculta la reparacion historica, que conserva Nivel C en su documento separado.

### Estado de Implementacion

| Verificacion | Resultado |
| --- | --- |
| Rama de implementacion | `[CONFIRMADO]` `staging`. |
| Suite PHPUnit | `[CONFIRMADO]` 389 tests, 1332 assertions, 1 test de integracion opt-in omitido. |
| Sintaxis PHP | `[CONFIRMADO]` 135 archivos validados con `php -l`. |
| PDO retenido en modelos | `[CONFIRMADO]` Cero coincidencias para `$readDb`, `$writeDb` o `getWriteDb` en `app/Models`. |
| Productor de `document_rejected` | `[CONFIRMADO]` Solo `DocumentExtractionWorker` publica el evento en codigo productivo. |
| `DOWNLOAD_ERROR` | `[CONFIRMADO]` Solo permanece como valor prohibido en la guarda de `AuditPersistenceWorker`. |
| Prueba operativa | `[CONFIRMADO]` Dos rondas de tres jobs simultaneos: 15 auditorias reales, 15 terminales persistidas, cero fallos de job, cero DLQ y cero retries. |
| Jobs ronda principal | `[CONFIRMADO]` `29364450-74a5-4e14-937d-b937bf1d0bff` (3/3), `1e2246e9-50c0-4e70-a651-3070c0876b8f` (3/3), `ecc8d971-d94b-4ab3-809f-c380855b703a` (5/5). |
| Jobs ronda final | `[CONFIRMADO]` `d740a49c-dbd4-4bbd-9177-51471b016e42` (2/2), `6e4daa9f-8834-4595-a321-99b61807ba00` (1/1), `64a32fbc-112f-472c-a39a-983a288f5d94` (1/1). |
| Latencia SQL observada | `[CONFIRMADO]` 15 muestras `sql_persist_ms`: min 2283, promedio 2423, p50 2369, p95/max 3045 ms. |
| Salud posterior | `[CONFIRMADO]` `default`, `db2` y Redis en estado `ok`; 5 PHP healthy y 3 workers de persistencia activos. |
| Observabilidad | `[CONFIRMADO]` La prueba revelo y corrigio transiciones de metricas por estado de auditoria y `pending -> completed` directo; Lua real valido `queued=0`, `running=1`, `completed=1` intermedio y `queued=0`, `running=0`, `completed=2` terminal. |
| Gate ODBC real con fault injection | `[PENDIENTE]` Requiere ventana controlada para cortes SQL de apertura y statement; no se simula en produccion. |
| Comparacion de rendimiento | `[PENDIENTE]` No existe medicion pre-cambio equivalente para demostrar el limite relativo de 125%; las cifras absolutas de staging quedan como baseline inicial. |
| Remediacion historica | `[FUERA DE ALCANCE]` Continua en `plans/sdd-sql-incident-remediation.md`. |
