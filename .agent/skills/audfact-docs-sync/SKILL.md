---
name: audfact-docs-sync
description: Usar esta skill OBLIGATORIAMENTE al finalizar cualquier tarea de código, desarrollo o bugfix para asegurar que la documentación del proyecto Y las skills del agente se sincronicen con los cambios arquitectónicos y operativos.
---

# 📚 Sincronización de Documentación (docs-sync) v2.0

## 🚨 ALERTA CRÍTICA: Desvío de Documentación (Documentation Drift) 🚨

Tienes **estrictamente prohibido** dar por finalizada una sesión de desarrollo, refactor o bugfix sin antes ejecutar **TODOS** los pasos de este protocolo de validación documental.

**Tu tarea actual NO está completa si la documentación Y las skills no reflejan el estado exacto del código que acabas de modificar.**

## 1. Contexto

Los agentes de IA sufren de **Attention Decay**: una vez que el código "funciona", olvidan actualizar la documentación. Esta skill existe como un **bloqueo físico procedimental** con **dos dimensiones de cobertura**:

| Dimensión | Qué cubre | Ejemplo |
|---|---|---|
| **Documentación** (`plans/`, `AGENTS.md`, `README.md`) | Manuales humanos | `plans/api-endpoints.md` |
| **Skills** (`.agent/skills/*/SKILL.md`, `CATALOG.md`) | Instrucciones para agentes IA | `audfact-api-rest/SKILL.md` |

**Ambas dimensiones son igual de críticas.** Un agente que actualiza `plans/` pero olvida las skills deja una bomba de tiempo para el próximo agente.

## 2. Matriz de Impacto Documental (Dimensión 1: Documentación)

| 🛠️ Si modificaste... | 📝 Debes actualizar OBLIGATORIAMENTE... |
|---|---|
| Controladores, validación (Validator), ruteo (web.php) | `plans/api-endpoints.md` |
| Componentes principales, servicios (Services/) nuevos | `plans/architecture.md` |
| Lógicas de negocio pesadas (ej. DocumentAuditOrchestrator o RulesEvaluationWorker) | `plans/features/[nombre].md` |
| Tablas SQL, modelos, migraciones | `plans/database-schema.md` |
| Autenticación, Rate Limits, timeouts, variables de entorno | `AGENTS.md` (Catálogo de variables, Guardrails) |
| Webhooks, integraciones externas (MCP) | `plans/features/mcp-integration.md` o `AGENTS.md` |
| Docker, Nginx, PHP-FPM, despliegue | `plans/docker-operations.md`, `plans/deployment-and-ci.md` |
| Conteos de componentes (controllers, models, services, endpoints) | `README.md` |

## 3. Matriz de Impacto en Skills (Dimensión 2: Skills)

Consulta el archivo **`CATALOG.md`** → sección "Mapeo Archivo → Skill" para identificar qué skill(s) gobiernan los archivos que modificaste.

| 🛠️ Si modificaste... | 🧠 Debes verificar/actualizar la skill... |
|---|---|
| `app/Routes/web.php`, `app/Controllers/*.php` | `audfact-api-rest/SKILL.md` |
| `app/Services/Audit/*.php` | `audfact-audit-gemini/SKILL.md` |
| `app/Models/*.php`, `core/Database.php` | `audfact-sqlsrv-models/SKILL.md` |
| `app/wrap/**` | `audfact-mcp-wrap/SKILL.md` |
| `docker-compose.yml`, `docker/*`, `.env*` | `audfact-runtime-docker/SKILL.md` |
| `core/RateLimit.php`, `core/Logger.php`, `public/index.php` | `audfact-security-guardrails/SKILL.md` |
| Cualquier cambio de conteos, estructura o flujo principal | `audfact-project-overview/SKILL.md` |
| Creación/eliminación/renombrado de archivos gobernados | `CATALOG.md` (Mapeo Archivo → Skill) |

### Qué verificar en cada skill afectada:
1. ¿Los archivos listados en "Archivos clave" siguen existiendo? ¿Hay nuevos?
2. ¿Los conteos (endpoints, servicios, modelos) siguen correctos?
3. ¿Los diagramas/mapas de dependencias siguen vigentes?
4. ¿Los ejemplos de código siguen siendo válidos?
5. ¿Los anti-patterns siguen siendo relevantes?

## 4. Protocolo de Ejecución Obligatorio (4 pasos)

### Paso 1: Autoevaluación
Pregúntate: "¿Hice algún cambio arquitectónico, agregué una variable, modifiqué el modelo de base de datos, agregué un endpoint o refactoricé una ruta crítica?"

### Paso 2: Actualización Concurrente (Doble Audiencia)
1. Modifica los archivos en `plans/`, `AGENTS.md` o el `README.md` según la matriz de impacto documental. **ATENCIÓN: Nunca muevas ni renombres estos archivos Markdown originales. Son la fuente de verdad fundamental para los agentes.**
2. Modifica los archivos en `.agent/skills/` según la matriz de impacto en skills.

### Paso 2.1: Sincronización Remota de Variables y Secretos en GitHub (OBLIGATORIO)
Si modificaste o creaste variables en `.env.example`, código PHP (`Env::get()`) o Next.js (`process.env`):
- **OBLIGATORIO**: Sincroniza inmediatamente con GitHub Environment `production` usando `gh cli`:
  ```bash
  gh variable set <VARIABLE_NAME> --body "<VALOR>" --env production
  # o para secretos/credenciales:
  gh secret set <SECRET_NAME> --body "<VALOR>" --env production
  ```
- Verifica la sincronización con: `gh variable list --env production` / `gh secret list --env production`.
- **Prohibido hacer push o merge a `main` si las variables remotas no están sincronizadas.**

### Paso 3: Validación del Portal Docusaurus
Como usamos la estrategia de "Doble Audiencia", la documentación humana se genera estáticamente.
**OBLIGATORIO**: Valida que tus cambios no rompan la compilación del portal de documentación.
Ejecuta el build y soluciona cualquier error de sintaxis MDX o "broken links" en tus archivos `.md`:
```bash
cd website && npm run build
```

### Paso 4: Registro en CHANGELOG.md
Escribe explícitamente en tu respuesta:
> *"He modificado [lista de archivos]. Según la Matriz de Impacto Documental, los documentos afectados son: [...]. Según la Matriz de Impacto en Skills, las skills afectadas son: [...]."*

Presenta al usuario la siguiente tabla rellenada:

```markdown
### 📝 Matriz de Impacto Documental (OBLIGATORIO)
| Archivo | ¿Requirió Cambio? | Razón | Estado |
|---|---|---|---|
| `plans/api-endpoints.md` | [Sí/No] | ... | [Hecho/N/A] |
| `plans/architecture.md` | [Sí/No] | ... | [Hecho/N/A] |
| `AGENTS.md` | [Sí/No] | ... | [Hecho/N/A] |
| `README.md` | [Sí/No] | ... | [Hecho/N/A] |
| ... | ... | ... | ... |

### 🧠 Matriz de Impacto en Skills (OBLIGATORIO)
| Skill | ¿Requirió Cambio? | Razón | Estado |
|---|---|---|---|
| `audfact-api-rest` | [Sí/No] | ... | [Hecho/N/A] |
| `audfact-audit-gemini` | [Sí/No] | ... | [Hecho/N/A] |
| `CATALOG.md` | [Sí/No] | ... | [Hecho/N/A] |
| ... | ... | ... | ... |
```

### Paso 3: Actualizar el Changelog
Agrega SIEMPRE un log en `plans/changelog.md` bajo la fecha de hoy describiendo el cambio. Si hubo actualizaciones de skills, mencionarlo explícitamente.

## 5. Fallas y Penalizaciones

- Generar una respuesta final omitiendo este protocolo es una **violación crítica** de las System Instructions.
- Si no hay documentación ni skills que deban actualizarse, **DEBES** escribir ambas tablas de la matriz y justificar el "No" en cada fila en lugar de ignorar el paso.
- Un agente que actualiza docs pero omite skills (o viceversa) se considera **parcialmente negligente**.
- Si descubres drift preexistente en una skill mientras la revisas, **DEBES corregirlo** como parte de esta tarea.
