---
name: audfact-mcp-wrap
description: Implementar o ajustar la integración MCP de AudFact. Usar cuando se trabaje en app/wrap/webhook.php, app/wrap/capabilities.php, app/wrap/core/MCPServer.php, app/wrap/core/tools/* o app/wrap/core/ApiClient.php.
---

# AudFact MCP Wrap

## Objetivo
Mantener interoperabilidad estable entre asistentes IA y endpoints REST internos.

> [!TIP]
> Consulta la documentación detallada de la integración en [mcp-integration.md](file:///c:/Users/USER/Desktop/AudFact/plans/features/mcp-integration.md).

## Archivos clave

| Archivo | Tamaño | Rol |
|---|---|---|
| `app/wrap/webhook.php` | 888 B | Punto de entrada MCP |
| `app/wrap/capabilities.php` | 1.8 KB | Declaración de capacidades y params |
| `app/wrap/core/MCPServer.php` | 890 B | Servidor MCP: registro y dispatch de tools |
| `app/wrap/core/ApiClient.php` | 2.1 KB | Cliente HTTP interno → API REST AudFact |
| `app/wrap/core/tools/GetClients.php` | 370 B | Tool: obtener clientes |
| `app/wrap/core/tools/GetInvoices.php` | 720 B | Tool: buscar facturas |
| `app/wrap/core/tools/GetDispensation.php` | 449 B | Tool: datos de dispensación |
| `app/wrap/core/tools/GetAttachments.php` | 720 B | Tool: adjuntos de dispensación |

## Mapeo Tool → Endpoint REST

| Tool MCP | Método | Endpoint REST Interno |
|---|---|---|
| `GetClients` | GET | `/clients` o `/clients/{clientId}` |
| `GetInvoices` | GET | `/invoices` |
| `GetDispensation` | GET | `/dispensation/{invoiceId}` |
| `GetAttachments` | GET | `/dispensation/{invoiceId}/attachments/{nitSec}` o `/dispensation/{invoiceId}/attachments/download/{attachmentId}` |

## Flujo MCP
1. Recibir payload con `tools[]` en `webhook.php`.
2. **Validar autenticación**: header `X-API-KEY` debe coincidir con `MCP_WEBHOOK_SECRET` env.
3. Registrar tools en `MCPServer`.
4. Ejecutar cada tool con `params`.
5. Resolver llamada interna via `ApiClient` → API REST local.
6. Retornar arreglo de resultados JSON.

## Reglas de implementación
1. **Validar formato MCP de entrada** antes de ejecutar tools.
2. **Mantener errores por tool** sin tumbar lote completo.
3. **Alinear paths de tools con rutas reales** de `app/Routes/web.php`.
4. **Mantener `capabilities.php` actualizado** con parámetros reales.
5. **Evitar acoplar tools con detalles internos de DB** — siempre ir por ApiClient.
6. Cada tool implementa `execute(array $params): array`.

## Anti-patterns ⚠️
1. **No llamar modelos directamente desde tools** — usar `ApiClient` como capa de abstracción.
2. **No olvidar actualizar `capabilities.php`** al agregar/modificar una tool.
3. **No asumir que los params MCP están validados** — validar dentro de cada tool.
4. **No hacer llamadas externas desde tools** — solo llamadas a la API REST propia.
5. **No olvidar `MCP_WEBHOOK_SECRET` en `.env`** — requests sin `X-API-KEY` válido reciben `401`.

## Cross-references
- **`audfact-api-rest`**: Las tools son wrappers de endpoints REST; cambios en rutas requieren actualizar tools.

## Ejemplos

### Ejemplo 1: payload MCP válido
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

### Ejemplo 2: implementación de tool
```php
class GetDispensation
{
    public function execute(array $params): array
    {
        $client = new \App\wrap\core\ApiClient();
        $invoiceId = $params['invoiceId'] ?? $params['DisDetNro'] ?? $params['facSec'] ?? null;
        return $client->get('/dispensation/' . urlencode((string)$invoiceId));
    }
}
```

## Checklist rápido
1. Tool nueva registrada en `webhook.php`.
2. Tool declarada en `capabilities.php` con params correctos.
3. Path interno válido y coincide con `web.php`.
4. Manejo de errores consistente por tool.
5. Respuesta JSON útil para asistentes IA.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

**Después de implementar cualquier cambio en los archivos gobernados por esta skill, DEBES:**

1. **Verificar si este SKILL.md sigue siendo preciso**:
   - ¿Los archivos de tools siguen existiendo? ¿Se crearon nuevas?
   - ¿El mapeo Tool→Endpoint REST sigue alineado con `web.php`?
   - ¿El flujo MCP refleja los pasos actuales (incluida autenticación)?
   - ¿El `capabilities.php` está actualizado?
2. **Si detectas una desviación**: corregirla ANTES de ejecutar `audfact-docs-sync`.
3. **Ejecutar `audfact-docs-sync`**: esto es la segunda capa de validación.

> [!CAUTION]
> Ignorar este paso y dejar la skill desactualizada generará drift
> acumulativo que confundirá a futuros agentes.

## Referencias
1. Ver casos ampliados en `references/examples.md`.
2. Ver plantilla y suite en `references/test-cases.md`.
