# Runbooks de Produccion AudFact

## Reglas

- Ejecutar diagnosticos primero y cambios despues.
- Pedir aprobacion explicita para deploy, rollback, restart, cambios de runner, cambios de `.env`, borrados, instalacion de paquetes o reboot.
- No imprimir `.env`, keys, passwords, tokens ni datos de pacientes.
- Usar GitHub Actions para deploy normal. SSH es para diagnostico, preparacion y recuperacion.

## Conexion SSH

Usar el wrapper desde el repo local:

```powershell
$env:AUDFACT_SSH_PASSWORD = 'PASSWORD_DE_LA_SESION'
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "pwd"
Remove-Item Env:\AUDFACT_SSH_PASSWORD
```

El wrapper usa `C:\WINDOWS\System32\OpenSSH\ssh.exe`, fuerza `SSH_ASKPASS`, crea un helper temporal sin password escrita y lo elimina al salir.

## Identidad y Host

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "pwd; whoami; id; hostname; uname -a"
```

Valores esperados:

- Usuario remoto: `admon`
- Home remoto: `/home/admon`
- Hostname observado: `apps`
- OS observado: Ubuntu 24.04 LTS
- Grupos utiles: `docker`, `sudo`

## Docker y Compose

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "docker --version; docker compose version"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'"
```

Para el stack productivo:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && docker compose ps"
```

## Runner Self Hosted

Servicio esperado:

```text
actions.runner.jfrem-AudFact.produccion-audfact.service
```

Comandos:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "systemctl is-active actions.runner.jfrem-AudFact.produccion-audfact.service"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "systemctl status actions.runner.jfrem-AudFact.produccion-audfact.service --no-pager"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/actions-runner && grep -E 'agentName|gitHubUrl|workFolder' .runner"
```

En GitHub, el runner debe estar online y tener labels:

```text
self-hosted
audfact-prod-lan
```

## Health Check

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "curl -sf http://localhost:8080/health"
```

Si falla:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && docker compose logs --tail=120 nginx php worker-batch worker-orchestrator worker-downloader worker-extraction worker-normalizer worker-policy worker-aggregator"
```

## Deploy Normal con GitHub Actions

El flujo correcto es:

```text
CI - AudFact
Publish Images - AudFact
Deploy Production - AudFact
```

El job de deploy debe correr en:

```yaml
runs-on: [self-hosted, audfact-prod-lan]
environment: production
```

Sincronizar GitHub Environment desde un `.env` productivo local:

```bash
bash scripts/sync-github-production-env.sh --dry-run
bash scripts/sync-github-production-env.sh --apply
```

El script escribe GitHub Secrets/Variables, no copia `.env` al host. El workflow
regenera `/home/admon/audfact-prod/.env` durante el deploy.

Checklist antes de disparar deploy:

- El runner esta `active`.
- GitHub Environment `production` tiene Secrets/Variables requeridos.
- Variables SQL de produccion usan host/IP limpio:
  - `DB_HOST=<PROD_DB_HOST>`
  - `DB2_HOST=<PROD_DB2_HOST_NEW>`
  - `DB_PORT=1433`
  - `DB2_PORT=1433`
- GHCR contiene imagenes `audfact-php:SHA` y `audfact-nginx:SHA`.
- El usuario aprobo deploy.

## Deploy Manual por SSH

Usar solo si el usuario lo pidio o si GitHub Actions no esta disponible. Requiere SHA concreto.

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "mkdir -p /home/admon/audfact-prod/logs"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=IMAGE_SHA docker compose pull"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=IMAGE_SHA docker compose --profile frontend up -d --no-build --remove-orphans"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "curl -sf http://localhost:8080/health"
```

Antes de manual deploy, verificar que `/home/admon/audfact-prod/.env` y `docker-compose.yml` existen. No imprimir `.env`.

## Rollback

Preferir workflow manual `Deploy Production - AudFact` con `image_tag` igual al SHA anterior.

Rollback manual, solo con autorizacion:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=SHA_ANTERIOR docker compose pull"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=SHA_ANTERIOR docker compose --profile frontend up -d --no-build --remove-orphans"
```

## Troubleshooting

`Permission denied`:

- Verificar password de la sesion.
- Confirmar usuario `admon`.
- No usar `ssh` generico si resuelve a `.sbx-denybin`.
- Usar el wrapper o el binario `C:\WINDOWS\System32\OpenSSH\ssh.exe`.

Job de GitHub queda esperando runner:

- Verificar labels `self-hosted` y `audfact-prod-lan`.
- Verificar que el servicio esta activo.
- Verificar en GitHub que el runner pertenece a `jfrem/AudFact`.

`docker compose pull` falla:

- Revisar login a GHCR en workflow.
- Verificar permisos `packages: read`.
- Verificar que el tag SHA existe.

Health check falla:

- Revisar logs de `nginx` y `php`.
- Confirmar que el puerto `8080` esta publicado.
- Confirmar que Redis y workers estan activos.

`Create production environment file` falla con `DB_HOST appears malformed`:

- Causa: la GitHub Variable `DB_HOST` o `DB2_HOST` del Environment `production` no es host/IP limpio.
- Valores correctos actuales:
  - `DB_HOST=<PROD_DB_HOST>`
  - `DB2_HOST=<PROD_DB2_HOST_NEW>`
- Corregir variables y relanzar el workflow. No editar `/home/admon/audfact-prod/.env` como solucion permanente porque el deploy lo regenera.

`/health` falla con `database unreachable` o `Login timeout expired`:

- Comparar `curl -sS http://localhost:8080/health` desde el host productivo.
- Revisar `docker compose ps`; si `php` esta `unhealthy` y workers reinician, sospechar SQL.
- Inspeccionar solo variables no sensibles:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && grep -E '^(DB_HOST|DB_PORT|DB2_HOST|DB2_PORT)=' .env"
```

- Ejecutar preflight PDO/sqlsrv desde la imagen PHP publicada si hace falta. No imprimir `DB_PASS` ni `DB2_PASS`.
