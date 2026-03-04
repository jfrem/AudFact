# Estrategia de Despliegue y CI/CD

## Deploy Automatizado (CD)

El despliegue a producción está automatizado mediante **GitHub Actions**.

### Flujo

```
Push a main → CI (lint + tests) → CD (GitHub notifica Runner Local → git pull → docker compose up)
```

### Configuración

- **Host**: Runner instalado en servidor local (`172.16.0.3` usuario `admon`)
- **Autenticación**: Token de registro de GitHub Actions
- **Ruta Base**: `/home/admon/actions-runner`
- **Runtime**: Docker Compose (PHP-FPM x5 + Nginx)

### GitHub Secrets requeridos

| Secret | Descripción |
|---|---|
| `APP_ENV` | Entorno (`production`) |
| `DB_HOST` | Host SQL Server |
| `DB_PORT` | Puerto SQL Server (`1433`) |
| `DB_NAME` | Nombre de base de datos |
| `DB_USER` | Usuario BD |
| `DB_PASS` | Contraseña BD |
| `DB_ENCRYPT` | Cifrado de conexión (`no`/`yes`) |
| `DB_TRUST_SERVER_CERT` | Trust cert (`yes`/`no`) |
| `GEMINI_API_KEY` | API Key de Google Gemini |
| `ALLOWED_ORIGINS` | Orígenes CORS permitidos |
| `MCP_WEBHOOK_SECRET` | Secret del webhook MCP |
| `LOG_LEVEL` | Nivel de log (`info`) |
| `AUDIT_NGINX_READ_TIMEOUT` | Timeout lectura Nginx (`3600`) |

### Qué hace el deploy

1. **CI (GitHub-hosted)**: Lint PHP, validación Composer, PHPUnit
2. **CD (Self-hosted runner)**: Checkout del código
3. Genera `.env` desde GitHub Secrets
4. `docker compose down` → `docker compose up --build -d`
5. **Entrypoint autónomo** (por contenedor): detecta si falta `vendor/autoload.php` o si `composer.lock` cambió → ejecuta `composer install` automáticamente. También repara permisos de `logs/`.
6. Health check con **retry loop** (3 intentos, 10s entre cada uno)

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

### Rollback manual en servidor

```bash
ssh admon@172.16.0.3
cd /home/admon/AudFact

# Volver a un commit específico
git log --oneline -5
git checkout <commit-hash> -- .
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
