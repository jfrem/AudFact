---
description: Usar esta skill OBLIGATORIAMENTE al finalizar cualquier tarea de código, desarrollo o bugfix para asegurar que la documentación del proyecto se sincronice con los cambios arquitectónicos y operativos.
---

# 📚 Sincronización de Documentación (docs-sync)

**🚨 ALERTA CRÍTICA: Desvío de Documentación (Documentation Drift) 🚨**
Tienes estrictamente prohibido dar por finalizada una sesión de desarrollo, refactor o bugfix sin antes ejecutar este protocolo de validación documental. 

## 1. Contexto de la Skill
Los agentes de IA tienden a sufrir de "Attention Decay" y olvidar actualizar los manuales del proyecto luego de hacer el código funcionar. Esta skill existe como un **bloqueo físico procedimental**. 

**Tu tarea actual no está completa si la documentación no refleja el estado exacto del código que acabas de modificar.**

## 2. Matriz de Impacto Documental
Debes evaluar qué archivos necesitan ser modificados en base al tipo de trabajo que hiciste. Utiliza esta matriz:

| 🛠️ Si modificaste... | 📝 Debes actualizar OBLIGATORIAMENTE... |
|---|---|
| Controladores, validación (Validator), ruteo (web.php) | `plans/api-endpoints.md` |
| Componentes principales, servicios (Services/) nuevos | `plans/architecture.md` (y revisar `diagrams` si aplica) |
| Lógicas de negocio pesadas (ej. GeminiAuditService) | `plans/features/[nombre].md` |
| Tablas SQL, modelos, migraciones | `plans/database-schema.md` |
| Autenticación, Rate Limits, timeouts, variables de entorno | `AGENTS.md` (Catálogo de variables, Guardrails) |
| Webhooks, integraciones externas (MCP) | `plans/features/mcp-integration.md` o `AGENTS.md` |

## 3. Protocolo de Ejecución Obligatorio

Antes de hacer `notify_user` o dar la tarea por concluida, debes seguir estos pasos formales:

1. **Auto-Cuestionario (Chain of Thought):**
   - Escribe en tu bloque de pensamiento o respuesta: *"He modificado X. ¿Qué documentos se ven afectados por X según la matriz de impacto?"*
2. **Generar la Matriz de Revisión en tu Respuesta:**
   Presenta al usuario la siguiente tabla rellenada con tus acciones:
   ```markdown
   ### 📝 Matriz de Impacto Documental (OBLIGATORIO)
   | Archivo de Docs | ¿Requirió Cambio? | Razón | Estado |
   |---|---|---|---|
   | `AGENTS.md` | [Sí/No] | ... | [Hecho/N/A] |
   | `plans/api-endpoints.md` | [Sí/No] | ... | [Hecho/N/A] |
   | ... | ... | ... | ... |
   ```
3. **Actualizar el Changelog:**
   Agrega SIEMPRE un log en `plans/changelog.md` bajo la fecha de hoy, describiendo el cambio realizado para propósitos de auditoría humana.

## 4. Fallas y Penalizaciones
- Generar explicaciones omitiendo este paso se considera una **violación crítica** de la System Instruction del usuario.
- Si no hay documentación que deba ser actualizada (un cambio cosmético de 1 línea, por ejemplo), DEBES escribir la tabla de la matriz y justificar el "No" en lugar de ignorar el paso.
