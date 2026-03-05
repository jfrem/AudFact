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
| `app/Routes/web.php` | ~1 KB | Definición de 15 rutas |
| `app/Controllers/Controller.php` | 2.3 KB | Base: `validate()`, `validateArray()`, `getJsonBody()` |
| `app/Controllers/AttachmentsController.php` | 6.7 KB | Controlador más complejo (stream/download) |
| `app/Controllers/AuditController.php` | ~3 KB | Orquestador de auditoría + resultados |
| `app/Controllers/InvoicesController.php` | 1.4 KB | Búsqueda de facturas |
| `app/Controllers/ClientsController.php` | 1.1 KB | Gestión de clientes |
| `app/Controllers/ConfigController.php` | ~0.5 KB | Configuración pública frontend |
| `app/Controllers/DispensationController.php` | 858 B | Datos de dispensación |
| `app/Controllers/HealthController.php` | 646 B | Health check |
| `core/Validator.php` | 4 KB | Reglas: required, integer, date, min_value, etc. |
| `core/Response.php` | 1.6 KB | `success($data)`, `error($msg, $code)` |
| `core/Router.php` | 3.6 KB | Dispatch, sanitización params (max 255 chars) |

## Endpoints actuales (15)

| Método | URI | Controlador::Acción |
|---|---|---|
| `GET` | `/` | `Controller::index` |
| `GET` | `/health` | `HealthController::status` |
| `GET` | `/config/public` | `ConfigController::publicConfig` |
| `GET` | `/clients` | `ClientsController::index` |
| `GET` | `/clients/{clientId}` | `ClientsController::show` |
| `POST` | `/clients` | `ClientsController::lookup` |
| `GET` | `/invoices` | `InvoicesController::index` |
| `POST` | `/invoices` | `InvoicesController::search` |
| `GET` | `/dispensation/{invoiceId}/attachments/{nitSec}` | `AttachmentsController::showByDispensation` |
| `GET` | `/dispensation/{invoiceId}/attachments/download/{attachmentId}` | `AttachmentsController::downloadByDispensation` |
| `GET` | `/dispensation/{DisDetNro}` | `DispensationController::show` |
| `POST` | `/dispensation` | `DispensationController::lookup` |
| `GET` | `/audit/results` | `AuditController::results` |
| `POST` | `/audit` | `AuditController::run` |
| `POST` | `/audit/single` | `AuditController::single` |

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

## Anti-patterns ⚠️
1. **No concatenar parámetros de ruta en SQL** — siempre parametrizar vía modelo.
2. **No crear endpoints sin validación** — todo POST/PUT requiere `validate()`.
3. **No devolver excepciones al cliente en prod** — `index.php` ya maneja esto globalmente.
4. **No olvidar agregar la ruta a `web.php`** — el Router solo despacha rutas registradas.
5. **No usar `exit()` o `die()`** — usar `Response::error()` que lanza `HttpResponseException` (ya no hace exit).

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
