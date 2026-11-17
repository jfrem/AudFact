# SDD — Retiro de `AUDIT_RESPONSE_IA_DIR` y aislamiento solo-desarrollo de `AUDIT_RESPONSE_IA_ENABLED`

- **ID**: `SDD-RESPONSE-IA-ENV-CLEANUP-001`
- **Fecha**: 2026-11-17
- **Estado**: Especificación Técnica para Revisión Independiente
- **Caso disparador**: Hallazgo sobre `.env:L54` (`AUDIT_RESPONSE_IA_DIR=/var/www/html/logs/responseIA`) y evaluación bajo `/clean-rebuild-policy`: los artefactos de ingesta y respuesta LLM deben conservarse opcionalmente en desarrollo, nunca en producción. `ResponseIADiskStore` ya ejecuta hard-deny cuando `APP_ENV !== 'development'`, haciendo obsoleta `AUDIT_RESPONSE_IA_DIR` e innecesaria la exposición de `AUDIT_RESPONSE_IA_ENABLED` en CI/CD de producción.

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor |
| --- | --- |
| Tipo | Refactor / Infraestructura (higiene de configuración y variables de entorno) |
| Riesgo | Bajo |
| Persistencia afectada | No |
| Contrato externo afectado | No |
| Cambio arquitectónico | No |
| Producción afectada | Sí (retiro de variables inertes del `.env` generado en deploy y GitHub Environment `production`) |
| Requiere 0.3.1 (cobertura de abstracciones) | No |

Perfil: Reducido (cumple Persistencia afectada = `No`, Contrato externo afectado = `No`, Cambio arquitectónico = `No`, Producción afectada = `Sí`).

---

## FASE 0 — Descubrimiento Empírico

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| `ResponseIADiskStore.php` | `app/Services/Audit/ResponseIADiskStore.php` | `MODIFIED` | Eliminar `ENV_DIR` y `Env::get('AUDIT_RESPONSE_IA_DIR')`; resolver ruta por defecto automáticamente. | 18-30, 135-145 | Sí |
| `.env.example` | `.env.example` | `MODIFIED` | Retirar `AUDIT_RESPONSE_IA_DIR`; documentar `AUDIT_RESPONSE_IA_ENABLED` como exclusiva de `development`. | 52-55 | Sí |
| `.env` | `.env` | `MODIFIED` | Retirar `AUDIT_RESPONSE_IA_DIR` en el entorno local para mantener paridad con `.env.example`. | 53-55 | Sí |
| `deploy-production.yml` | `.github/workflows/deploy-production.yml` | `MODIFIED` | Retirar `AUDIT_RESPONSE_IA_ENABLED` y `AUDIT_RESPONSE_IA_DIR` del `.env` de producción. | 148-149, 368-369 | Sí |
| `sync-github-production-env.sh` | `scripts/sync-github-production-env.sh` | `MODIFIED` | Excluir `AUDIT_RESPONSE_IA_ENABLED` (`DEV_ONLY_KEYS`) de la sincronización a `production`. | 40-52, 190-200, 385-391 | Sí |
| `ResponseIADiskStoreTest.php` | `tests/Services/Audit/ResponseIADiskStoreTest.php` | `MODIFIED` | Inyectar `$this->baseDir` vía constructor en vez de mutar `AUDIT_RESPONSE_IA_DIR` en env. | 21-26, 42-125 | Sí |
| `GeminiGatewayTest.php` | `tests/Services/Audit/GeminiGatewayTest.php` | `MODIFIED` | Inyectar `diskStore: new ResponseIADiskStore($tempDir)` en vez de `Env::set('AUDIT_RESPONSE_IA_DIR', ...)`. | 244-303 | Sí |
| `AGENTS.md` | `AGENTS.md` | `MODIFIED` | Retirar fila `AUDIT_RESPONSE_IA_DIR` y aclarar alcance desarrollo-only de `AUDIT_RESPONSE_IA_ENABLED`. | 319-320 | Sí |
| `docker-operations.md` | `plans/docker-operations.md` | `MODIFIED` | Actualizar nota sobre `logs/responseIA` sin `AUDIT_RESPONSE_IA_DIR`. | 273 | Sí |
| `audfact-audit-gemini` | `.agent/skills/audfact-audit-gemini/SKILL.md` | `MODIFIED` | Retirar referencia a `AUDIT_RESPONSE_IA_DIR` en tabla de arquitectura y variables. | 85, 197 | Sí |
| `audfact-runtime-docker` | `.agent/skills/audfact-runtime-docker/SKILL.md` | `MODIFIED` | Retirar fila `AUDIT_RESPONSE_IA_DIR`. | 109-110 | Sí |
| `GeminiGateway.php` | `app/Services/Audit/GeminiGateway.php` | `INSPECTED` | Confirma parámetro `?ResponseIADiskStore $diskStore = null` ya disponible en constructor (`L54`). | 49-63 | Sí |
| `ci.yml` | `.github/workflows/ci.yml` | `INSPECTED` | `Validate env contract completeness` exige en `.env.example` toda clave usada en `Env::get`/`getenv`. | 109-129 | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda textual / símbolo | `git grep -n -E "ResponseIADiskStore\|AUDIT_RESPONSE_IA"` | Todas las ocurrencias inventariadas en código, tests, scripts, CI, skills y docs. | Salida observada en repo completa |
| Búsqueda en workflows/CI | `Select-String` en `.github/workflows/ci.yml` y `deploy-production.yml` | Únicas referencias en `deploy-production.yml:L148-L149,L368-L369` y regla de paridad en `ci.yml:L114-L128`. | Leído `ci.yml:1-165` y `deploy-production.yml:140-380` |
| Búsqueda operativa remota | `gh variable list --env production` | `[CONFIRMADO]` En GitHub `production` existían `AUDIT_RESPONSE_IA_ENABLED=0` y `AUDIT_RESPONSE_IA_DIR=C:/Program Files/Git/var/www/html/logs/responseIA`. | Ejecución CLI observada |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `ResponseIADiskStore.php` | `GeminiGateway` | `app/Services/Audit/GeminiGateway.php` | 54, 61 | Directa | Estática (inyección o default `new ResponseIADiskStore()`) | Repositorio local |
| `.env.example` | `ci.yml` | `.github/workflows/ci.yml` | 114-128 | Directa | Pipeline CI (`Env::get`/`getenv` vs `.env.example`) | Repositorio local |
| `.env.example` | `sync-github-production-env.sh` | `scripts/sync-github-production-env.sh` | 337-368 | Directa | Operacional (paridad estricta con `.env`) | Operación manual |
| `ResponseIADiskStore.php` | `ResponseIADiskStoreTest` | `tests/Services/Audit/ResponseIADiskStoreTest.php` | 48, 89, 102, 115 | Directa | Estática (`new ResponseIADiskStore($dir)`) | Repositorio local |
| `ResponseIADiskStore.php` | `GeminiGatewayTest` | `tests/Services/Audit/GeminiGatewayTest.php` | 248-266 | Directa | Estática + Env | Repositorio local |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Eliminar `AUDIT_RESPONSE_IA_DIR` de `ResponseIADiskStore` | `ResponseIADiskStoreTest` | `tests/Services/Audit/ResponseIADiskStoreTest.php:46,87,100,113` | Test | Pasar `$this->baseDir` al constructor `new ResponseIADiskStore($this->baseDir)`. |
| Eliminar `AUDIT_RESPONSE_IA_DIR` de `ResponseIADiskStore` | `GeminiGatewayTest` | `tests/Services/Audit/GeminiGatewayTest.php:249,262` | Test | Inyectar `diskStore: new ResponseIADiskStore($tempDir)` en `new GeminiGateway(...)`. |
| Dejar `AUDIT_RESPONSE_IA_ENABLED` en `.env.example` | `sync-github-production-env.sh` | `scripts/sync-github-production-env.sh:376-391` | Pipeline | Clasificar `AUDIT_RESPONSE_IA_ENABLED` en `DEV_ONLY_KEYS` para omitirla en sincronización a `production` sin romper paridad `.env`/`.env.example`. |
| Retirar `AUDIT_RESPONSE_IA_DIR` de `.env.example` | `.env` local | `.env:54` | DX / Pipeline | Retirar línea 54 del `.env` local y verificar sintaxis de `sync-github-production-env.sh`. |

---

### 0.4 Semántica de Herramientas y Entornos

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Docker Compose / PHP CLI Windows | `APP_ENV=development` + `AUDIT_RESPONSE_IA_ENABLED=1` | Sí | `ResponseIADiskStore::resolveDefaultBaseDir()` retorna `/var/www/html/logs/responseIA` (Docker) o `dirname(__DIR__, 3)/logs/responseIA` (Windows). |
| CI automatizado | GitHub Actions (`ci.yml`) | `Validate env contract completeness` + `phpunit` | Sí | `Env::get('AUDIT_RESPONSE_IA_ENABLED')` sigue en `.env.example`; `AUDIT_RESPONSE_IA_DIR` desaparece de ambos. |
| Producción | Deploy workflow (`deploy-production.yml`) | `APP_ENV=production` | Sí | `ResponseIADiskStore::isEnabledForEnvironment()` retorna `false` antes de tocar disco (`L148-L150`). |
| Testing aislado | PHPUnit 10.5 | `new ResponseIADiskStore($tempDir)` | Sí | Inyección directa por constructor evita mutar variables globales de ruta. |
| Staging / Preproducción | N/A | N/A | N/A | `[CONFIRMADO]` El workflow y compose operan desarrollo local y `production`. |

---

### 0.6 – 0.11 Inventario y Completitud Inicial

- **Información confirmada (`0.6`)**: Todo el universo de referencias fue leído directamente (`ResponseIADiskStore.php`, `GeminiGateway.php`, `deploy-production.yml`, `sync-github-production-env.sh`, `ci.yml`, `ResponseIADiskStoreTest.php`, `GeminiGatewayTest.php`).
- **Información faltante crítica / importante / opcional (`0.7–0.9`)**: Ninguna.
- **Supuestos declarados (`0.10`)**: Ninguno crítico o abierto (S1/S2: ninguno).
- **Clasificación inicial (`0.11`)**: **Nivel A — Implementable**.

---

## FASE 1 — Especificación

### 1. Objetivo

- **Problema actual**: `.env.example:L54`, `.env:L54` y `deploy-production.yml:L148-L149,L368-L369` declaran y propagan `AUDIT_RESPONSE_IA_DIR` y `AUDIT_RESPONSE_IA_ENABLED` hacia producción, a pesar de que `ResponseIADiskStore` desactiva toda escritura cuando `APP_ENV !== 'development'` (`L148-L150`) y resuelve automáticamente la ruta por defecto (`logs/responseIA`). Como consecuencia, GitHub Environment `production` acumuló incluso una ruta inválida convertida por Git Bash (`C:/Program Files/Git/var/www/html/logs/responseIA`).
- **Resultado esperado**:
  1. Eliminar `AUDIT_RESPONSE_IA_DIR` de código, configuración, workflows y pruebas (tests usan inyección por constructor).
  2. Mantener `AUDIT_RESPONSE_IA_ENABLED` únicamente como palanca de desarrollo local (`APP_ENV=development`), excluyéndola de `deploy-production.yml` y de la sincronización remota hacia `production` en `scripts/sync-github-production-env.sh`.

### 2. Alcance

- **Incluido**: `ResponseIADiskStore.php`, `.env.example`, `.env`, `.github/workflows/deploy-production.yml`, `scripts/sync-github-production-env.sh`, `ResponseIADiskStoreTest.php`, `GeminiGatewayTest.php`, `AGENTS.md`, `plans/docker-operations.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`, y limpieza remota en GitHub Environment `production` (`gh variable delete`).
- **Excluido**: Funcionalidad de snapshots redactados en `APP_ENV=development` (debe seguir guardando snapshots en `logs/responseIA` cuando `AUDIT_RESPONSE_IA_ENABLED=1`); no se modifica `GeminiGateway` ni su firma.

### 6.1 Estrategia de Cambio y Excepciones (`clean-rebuild-policy`)

| Dimensión | Valor |
| --- | --- |
| Estrategia | `Eliminar` (sobre `AUDIT_RESPONSE_IA_DIR` como env var) + `Refactorización incremental` (aislar `AUDIT_RESPONSE_IA_ENABLED` de producción preservando su uso local en desarrollo). |
| Fila de la compuerta aplicada | «Componente reemplazado y sin consumidores verificables → Eliminar» (`AUDIT_RESPONSE_IA_DIR` sin consumidor productivo; tests cubiertos por constructor) + «Componente productivo con consumidores activos → Refactorización incremental» (`AUDIT_RESPONSE_IA_ENABLED` usado en desarrollo). |
| Candidatas descartadas y desempate | Descartado eliminar `ResponseIADiskStore` o `AUDIT_RESPONSE_IA_ENABLED` porque el desarrollador requiere guardar y conmutar artefactos LLM en desarrollo local. |
| Fuente del alcance MVP | Solicitud explícita del usuario sobre `.env:L54` y `/clean-rebuild-policy`. |

**Excepciones:** Ninguna.

### 11. Cambios Detallados de Diseño

#### 11.1 `app/Services/Audit/ResponseIADiskStore.php`

- Eliminar constante `private const ENV_DIR = 'AUDIT_RESPONSE_IA_DIR';`.
- Reemplazar `self::configuredBaseDir()` y `DEFAULT_BASE_DIR` por `self::resolveDefaultBaseDir()`:
  ```php
  private static function resolveDefaultBaseDir(): string
  {
      return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'responseIA';
  }
  ```
- Conservar `public function __construct(?string $baseDir = null)` para pruebas unitarias e inyección limpia.
- Conservar `isEnabledForEnvironment(string $appEnv)` con hard-deny cuando `$appEnv !== 'development'` y lectura de `AUDIT_RESPONSE_IA_ENABLED` (default `'1'`) únicamente en desarrollo.

#### 11.2 Configuración Local y CI/CD

- **`.env.example` y `.env`**:
  - Eliminar línea `AUDIT_RESPONSE_IA_DIR=...`.
  - Documentar con comentario en `.env.example` que `AUDIT_RESPONSE_IA_ENABLED` solo aplica cuando `APP_ENV=development` (producción siempre desactiva snapshots por código).
- **`.github/workflows/deploy-production.yml`**:
  - Retirar `AUDIT_RESPONSE_IA_ENABLED` y `AUDIT_RESPONSE_IA_DIR` de `env:` (`L148-L149`) y de `write_env_var` (`L368-L369`).
- **`scripts/sync-github-production-env.sh`**:
  - Declarar `DEV_ONLY_KEYS=(AUDIT_RESPONSE_IA_ENABLED)` y cuando `$GITHUB_ENVIRONMENT == "production"`, omitir `DEV_ONLY_KEYS` de `variable_keys_to_sync` (reportándolo en salida informativa).
  - En `generate_env()` para `production`, omitir `DEV_ONLY_KEYS` (o no escribir variables exclusivas de desarrollo).

#### 11.3 Pruebas Unitarias (`ResponseIADiskStoreTest` y `GeminiGatewayTest`)

- **`tests/Services/Audit/ResponseIADiskStoreTest.php`**:
  - Retirar backup/mutación de `AUDIT_RESPONSE_IA_DIR`.
  - Instanciar `new ResponseIADiskStore($this->baseDir)` en los tests de persistencia y de entorno.
  - Agregar `testDefaultBaseDirResolvesWithoutEnvVar()` verificando que `new ResponseIADiskStore()` funciona sin `AUDIT_RESPONSE_IA_DIR` y que en `production` nunca persiste ni crea directorios.
- **`tests/Services/Audit/GeminiGatewayTest.php`**:
  - En `testSendSingleAttemptPersistsSnapshotInDevelopment()`, retirar `\Core\Env::set('AUDIT_RESPONSE_IA_DIR', $tempDir)` y construir `$gateway` con `diskStore: new ResponseIADiskStore($tempDir)`.

### 16. Criterios de Aceptación Verificables

1. `git grep -n "AUDIT_RESPONSE_IA_DIR"` no retorna ocurrencias en `app/`, `tests/`, `.env.example`, `.github/`, `AGENTS.md`, `plans/docker-operations.md` ni `.agent/skills/`.
2. `git grep -n "AUDIT_RESPONSE_IA_ENABLED"` no retorna ocurrencias en `.github/workflows/deploy-production.yml`.
3. `php vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` pasa al 100% sin errores, fallos ni regresiones.
4. La comprobación equivalente a `Validate env contract completeness` (`.github/workflows/ci.yml:L114-L128`) confirma que toda variable usada con `Env::get`/`getenv` en PHP está declarada en `.env.example`.
5. `bash -n scripts/sync-github-production-env.sh` pasa sin errores de sintaxis.
6. `node .agent/skills/_shared/scripts/validate-skills.mjs` retorna `PASS`.

---

## FASE 2 y FASE 3 — Auto-auditoría Adversarial y Nivel Final

- Todas las secciones del perfil reducido cubiertas con evidencia directa de lectura (`PASS`).
- **Nivel Final**: **Nivel A — Implementable**.
