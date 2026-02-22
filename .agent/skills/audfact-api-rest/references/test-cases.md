# Test Cases - audfact-api-rest

## Plantilla GWT
```text
Given: contexto inicial
When: accion HTTP o cambio de codigo
Then: resultado esperado (status/body/efecto)
```

## Casos
1. `POST /clients` valido
   - Given: `clientId` existente.
   - When: request con `application/json`.
   - Then: `200` y `success=true`.
2. `POST /clients` sin JSON
   - Given: `Content-Type: text/plain`.
   - When: request.
   - Then: `415`.
3. `GET /clients/{id}` invalido
   - Given: `id=0`.
   - When: request.
   - Then: `422` por `min_value:1`.
