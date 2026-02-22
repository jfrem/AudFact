# Test Cases - audfact-mcp-wrap

## Plantilla GWT
```text
Given: payload MCP con tools[]
When: se invoca webhook.php
Then: respuesta por tool con status/body o error controlado
```

## Casos
1. Tool valida
   - Given: `GetClients` con parametros validos.
   - When: request MCP.
   - Then: arreglo con resultado `success=true`.
2. Tool inexistente
   - Given: `tool=UnknownTool`.
   - When: request MCP.
   - Then: error `Herramienta no encontrada`.
3. Formato invalido
   - Given: payload sin `tools`.
   - When: request.
   - Then: HTTP `400`.
