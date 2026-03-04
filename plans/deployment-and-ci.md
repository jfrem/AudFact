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
| `SSH_HOST` | IP del servidor (`172.16.0.3`) |
| `SSH_USER` | Usuario SSH (`admon`) |
| `SSH_PASSWORD` | Contraseña SSH |
| `SSH_PORT` | Puerto SSH (`22`) |
| `DEPLOY_PATH` | Ruta absoluta (`/home/admon/AudFact`) |

### Qué hace el deploy

1. Conecta al servidor vía SSH
2. `git pull origin main` — descarga últimos cambios
3. `docker compose down` — detiene contenedores
4. `docker compose up --build -d` — reconstruye y levanta
5. Health check: `curl http://localhost:8080/`

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
- [ ] Health check (`/`) responde correctamente
- [ ] Variables de entorno de producción configuradas (`.env`)
- [ ] `APP_ENV=production`
- [ ] `composer install --no-dev` (sin dependencias de desarrollo)
- [ ] Tests unitarios pasan (CI)

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
