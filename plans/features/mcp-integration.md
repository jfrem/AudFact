# Feature: Integración MCP (Model Context Protocol)

## Descripción

Capa de integración que expone los datos del sistema a asistentes de IA (Claude, Gemini, etc.) mediante el protocolo MCP. Reutiliza la API REST existente sin duplicar lógica de negocio.

## Archivos Involucrados

| Archivo | Rol |
|---|---|
| `app/wrap/webhook.php` | Entry point — recibe JSON-RPC 2.0 |
| `app/wrap/capabilities.php` | Declara las tools disponibles al asistente |
| `app/wrap/core/MCPServer.php` | Servidor MCP — routing de tools, manejo de errores |
| `app/wrap/core/ApiClient.php` | Cliente HTTP interno — llama a la API REST |
| `app/wrap/core/tools/GetClients.php` | Tool: obtener listado de clientes |
| `app/wrap/core/tools/GetInvoices.php` | Tool: buscar facturas por cliente/fecha |
| `app/wrap/core/tools/GetDispensation.php` | Tool: obtener datos de dispensación |
| `app/wrap/core/tools/GetAttachments.php` | Tool: listar documentos adjuntos |

## Flujo de Operación

1. Asistente IA envía `POST /wrap/webhook.php` con payload JSON-RPC 2.0
2. `webhook.php` instancia `MCPServer` y delega `handleRequest()`
3. `MCPServer` identifica el método:
   - `initialize` → Retorna server info + capabilities
   - `tools/list` → Retorna tools disponibles desde `capabilities.php`
   - `tools/call` → Ejecuta tool específica
4. La tool invocada usa `ApiClient` para llamar al endpoint REST interno correspondiente
5. `ApiClient` hace HTTP request local (ej: `GET /api/clients`)
6. La respuesta se formatea como `content` MCP y se retorna al asistente

## Dependencias

- **API REST interna**: Todas las tools reutilizan los endpoints existentes
- `ApiClient` requiere que la API esté corriendo en el mismo servidor

## Configuración

No requiere configuración adicional. Las tools usan la URL base interna para conectarse a la API REST.

## Notas Técnicas

- **Patrón Facade**: MCP actúa como fachada sobre la API REST, adaptando el formato de respuesta
- **Sin duplicación**: La lógica de negocio vive en Controllers/Models. MCP solo traduce el protocolo
- **JSON-RPC 2.0**: El protocolo sigue el estándar con `jsonrpc`, `method`, `params`, `id`
- **Extensibilidad**: Agregar una nueva tool requiere crear un archivo en `core/tools/` y registrarla en `capabilities.php`
