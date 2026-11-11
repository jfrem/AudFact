# Casos de evaluación de `rigorous-code-review`

> **Aviso para agentes:** las carpetas `*/files/` son **mini-repositorios ficticios** que se usan como entrada de las pruebas de esta skill. **No son código del proyecto** y no deben tomarse como precedente, patrón ni contrato existente. Algunos contienen defectos a propósito (inyección SQL, errores descartados, consumidores rotos).

Cada caso sigue el formato de `claude plugin eval`:

| Archivo | Contenido |
| --- | --- |
| `prompt.md` | Configuración de la ejecución y petición del usuario |
| `case.yaml` | Resultado esperado |
| `graders/` | Una aserción verificable por archivo |
| `files/` | Fixture: el repositorio que se revisa, con `change.diff`. Cada archivo lleva el sufijo `.fixture` |
| `place-files.sh` | Copia el fixture al directorio de trabajo de la ejecución quitando el sufijo `.fixture` |

Casos:

- `sql-injection-swallowed-error`: debe detectar una inyección SQL (P0) y un `catch` vacío.
- `clean-operator-no-false-positives`: cambio correcto; no debe inventar defectos.
- `contract-change-stale-consumers`: debe encontrar consumidores fuera del diff que quedaron desactualizados.

Dos capas evitan que se confundan con código real:

1. **Sufijo `.fixture`:** ningún glob de código (`**/*.ts`), `tsc`, Vitest ni lector de `AGENTS.md` anidados los trata como archivos del proyecto.
2. **`.ignore` en la raíz:** las búsquedas basadas en ripgrep (Grep de Claude Code, OpenCode, Codex) omiten `files/`.

Para añadir o editar un fixture, crea el archivo con su nombre real más `.fixture` (por ejemplo, `src/app.ts.fixture`).
