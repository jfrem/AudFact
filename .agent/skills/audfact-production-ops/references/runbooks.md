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
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && docker compose -f docker-compose.prod.yml ps"
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
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && docker compose -f docker-compose.prod.yml logs --tail=120 nginx php worker-orchestrator worker-extraction worker-normalizer worker-policy worker-aggregator"
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

Checklist antes de disparar deploy:

- El runner esta `active`.
- GitHub Environment `production` tiene secrets requeridos.
- GHCR contiene imagenes `audfact-php:SHA` y `audfact-nginx:SHA`.
- El usuario aprobo deploy.

## Deploy Manual por SSH

Usar solo si el usuario lo pidio o si GitHub Actions no esta disponible. Requiere SHA concreto.

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "mkdir -p /home/admon/audfact-prod/logs /home/admon/audfact-prod/responseIA"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=IMAGE_SHA docker compose -f docker-compose.prod.yml pull"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=IMAGE_SHA docker compose -f docker-compose.prod.yml up -d --remove-orphans"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "curl -sf http://localhost:8080/health"
```

Antes de manual deploy, verificar que `/home/admon/audfact-prod/.env` y `docker-compose.prod.yml` existen. No imprimir `.env`.

## Rollback

Preferir workflow manual `Deploy Production - AudFact` con `image_tag` igual al SHA anterior.

Rollback manual, solo con autorizacion:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=SHA_ANTERIOR docker compose -f docker-compose.prod.yml pull"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "cd /home/admon/audfact-prod && AUDFACT_IMAGE_TAG=SHA_ANTERIOR docker compose -f docker-compose.prod.yml up -d --remove-orphans"
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
