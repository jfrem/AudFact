# SDD - Endpoint `GET /audit/jobs/{jobId}/failures`

Estado: Borrador para revisión y aprobación  
Fecha: 2026-06-17  
Solicitud origen: `crea el sdd.md para revision y aprobacion`

## FASE 0 — Descubrimiento Obligatorio

### Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Endpoint solicitado: `GET /audit/jobs/{jobId}/failures`. | Confirmado | [CONFIRMADO] Solicitud del usuario: "Crea el plan ... de implementacion del endpint GET /audit/jobs/{jobId}/failures". |
| Ruta actual de estado de job: `GET /audit/jobs/{jobId}`. | Confirmado | [CONFIRMADO] `app/Routes/web.php:39` registra `/audit/jobs/{jobId}` hacia `AuditController::jobStatus`. |
| El router evalúa rutas registradas por método en orden de inserción. | Confirmado | [CONFIRMADO] `core/Router.php` itera `foreach ($this->routes[$method] as $route => $routeObj)` antes de ejecutar el controlador. |
| `AuditController::jobStatus()` valida UUID v4, lee `BatchJobStore::getJob()` y responde 422, 404, 503 o 200. | Confirmado | [CONFIRMADO] `app/Controllers/AuditController.php:422-442`. |
| `AuditController::formatJobStatus()` expone `audit_id`, `dis_det_nro` y `status` por auditoría, sin detalle técnico de fallo. | Confirmado | [CONFIRMADO] `app/Controllers/AuditController.php:445-478`. |
| `BatchJobStore` conserva estado de job en Redis con TTL de 86400 segundos. | Confirmado | [CONFIRMADO] `app/Services/Audit/Pipeline/BatchJobStore.php:22`, `:63`, `:112`, `:131`, `:169`. |
| `BatchJobStore::registerAuditInJob()` guarda `auditId`, `dis_det_nro`, `dis_id` y `reservation_token` dentro del job. | Confirmado | [CONFIRMADO] `app/Services/Audit/Pipeline/BatchJobStore.php:88-116`. |
| `BatchJobStore::markAuditCompletedInJob()` marca auditorías terminales dentro del job y actualiza métricas. | Confirmado | [CONFIRMADO] `app/Services/Audit/Pipeline/BatchJobStore.php:160-176`. |
| `AuditStateStore` conserva estado de auditoría en Redis con TTL de 86400 segundos. | Confirmado | [CONFIRMADO] `app/Services/Audit/Pipeline/AuditStateStore.php:29`, `:68-80`, `:97`, `:230`. |
| `AuditStateStore::initAudit()` contiene `audit_id`, `status`, `dis_det_nro`, `job_id`, `fac_nit_sec`, `dis_id`, contadores documentales y `documents`. | Confirmado | [CONFIRMADO] `app/Services/Audit/Pipeline/AuditStateStore.php:41-61`. |
| Los fallos finales de persistencia guardan `detail_error`, `failed_stage`, `audit_result`, `audit_result_data` y `document_decisions` en estado Redis. | Confirmado | [CONFIRMADO] `app/Services/Audit/Pipeline/AuditAggregationWorker.php:219-241`. |
| Los fallos por DLQ guardan `detail_error`, `failed_stage` y `failed_event_type` en estado Redis. | Confirmado | [CONFIRMADO] `app/Services/Audit/Pipeline/AuditEventConsumer.php:446-475`. |
| Existen tests de controller para `jobStatus`. | Confirmado | [CONFIRMADO] `tests/Controllers/AuditControllerTest.php:204-261`. |
| La documentación de endpoints actual incluye `GET /audit/jobs/{job_id}` y no incluye `/audit/jobs/{jobId}/failures`. | Confirmado | [CONFIRMADO] `plans/api-endpoints.md:255-270` documenta el endpoint actual; búsqueda local no encontró `failures` documentado. |
| La skill `audfact-api-rest` lista 27 endpoints y contiene `/audit/jobs/{jobId}`. | Confirmado | [CONFIRMADO] `.agent/skills/audfact-api-rest/SKILL.md:18`, `:34`, `:60`. |
| La skill `audfact-audit-gemini` lista `GET /audit/jobs/{job_id}` como endpoint del pipeline async. | Confirmado | [CONFIRMADO] `.agent/skills/audfact-audit-gemini/SKILL.md:64-68`. |

### Información Faltante Crítica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Ninguna información crítica bloquea el diseño backend MVP. | [INFERIDO] El controlador, ruta actual, stores Redis, campos de fallo y pruebas base existen. | [INFERIDO] La implementación es determinística si se aprueban los supuestos de esta especificación. |

### Información Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Política final de autorización administrativa para este endpoint. | [DESCONOCIDO] Los endpoints `/audit/jobs/{jobId}` y `/audit/dlq` existen sin una regla de auth específica visible en la ruta explorada. | [INFERIDO] La versión aprobada mantendrá el patrón actual; una política de auth futura requerirá otro SDD o una revisión de seguridad. |
| Consumidor frontend del endpoint en el panel de administración. | [DESCONOCIDO] La solicitud actual pide el endpoint y el SDD, no una vista en Next.js. | [INFERIDO] La implementación backend será usable por API y el panel podrá integrarse después con contrato estable. |
| Longitud máxima exacta de `detail_error` expuesto. | [DESCONOCIDO] No existe contrato actual para errores detallados por job. | [INFERIDO] Este SDD fija truncado a 500 caracteres para reducir exposición accidental y mantener utilidad operativa. |

### Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Paginación de fallos. | [INFERIDO] El endpoint consulta fallos de un job individual y no introduce búsqueda global. | [INFERIDO] La primera versión retorna todos los fallos del job en un arreglo `items`. |
| Persistencia histórica SQL de fallos. | [INFERIDO] El objetivo solicitado es consultar datos vivos del job; la evidencia local muestra estado Redis con TTL. | [INFERIDO] Los fallos expiran con las llaves Redis actuales. |
| Exportación CSV o descarga de reporte. | [DESCONOCIDO] La solicitud no menciona exportación. | [INFERIDO] La omisión no afecta la consulta JSON del endpoint. |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
| A1 | [INFERIDO] El endpoint leerá únicamente Redis y no persistirá datos nuevos en SQL Server. | [CONFIRMADO] `BatchJobStore` y `AuditStateStore` contienen la información requerida en Redis. | Medio: los datos no estarán disponibles después del TTL actual. |
| A2 | [INFERIDO] El endpoint devolverá auditorías con `status = failed`; no incluirá `error`, `manual_review` ni `completed_with_errors` como items de fallo. | [CONFIRMADO] `AuditStateStore::AUDIT_STATUS_FAILED` representa error fatal de pipeline. | Bajo: algunos estados no fatales seguirán consultándose por endpoints actuales. |
| A3 | [INFERIDO] `detail_error` será sanitizado, normalizado a una línea y truncado a 500 caracteres. | [CONFIRMADO] Los fallos guardan mensajes crudos con `$error->getMessage()`. | Bajo: un mensaje truncado puede requerir consulta a logs para diagnóstico profundo. |
| A4 | [INFERIDO] No se agregará autenticación nueva en esta tarea; el endpoint seguirá el patrón de los endpoints `/audit/*` actuales. | [CONFIRMADO] La ruta actual `/audit/jobs/{jobId}` no muestra middleware en `app/Routes/web.php`. | Medio: si el panel requiere control de acceso fuerte, se planificará un cambio separado. |
| A5 | [INFERIDO] No se implementará UI en Next.js en esta tarea. | [CONFIRMADO] La solicitud nombra el endpoint y el archivo SDD. | Bajo: el frontend consumirá el contrato en una tarea posterior. |
| A6 | [INFERIDO] La documentación y skills de API/pipeline se actualizarán cuando se implemente el endpoint. | [CONFIRMADO] `audfact-docs-sync` exige sincronizar documentación y skills después de cambios de código. | Bajo: omitirlo causaría drift documental. |

### Clasificación de Completitud Inicial

[INFERIDO] Nivel B — Implementable con Supuestos Declarados.  
[INFERIDO] La especificación no requiere descubrimiento adicional para implementar el MVP backend, pero depende de aprobar los supuestos A1-A6.

## FASE 1 — Especificación

### 1. Objetivo

- [CONFIRMADO] El sistema ya permite consultar el estado agregado de un job batch con `GET /audit/jobs/{jobId}`.
- [CONFIRMADO] La respuesta actual de `jobStatus` incluye conteo `failed` y una lista de auditorías con `audit_id`, `dis_det_nro` y `status`, sin `detail_error`, `failed_stage` ni `failed_event_type`.
- [CONFIRMADO] Los fallos técnicos de auditoría se guardan en Redis mediante `AuditStateStore::completeAudit()` en escenarios de persistencia final y DLQ.
- [INFERIDO] El problema actual es que un operador puede ver cuántas auditorías fallaron en el job, pero no puede consultar desde API el motivo técnico por cada `DisDetNro` fallido.
- [INFERIDO] La causa raíz es la ausencia de un endpoint de lectura que combine el índice de auditorías del job con el estado detallado de cada auditoría fallida.
- [INFERIDO] El resultado esperado es un endpoint administrativo que devuelva una tabla JSON de fallos por job con identificadores operativos, etapa fallida, tipo de evento fallido y detalle sanitizado del error.

### 2. Alcance

#### Incluido

- [CONFIRMADO] Crear el documento raíz `sdd.md` como especificación para revisión y aprobación.
- [INFERIDO] Agregar `GET /audit/jobs/{jobId}/failures` al backend PHP.
- [INFERIDO] Reutilizar `BatchJobStore` para ubicar auditorías asociadas al job.
- [INFERIDO] Reutilizar `AuditStateStore` para recuperar detalles de auditorías fallidas.
- [INFERIDO] Retornar respuesta JSON estándar mediante `Core\Response::success()` y `Core\Response::error()`.
- [INFERIDO] Agregar pruebas unitarias de controller para contrato exitoso y errores.
- [INFERIDO] Actualizar documentación de endpoints y skills afectadas por el nuevo endpoint.

#### Excluido

- [INFERIDO] No crear tablas SQL ni migraciones.
- [INFERIDO] No modificar el pipeline de workers ni la semántica de estados.
- [INFERIDO] No modificar `GET /audit/jobs/{jobId}`.
- [INFERIDO] No agregar reproceso de fallos desde este endpoint.
- [INFERIDO] No implementar UI en el panel de administración.
- [INFERIDO] No consultar producción ni Redis remoto como parte de la implementación.

### 3. Non Goals

- [INFERIDO] El cambio no convertirá Redis en histórico permanente.
- [INFERIDO] El cambio no reemplazará `GET /audit/dlq`.
- [INFERIDO] El cambio no expondrá payloads documentales, BLOBs, base64 ni respuestas completas de Gemini.
- [INFERIDO] El cambio no alterará TTLs actuales de job o auditoría.
- [INFERIDO] El cambio no introducirá filtros globales por fecha, EPS o `DisDetNro`.

### 4. Estado Actual

- [CONFIRMADO] `app/Routes/web.php` registra `GET /audit/jobs/{jobId}` y no registra `GET /audit/jobs/{jobId}/failures`.
- [CONFIRMADO] `AuditController::jobStatus()` usa `AuditEvent::isUuidV4()` para validar `jobId`.
- [CONFIRMADO] `AuditController::jobStatus()` captura `RuntimeException` del store y responde `503` con mensaje genérico.
- [CONFIRMADO] `BatchJobStore::getJob()` lee la key Redis `job:{jobId}:state` y retorna `null` si no existe.
- [CONFIRMADO] `AuditStateStore::getAudit()` lee la key Redis `audit:{auditId}:state` y retorna `null` si no existe.
- [CONFIRMADO] `AuditAggregationWorker::handleFinalFailure()` guarda `detail_error` y `failed_stage = final_persistence`.
- [CONFIRMADO] `AuditEventConsumer::finalizeDeadLetterAudit()` guarda `detail_error`, `failed_stage` y `failed_event_type`.
- [CONFIRMADO] El endpoint actual de job no lee `AuditStateStore`; solo formatea el estado del job.
- [INFERIDO] La información requerida existe durante el TTL Redis cuando la auditoría fallida conserva su estado.

### 5. Estado Objetivo

- [INFERIDO] `GET /audit/jobs/{jobId}/failures` validará `jobId` como UUID v4.
- [INFERIDO] El endpoint consultará el job con `BatchJobStore::getJob($jobId)`.
- [INFERIDO] Si el job no existe, el endpoint responderá `404` con `No se encontró el job solicitado`.
- [INFERIDO] Si Redis falla al leer el job o una auditoría, el endpoint responderá `503` con `No se pudieron consultar los fallos del job`.
- [INFERIDO] El endpoint recorrerá `state['audits']` y seleccionará solo entradas con `status = failed`.
- [INFERIDO] Por cada auditoría fallida, el endpoint consultará `AuditStateStore::getAudit($auditId)`.
- [INFERIDO] Si la auditoría fallida ya expiró, el item se devolverá con `audit_state_available = false` y sin `detail_error`.
- [INFERIDO] Si la auditoría existe, el item se devolverá con campos operativos sanitizados y `audit_state_available = true`.
- [INFERIDO] La respuesta agregada incluirá `job_id`, `status`, `total`, `failed`, `failures_count` e `items`.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| D1 | [INFERIDO] Registrar `/audit/jobs/{jobId}/failures` antes de `/audit/jobs/{jobId}`. | [INFERIDO] Registrar la ruta después del endpoint genérico. | [CONFIRMADO] El router evalúa rutas en orden; registrar primero la ruta específica evita ambigüedad operativa. |
| D2 | [INFERIDO] Implementar el método en `AuditController` y no crear un controlador nuevo. | [INFERIDO] Crear `AuditJobFailuresController`. | [CONFIRMADO] `AuditController` ya contiene `jobStatus`, `status`, `async` y builders para `BatchJobStore` y `AuditStateStore`. |
| D3 | [INFERIDO] Leer primero `BatchJobStore` y luego `AuditStateStore`. | [INFERIDO] Escanear Redis por patrón de auditorías. | [CONFIRMADO] El job ya contiene el mapa `audits`; escanear Redis ampliaría costo y superficie operativa. |
| D4 | [INFERIDO] Exponer solo auditorías `failed`. | [INFERIDO] Mezclar `error`, `manual_review` y `failed`. | [CONFIRMADO] `AUDIT_STATUS_FAILED` representa fallo fatal de pipeline; otros estados tienen significado funcional distinto. |
| D5 | [INFERIDO] Sanitizar y truncar `detail_error` a 500 caracteres. | [INFERIDO] Exponer el mensaje crudo completo. | [CONFIRMADO] Los stores guardan `$error->getMessage()` crudo; el API público no debe exponer trazas o payloads internos. |
| D6 | [INFERIDO] No persistir histórico SQL. | [INFERIDO] Crear tabla de fallos por job. | [CONFIRMADO] La información vive en Redis y el requerimiento nombra un endpoint por `jobId`, no un reporte histórico. |
| D7 | [INFERIDO] No agregar UI en esta implementación. | [INFERIDO] Crear pantalla del panel junto con el endpoint. | [CONFIRMADO] La solicitud actual pide el SDD y el endpoint. |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| `Core\Router` | API interna | [CONFIRMADO] Sin versión explícita local. | [CONFIRMADO] Requiere registrar la ruta específica antes de la ruta genérica. |
| `Core\Response` | API interna | [CONFIRMADO] Sin versión explícita local. | [CONFIRMADO] Mantiene el wrapper JSON `success`, `message`, `data`. |
| `AuditEvent` | Value object interno | [CONFIRMADO] Sin versión explícita local. | [CONFIRMADO] Provee validación UUID v4 ya usada por `jobStatus`. |
| `BatchJobStore` | Redis store | [CONFIRMADO] TTL 86400 segundos. | [CONFIRMADO] Fuente del índice de auditorías por job. |
| `AuditStateStore` | Redis store | [CONFIRMADO] TTL 86400 segundos. | [CONFIRMADO] Fuente del detalle técnico de auditoría fallida. |
| Redis | Infraestructura | [CONFIRMADO] Configurado por `REDIS_HOST`, `REDIS_PORT`, `REDIS_PREFIX` en AGENTS.md. | [CONFIRMADO] Si Redis no está disponible, el endpoint responderá 503. |
| PHPUnit | Testing | [CONFIRMADO] Configurado por `phpunit.xml`. | [INFERIDO] Validará controller tests focalizados. |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| [CONFIRMADO] Todas las respuestas de controller usan `Core\Response`. | [INFERIDO] `jobFailures()` llamará `Response::success()` o `Response::error()`. | [INFERIDO] Tests capturarán `HttpResponseException` como los tests actuales. |
| [CONFIRMADO] Los parámetros de ruta tienen longitud máxima de 255 caracteres en `Core\Router`. | [CONFIRMADO] `Core\Router::execute()` valida longitud antes de llamar el controlador. | [INFERIDO] Test de UUID inválido cubre entrada no válida a nivel controller. |
| [CONFIRMADO] `jobId` debe ser UUID v4 para jobs async. | [INFERIDO] `jobFailures()` reutilizará `AuditEvent::isUuidV4()`. | [INFERIDO] Test espera HTTP 422 para `not-a-uuid`. |
| [CONFIRMADO] Los TTLs de Redis para job y auditoría son 86400 segundos. | [INFERIDO] La implementación no tocará `JOB_TTL_SECONDS` ni `AUDIT_TTL_SECONDS`. | [INFERIDO] Revisión de diff confirma ausencia de cambios en constantes. |
| [INFERIDO] El endpoint no expondrá `documents`, `audit_result_data`, `document_decisions`, `original_event`, `fac_nit_sec` ni `reservation_token`. | [INFERIDO] Formatter allowlist devolverá solo campos definidos en contrato. | [INFERIDO] Test verificará ausencia de campos internos en la respuesta. |

### 9. Modelo de Datos

[CONFIRMADO] Sin impacto en persistencia SQL Server.  
[CONFIRMADO] La implementación propuesta no crea tablas, columnas, índices, constraints, foreign keys, triggers, vistas ni procedimientos almacenados.  
[CONFIRMADO] La implementación propuesta no modifica modelos PDO ni queries SQL.

#### DDL

[CONFIRMADO] No aplica. No existe DDL para este cambio.

#### Orden de Ejecución

[CONFIRMADO] No aplica. No existe migración SQL para ejecutar.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
| [CONFIRMADO] No aplica. | [CONFIRMADO] No aplica. | [CONFIRMADO] No aplica. | [CONFIRMADO] Revisión de diff sin archivos de migración ni SQL. |

#### Rollback

[CONFIRMADO] No aplica rollback SQL.  
[INFERIDO] El rollback funcional consiste en retirar la ruta nueva y el método nuevo, y revertir tests/documentación del endpoint.

### 10. Contratos

#### Antes

[CONFIRMADO] No existe `GET /audit/jobs/{jobId}/failures` registrado en `app/Routes/web.php`.

[CONFIRMADO] Contrato actual relacionado:

```http
GET /audit/jobs/{jobId}
```

```json
{
  "success": true,
  "message": "Estado del job",
  "data": {
    "job_id": "uuid",
    "status": "completed_with_errors",
    "total": 100,
    "done": 97,
    "failed": 3,
    "pending": 0,
    "avg_duration_ms": 1200,
    "accumulated_duration_ms": 120000,
    "throughput_per_sec": 0.83,
    "created_at": "2026-06-17T10:00:00Z",
    "updated_at": "2026-06-17T10:30:00Z",
    "audits": [
      {
        "audit_id": "uuid",
        "dis_det_nro": "X07260604295",
        "status": "failed"
      }
    ]
  }
}
```

#### Después

[INFERIDO] Nuevo contrato:

```http
GET /audit/jobs/{jobId}/failures
```

[INFERIDO] Respuesta HTTP 200:

```json
{
  "success": true,
  "message": "Fallos del job",
  "data": {
    "job_id": "4fd9c0c0-1111-4aaa-8bbb-123456789abc",
    "status": "completed_with_errors",
    "total": 100,
    "failed": 3,
    "failures_count": 3,
    "items": [
      {
        "audit_id": "7be54f30-2222-4aaa-8bbb-123456789abc",
        "dis_det_nro": "X07260604295",
        "dis_id": "123456",
        "status": "failed",
        "audit_state_available": true,
        "failed_stage": "final_persistence",
        "failed_event_type": null,
        "detail_error": "No se encontró AdjuntosDispensacion para documento AUTORIZACION",
        "docs_total": 2,
        "docs_done": 1,
        "docs_extracted": 1,
        "docs_evaluated": 0,
        "docs_rejected": 0,
        "completed_at": "2026-06-17T10:30:00Z",
        "updated_at": "2026-06-17T10:30:00Z"
      }
    ]
  }
}
```

[INFERIDO] Respuesta HTTP 200 sin fallos:

```json
{
  "success": true,
  "message": "Fallos del job",
  "data": {
    "job_id": "4fd9c0c0-1111-4aaa-8bbb-123456789abc",
    "status": "completed",
    "total": 10,
    "failed": 0,
    "failures_count": 0,
    "items": []
  }
}
```

[INFERIDO] Respuesta HTTP 422:

```json
{
  "success": false,
  "message": "jobId inválido"
}
```

[INFERIDO] Respuesta HTTP 404:

```json
{
  "success": false,
  "message": "No se encontró el job solicitado"
}
```

[INFERIDO] Respuesta HTTP 503:

```json
{
  "success": false,
  "message": "No se pudieron consultar los fallos del job"
}
```

[INFERIDO] Campos agregados por el nuevo endpoint:

| Campo | Tipo | Regla |
| --- | --- | --- |
| `job_id` | string | [INFERIDO] Copiado desde estado del job. |
| `status` | string | [INFERIDO] Copiado desde estado del job. |
| `total` | integer | [INFERIDO] Copiado desde estado del job con default `0`. |
| `failed` | integer | [INFERIDO] Copiado desde estado del job con default `0`. |
| `failures_count` | integer | [INFERIDO] Conteo de items retornados. |
| `items` | array | [INFERIDO] Lista de auditorías con `status = failed`. |
| `items[].audit_id` | string | [INFERIDO] Key del mapa `audits`. |
| `items[].dis_det_nro` | string | [INFERIDO] Valor del job o del estado de auditoría. |
| `items[].dis_id` | string|null | [INFERIDO] Valor del job o del estado de auditoría. |
| `items[].status` | string | [INFERIDO] Siempre `failed` para items incluidos. |
| `items[].audit_state_available` | boolean | [INFERIDO] `true` si `AuditStateStore::getAudit()` devuelve array; `false` si devuelve null. |
| `items[].failed_stage` | string|null | [INFERIDO] Valor sanitizado desde estado de auditoría. |
| `items[].failed_event_type` | string|null | [INFERIDO] Valor sanitizado desde estado de auditoría. |
| `items[].detail_error` | string|null | [INFERIDO] Mensaje sanitizado, en una línea, máximo 500 caracteres. |
| `items[].docs_total` | integer|null | [INFERIDO] Contador desde estado de auditoría disponible. |
| `items[].docs_done` | integer|null | [INFERIDO] Contador desde estado de auditoría disponible. |
| `items[].docs_extracted` | integer|null | [INFERIDO] Contador desde estado de auditoría disponible. |
| `items[].docs_evaluated` | integer|null | [INFERIDO] Contador desde estado de auditoría disponible. |
| `items[].docs_rejected` | integer|null | [INFERIDO] Contador desde estado de auditoría disponible. |
| `items[].completed_at` | string|null | [INFERIDO] Timestamp terminal si existe en job o estado de auditoría. |
| `items[].updated_at` | string|null | [INFERIDO] Timestamp del estado de auditoría si existe. |

[INFERIDO] Compatibilidad backward: ningún contrato existente cambia.  
[INFERIDO] Compatibilidad forward: consumidores futuros pueden agregar filtros o UI sin romper el contrato base si preservan `items`.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| R1 | [CONFIRMADO] Crear `sdd.md` para revisión y aprobación. | [INFERIDO] Archivo raíz `sdd.md` con esta especificación. | [INFERIDO] Verificar existencia del archivo. |
| R2 | [INFERIDO] Exponer fallos de un job por API. | [INFERIDO] Ruta `GET /audit/jobs/{jobId}/failures` y método `AuditController::jobFailures()`. | [INFERIDO] Controller test HTTP 200 con items fallidos. |
| R3 | [INFERIDO] Validar `jobId`. | [INFERIDO] Reutilizar `AuditEvent::isUuidV4()`. | [INFERIDO] Controller test HTTP 422 con `not-a-uuid`. |
| R4 | [INFERIDO] Manejar job inexistente o expirado. | [INFERIDO] `BatchJobStore::getJob()` null produce 404. | [INFERIDO] Controller test HTTP 404. |
| R5 | [INFERIDO] Evitar exposición de payloads internos. | [INFERIDO] Formatter allowlist y sanitización de strings. | [INFERIDO] Test confirma ausencia de `documents`, `audit_result_data`, `document_decisions`, `original_event`. |
| R6 | [INFERIDO] Mantener TTL y persistencia actuales. | [INFERIDO] No modificar stores ni constantes TTL. | [INFERIDO] Revisión de diff y ausencia de migraciones. |
| R7 | [INFERIDO] Documentar el endpoint. | [INFERIDO] Actualizar `plans/api-endpoints.md` y skills afectadas. | [INFERIDO] Revisión documental post-implementación. |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `app/Routes/web.php` | `Core\Router` | [INFERIDO] Nueva ruta GET específica. | [INFERIDO] Registrar `/audit/jobs/{jobId}/failures` antes de `/audit/jobs/{jobId}`. | [CONFIRMADO] `core/Router.php` evalúa rutas en orden. |
| `AuditController` | `BatchJobStore`, `AuditStateStore`, `AuditEvent`, `Response`, `Logger` | [INFERIDO] Nuevo método de lectura y formatter privado. | [INFERIDO] Agregar `jobFailures()`, `formatJobFailures()`, `formatFailureItem()` y `sanitizeFailureDetail()`. | [CONFIRMADO] Builders existentes en `AuditController.php:537-549`. |
| `BatchJobStore` | Redis | [CONFIRMADO] Sin cambio de código esperado. | [CONFIRMADO] Reutilizar `getJob()`. | [CONFIRMADO] `BatchJobStore.php:72-80`. |
| `AuditStateStore` | Redis | [CONFIRMADO] Sin cambio de código esperado. | [CONFIRMADO] Reutilizar `getAudit()`. | [CONFIRMADO] `AuditStateStore.php:72-80`. |
| `tests/Controllers/AuditControllerTest.php` | PHPUnit | [INFERIDO] Nuevas pruebas de contrato. | [INFERIDO] Extender stubs para devolver estados de auditoría por `auditId`. | [CONFIRMADO] Tests actuales usan `TestableAuditController` y stubs. |
| `plans/api-endpoints.md` | Documentación API | [INFERIDO] Nuevo endpoint documentado. | [INFERIDO] Agregar sección después de `GET /audit/jobs/{job_id}`. | [CONFIRMADO] Documento contiene sección actual en `plans/api-endpoints.md:255`. |
| `.agent/skills/audfact-api-rest/SKILL.md` | Skill de API | [INFERIDO] Conteo y tabla de endpoints cambian. | [INFERIDO] Actualizar 27 a 28 y agregar ruta. | [CONFIRMADO] Skill lista 27 endpoints. |
| `.agent/skills/audfact-audit-gemini/SKILL.md` | Skill de pipeline | [INFERIDO] Lista de endpoints async cambia. | [INFERIDO] Agregar referencia al endpoint de fallos por job. | [CONFIRMADO] Skill lista `GET /audit/jobs/{job_id}` como endpoint del pipeline. |
| `CHANGELOG.md` | Registro de cambios | [INFERIDO] Debe registrar la implementación aprobada. | [INFERIDO] Agregar entrada cuando se implemente el endpoint. | [CONFIRMADO] El repo contiene `CHANGELOG.md` en la raíz. |
| Frontend Next.js | API endpoints helper | [INFERIDO] Sin impacto en esta tarea. | [INFERIDO] No modificar frontend. | [CONFIRMADO] La solicitud no pide UI. |

### 13. Cambios por Archivo

| Estado | Ruta completa | Clases / funciones afectadas | Líneas aproximadas | Fragmentos antes / después |
| --- | --- | --- | --- | --- |
| [NEW] | `sdd.md` | Documento SDD | Archivo nuevo | [CONFIRMADO] Este archivo contiene la especificación para aprobación. |
| [MODIFY] | `app/Routes/web.php` | Registro de rutas GET | Cerca de ruta actual `/audit/jobs/{jobId}` | [INFERIDO] Antes: solo `/audit/jobs/{jobId}`. Después: agregar `/audit/jobs/{jobId}/failures` antes de la ruta genérica. |
| [MODIFY] | `app/Controllers/AuditController.php` | `jobFailures()`, helpers privados de formato y sanitización | Después de `jobStatus()` o antes de `formatJobStatus()` | [INFERIDO] Antes: `jobStatus()` retorna resumen. Después: método nuevo retorna detalle de fallos sin modificar `jobStatus()`. |
| [MODIFY] | `tests/Controllers/AuditControllerTest.php` | Tests de `jobFailures`, `StubAuditStateStore` | Junto a tests de `jobStatus()` | [INFERIDO] Antes: tests cubren `jobStatus`. Después: tests cubren invalid UUID, 404, 503, sin fallos, fallos con estado disponible, estado expirado y sanitización. |
| [MODIFY] | `plans/api-endpoints.md` | Sección de endpoints de auditoría async | Después de `GET /audit/jobs/{job_id}` | [INFERIDO] Agregar contrato, errores y nota de TTL Redis. |
| [MODIFY] | `.agent/skills/audfact-api-rest/SKILL.md` | Conteo y tabla de endpoints | Secciones "Archivos clave" y "Endpoints actuales" | [INFERIDO] Cambiar 27 a 28 y agregar `/audit/jobs/{jobId}/failures`. |
| [MODIFY] | `.agent/skills/audfact-audit-gemini/SKILL.md` | Lista de controllers/endpoints de pipeline | Sección "Controllers y endpoints" | [INFERIDO] Agregar endpoint de fallos por job como lectura Redis. |
| [MODIFY] | `CHANGELOG.md` | Entrada de cambio | Sección de fecha de implementación | [INFERIDO] Registrar endpoint agregado y documentación sincronizada cuando el código se implemente. |

#### Fragmento propuesto de ruta

```php
$router->get('/audit/jobs/{jobId}/failures', 'AuditController', 'jobFailures');
$router->get('/audit/jobs/{jobId}', 'AuditController', 'jobStatus');
```

#### Fragmento propuesto de controller

```php
public function jobFailures(string $jobId): void
{
    if (!AuditEvent::isUuidV4($jobId)) {
        Response::error('jobId inválido', 422);
    }

    try {
        $jobState = $this->buildBatchJobStore()->getJob($jobId);
        if ($jobState === null) {
            Response::error('No se encontró el job solicitado', 404);
        }

        $data = self::formatJobFailures($jobState, $this->buildStateStore());
    } catch (RuntimeException $e) {
        Logger::error('AuditController::jobFailures falló', [
            'job_id' => $jobId,
            'error' => $e->getMessage(),
        ]);
        Response::error('No se pudieron consultar los fallos del job', 503);
    }

    Response::success($data, 'Fallos del job');
}
```

### 14. Plan de Migración

#### Prerequisitos

- [CONFIRMADO] El repo debe estar en `C:\Users\USER\Desktop\AudFact`.
- [CONFIRMADO] PHP y PHPUnit ya están configurados por `composer.json` y `phpunit.xml`.
- [INFERIDO] La aprobación explícita del usuario habilita pasar de SDD a implementación.

#### Ejecución

1. [INFERIDO] Crear checkpoint de trabajo antes de cambios de código.
2. [INFERIDO] Modificar `app/Routes/web.php` con la ruta específica antes de la genérica.
3. [INFERIDO] Implementar `AuditController::jobFailures()` y helpers privados.
4. [INFERIDO] Extender stubs y pruebas en `tests/Controllers/AuditControllerTest.php`.
5. [INFERIDO] Actualizar `plans/api-endpoints.md`.
6. [INFERIDO] Actualizar `.agent/skills/audfact-api-rest/SKILL.md` y `.agent/skills/audfact-audit-gemini/SKILL.md`.
7. [INFERIDO] Actualizar `CHANGELOG.md`.
8. [INFERIDO] Ejecutar verificaciones sintácticas y tests focalizados.

#### Validaciones Previas

- [INFERIDO] Verificar que `sdd.md` está aprobado.
- [INFERIDO] Verificar que no existen cambios de usuario en los archivos objetivo que bloqueen la implementación.
- [INFERIDO] Verificar que los tests actuales de `AuditControllerTest` pasan o registrar cualquier fallo preexistente.

#### Validaciones Posteriores

- [INFERIDO] Ejecutar `php -l app/Controllers/AuditController.php`.
- [INFERIDO] Ejecutar `php -l app/Routes/web.php`.
- [INFERIDO] Ejecutar PHPUnit focalizado para `tests/Controllers/AuditControllerTest.php`.
- [INFERIDO] Revisar que `GET /audit/jobs/{jobId}` conserva contrato previo.
- [INFERIDO] Revisar que documentación y skills reflejan 28 endpoints.

#### Rollback

1. [INFERIDO] Eliminar la línea de ruta `/audit/jobs/{jobId}/failures`.
2. [INFERIDO] Eliminar `AuditController::jobFailures()` y helpers privados exclusivos del endpoint.
3. [INFERIDO] Eliminar tests exclusivos de `jobFailures`.
4. [INFERIDO] Revertir documentación y skills del endpoint nuevo.
5. [INFERIDO] Revertir entrada de `CHANGELOG.md` asociada al endpoint.
6. [INFERIDO] Ejecutar `php -l` y PHPUnit focalizado para confirmar retorno al estado anterior.

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| `jobId` no es UUID v4. | [INFERIDO] Responder 422 `jobId inválido`. | [INFERIDO] Test controller con `not-a-uuid`. |
| Job no existe o expiró. | [INFERIDO] Responder 404 `No se encontró el job solicitado`. | [INFERIDO] Stub `BatchJobStore::getJob()` retorna null. |
| Redis falla al leer job. | [INFERIDO] Responder 503 `No se pudieron consultar los fallos del job`. | [INFERIDO] Stub lanza `RuntimeException`. |
| Redis falla al leer una auditoría fallida. | [INFERIDO] Responder 503 para evitar respuesta parcial engañosa. | [INFERIDO] Stub `AuditStateStore::getAudit()` lanza `RuntimeException`. |
| Job existe sin mapa `audits`. | [INFERIDO] Responder 200 con `items = []` y `failures_count = 0`. | [INFERIDO] Test con `audits` ausente o no-array. |
| Job existe con auditorías no fallidas. | [INFERIDO] Responder 200 con `items = []`. | [INFERIDO] Test con estados `completed`, `manual_review`, `error`, `pending`. |
| Auditoría fallida existe en job pero su estado Redis expiró. | [INFERIDO] Responder item con `audit_state_available = false`, `detail_error = null`, contadores null. | [INFERIDO] Test con `getAudit()` null para audit failed. |
| `detail_error` contiene saltos de línea o caracteres de control. | [INFERIDO] Responder una línea normalizada. | [INFERIDO] Test compara ausencia de `\n`, `\r` y control chars. |
| `detail_error` supera 500 caracteres. | [INFERIDO] Responder string truncado a 500 caracteres. | [INFERIDO] Test mide longitud máxima. |
| Estado Redis contiene payloads internos. | [INFERIDO] Respuesta no incluye campos internos fuera de allowlist. | [INFERIDO] Test agrega `documents`, `audit_result_data`, `document_decisions` y confirma ausencia. |
| `audits` contiene entradas corruptas no-array o auditId no-string. | [INFERIDO] Ignorar entradas inválidas sin romper respuesta. | [INFERIDO] Test con entradas corruptas. |

### 16. Testing

#### Nuevos Tests

| Test | Objetivo | Precondiciones | Pasos | Resultado Esperado |
| --- | --- | --- | --- | --- |
| `testJobFailuresReturns422WhenJobIdInvalid` | [INFERIDO] Validar UUID. | [INFERIDO] Controller testeable sin stores reales. | [INFERIDO] Ejecutar `jobFailures('not-a-uuid')`. | [INFERIDO] HTTP 422 y mensaje `jobId inválido`. |
| `testJobFailuresReturns404WhenJobMissing` | [INFERIDO] Manejar job ausente. | [INFERIDO] `StubBatchJobStore` retorna null. | [INFERIDO] Ejecutar con UUID v4. | [INFERIDO] HTTP 404. |
| `testJobFailuresReturns503WhenJobStoreFails` | [INFERIDO] Manejar Redis no disponible al leer job. | [INFERIDO] `StubBatchJobStore` lanza `RuntimeException`. | [INFERIDO] Ejecutar con UUID v4. | [INFERIDO] HTTP 503 y mensaje genérico. |
| `testJobFailuresReturns200WithEmptyItemsWhenNoFailures` | [INFERIDO] Filtrar estados no fallidos. | [INFERIDO] Job contiene auditorías `completed`, `manual_review`, `error`, `pending`. | [INFERIDO] Ejecutar endpoint. | [INFERIDO] `failures_count = 0`, `items = []`. |
| `testJobFailuresReturns200WithFailureDetails` | [INFERIDO] Retornar detalle operativo. | [INFERIDO] Job contiene audit failed y estado de auditoría con `detail_error`. | [INFERIDO] Ejecutar endpoint. | [INFERIDO] Item contiene `audit_id`, `dis_det_nro`, `failed_stage`, `detail_error` sanitizado y contadores. |
| `testJobFailuresMarksExpiredAuditStateUnavailable` | [INFERIDO] Manejar estado de auditoría expirado. | [INFERIDO] Job contiene audit failed y `getAudit()` retorna null. | [INFERIDO] Ejecutar endpoint. | [INFERIDO] Item contiene `audit_state_available = false`. |
| `testJobFailuresDoesNotExposeInternalPayloads` | [INFERIDO] Validar allowlist. | [INFERIDO] Estado de auditoría contiene payloads internos. | [INFERIDO] Ejecutar endpoint. | [INFERIDO] JSON no contiene campos internos prohibidos. |
| `testJobFailuresSanitizesAndTruncatesDetailError` | [INFERIDO] Validar sanitización. | [INFERIDO] `detail_error` multilínea y largo. | [INFERIDO] Ejecutar endpoint. | [INFERIDO] `detail_error` sin saltos de línea y longitud máxima 500. |

#### Tests Modificados

| Test | Objetivo | Precondiciones | Pasos | Resultado Esperado |
| --- | --- | --- | --- | --- |
| `StubAuditStateStore` en `tests/Controllers/AuditControllerTest.php` | [INFERIDO] Permitir devolver estados por `auditId`. | [CONFIRMADO] El stub actual existe en el archivo de tests. | [INFERIDO] Agregar mapa configurable para `getAudit()`. | [INFERIDO] Tests nuevos no requieren Redis real. |

#### Tests Eliminados

| Test | Motivo | Cobertura de reemplazo |
| --- | --- | --- |
| Ninguno. | [INFERIDO] No se elimina comportamiento existente. | [INFERIDO] Los tests actuales de `jobStatus` permanecen. |

#### Verificaciones Manuales

| Verificación | Objetivo | Precondiciones | Pasos | Resultado Esperado |
| --- | --- | --- | --- | --- |
| Sintaxis PHP | [INFERIDO] Confirmar archivos PHP válidos. | [INFERIDO] Código implementado. | [INFERIDO] Ejecutar `php -l` sobre controller y routes. | [INFERIDO] Sin errores de sintaxis. |
| Endpoint con job conocido en entorno local o staging | [INFERIDO] Confirmar contrato HTTP real. | [DESCONOCIDO] Requiere job con fallo activo en Redis. | [INFERIDO] Ejecutar `curl GET /audit/jobs/{jobId}/failures`. | [INFERIDO] JSON coincide con contrato. |
| Endpoint actual de estado de job | [INFERIDO] Confirmar compatibilidad. | [INFERIDO] Job existente. | [INFERIDO] Ejecutar `GET /audit/jobs/{jobId}`. | [INFERIDO] Contrato anterior no cambia. |

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| Exposición accidental de payloads internos o datos sensibles. | seguridad | Alta | [INFERIDO] Usar allowlist de campos y tests de ausencia de payloads prohibidos. |
| Datos no disponibles después de 24 horas. | operativo | Media | [CONFIRMADO] Documentar TTL Redis; no prometer histórico. |
| Ruta genérica captura la ruta específica si el orden es incorrecto. | técnico | Media | [CONFIRMADO] Registrar ruta específica antes de `/audit/jobs/{jobId}`. |
| Error crudo demasiado largo o multilínea degrada la respuesta. | seguridad | Media | [INFERIDO] Sanitizar y truncar `detail_error` a 500 caracteres. |
| Drift documental en skills y docs. | gobernanza | Media | [INFERIDO] Actualizar `plans/api-endpoints.md`, skills afectadas y `CHANGELOG.md`. |
| Falta de autorización administrativa explícita. | seguridad | Media | [INFERIDO] Mantener patrón actual y registrar riesgo; planificar auth en cambio separado si el panel lo exige. |

### 18. Criterios de Aceptación

1. [INFERIDO] `GET /audit/jobs/{jobId}/failures` existe en `app/Routes/web.php` antes de `GET /audit/jobs/{jobId}`.
2. [INFERIDO] UUID inválido responde HTTP 422 con `jobId inválido`.
3. [INFERIDO] Job inexistente o expirado responde HTTP 404 con `No se encontró el job solicitado`.
4. [INFERIDO] Falla de Redis/store responde HTTP 503 con `No se pudieron consultar los fallos del job`.
5. [INFERIDO] Job sin auditorías `failed` responde HTTP 200 con `failures_count = 0` e `items = []`.
6. [INFERIDO] Job con auditorías `failed` responde HTTP 200 con un item por auditoría fallida.
7. [INFERIDO] Cada item fallido contiene `audit_id`, `dis_det_nro`, `dis_id`, `status`, `audit_state_available`, `failed_stage`, `failed_event_type`, `detail_error`, contadores documentales y timestamps permitidos.
8. [INFERIDO] La respuesta no contiene `documents`, `audit_result_data`, `document_decisions`, `original_event`, `fac_nit_sec` ni `reservation_token`.
9. [INFERIDO] `detail_error` se entrega sin saltos de línea y con longitud máxima de 500 caracteres.
10. [INFERIDO] `GET /audit/jobs/{jobId}` conserva su contrato actual.
11. [INFERIDO] Tests focalizados de `AuditControllerTest` pasan.
12. [INFERIDO] `plans/api-endpoints.md`, `.agent/skills/audfact-api-rest/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md` y `CHANGELOG.md` quedan sincronizados al implementar.

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas están definidas | PASS | [CONFIRMADO] No se crean ni modifican tablas SQL. |
| Todas las columnas existen | PASS | [CONFIRMADO] No se crean ni modifican columnas SQL. |
| Todos los contratos documentados | PASS | [INFERIDO] El contrato antes/después del endpoint nuevo está definido en sección 10. |
| Todos los requisitos tienen trazabilidad | PASS | [INFERIDO] La sección 11 mapea R1-R7 a implementación y validación. |
| Todos los consumidores analizados | PASS | [INFERIDO] Consumidor backend y documentación analizados; frontend queda fuera de alcance por A5. |
| Todas las migraciones tienen rollback | PASS | [CONFIRMADO] No existe migración SQL; rollback funcional definido en sección 14. |
| Todas las referencias están definidas | PASS | [INFERIDO] Las clases, rutas, stores y documentos citados existen en el repo. |
| Toda compatibilidad tiene evidencia | PASS | [INFERIDO] No se modifica contrato existente; se agrega endpoint nuevo. |
| Todos los criterios son verificables | PASS | [INFERIDO] Los criterios de aceptación son medibles por pruebas o revisión de diff. |

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado |
| --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | No. [INFERIDO] Las decisiones D1-D7 están documentadas. |
| ¿Existe algún contrato sin documentar? | No. [INFERIDO] El contrato HTTP 200/422/404/503 está documentado. |
| ¿Existe algún consumidor no analizado? | No. [INFERIDO] No existe consumidor frontend dentro del alcance aprobado; el endpoint será API backend. |
| ¿Existe alguna migración sin rollback? | No. [CONFIRMADO] No existe migración SQL. |
| ¿Existe algún dato persistido sin migración? | No. [CONFIRMADO] No se agrega persistencia nueva. |
| ¿Existe alguna afirmación sin evidencia? | No. [INFERIDO] Las afirmaciones usan etiquetas `[CONFIRMADO]`, `[INFERIDO]` o `[DESCONOCIDO]`. |
| ¿Existen referencias huérfanas? | No. [INFERIDO] Las rutas y clases mencionadas existen o están definidas como cambios futuros. |
| ¿Dos implementadores producirían soluciones diferentes? | No. [INFERIDO] El contrato, archivos, errores, sanitización, filtros y tests están especificados. |

## FASE 4 — Resultado Final

### Nivel de Completitud

[INFERIDO] Nivel B — Implementable con Supuestos Declarados.

### Definición de Completitud

- [INFERIDO] La especificación no requiere decisiones arquitectónicas adicionales para implementar el MVP backend.
- [INFERIDO] La especificación permite revisión técnica independiente porque identifica fuentes Redis, contrato HTTP, archivos afectados, tests y rollback.
- [INFERIDO] La especificación requiere aprobación de los supuestos A1-A6 antes de escribir código.
- [INFERIDO] Si se rechaza cualquier supuesto de A1-A6, esta especificación debe revisarse antes de implementar.
