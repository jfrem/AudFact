---
name: audfact-security-guardrails
description: Aplicar guardrails de seguridad en AudFact. Usar cuando se modifiquen validaciones de entrada, rate limit, CORS, manejo de archivos, serialización JSON, logging de datos sensibles o respuestas de error en producción.
---

# AudFact Security Guardrails

## Objetivo
Mantener un baseline de seguridad consistente en todo el backend.

> [!IMPORTANT]
> Los detalles sobre seguridad y hardening están documentados en [overview.md](file:///c:/Users/USER/Desktop/AudFact/plans/overview.md#seguridad-y-hardening).

## Archivos clave

| Archivo | Tamaño | Rol |
|---|---|---|
| `public/index.php` | 2 KB | CORS, exception handler, rate limit bootstrap |
| `core/RateLimit.php` | 6.5 KB | Rate limiting file-based (100/min por IP) |
| `core/Validator.php` | 4 KB | Validación de entrada: required, integer, date, min_value |
| `core/Logger.php` | 4.5 KB | Logging rotacional con redacción de secretos |
| `core/Middleware.php` | 1.6 KB | Sistema de middlewares (auth registrado) |
| `core/Router.php` | 3.6 KB | Sanitización de params (FILTER_SANITIZE_SPECIAL_CHARS, max 255) |
| `app/Controllers/Controller.php` | 2.3 KB | validate(), validateArray(), getJsonBody() |
| `app/Controllers/AttachmentsController.php` | 6.7 KB | Stream/download seguro de archivos |
| `app/Services/Audit/AuditFileManager.php` | 11.2 KB | Descarga y limpieza de archivos temporales |

## Áreas clave de seguridad

### 1. CORS (en `public/index.php`)
- **Desarrollo**: `Access-Control-Allow-Origin: *`
- **Producción**: Allowlist desde `ALLOWED_ORIGINS` env var
- Siempre permite: `GET, POST, PUT, DELETE, OPTIONS`
- Headers permitidos: `Content-Type, Authorization`
- Preflight `OPTIONS` → `200` inmediato

### 2. Rate Limiting (en `core/RateLimit.php`)
- **Mecanismo**: File-based locking por IP
- **Límite por defecto**: 100 requests/minuto
- **En producción**: Falla silenciosamente (permite request si rate limit falla)
- **En desarrollo**: Lanza excepción

### 3. Exception Handler (en `public/index.php`)
- **Producción**: `"Internal server error"` sin detalles
- **Desarrollo**: Mensaje completo de la excepción

### 4. JWT Middleware
- **Estado**: Registrado como `'auth'` en `Middleware::register()` pero **ninguna ruta lo aplica actualmente**
- Disponible para activar vía `$router->post('/ruta', 'Ctrl', 'action')->middleware('auth')`

### 5. Sanitización de parámetros (en `core/Router.php`)
- **Filtro**: `FILTER_SANITIZE_SPECIAL_CHARS` en params de ruta
- **Límite**: 255 caracteres máximo
- **Excede**: `400 Bad Request`

## Reglas de implementación
1. **No aceptar payload no JSON** en endpoints API con body.
2. Mantener límites de tamaño de payload (`MAX_JSON_SIZE`).
3. **No exponer mensajes internos** en `APP_ENV=production`.
4. **Redactar secretos en logs** (`token`, `password`, `api_key`, `authorization`).
5. Sanitizar nombres de archivo y validar tipo MIME antes de entregar contenido.

## Anti-patterns ⚠️
1. **No loguear tokens ni API keys** — el Logger redacta automáticamente, pero no confiar en datos custom.
2. **No confiar solo en Content-Length** para validar tamaño de payload — puede ser manipulado.
3. **No desactivar CORS para producción** — mantener allowlist.
4. **No atrapar excepciones sin logging** — siempre `Logger::error()` antes de `Response::error()`.
5. **No servir archivos temporales directamente** — siempre stream a través de `AttachmentsController`.
6. **No activar JWT middleware sin verificar impacto** en tools MCP y health checks.

## Cross-references
- **`audfact-api-rest`**: Validación de entrada en controladores.
- **`audfact-audit-gemini`**: Manejo seguro de archivos temporales.
- **`audfact-runtime-docker`**: Variables de entorno de seguridad.

## Ejemplos

### Ejemplo 1: rechazar Content-Type inválido
```php
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    \Core\Response::error('Content-Type must be application/json', 415);
}
```

### Ejemplo 2: máximo tamaño de payload
```php
$maxSize = (int)(getenv('MAX_JSON_SIZE') ?: 1048576);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxSize) {
    \Core\Response::error('Payload Too Large', 413);
}
```

### Ejemplo 3: CORS condicional (de index.php)
```php
if (Env::get('APP_ENV') === 'development') {
    header('Access-Control-Allow-Origin: *');
} else {
    $allowedOrigins = explode(',', Env::get('ALLOWED_ORIGINS', ''));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: {$origin}");
    }
}
```

## Checklist rápido
1. Validaciones obligatorias aplicadas en controlador.
2. CORS configurado según entorno.
3. Rate limit no rompe servicio ante fallo interno.
4. Logs sin secretos expuestos.
5. Descargas seguras para URL/BLOB.
6. Excepciones manejadas con logging.

## Referencias
1. Ver casos ampliados en `references/examples.md`.
2. Ver plantilla y suite en `references/test-cases.md`.
