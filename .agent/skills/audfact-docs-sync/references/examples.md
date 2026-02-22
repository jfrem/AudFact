# Ejemplos Extendidos - audfact-docs-sync

## Happy path: Matriz de Impacto correcta
Después de modificar `app/Routes/web.php` y `app/wrap/webhook.php`:

```markdown
### 📝 Matriz de Impacto Documental (OBLIGATORIO)
| Archivo de Docs | ¿Requirió Cambio? | Razón | Estado |
|---|---|---|---|
| `AGENTS.md` | Sí | Se agregaron headers de Auth para MCP | Hecho |
| `plans/api-endpoints.md` | Sí | Router modificado | Hecho |
| `plans/features/mcp-integration.md` | Sí | Lógica de Webhook actualizada | Hecho |
| `plans/database-schema.md` | No | No hubo alteraciones SQL | N/A |
```

## Failure path: Tarea sin documentar abortada
El Agente intenta hacer un `notify_user` o terminar un ticket directamente sin crear la tabla anterior ni leer este Skill. Las reglas en `GEMINI.md` interceptan al agente y lo fuerzan a regresar al estado de Ejecución para aplicar los manuales.
