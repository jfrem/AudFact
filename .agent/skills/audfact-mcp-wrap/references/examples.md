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
        "date": "2026-05-30",
        "page": 1,
        "pageSize": 20
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
      "data": {
        "items": [],
        "total": 0,
        "page": 1,
        "pageSize": 20,
        "totalPages": 0,
        "filters": {
          "facNitSec": 1165,
          "dateFrom": "2026-05-30",
          "dateTo": "2026-05-30"
        }
      }
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
