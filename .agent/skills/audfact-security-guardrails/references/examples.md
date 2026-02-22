# Ejemplos Extendidos - audfact-security-guardrails

## Happy path: control de payload y JSON
```php
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    \Core\Response::error('Content-Type must be application/json', 415);
}

$maxSize = (int)(getenv('MAX_JSON_SIZE') ?: 1048576);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxSize) {
    \Core\Response::error('Payload Too Large', 413);
}
```

## Failure path: exposicion de mensaje interno en prod
No hacer:
```php
\Core\Response::error($e->getMessage(), 500);
```
en `APP_ENV=production`.

Hacer:
```php
\Core\Response::error('Internal server error', 500);
```

## Failure path: nombre de archivo sin sanitizar
No usar directamente `AdjDisNom` en `Content-Disposition`.
Aplicar siempre sanitizacion con regex segura.
