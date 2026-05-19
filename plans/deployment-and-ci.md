# Estrategia de Despliegue y CI/CD

## Deploy Automatizado (CD)

El despliegue a producción está automatizado mediante **GitHub Actions**.

### Flujo

```
Push a main → CI (lint + tests) → Publish Images → CD (self-hosted runner: checkout → generate .env → pull GHCR images → SQL preflight → docker compose up → health check)
```

### Configuración

- **Host**: Runner instalado en servidor local (`172.16.0.3` usuario `admon`)
- **Autenticación**: Token de registro de GitHub Actions
- **Ruta Base**: `/home/admon/actions-runner`
- **Runtime**: Docker Compose sobre runner self-hosted (`docker compose up --build -d`). La definición conserva intención HA, pero el workflow actual no debe presentarse como orquestación multi-réplica garantizada.

### GitHub Secrets requeridos

| Secret | Requerido | Descripción |
|---|---|---|
| `APP_ENV` | ✅ | Entorno (`production`) |
| `DB_HOST` | ✅ | Host SQL Server escritura sin instancia ni puerto embebido (ej: `169.46.6.53`) |
| `DB_PORT` | ✅ | Puerto SQL Server (`1433`) |
| `DB_NAME` | ✅ | Nombre de base de datos |
| `DB_USER` | ✅ | Usuario BD escritura |
| `DB_PASS` | ✅ | Contraseña BD escritura |
| `DB_ENCRYPT` | ✅ en prod | Cifrado conexión principal (`no` temporal en este entorno; objetivo futuro: `yes`) |
| `DB_TRUST_SERVER_CERT` | ✅ en prod | Trust cert conexión principal (`yes` temporal sin certificado válido; objetivo futuro: `no`) |
| `DB2_HOST` | ✅ | Host SQL Server lectura sin instancia ni puerto embebido (ej: `169.46.6.55`) |
| `DB2_PORT` | ✅ | Puerto SQL Server lectura (`1433`) |
| `DB2_NAME` | ✅ | Nombre de BD lectura |
| `DB2_USER` | ✅ | Usuario BD lectura |
| `DB2_PASS` | ✅ | Contraseña BD lectura |
| `DB2_ENCRYPT` | ✅ en prod | Cifrado conexión lectura (`no` temporal en este entorno; objetivo futuro: `yes`) |
| `DB2_TRUST_SERVER_CERT` | ✅ en prod | Trust cert conexión lectura (`yes` temporal sin certificado válido; objetivo futuro: `no`) |
| `GOOGLE_DRIVE_CLIENT_EMAIL` | — | Email de service account de Google Drive |
| `GOOGLE_DRIVE_PRIVATE_KEY` | — | Clave privada PEM de la service account |
| `GEMINI_API_KEY` | ✅ | API Key de Google Gemini |
| `ALLOWED_ORIGINS` | — | Orígenes CORS permitidos |
| `MCP_WEBHOOK_SECRET` | — | Secret del webhook MCP |
| `LOG_LEVEL` | — | Nivel de log (`info`, default: `info`) |
| `AUDIT_NGINX_READ_TIMEOUT` | — | Timeout lectura Nginx (default: `3600`) |

### GitHub Variables opcionales

| Variable | Requerido | Descripción |
|---|---|---|
| `AUDFACT_FRONTEND_HOST_PORT` | — | Puerto LAN del frontend productivo (`3100` por defecto) |
| `AUDFACT_FRONTEND_PUBLIC_URL` | — | Origen público del frontend para CORS |
| `AUDFACT_API_PUBLIC_URL` | — | URL pública del backend para generar `WEBHOOK_URL` y `CAPABILITIES_URL` |


### Qué hace el deploy

1. **CI (GitHub-hosted)**: Lint PHP, validación Composer, PHPUnit
2. **CI Frontend (GitHub-hosted)**: `npm ci`, `npm run lint`, `npm run build`
3. **Publish Images (GitHub-hosted)**: construye y publica imagenes inmutables en GHCR por SHA.
4. **CD (self-hosted runner)**: checkout de archivos de despliegue (`clean: true`).
5. Genera `.env` dinámicamente desde GitHub Secrets con hosts SQL normalizados a host/IP limpio.
6. Ejecuta preflight SQL con la imagen PHP publicada antes de recrear el stack.
7. En `APP_ENV=production`, el workflow exige temporalmente `DB_ENCRYPT=no`, `DB_TRUST_SERVER_CERT=yes`, `DB2_ENCRYPT=no` y `DB2_TRUST_SERVER_CERT=yes` porque la infraestructura actual falla incluso con `Encrypt=yes;TrustServerCertificate=yes`.
8. `docker compose pull` → `docker compose up -d --remove-orphans`
9. Health check con **retry loop** (5 intentos, 10s entre cada uno)

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

6. **`DB_HOST appears malformed` en `Create production environment file`**
   - Causa: GitHub Secret `DB_HOST` o `DB2_HOST` contiene instancia, puerto embebido o un host ya corrupto por escaping.
   - Valores correctos en `production`: `DB_HOST=169.46.6.53`, `DB2_HOST=169.46.6.55`, `DB_PORT=1433`, `DB2_PORT=1433`.
   - Prevención: mantener hosts SQL como host/IP limpio y dejar el puerto en `DB_PORT`/`DB2_PORT`.
   - Acción: corregir secrets y relanzar `Deploy Production - AudFact`; no parchear el `.env` del host como solución permanente.

7. **`/health` unhealthy con `Login timeout expired`**
   - Causa probable: conectividad SQL rota desde PHP-FPM o secrets SQL mal escritos.
   - Evidencia esperada: `php` unhealthy, workers reiniciando y logs con `SQLSTATE[HYT00]`.
   - Diagnóstico: probar `/health`, `docker compose ps`, logs de `php`/workers y preflight PDO/sqlsrv desde la imagen PHP.
   - Prevención: el workflow actual ejecuta `Preflight SQL connectivity` antes de recrear el stack.

### Condiciones de ejecución

- Solo se activa en **push a `main`** (no en PRs ni feature branches)
- Requiere que el job `lint` (CI) pase exitosamente
- El deploy del frontend requiere que su job de validación (`lint` + `build`) pase antes de tocar el runner

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
- [ ] `DB_HOST` y `DB2_HOST` configurados como host/IP limpio, sin instancia ni puerto embebido
- [ ] `APP_ENV=production` en Secrets
- [ ] `DB_ENCRYPT=no`, `DB_TRUST_SERVER_CERT=yes`, `DB2_ENCRYPT=no`, `DB2_TRUST_SERVER_CERT=yes`
- [ ] Existe plan para migrar a `DB_ENCRYPT=yes`, `DB2_ENCRYPT=yes`, `DB_TRUST_SERVER_CERT=no` y `DB2_TRUST_SERVER_CERT=no` al disponer de certificado válido
- [ ] Tests unitarios pasan (CI automático)
- [ ] `vendor/` se instala automáticamente por el entrypoint (no requiere paso manual)
- [ ] Build frontend no contiene URLs locales de backend embebidas; el navegador consume `/api/backend/*`

---

## Pipeline CI (Verificación)

| Etapa | Herramienta | Objetivo |
|---|---|---|
| **Lint** | `php -l` | Detectar errores de sintaxis |
| **Estructura** | Script custom | Validar directorios obligatorios |
| **Secrets Scan** | `grep` | Detectar credenciales hardcodeadas |
| **Unit Tests** | PHPUnit | Validar lógica core |
| **Frontend Lint** | ESLint/Next | Validar calidad estática del frontend |
| **Frontend Build** | Next.js | Verificar que el bundle de producción compila |

### Branches monitoreados
- `main`, `develop`, `feature/*` (push)
- `main`, `develop` (pull_request)

## Riesgo diferido

La autenticación/autorización de endpoints críticos permanece fuera de este sprint. Debe tratarse como trabajo P0/P1 del siguiente ciclo y no debe confundirse con una remediación ya implementada por este endurecimiento del pipeline.
La validación completa del certificado SQL Server también queda diferida temporalmente. En este entorno, el despliegue opera con `Encrypt=no` y `TrustServerCertificate=yes` hasta que infraestructura remedie la conectividad TLS del servidor.

## Registro operativo reciente

### 2026-05-13 — Blindaje de hosts SQL en deploy

Produccion fallo porque el workflow genero hosts SQL invalidos en `/home/admon/audfact-prod/.env`:

```text
DB_HOST=169.46.6.53SQL2022
DB2_HOST=169.46.6.55SQL2022_REPLICA
```

La causa fue el formato de los GitHub Secrets y el manejo del heredoc al escribir `.env`. La correccion permanente fue:

```text
DB_HOST=169.46.6.53
DB2_HOST=169.46.6.55
```

El workflow ahora normaliza hosts SQL, rechaza valores malformados y ejecuta preflight PDO/sqlsrv antes de levantar contenedores. La corrida manual `Deploy Production - AudFact` `25812026509` confirmo el flujo completo exitoso: `Create production environment file`, `Pull release images`, `Preflight SQL connectivity`, `Start production stack` y `Health check`.
