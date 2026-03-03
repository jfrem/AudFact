# Control de Versiones (Git) — AudFact

## Estado actual

> ⚠️ El repositorio **puede no estar inicializado**. Antes de cualquier operación Git, verificar con `git status`. Si no existe repo, inicializarlo siguiendo el procedimiento de esta sección.

## Inicialización del repositorio

Si el proyecto no tiene Git inicializado:

```bash
# 1. Inicializar
git init

# 2. Crear .gitignore ANTES del primer commit
# (ver contenido obligatorio abajo)

# 3. Primer commit
git add .
git commit -m "chore: inicializar repositorio AudFact"

# 4. Configurar remote (si aplica)
git remote add origin <url-del-repositorio>
git push -u origin main
```

## .gitignore obligatorio

El archivo `.gitignore` **debe existir** antes del primer commit. Contenido mínimo:

```gitignore
# Dependencias
/vendor/

# Variables de entorno (credenciales)
.env
.env.dev
.env.prod
.env.test

# Logs
/logs/*.log
/logs/*.txt

# Respuestas crudas de IA (debug)
/responseIA/

# IDE y editores
.idea/
.vscode/
*.swp
*.swo
*~

# Sistema operativo
Thumbs.db
.DS_Store

# Archivos temporales
*.tmp
*.bak

# Composer
composer.phar

# Docker (volúmenes locales)
docker/data/
```

**NUNCA deben entrar al repo**: `.env`, `logs/`, `vendor/`, `responseIA/`, credenciales, API keys.

## Estrategia de branching

Se usa **Git Flow simplificado** adaptado para el proyecto:

```
main ← rama de producción estable
  └── develop ← rama de integración
        ├── feature/<nombre> ← nuevas funcionalidades
        ├── fix/<nombre> ← correcciones de bugs
        ├── refactor/<nombre> ← refactorizaciones
        └── security/<nombre> ← parches de seguridad
```

| Branch | Propósito | Se crea desde | Se mergea a |
|---|---|---|---|
| `main` | Producción estable | — | — |
| `develop` | Integración y pruebas | `main` | `main` (via merge) |
| `feature/*` | Nueva funcionalidad | `develop` | `develop` |
| `fix/*` | Corrección de bug | `develop` | `develop` |
| `refactor/*` | Reorganización de código | `develop` | `develop` |
| `security/*` | Parche de seguridad urgente | `main` | `main` + `develop` |

## Nomenclatura de branches

```
feature/agregar-timeout-auditoria
fix/C01-eliminar-exit-response
refactor/rate-limit-apcu-driver
security/C05-autenticar-webhook-mcp
```

Reglas:
- Nombres en **español** o en **inglés técnico** (consistente dentro del proyecto)
- Usar **kebab-case** (minúsculas, guiones)
- Incluir **ID de hallazgo** si aplica: `fix/C01-descripcion`
- Máximo **50 caracteres** en el nombre de la rama

## Flujo de trabajo Git (paso a paso)

Este flujo se integra con el tablero Kanban de la sección "Planificación Pre-Implementación":

```
1. Plan aprobado (📌→🛠️)
   └── git checkout develop
   └── git pull origin develop
   └── git checkout -b feature/nombre-tarea

2. Implementación (🧑‍💻 In Dev)
   └── Hacer cambios
   └── git add <archivos-específicos>   ← NUNCA usar git add .
   └── git commit -m "tipo(ámbito): descripción"

3. Code Review (🔍)
   └── git push origin feature/nombre-tarea
   └── Crear Pull Request hacia develop
   └── Solicitar revisión

4. QA / Testing (🧪)
   └── Verificar en branch de feature
   └── Ejecutar tests

5. Merge (📦→✅)
   └── git checkout develop
   └── git merge --no-ff feature/nombre-tarea
   └── git push origin develop
   └── git branch -d feature/nombre-tarea
```

## Commits

### Formato (Conventional Commits en español)

```
<tipo>(<ámbito>): <descripción breve>

feat(audit): agregar timeout de 120s al batch de auditoría
fix(models): cerrar cursor PDO después de fetch en AttachmentsModel
refactor(core): extraer rate limit a interfaz + driver APCu
docs(agents): crear AGENTS.md con guidelines del proyecto
chore(docker): condicionar instalación de Xdebug
security(wrap): C05 agregar autenticación API key al webhook
```

### Tipos permitidos

| Tipo | Uso |
|---|---|
| `feat` | Nueva funcionalidad |
| `fix` | Corrección de bug |
| `refactor` | Cambio de código sin cambiar comportamiento |
| `docs` | Documentación |
| `chore` | Tareas de mantenimiento, dependencias |
| `test` | Agregar o modificar tests |
| `perf` | Mejora de rendimiento |
| `security` | Corrección de seguridad |

### Reglas de commits

- **Atómicos**: un commit = un cambio lógico. No mezclar refactors con features
- **Específicos**: usar `git add <archivo>` en lugar de `git add .`
- **Verificados**: asegurarse de que el código funciona antes de commitear
- **Referenciados**: si resuelve un hallazgo → `fix(core): C01 eliminar exit() de Response`
- **Sin archivos prohibidos**: `.env`, `logs/`, `vendor/`, `responseIA/`, `composer.lock`

## Cuándo hacer commit

| Situación | ¿Commitear? | Ejemplo |
|---|---|---|
| Feature completa y probada | ✅ Sí | `feat(audit): agregar límite de archivos` |
| Fix de bug verificado | ✅ Sí | `fix(models): corregir query de attachments` |
| Antes de un refactor riesgoso | ✅ Sí (checkpoint) | `chore: checkpoint antes de refactor rate-limit` |
| Código a medio hacer | ❌ No | — |
| Solo cambios de formato | ⚠️ Separar | `chore(format): aplicar prettier a controllers` |
| Documentación | ✅ Sí | `docs(agents): agregar sección de Git` |

## Protecciones y reglas de seguridad

```
⛔ PROHIBIDO en Git:
├── Commitear .env o archivos con credenciales
├── Force push a main o develop
├── Commitear directamente a main (siempre via merge desde develop)
├── Eliminar branches remotas sin aprobación
├── Rebase de branches compartidas
└── Hacer git add . (agregar archivos uno por uno)

⚠️ REQUIERE APROBACIÓN del usuario:
├── Merge a main
├── Crear tags/releases
├── Resolver conflictos (mostrar ambos lados al usuario)
├── Crear/eliminar git stash
└── Cambiar de branch cuando hay cambios sin commitear
```

## Resolución de conflictos

Cuando ocurra un conflicto de merge:

1. **Nunca resolver automáticamente** — siempre informar al usuario
2. Mostrar ambos lados del conflicto con contexto
3. Proponer resolución con justificación
4. Esperar aprobación antes de aplicar
5. Después del merge, verificar que el código funciona

```bash
# Ver archivos en conflicto
git diff --name-only --diff-filter=U

# Después de resolver
git add <archivo-resuelto>
git commit -m "fix: resolver conflicto en <archivo> (merge develop)"
```

## Tags y releases

Para marcar versiones estables:

```bash
# Formato semántico: v<major>.<minor>.<patch>
git tag -a v1.0.0 -m "release: versión inicial estable"
git push origin v1.0.0
```

| Cambio | Bump |
|---|---|
| Breaking change / cambio de API | Major (v**2**.0.0) |
| Nueva funcionalidad compatible | Minor (v1.**1**.0) |
| Bug fix | Patch (v1.0.**1**) |

**Solo crear tags con aprobación del usuario.**
