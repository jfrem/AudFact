# SDD - Remediacion del incidente DOWNLOAD_ERROR

| Metadato | Valor |
| --- | --- |
| Estado | `[CONFIRMADO]` Diseno parcial; prohibida la ejecucion de DML hasta resolver la informacion critica. |
| Fecha | `[CONFIRMADO]` 2026-07-30. |
| Repositorio | `[CONFIRMADO]` `C:\Users\USER\Desktop\AudFact`. |
| Politicas | `[CONFIRMADO]` `write-sdd-spec`, `clean-rebuild-policy`, `audfact-sqlsrv-models`, `audfact-audit-gemini`, `audfact-production-ops` y `audfact-docs-sync`. |
| Precondicion | `[CONFIRMADO]` Implementar y desplegar primero `plans/sdd-sql-persistence-resilience.md`. |

## FASE 0 - Descubrimiento Obligatorio

### Inventario de Informacion

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Defecto que origina contaminacion | `[CONFIRMADO]` | `AttachmentDownloadWorker.php:83-102,164-196` transforma cualquier fallo de descarga en `DOWNLOAD_ERROR` y `document_rejected`. |
| Resultado de negocio derivado | `[CONFIRMADO]` | `RulesEvaluationWorker.php:344-379` transforma el rechazo en `INTEGRIDAD_DOCUMENTO`, severidad alta y `approved=false`. |
| Persistencia global | `[CONFIRMADO]` | `AuditResultPersistenceModel.php:115-176` escribe `AudDispEst.Hallazgos` por `FacNro`. |
| Persistencia documental | `[CONFIRMADO]` | `AuditResultPersistenceModel.php:204-386` escribe observacion, estado, reclamacion y usuario en `AdjuntosDispensacion`. |
| Marca de detalle | `[CONFIRMADO]` | La misma transaccion escribe `DisDetUsuAud` y `DisDetFecAud` en `DispensacionDetalleServicio`. |
| Marcador rastreable | `[CONFIRMADO]` | `buildRejectedPolicyResult()` incorpora el texto `DOWNLOAD_ERROR` en la descripcion persistida; el modelo serializa ese payload en `AdjDisObsRec` y `Hallazgos`. |
| Identidad de reproceso | `[CONFIRMADO]` | `AudDispEst.FacSec` conserva `DisId` y `AudDispEst.FacNro` conserva `DisDetNro`. |
| Endpoint que inicia el pipeline desde cero | `[CONFIRMADO]` | `POST /audit/single` crea un `audit_id`, inicializa estado y publica `audit_created`; `AuditController.php:20-116`. |
| Reproceso DLQ actual | `[CONFIRMADO]` | `POST /audit/dlq/reprocess` republica el evento original; `AuditDlqController.php:74-123`. |
| Riesgo de reusar evento contaminado | `[CONFIRMADO]` | Reprocesar `rules_evaluated` evita descarga, extraccion y policy; no corrige el origen del falso resultado. |
| Cache de extraccion | `[CONFIRMADO]` | `DocumentExtractionWorker` usa una llave compuesta por hash documental, contrato y prompt antes de invocar Gemini. |
| Bypass administrativo de cache | `[DESCONOCIDO]` | No se encontro un contrato de reauditoria que obligue una llamada Gemini fresca. |
| Estado previo de filas legacy | `[DESCONOCIDO]` | No se encontro tabla temporal, auditoria de cambios ni snapshot de los valores anteriores de adjuntos y detalle. |
| Ventana exacta del incidente | `[DESCONOCIDO]` | El log citado fue rotado y no existe correlacion completa por factura/job en el workspace actual. |
| Jobs afectados | `[DESCONOCIDO]` | `AuditResultPersistenceModel` no persiste `JobId`; Redis puede haber expirado. |
| Semantica de cuarentena | `[DESCONOCIDO]` | No existe un estado documentado que invalide temporalmente una auditoria sin alterar estados legacy. |
| Backup restaurable por fila | `[DESCONOCIDO]` | No se aporto mecanismo DBA, tabla de respaldo ni ubicacion segura aprobada. |
| Autorizacion de negocio para sobrescribir | `[DESCONOCIDO]` | No consta aprobacion para reemplazar resultados historicos mediante una nueva auditoria. |

### Informacion Faltante Critica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Inventario confirmado de `DisId`/`DisDetNro` afectados | La marca textual permite generar candidatos, pero no prueba por si sola que todos pertenecen a la misma ventana ni que no fueron corregidos despues. | Impide fijar el lote final de mutacion. |
| Mecanismo de cuarentena aprobado | No existe un estado funcional confirmado para excluir resultados mientras se reauditan. | Impide garantizar que un consumidor no use un resultado contaminado durante el proceso. |
| Politica de reextraccion | El endpoint actual puede usar cache de extraccion. | Impide afirmar que cada adjunto fue descargado y enviado nuevamente a Gemini. |
| Backup y restauracion autorizados | No existe una copia restaurable de las filas objetivo ni SQL de restauracion aprobado. | Impide rollback de una reauditoria defectuosa. |
| Aprobacion del dueno de negocio | La reauditoria sobrescribe decisiones sobre soportes y resultado global. | Impide ejecutar cambios de datos de dominio. |

### Informacion Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Ventana UTC exacta | Reduce el costo y facilita correlacion con logs. | Sin ella, el descubrimiento debe buscar el marcador en todo el historico. |
| Zona horaria efectiva de `GETDATE()` | Las fechas persistidas dependen del reloj del servidor SQL y no estan documentadas como UTC. | Los filtros temporales deben permanecer `NULL` hasta confirmacion DBA o convertirse con una regla aprobada. |
| Retencion de telemetria Redis | Permitiria probar etapas recorridas por auditoria. | Su ausencia obliga a evidencia SQL y logs operativos. |
| Cantidad total de candidatos | Define duracion, costo Gemini y concurrencia segura. | No bloquea el query de descubrimiento, pero bloquea el plan de capacidad. |
| Limite de reauditorias simultaneas | SQL y Gemini comparten recursos con produccion. | No se puede fijar una tasa operativa segura. |

### Informacion Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Job original por factura | Es util para el postmortem. | La reauditoria puede identificarse por `DisId` y `DisDetNro`. |
| Causa administrativa de SQL `SHUTDOWN` | Pertenece a RCA de infraestructura. | No cambia la identificacion por `DOWNLOAD_ERROR`. |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
| Ninguno | `[CONFIRMADO]` No se autoriza una mutacion basada en supuestos. | La informacion critica se mantiene como desconocida. | Ningun supuesto habilita DML. |

### Clasificacion de Completitud Inicial

`[CONFIRMADO] Nivel C - Diseno Parcial`.

- `[CONFIRMADO]` La fase de descubrimiento de solo lectura es ejecutable.
- `[CONFIRMADO]` La cuarentena, reextraccion, mutacion y rollback no son implementables de forma segura con la evidencia disponible.

## FASE 1 - Especificacion

### 1. Objetivo

- `[CONFIRMADO]` Identificar resultados que contienen el marcador tecnico `DOWNLOAD_ERROR`.
- `[CONFIRMADO]` Evitar que esos resultados se sigan aceptando como evidencia de integridad documental.
- `[CONFIRMADO]` Reprocesar cada candidato desde `audit_created`, nunca desde `rules_evaluated`.
- `[CONFIRMADO]` Verificar descarga completa y una extraccion valida antes de aceptar el reemplazo.
- `[CONFIRMADO]` Conservar una ruta de rollback por cada fila afectada.
- `[DESCONOCIDO]` El mecanismo concreto de cuarentena, reextraccion forzada y backup aun no esta aprobado.

### 2. Alcance

#### Incluido

- `[CONFIRMADO]` Query de descubrimiento de solo lectura.
- `[CONFIRMADO]` Inventario por `DisId`, `DisDetNro`, fechas y presencia del marcador.
- `[CONFIRMADO]` Reglas que prohiben reprocesar `rules_evaluated` o editar hallazgos manualmente.
- `[CONFIRMADO]` Gates previos a cualquier DML.
- `[INFERIDO]` Flujo objetivo de snapshot, cuarentena, reauditoria y verificacion.

#### Excluido

- `[CONFIRMADO]` Ejecutar `UPDATE`, `DELETE`, `INSERT`, `MERGE` o DDL con el estado actual de esta especificacion.
- `[CONFIRMADO]` Inferir valores legacy anteriores.
- `[CONFIRMADO]` Marcar adjuntos como pendientes sin respaldo confirmado.
- `[CONFIRMADO]` Reprocesar eventos de policy o persistencia.
- `[CONFIRMADO]` Declarar corregido un registro solo porque desaparecio el texto `DOWNLOAD_ERROR`.
- `[CONFIRMADO]` Ejecutar la remediacion antes de desplegar la barrera preventiva.

### 3. Non Goals

- `[CONFIRMADO]` No reparar el motor SQL Server.
- `[CONFIRMADO]` No borrar evidencia del incidente.
- `[CONFIRMADO]` No reutilizar el job original como requisito.
- `[CONFIRMADO]` No confiar en una modificacion manual directa de JSON.
- `[CONFIRMADO]` No ejecutar la remediacion en paralelo sin un limite aprobado.

### 4. Estado Actual

```text
PDO db2 roto
  -> DOWNLOAD_ERROR
  -> document_rejected
  -> INTEGRIDAD_DOCUMENTO
  -> rules_evaluated
  -> AudDispEst.Hallazgos
  -> AdjuntosDispensacion.AdjDisObsRec/estado/reclamacion
  -> DispensacionDetalleServicio.DisDetUsuAud/fecha
```

- `[CONFIRMADO]` La transaccion hace consistente la contaminacion entre las tres superficies.
- `[CONFIRMADO]` El endpoint de reauditoria individual vuelve a iniciar el pipeline.
- `[CONFIRMADO]` Una reauditoria exitosa usa upsert y asignaciones deterministas, por lo que puede reemplazar el estado actual.
- `[DESCONOCIDO]` No se ha confirmado que esa sobrescritura sea la politica de reparacion aceptada por negocio.
- `[DESCONOCIDO]` No existe hoy una prueba obligatoria de cache bypass.

### 5. Estado Objetivo

`[INFERIDO]` El flujo propuesto, sujeto a los gates criticos, es:

```text
descubrimiento read-only
  -> revision humana del inventario
  -> snapshot restaurable
  -> cuarentena aprobada
  -> reauditoria desde audit_created
  -> descarga completa verificada
  -> Gemini fresco o politica de cache aprobada
  -> persistencia transaccional normal
  -> verificacion SQL + estado de auditoria
  -> cierre individual en ledger
```

`[CONFIRMADO]` Un registro no sale de cuarentena si la nueva auditoria termina `failed`, si algun adjunto no fue descargado completamente o si falta evidencia de extraccion aceptada.

### 6. Decisiones Arquitectonicas

| ID | Decision | Alternativas Rechazadas | Justificacion |
| --- | --- | --- | --- |
| ADR-I01 | `[CONFIRMADO]` Ejecutar primero solo descubrimiento. | Corregir mientras se descubre. | Evita mutacion irreversible sin inventario. |
| ADR-I02 | `[CONFIRMADO]` Usar `DOWNLOAD_ERROR` como marcador inicial. | Inferir por severidad o texto generico. | Es la firma exacta del defecto confirmado. |
| ADR-I03 | `[CONFIRMADO]` No restaurar valores previos inventados. | Asignar `P`, `C`, `N` o null por convencion. | El estado anterior no esta disponible. |
| ADR-I04 | `[CONFIRMADO]` No reprocesar `rules_evaluated`. | Usar `/audit/dlq/reprocess` sobre persistencia. | Volveria a persistir el payload contaminado. |
| ADR-I05 | `[CONFIRMADO]` Reprocesar desde `audit_created`. | Reanudar desde extraction/policy. | Obliga a recorrer descarga e integridad. |
| ADR-I06 | `[DESCONOCIDO]` Mecanismo de cuarentena. | No seleccionada. | Falta contrato funcional aprobado. |
| ADR-I07 | `[DESCONOCIDO]` Mecanismo de reextraccion forzada. | No seleccionada. | El cache actual puede evitar una llamada fresca. |
| ADR-I08 | `[DESCONOCIDO]` Forma del backup restaurable. | No seleccionada. | Requiere DBA y politica de datos. |
| ADR-I09 | `[DESCONOCIDO]` Tasa y orden de reauditoria. | No seleccionada. | Depende del volumen y capacidad medidos. |

### 7. Dependencias

| Dependencia | Tipo | Version | Impacto |
| --- | --- | --- | --- |
| SQL Server | base de datos | `[DESCONOCIDO]` | Fuente de candidatos y destino del reemplazo. |
| `AudDispEst` | tabla | `[CONFIRMADO]` | Resultado global y marcador. |
| `AdjuntosDispensacion` | tabla | `[CONFIRMADO]` | Decision documental y segundo marcador. |
| `DispensacionDetalleServicio` | tabla | `[CONFIRMADO]` | Identidad y marca de auditoria. |
| Redis | cola/estado | `[CONFIRMADO]` | Nueva auditoria y telemetria temporal. |
| Gemini | tercero | `[CONFIRMADO]` | Costo y evidencia de reextraccion. |
| Operacion DBA | proceso | `[DESCONOCIDO]` | Backup, restauracion y ventana. |
| Dueno de negocio | gobernanza | `[DESCONOCIDO]` | Autoriza cuarentena y sobrescritura. |

### 8. Invariantes

| Invariante | Enforcement | Validacion |
| --- | --- | --- |
| `[CONFIRMADO]` No se muta una fila sin snapshot restaurable. | Gate operativo. | Ledger enlaza candidato y backup. |
| `[CONFIRMADO]` No se reutiliza un outcome contaminado. | Reproceso desde `audit_created`. | Nuevo `audit_id`. |
| `[CONFIRMADO]` No se acepta una descarga parcial. | SDD preventivo desplegado. | Bytes iguales a `DATALENGTH`. |
| `[CONFIRMADO]` No se acepta `DOWNLOAD_ERROR` como negocio. | Guardas del SDD preventivo. | Persistencia rechaza el marcador. |
| `[CONFIRMADO]` Cada candidato tiene resultado individual. | Ledger de remediacion. | Estado `pending/succeeded/failed/rolled_back`. |
| `[DESCONOCIDO]` Forma persistente del ledger. | No definida. | Bloquea implementacion. |

### 9. Modelo de Datos

`[DESCONOCIDO]` No se ha decidido si el ledger y la cuarentena residiran en SQL, Redis o un repositorio operacional externo aprobado.

#### DDL

`[DESCONOCIDO]` No existe DDL aprobado. Ejecutar DDL con esta revision esta prohibido.

#### Orden de Ejecucion

1. `[CONFIRMADO]` Resolver ADR-I06, ADR-I07, ADR-I08 y ADR-I09.
2. `[CONFIRMADO]` Publicar una revision Nivel A o B con DDL completo si se elige persistencia nueva.
3. `[CONFIRMADO]` Ejecutar solo despues de aprobacion DBA y negocio.

#### Migracion de Datos

| Origen | Transformacion | Destino | Validacion |
| --- | --- | --- | --- |
| `AudDispEst` + adjuntos + detalle | `[INFERIDO]` Snapshot exacto por candidato. | `[DESCONOCIDO]` Repositorio de backup. | Restauracion de prueba. |
| Candidato aprobado | `[INFERIDO]` Nueva auditoria completa. | Mismas tablas mediante modelo normal. | Sin marcador y con evidencia de etapas. |

#### Rollback

`[DESCONOCIDO]` No existe SQL de rollback porque no se ha definido el formato del snapshot. Esta ausencia bloquea DML.

### 10. Contratos

#### Antes

`[CONFIRMADO]` `POST /audit/single` acepta:

```json
{
  "disId": "87723098",
  "disDetNro": "T38250701547"
}
```

`[CONFIRMADO]` No acepta un modo administrativo que fuerce reextraccion ni un identificador de remediacion.

#### Despues

`[DESCONOCIDO]` Falta seleccionar uno de estos contratos y documentarlo en una revision posterior:

1. Herramienta CLI administrativa dentro de la imagen.
2. Endpoint administrativo autenticado con idempotencia y `force_reextract`.
3. Politica aprobada que acepte cache por hash completo y contrato identico.

`[CONFIRMADO]` No se implementa compatibilidad parcial entre esas alternativas.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementacion | Validacion |
| --- | --- | --- | --- |
| IR-01 | Identificar candidatos. | Query read-only incluida. | Revision y conteo. |
| IR-02 | Delimitar lote final. | `[DESCONOCIDO]` Aprobacion inventario. | Firma negocio/DBA. |
| IR-03 | Cuarentena. | `[DESCONOCIDO]` ADR-I06. | Consumidores no ven outcome. |
| IR-04 | Snapshot. | `[DESCONOCIDO]` ADR-I08. | Restore de prueba. |
| IR-05 | Reprocesar desde inicio. | Nuevo `audit_created`. | Nuevo `audit_id`. |
| IR-06 | Forzar evidencia de extraccion. | `[DESCONOCIDO]` ADR-I07. | Telemetria por documento. |
| IR-07 | Evitar duplicados. | `[DESCONOCIDO]` Ledger/idempotencia. | Una ejecucion efectiva por candidato. |
| IR-08 | Verificar reemplazo. | SQL + estado + telemetria. | Checklist individual. |
| IR-09 | Rollback. | `[DESCONOCIDO]` Restauracion desde snapshot. | Comparacion bit a bit. |
| IR-10 | Preservar evidencia. | No borrar registros/logs. | Backup y ledger retenidos. |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| SQL historico | Tres tablas | Critico | Descubrimiento; mutacion aun bloqueada. | Queries del modelo. |
| Pipeline | `audit_created` | Alto | Modo de remediacion por definir. | Endpoint actual no fuerza Gemini. |
| Cache extraction | Redis | Alto | Bypass o politica aprobada. | Cache hit vigente. |
| Frontend/consumidores | Resultados | Alto | Cuarentena por definir. | No existe estado actual. |
| Operaciones | DBA/Gemini | Alto | Ventana, backup y tasa. | Datos faltantes criticos. |
| Seguridad | Datos de pacientes | Alto | Backup protegido y sin logs sensibles. | Guardrails del proyecto. |

### 13. Cambios por Archivo

`[DESCONOCIDO]` No se pueden fijar archivos de implementacion hasta resolver ADR-I06, ADR-I07 y ADR-I08.

| Estado | Ruta completa | Cambio |
| --- | --- | --- |
| `[NEW]` | `C:\Users\USER\Desktop\AudFact\plans\sdd-sql-incident-remediation.md` | Documento de descubrimiento y gates. |
| `[DESCONOCIDO]` | Herramienta o endpoint administrativo | Depende del contrato seleccionado. |
| `[DESCONOCIDO]` | Persistencia de ledger/cuarentena | Depende de la decision de datos. |
| `[MODIFY]` | `C:\Users\USER\Desktop\AudFact\plans\changelog.md` | Registrar el diseno parcial. |

### 14. Plan de Migracion

#### Prerequisitos

1. `[CONFIRMADO]` Desplegar y validar el SDD preventivo.
2. `[CONFIRMADO]` Resolver todas las filas de informacion critica.
3. `[CONFIRMADO]` Elevar este documento a Nivel A o B.
4. `[CONFIRMADO]` Obtener aprobacion escrita de DBA, seguridad y negocio.

#### Ejecucion

`[CONFIRMADO]` Con el estado Nivel C solo se autoriza la siguiente consulta de descubrimiento:

```sql
DECLARE @IncidentStartDbTime datetime2(0) = NULL;
DECLARE @IncidentEndDbTime   datetime2(0) = NULL;

WITH CandidateRows AS (
    SELECT
        ade.FacSec AS DisId,
        ade.FacNro AS DisDetNro,
        ade.EstadoDetallado,
        ade.FechaCreacion,
        ade.FechaActualizacion,
        CASE
            WHEN CONVERT(nvarchar(max), ade.Hallazgos)
                 LIKE N'%DOWNLOAD_ERROR%' THEN 1
            ELSE 0
        END AS MarkerInAudit,
        CASE
            WHEN CONVERT(nvarchar(max), a.AdjDisObsRec)
                 LIKE N'%DOWNLOAD_ERROR%' THEN 1
            ELSE 0
        END AS MarkerInAttachment,
        a.DisDetId,
        a.AdjDisId,
        a.AdjDisNom,
        a.AdjDisEstSop,
        a.AdjDisRec,
        a.AdjDisUsuAudi,
        a.AdJDisFecAudi,
        a.AdjDisUsuRec,
        a.AdjDisFecRec
    FROM Discolnet.dbo.AudDispEst AS ade WITH (NOLOCK)
    LEFT JOIN DispensacionDetalleServicio AS d WITH (NOLOCK)
        ON d.DisDetNro = ade.FacNro
       AND CONVERT(nvarchar(320), d.DisId) = ade.FacSec
    LEFT JOIN AdjuntosDispensacion AS a WITH (NOLOCK)
        ON a.DisId = d.DisId
       AND a.DisDetId = d.DisDetId
    WHERE
        (
            CONVERT(nvarchar(max), ade.Hallazgos)
                LIKE N'%DOWNLOAD_ERROR%'
            OR CONVERT(nvarchar(max), a.AdjDisObsRec)
                LIKE N'%DOWNLOAD_ERROR%'
        )
        AND (
            @IncidentStartDbTime IS NULL
            OR COALESCE(ade.FechaActualizacion, ade.FechaCreacion)
                >= @IncidentStartDbTime
        )
        AND (
            @IncidentEndDbTime IS NULL
            OR COALESCE(ade.FechaActualizacion, ade.FechaCreacion)
                < @IncidentEndDbTime
        )
)
SELECT *
FROM CandidateRows
ORDER BY DisDetNro, AdjDisId;
```

`[CONFIRMADO]` Esta query no autoriza el uso de `NOLOCK` para el snapshot definitivo; solo produce un inventario inicial no bloqueante.

`[CONFIRMADO]` Los dos parametros de tiempo permanecen `NULL` hasta que DBA confirme la zona horaria de las columnas alimentadas con `GETDATE()`.

#### Validaciones Previas

- `[CONFIRMADO]` La query se ejecuta con una cuenta read-only.
- `[CONFIRMADO]` El resultado no se copia a logs de aplicacion.
- `[CONFIRMADO]` El inventario se almacena en ubicacion protegida aprobada.
- `[CONFIRMADO]` Un revisor valida falsos positivos del propio query.

#### Validaciones Posteriores

`[DESCONOCIDO]` No aplican hasta definir e implementar la remediacion.

#### Rollback

`[DESCONOCIDO]` Bloqueado por ADR-I08.

### 15. Casos Limite

| Condicion | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Marcador solo en `Hallazgos` | Entra al inventario. | `MarkerInAudit=1`. |
| Marcador solo en adjunto | Entra al inventario. | `MarkerInAttachment=1`. |
| Registro ya corregido sin marcador | No entra por firma textual. | Requiere correlacion adicional si la ventana lo exige. |
| Varias filas de adjunto por factura | Se agrupan bajo mismo `DisId/DisDetNro`. | Una unidad de reauditoria. |
| Redis del job expiro | Se usa identidad SQL. | No bloquea descubrimiento. |
| Reauditoria termina failed | Permanece en cuarentena. | Ledger no marca success. |
| Cache hit | `[DESCONOCIDO]` Aceptacion depende de ADR-I07. | Gate no resuelto. |
| Gemini retorna rechazo legitimo | Se acepta solo con descarga completa y contrato preventivo. | Razon permitida, no tecnica. |
| Rollback no probado | No se ejecuta reauditoria. | Cero DML. |

### 16. Testing

#### Nuevos Tests

`[DESCONOCIDO]` Los tests de implementacion dependen del contrato de remediacion aun no seleccionado.

#### Tests Modificados

`[CONFIRMADO]` Ninguno en la fase read-only.

#### Tests Eliminados

`[CONFIRMADO]` Ninguno.

#### Verificaciones Manuales

1. `[CONFIRMADO]` Ejecutar el query read-only y revisar una muestra con DBA y negocio.
2. `[CONFIRMADO]` Comparar conteo por `DisDetNro` con logs/telemetria conservados.
3. `[DESCONOCIDO]` Probar restauracion completa del snapshot.
4. `[DESCONOCIDO]` Probar una reauditoria canario con extraccion forzada.

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigacion |
| --- | --- | --- | --- |
| Sobrescribir una decision legitima posterior. | Consistencia de datos | Critica | Inventario revisado, snapshot y control de fechas. |
| Inventar estado previo. | Migracion | Critica | Prohibicion explicita de DML sin backup. |
| Cache oculta una nueva llamada Gemini. | Tecnico | Alta | Resolver ADR-I07. |
| Consumidor usa resultado durante reparacion. | Operativo | Alta | Resolver cuarentena antes de ejecutar. |
| Reauditoria masiva satura SQL/Gemini. | Rendimiento | Alta | Medir volumen y fijar tasa. |
| Backup contiene datos sensibles. | Seguridad | Alta | Ubicacion aprobada, acceso minimo y retencion definida. |
| Reprocesar evento equivocado repite contaminacion. | Consistencia | Alta | Solo iniciar desde `audit_created`. |

### 18. Criterios de Aceptacion

`[CONFIRMADO]` Esta revision alcanza Nivel C y solo acepta la fase de descubrimiento cuando:

1. La query ejecuta con permisos read-only.
2. El resultado contiene `DisId`, `DisDetNro`, marcadores y filas documentales.
3. No se ejecuta DML ni DDL.
4. El inventario queda protegido y revisado.

`[DESCONOCIDO]` La remediacion completa no tiene criterios finales verificables hasta resolver:

1. Cuarentena.
2. Backup y rollback.
3. Reextraccion/cache.
4. Idempotencia y ledger.
5. Tasa operativa.
6. Aprobacion de negocio.

## FASE 2 - Auditoria de Consistencia

| Verificacion | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas estan definidas | PASS | Las tres tablas afectadas estan identificadas. |
| Todas las columnas existen | PASS | Las columnas del query aparecen en modelos y esquema documentado. |
| Todos los contratos documentados | FAIL | El contrato administrativo de remediacion no esta seleccionado. |
| Todos los requisitos tienen trazabilidad | PASS | IR-01 a IR-10 estan mapeados, incluidos los bloqueados. |
| Todos los consumidores analizados | FAIL | La poblacion de consumidores que requiere cuarentena no esta inventariada. |
| Todas las migraciones tienen rollback | FAIL | No existe formato de snapshot ni SQL de restauracion. |
| Todas las referencias estan definidas | FAIL | Herramienta, ledger y cuarentena no tienen rutas. |
| Toda compatibilidad tiene evidencia | FAIL | La politica de cache/reextraccion no esta aprobada. |
| Todos los criterios son verificables | FAIL | Los criterios de remediacion final dependen de decisiones criticas. |

## FASE 3 - Auditoria Arquitectonica

| Pregunta | Resultado |
| --- | --- |
| Existe alguna decision arquitectonica implicita? | Si; cuarentena, backup, cache y tasa estan abiertas. |
| Existe algun contrato sin documentar? | Si; herramienta administrativa. |
| Existe algun consumidor no analizado? | Si; consumidores de resultados historicos. |
| Existe alguna migracion sin rollback? | Si; la mutacion propuesta aun no tiene restauracion. |
| Existe algun dato persistido sin migracion? | Si; ledger/cuarentena no estan definidos. |
| Existe alguna afirmacion sin evidencia? | No; todo faltante esta marcado como desconocido. |
| Existen referencias huerfanas? | Si; herramienta y repositorio de backup no existen. |
| Dos implementadores producirian soluciones diferentes? | Si |

## FASE 4 - Resultado Final

### Nivel de Completitud

`[CONFIRMADO] Nivel C - Diseno Parcial`.

### Definicion de Completitud

- `[CONFIRMADO]` El descubrimiento read-only es util y ejecutable.
- `[CONFIRMADO]` La remediacion no debe implementarse ni ejecutarse con esta revision.
- `[CONFIRMADO]` El documento debe revisarse a Nivel A o B despues de resolver las cinco filas criticas.
