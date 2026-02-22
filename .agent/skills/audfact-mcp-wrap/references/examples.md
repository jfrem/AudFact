# Ejemplos Extendidos - audfact-mcp-wrap

## Happy path: ejecutar tool GetInvoices
Request a `webhook.php`:
```json
{
  "tools": [
    {
      "tool": "GetInvoices",
      "params": {
        "facNitSec": 1165,
        "date": "2025-12-30",
        "limit": 5
      }
    }
  ]
}
```

Respuesta esperada:
```json
[
  {
    "success": true,
    "status": 200,
    "body": {
      "success": true,
      "data": []
    }
  }
]
```

## Failure path: herramienta inexistente
Si `tool` no registrada:
```json
{
  "error": "Herramienta no encontrada: ToolX"
}
```
