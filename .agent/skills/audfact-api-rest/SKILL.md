---
name: audfact-api-rest
description: Diseñar, crear o modificar endpoints REST del proyecto AudFact. Usar cuando se trabaje en app/Routes/web.php, app/Controllers/*, validación de entrada (Validator/Controller::validate), formato de respuestas (Core\Response) o manejo de errores HTTP.
---

# AudFact API REST

## Objetivo
Implementar cambios de API REST sin romper el contrato JSON ni las validaciones existentes.

> [!TIP]
> Consulta la documentación completa de endpoints en [api-endpoints.md](file:///c:/Users/USER/Desktop/AudFact/plans/api-endpoints.md).

## Archivos clave

| Archivo | Tamaño | Rol |
|---|---|---|
| `app/Routes/web.php` | ~1.8 KB | Definición de 29 rutas |
| `app/Controllers/Controller.php` | 3.6 KB | Base: `validate()`, `validateArray()`, `getBody()`, `validateQuery()` |
| `app/Controllers/AttachmentsController.php` | 7.6 KB | Controlador de metadatos y stream/download de adjuntos |
| `app/Controllers/AuditConfigController.php` | 8.9 KB | Configuración dinámica de auditoría por cliente |
| `app/Controllers/AuditController.php` | 13.9 KB | Auditoría async/single, resumen/detalle de resultados, stats, jobs y timings |
| `app/Controllers/AuditDlqController.php` | 4.6 KB | Consulta y reproceso de DLQ; `rules_evaluated` se reencola mediante el scheduler de persistencia |
| `app/Controllers/AuditFlowController.php` | 3 KB | Stream SSE de telemetría live por `audit_id` UUID v4 |
| `app/Controllers/ObservabilityController.php` | 3.9 KB | Métricas async Redis para UI, incluido el stream `persistence` |
| `app/Controllers/InvoicesController.php` | 2.5 KB | Búsqueda de facturas |
| `app/Controllers/ClientsController.php` | 1.6 KB | Gestión de clientes y catálogo documental |
| `app/Controllers/ConfigController.php` | 0.6 KB | Configuración pública frontend |
| `app/Controllers/DispensationController.php` | 1.0 KB | Datos de dispensación |
| `app/Controllers/HealthController.php` | 2.8 KB | Health check |
| `core/Validator.php` | 4 KB | Reglas: required, integer, date, min_value, etc. |
| `core/Response.php` | 1.6 KB | `success($data)`, `error($msg, $code)` |
| `core/Router.php` | 3.6 KB | Dispatch, sanitización params (max 255 chars) |

## Endpoints actuales (29)

| Método | URI | Controlador::Acción |
|---|---|---|
| `GET` | `/` | `Controller::index` |
| `GET` | `/health` | `HealthController::status` |
| `GET` | `/metrics/async` | `ObservabilityController::asyncMetrics` |
| `GET` | `/config/public` | `ConfigController::publicConfig` |
| `GET` | `/clients` | `ClientsController::index` |
| `GET` | `/clients/{clientId}` | `ClientsController::show` |
| `GET` | `/clients/{clientId}/documents` | `ClientsController::documents` |
| `POST` | `/clients` | `ClientsController::lookup` |
| `GET` | `/clients/{clientId}/audit-config` | `AuditConfigController::show` |
| `POST` | `/clients/{clientId}/audit-config` | `AuditConfigController::save` |
| `GET` | `/audit/field-catalog` | `AuditConfigController::catalog` |
| `GET` | `/invoices` | `InvoicesController::index` |
| `POST` | `/invoices` | `InvoicesController::search` |
| `GET` | `/dispensation/{disDetNro}/attachments/download/{attachmentId}` | `AttachmentsController::downloadByDispensation` |
| `GET` | `/dispensation/{disDetNro}/attachments/{nitSec}` | `AttachmentsController::showByDispensation` |
| `GET` | `/dispensation/{DisId}/{DisDetNro}` | `DispensationController::show` |
| `POST` | `/dispensation` | `DispensationController::lookup` |
| `GET` | `/audit/results` | `AuditController::results` |
| `GET` | `/audit/results/{facNro}` | `AuditController::resultDetail` |
| `GET` | `/audit/stats` | `AuditController::stats` |
| `GET` | `/audit/documents-history` | `AuditController::documentsHistory` |
| `POST` | `/audit/single` | `AuditController::single` |
| `POST` | `/audit/async` | `AuditController::async` |
| `GET` | `/audit/jobs/{jobId}` | `AuditController::jobStatus` |
| `GET` | `/audit/status/{auditId}` | `AuditController::status` |
| `GET` | `/audit/dlq` | `AuditDlqController::index` |
| `POST` | `/audit/dlq/reprocess` | `AuditDlqController::reprocess` |
| `GET` | `/audit/{facNro}/timings` | `AuditController::timings` |
| `GET` | `/audit/{auditId}/flow-stream` | `AuditFlowController::stream` |

## Flujo de trabajo
1. Revisar rutas en `app/Routes/web.php`.
2. Revisar controlador objetivo en `app/Controllers/`.
3. Mantener validación con `validate()` o `validateArray()`.
4. Retornar siempre con `Core\Response::success()` o `Core\Response::error()`.
5. Confirmar códigos HTTP esperados (`400`, `404`, `415`, `422`, `500`).

## Reglas de implementación
1. Aceptar body solo `application/json` para endpoints POST/PUT.
2. Sanitizar y validar parámetros de ruta y query antes de usar en modelo.
3. Mantener mensajes consistentes en español.
4. **No hacer SQL en controladores** — delegar a modelos.
5. **No retornar arrays crudos** con `echo`; usar `Response`.
6. Router sanitiza params con `FILTER_SANITIZE_SPECIAL_CHARS` y limita a **255 caracteres**.
7. **Patrón Uniforme**: Todo endpoint que retorne listas debe incluir metadatos de paginación y reflejar los filtros aplicados.
8. `POST /clients/{clientId}/audit-config` debe preservar `codigoCampo` cuando venga en los campos activos; ese código se usa luego como prefijo textual `-CODIGO- detalle` en hallazgos fallidos.

## Patrón de Endpoint Estándar (Uniforme) 💎

Para garantizar la coherencia, todo nuevo endpoint debe seguir esta estructura:

### 1. Endpoints de Consulta (GET) con Filtros
```php
public function miMetodo(): void
{
    // 1. Validar Query Params (Patrón Uniforme)
    $filters = $this->validateQuery([
        'facNitSec' => 'optional|integer',
        'facNro'    => 'optional|string',
        'page'      => 'optional|integer|min_value:1',
        'pageSize'  => 'optional|integer|min_value:1|max_value:100'
    ]);

    // 2. Valores por defecto (si no vienen en validation)
    $page = (int) ($filters['page'] ?? 1);
    $pageSize = (int) ($filters['pageSize'] ?? 20);

    // 3. Consumo Estándar en Modelo (Pasar array $filters completo)
    $total = $this->model->countSomething($filters);
    $items = $this->model->getSomething($page, $pageSize, $filters);

    // 4. Respuesta Estándar (Data Wrap)
    \Core\Response::success([
        'items'      => $items,
        'total'      => $total,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'totalPages' => ceil($total / $pageSize),
        'filters'    => $filters // Reflejar filtros aplicados
    ], 'Mensaje descriptivo en español');
}
```

### 2. Endpoints de Acción (POST/PUT)
```php
public function miAccion(): void
{
    // 1. Validar Body
    $data = $this->validate([
        'id'     => 'required|integer',
        'status' => 'required|string'
    ]);

    // 2. Ejecutar lógica en Modelo
    $result = $this->model->updateStatus($data['id'], $data['status']);

    // 3. Respuesta
    \Core\Response::success($result, 'Operación realizada con éxito');
}
```

## Anti-patterns ⚠️
1. **No concatenar parámetros de ruta en SQL** — siempre parametrizar vía modelo.
2. **No crear endpoints sin validación** — todo POST/PUT requiere `validate()`.
3. **No devolver excepciones al cliente en prod** — `index.php` ya maneja esto globalmente.
4. **No olvidar agregar la ruta a `web.php`** — el Router solo despacha rutas registradas.
5. **No usar `exit()` o `die()`** — usar `Response::error()` que lanza `HttpResponseException` (ya no hace exit).
6. **No omitir el campo `filters` en respuestas de búsqueda** — es vital para que el frontend sepa qué criterios se procesaron.

## Cross-references
- **`audfact-sqlsrv-models`**: Controladores consumen modelos para acceso a datos.
- **`audfact-security-guardrails`**: Validación de entrada y Content-Type.

## Ejemplos

### Ejemplo 1: endpoint POST con validación
```php
public function lookup(): void
{
    $data = $this->validate([
        'clientId' => 'required|integer|min_value:1'
    ]);

    $client = $this->model->getClientById((int)$data['clientId']);
    if (!$client) {
        \Core\Response::error('Cliente no encontrado', 404);
    }

    \Core\Response::success($client);
}
```

### Ejemplo 2: prueba HTTP
```bash
curl -X POST http://localhost:8080/clients ^
  -H "Content-Type: application/json" ^
  -d "{\"clientId\":1165}"
```

## Checklist rápido
1. Ruta agregada/ajustada en `web.php`.
2. Validación aplicada con `validate()` o `validateArray()`.
3. Errores tipados por código HTTP.
4. Respuesta JSON estándar via `Response`.
5. Logs agregados solo cuando aporten diagnóstico.
6. Parámetros de ruta no exceden 255 caracteres.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

**Después de implementar cualquier cambio en los archivos gobernados por esta skill, DEBES:**

1. **Verificar si este SKILL.md sigue siendo preciso**:
   - ¿Los archivos listados en "Archivos clave" siguen existiendo? ¿Hay nuevos?
   - ¿El conteo de endpoints sigue correcto?
   - ¿La tabla de endpoints refleja todas las rutas de `web.php`?
   - ¿Los ejemplos de código siguen siendo válidos?
2. **Si detectas una desviación**: corregirla ANTES de ejecutar `audfact-docs-sync`.
3. **Ejecutar `audfact-docs-sync`**: esto es la segunda capa de validación.

> [!CAUTION]
> Ignorar este paso y dejar la skill desactualizada generará drift
> acumulativo que confundirá a futuros agentes.

## Referencias
1. Ver casos ampliados en `references/examples.md`.
2. Ver plantilla y suite en `references/test-cases.md`.
