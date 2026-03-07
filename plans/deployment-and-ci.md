# Estrategia de Despliegue y CI/CD

## Deploy Automatizado (CD)

El despliegue a producción está automatizado mediante **GitHub Actions**.

### Flujo

```
Push a main → CI (lint + tests) → CD (Self-hosted runner: checkout → generate .env → docker compose up --build → health check → Zero-Source Host Purge)
```

### Configuración

- **Host**: Runner instalado en servidor local (`172.16.0.3` usuario `admon`)
- **Autenticación**: Token de registro de GitHub Actions
- **Ruta Base**: `/home/admon/actions-runner`
- **Runtime**: Docker Compose (PHP-FPM x5 + Nginx)

### GitHub Secrets requeridos

| Secret | Requerido | Descripción |
|---|---|---|
| `APP_ENV` | ✅ | Entorno (`production`) |
| `DB_HOST` | ✅ | Host SQL Server escritura (ej: `169.46.6.53\SQL2022`) |
| `DB_PORT` | ✅ | Puerto SQL Server (`1433`) |
| `DB_NAME` | ✅ | Nombre de base de datos |
| `DB_USER` | ✅ | Usuario BD escritura |
| `DB_PASS` | ✅ | Contraseña BD escritura |
| `DB_ENCRYPT` | — | Cifrado de conexión (`no`/`yes`, default: `no`) |
| `DB_TRUST_SERVER_CERT` | — | Trust cert (`yes`/`no`, default: `yes`) |
| `DB2_HOST` | ✅ | Host SQL Server lectura (ej: `169.46.6.55\SQL2022_REPLICA`) |
| `DB2_PORT` | ✅ | Puerto SQL Server lectura (`1433`) |
| `DB2_NAME` | ✅ | Nombre de BD lectura |
| `DB2_USER` | ✅ | Usuario BD lectura |
| `DB2_PASS` | ✅ | Contraseña BD lectura |
| `GOOGLE_DRIVE_CLIENT_EMAIL` | — | Email de service account de Google Drive |
| `GOOGLE_DRIVE_PRIVATE_KEY` | — | Clave privada PEM de la service account |
| `GEMINI_API_KEY` | ✅ | API Key de Google Gemini |
| `ALLOWED_ORIGINS` | — | Orígenes CORS permitidos |
| `MCP_WEBHOOK_SECRET` | — | Secret del webhook MCP |
| `LOG_LEVEL` | — | Nivel de log (`info`, default: `info`) |
| `AUDIT_NGINX_READ_TIMEOUT` | — | Timeout lectura Nginx (default: `3600`) |


### Qué hace el deploy

1. **CI (GitHub-hosted)**: Lint PHP, validación Composer, PHPUnit
2. **CD (Self-hosted runner)**: Fix de permisos Docker + Checkout del código (`clean: true`)
3. Genera `.env` dinámicamente desde GitHub Secrets (con validación de secrets requeridos)
4. `docker compose down` → `docker compose up --build -d`
5. **Entrypoint autónomo** (por contenedor): detecta si falta `vendor/autoload.php` o si `composer.lock` cambió → ejecuta `composer install` automáticamente. También repara permisos de `logs/`.
6. Health check con **retry loop** (3 intentos, 10s entre cada uno)
7. **Zero-Source Host Purge** (Lean Production 3.0): Elimina todo el código fuente y metadatos del workspace del runner, dejando solo `.env`, `docker-compose.yml`, `logs/` y `.git`

### Inconsistencias recurrentes y cómo evitarlas

1. **`_work/AudFact` aparece vacío después del deploy**
   - Causa: comportamiento esperado del paso `Zero-Source Host Purge`.
   - Prevención: no usar inspección post-job del workspace como criterio de fallo.
   - Verificación correcta: revisar logs del workflow en GitHub y/o `_diag/Worker_*.log`.

2. **Intentar ejecutar YAML del workflow en shell SSH**
   - Causa: bloques como `- name:` y `run:` son sintaxis YAML, no comandos bash.
   - Prevención: ejecutar solo comandos Linux en SSH; editar YAML en `.github/workflows/ci.yml`.

3. **`GITHUB_WORKSPACE` vacío al conectarse por SSH**
   - Causa: esa variable existe dentro del job de GitHub Actions, no en sesiones interactivas normales.
   - Prevención: para depuración, agregar un step temporal en el workflow que imprima `GITHUB_WORKSPACE`, `pwd` y `ls -la`.

4. **No aparecen logs nuevos en `_diag/Worker_*`**
   - Causa más común: el job `deploy` nunca se ejecutó en self-hosted porque `lint` falló antes en `ubuntu-latest`.
   - Prevención: validar primero el estado del job `lint` en la corrida de Actions.

5. **Fallo de tests por `withConsecutive()` en PHPUnit 10**
   - Causa: `withConsecutive()` fue removido en PHPUnit 10.
   - Prevención: usar `willReturnCallback()` + contador/aserciones por invocación en mocks.

### Condiciones de ejecución

- Solo se activa en **push a `main`** (no en PRs ni feature branches)
- Requiere que el job `lint` (CI) pase exitosamente

---

## Procedimiento de Rollback y Recuperación

### Rollback automático (recomendado)

```bash
# Revertir último commit y hacer push — CD se re-ejecuta automáticamente
git revert HEAD --no-edit
git push origin main
```

### Rollback manual en servidor (solo emergencia)

```bash
ssh admon@172.16.0.3
cd /home/admon/actions-runner

# Nota: con Zero-Source activo, el workspace puede quedar vacío tras un deploy exitoso.
# En condiciones normales, usar rollback automático por git revert desde repositorio remoto.
# Si necesitas recuperación manual, primero fuerza un checkout limpio en un directorio temporal:
mkdir -p /tmp/audfact-rollback && cd /tmp/audfact-rollback
git clone https://github.com/jfrem/AudFact.git .
git checkout <commit-hash>
docker compose down && docker compose up --build -d
```

### Rollback por backup

```bash
# ANTES de deployar (si se hace manual)
cp -r /home/admon/AudFact /home/admon/AudFact.backup.$(date +%F)

# Si algo falla
rm -rf /home/admon/AudFact
mv /home/admon/AudFact.backup.YYYY-MM-DD /home/admon/AudFact
```

---

## Checklist Pre-Deploy

- [ ] Código funciona en entorno local Docker
- [ ] Health check (`/health`) responde correctamente
- [ ] GitHub Secrets de producción configurados (ver tabla arriba)
- [ ] `APP_ENV=production` en Secrets
- [ ] Tests unitarios pasan (CI automático)
- [ ] `vendor/` se instala automáticamente por el entrypoint (no requiere paso manual)

---

## Pipeline CI (Verificación)

| Etapa | Herramienta | Objetivo |
|---|---|---|
| **Lint** | `php -l` | Detectar errores de sintaxis |
| **Estructura** | Script custom | Validar directorios obligatorios |
| **Secrets Scan** | `grep` | Detectar credenciales hardcodeadas |
| **Unit Tests** | PHPUnit | Validar lógica core |

### Branches monitoreados
- `main`, `develop`, `feature/*` (push)
- `main`, `develop` (pull_request)
