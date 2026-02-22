# Ejemplos Extendidos - audfact-api-rest

## Happy path: crear endpoint GET por parametro de ruta
Objetivo: agregar `GET /clients/{clientId}` con validacion.

1. Ruta:
```php
$router->get('/clients/{clientId}', 'ClientsController', 'show');
```

2. Controlador:
```php
public function show(string $clientId): void
{
    $this->validateArray(['clientId' => $clientId], [
        'clientId' => 'required|integer|min_value:1'
    ]);
    $client = $this->model->getClientById((int)$clientId);
    if (!$client) {
        \Core\Response::error('Cliente no encontrado', 404);
    }
    \Core\Response::success($client);
}
```

3. Prueba:
```bash
curl http://localhost:8080/clients/1165
```

## Failure path: body sin JSON
Si llega `Content-Type: text/plain`, responder `415`.

Respuesta esperada:
```json
{
  "success": false,
  "message": "Content-Type must be application/json"
}
```
