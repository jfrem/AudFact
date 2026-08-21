---
name: audfact-production-ops
description: Opera el servidor LAN de produccion de AudFact mediante SSH, Docker Compose y GitHub Actions self-hosted runner. Use when the user asks to entrar por SSH, diagnosticar produccion, revisar runner, revisar Docker, leer logs, hacer deploy, rollback, healthcheck, o ejecutar comandos en admon@172.16.0.3.
---

# AudFact Production Ops

## Objetivo

Usar esta skill para operaciones remotas sobre el servidor de produccion LAN `admon@172.16.0.3`. El patron principal es ejecutar comandos SSH no interactivos desde Windows/PowerShell con OpenSSH explicito y un helper `SSH_ASKPASS` temporal que nunca guarda la password.

## Datos Base

- Host SSH: `172.16.0.3`
- Usuario: `admon`
- Home remoto esperado: `/home/admon`
- Runner esperado: `actions.runner.jfrem-AudFact.produccion-audfact.service`
- Repo asociado al runner: `https://github.com/jfrem/AudFact`
- Directorio persistente de deploy: `/home/admon/audfact-prod`
- Workflow de deploy: `.github/workflows/deploy-production.yml`
- Compose productivo: `docker-compose.yml` con perfil `frontend`
- Sync GitHub Environment: `scripts/sync-github-production-env.sh`
- Variables SQL vigentes en GitHub Environment `production`: `DB_HOST=<PROD_DB_HOST>`, `DB2_HOST=<PROD_DB2_HOST_NEW>`, `DB_PORT=1433`, `DB2_PORT=1433`. No usar `host\instancia` en produccion.

## Guardrails

1. Tratar siempre el host como produccion.
2. No persistir passwords, tokens, `.env`, API keys ni secretos en archivos del repo o del skill.
3. No imprimir `.env` completo ni secrets en logs o respuestas. Si hace falta inspeccionar variables, mostrar solo nombres o valores redactados.
4. Pedir aprobacion explicita antes de acciones con impacto: deploy, rollback, `docker compose up`, `docker compose down`, restart de servicios, instalacion de paquetes, cambios de runner, edicion de `.env`, cambios de permisos recursivos, borrados o reboot.
5. Preferir GitHub Actions para despliegue normal. Usar deploy manual por SSH solo para diagnostico, recuperacion o cuando el usuario lo pida explicitamente.
6. No exponer SSH a Internet para resolver la falta de IP publica. El modelo correcto es runner self-hosted dentro de la LAN.
7. En Codex, si SSH falla por sandbox o red restringida, reintentar el mismo comando con `sandbox_permissions=require_escalated` y una justificacion concreta.
8. **Sincronización obligatoria de variables y secretos en GitHub**: Cualquier cambio o adición de variable en `.env.example` o código DEBE sincronizarse inmediatamente en el entorno remoto de GitHub (`gh variable set` / `gh secret set` con `--env production`) antes de fusionar o desplegar a producción.

## Flujo Rapido

1. Confirmar la intencion del usuario y clasificar el riesgo: diagnostico seguro o accion con impacto.
2. Si falta la password SSH en la sesion, pedirla al usuario. No inventarla ni buscarla en archivos.
3. Cargar la password solo en `$env:AUDFACT_SSH_PASSWORD` durante el comando.
4. Ejecutar comandos remotos con `scripts/Invoke-AudFactProdSsh.ps1`.
5. Eliminar cualquier helper temporal al terminar. El script lo hace automaticamente.
6. Reportar el comando ejecutado y el resultado relevante sin exponer secretos.

Ejemplo minimo:

```powershell
$env:AUDFACT_SSH_PASSWORD = 'PASSWORD_DE_LA_SESION'
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "pwd"
Remove-Item Env:\AUDFACT_SSH_PASSWORD
```

## Diagnostico Seguro

Usar primero estos comandos porque no cambian estado:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "pwd; whoami; id; hostname"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "docker --version; docker compose version"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "systemctl is-active actions.runner.jfrem-AudFact.produccion-audfact.service"
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "curl -sf http://localhost:8080/health"
```

Para diagnosticos mas completos, leer `references/runbooks.md`.

## Deploy y Rollback

El camino normal es GitHub Actions:

1. `CI - AudFact`
2. `Publish Images - AudFact`
3. `Deploy Production - AudFact` en runner `self-hosted` con label `audfact-prod-lan`

Antes de deploy o rollback, verificar:

```powershell
powershell -ExecutionPolicy Bypass -File .agent\skills\audfact-production-ops\scripts\Invoke-AudFactProdSsh.ps1 -Command "systemctl is-active actions.runner.jfrem-AudFact.produccion-audfact.service; docker ps"
```

El workflow productivo debe pasar `Preflight SQL connectivity` antes de `Start production stack`. Si falla con `DB_HOST appears malformed`, corregir GitHub Variables de `production`; no editar el `.env` remoto como solucion permanente.

Para sincronizar valores desde un `.env` productivo hacia GitHub Environment
`production`, usar:

```bash
bash scripts/sync-github-production-env.sh --dry-run
bash scripts/sync-github-production-env.sh --apply
```

El script escribe GitHub Secrets/Variables. No copia `.env` directamente al host
productivo; el deploy lo regenera en `/home/admon/audfact-prod/.env`.

Si el usuario autoriza deploy manual por SSH, usar el runbook y exigir un tag SHA concreto. No usar `latest` para rollback salvo instruccion explicita.

## Recursos

- `scripts/Invoke-AudFactProdSsh.ps1`: wrapper PowerShell para SSH con `SSH_ASKPASS` temporal.
- `scripts/sync-github-production-env.sh`: sincroniza `.env` productivo local hacia GitHub Environment `production` sin imprimir valores.
- `references/runbooks.md`: comandos de diagnostico, deploy, rollback y troubleshooting.

## Cierre Operativo

Al finalizar, indicar:

- Host y usuario usados.
- Comandos remotos ejecutados.
- Resultado de health check si aplica.
- Servicios o contenedores afectados.
- Cualquier accion pendiente para GitHub Actions, secrets o runner labels.
