# Comandos de Diagnóstico por Capa

> **Regla absoluta:** todo lo anterior a la sección *Comandos mutantes* es de **solo lectura**. Nunca imprimir `.env`, `REDIS_PASSWORD`, `DB_PASS`, `DB2_PASS`, `GEMINI_API_KEY`, API keys ni datos de pacientes.
>
> **Producción:** ejecutar los bloques Bash dentro de `/home/admon/audfact-prod` mediante el patrón remoto. **Local:** ejecutarlos desde la raíz del repo, sin SSH.

---

## Paso 0 — Triaje HTTP sin SSH

```powershell
$api = 'http://172.16.0.3:8080'   # local: http://localhost:8080

# 1. Salud base (SQL default, SQL db2, Redis, disco, memoria)
curl.exe -s -w "`nHTTP %{http_code} en %{time_total}s`n" "$api/health"

# 2. Pipeline durable v2 (un 503 aquí ya apunta a Redis/protocolo)
curl.exe -s -w "`nHTTP %{http_code}`n" "$api/metrics/async"

# 3. Resumen de señales clave de /metrics/async
node -e "fetch(process.argv[1]+'/metrics/async').then(r=>r.json()).then(({data:d})=>console.log(JSON.stringify({jobs:d.jobs,deadLetterDepth:d.deadLetterDepth,terminalFailures:d.terminalFailures,heartbeatAgeMs:d.executionMaintenance?.heartbeatAgeMs,outboxPending:d.executionMaintenance?.outboxPending,batchInbox:d.streamBacklogs?.batchInbox,persistence:d.persistenceScheduler,gemini:d.geminiBudget,scheduler:d.executionScheduler},null,1)))" $api

# 4. Tabla de jobs recientes
node -e "fetch(process.argv[1]+'/audit/jobs').then(r=>r.json()).then(j=>console.table(j.data.map(x=>({id:x.job_id.slice(0,8),client:(x.client_name||'').slice(0,25),status:x.status,total:x.total,done:x.done,pending:x.pending,failed:x.failed,created:x.created_at,updated:x.updated_at}))))" $api

# 5. Detalle puntual
curl.exe -s "$api/audit/jobs/<JOB_ID>"
curl.exe -s "$api/audit/status/<AUDIT_ID>"
curl.exe -s "$api/audit/results/<FAC_NRO>"
curl.exe -s "$api/audit/<FAC_NRO>/timings"

# 6. DLQ (API key solo en memoria de la sesión, nunca en el repo)
curl.exe -s -H "X-API-KEY: <API_KEY_DE_SESION>" "$api/audit/dlq"
```

Para confirmar un atasco, repetir 3 y 4 tras ≥ 60 s y comparar.

---

## Patrón de ejecución remota (PowerShell + Base64 + wrapper SSH)

Evita problemas de comillas anidadas entre PowerShell, SSH y Bash:

```powershell
$bash = @'
set -u
cd /home/admon/audfact-prod
# <pegar aquí el preámbulo y el bloque de la capa>
'@
$b64 = [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes(($bash -replace "`r`n", "`n")))
$env:AUDFACT_SSH_PASSWORD = '<PASSWORD_DE_LA_SESION>'
try {
    & .agent/skills/audfact-production-ops/scripts/Invoke-AudFactProdSsh.ps1 -Command "echo $b64 | base64 -d | bash"
} finally {
    Remove-Item Env:AUDFACT_SSH_PASSWORD -ErrorAction SilentlyContinue
}
```

---

## Capa 1 — Host, hardware y SO

```bash
echo "=== CPU / CARGA ==="; uptime; nproc
echo "=== IOWAIT (3 muestras) ==="; command -v vmstat >/dev/null && vmstat 1 3 || echo "vmstat no disponible"
echo "=== RAM / SWAP ==="; free -h
echo "=== DISCO E INODOS ==="; df -h -x tmpfs -x devtmpfs; df -i -x tmpfs -x devtmpfs
echo "=== DOCKER DATA ==="; docker system df
echo "=== KERNEL: OOM / I/O / THROTTLING / LINK ==="
dmesg -T 2>/dev/null | grep -iE "oom|killed process|throttl|hardware error|i/o error|ext4-fs error|link down" | tail -n 20 || echo "dmesg sin acceso o sin coincidencias"
command -v sensors   >/dev/null && sensors       || echo "sensors no instalado"
command -v edac-util >/dev/null && edac-util -v 2>/dev/null || echo "edac-util no disponible"
echo "=== RED ==="; ip -s link show | grep -A 5 "state UP"
```

---

## Capa 2 — Topología Docker y runtime

```bash
echo "=== SERVICIOS ==="
docker compose ps --format 'table {{.Service}}\t{{.Name}}\t{{.Status}}'

echo "=== CONTENEDORES ANÓMALOS (restarts, OOM, unhealthy, exited) ==="
docker compose ps -aq | xargs -r docker inspect -f '{{.Name}} status={{.State.Status}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}} started={{.State.StartedAt}}' \
  | grep -vE 'status=running health=(healthy|none) restarts=0 oom=false' || echo "Todos estables"

echo "=== RÉPLICAS POR SERVICIO ==="
docker compose ps --format '{{.Service}}' | sort | uniq -c

echo "=== CONSUMO ==="
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}'

echo "=== RUNNER (solo producción) ==="
systemctl is-active actions.runner.jfrem-AudFact.produccion-audfact.service 2>/dev/null || true
```

---

## Capa 3 — Redis y protocolo durable v2

### Preámbulo común (usar al inicio de los bloques 3.x)

La contraseña y el prefijo se leen **dentro** del contenedor `redis` (que ya los recibe por `environment`); nunca se copian al host ni aparecen en `argv`.

```bash
rcli() { docker compose exec -T redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" exec redis-cli --no-auth-warning "$@"' rcli "$@"; }
PREFIX=$(docker compose exec -T redis printenv REDIS_PREFIX | tr -d '\r\n'); PREFIX="${PREFIX:-audfact:}"
DLQ=$(docker compose exec -T php printenv AUDIT_DLQ_STREAM 2>/dev/null | tr -d '\r\n'); DLQ="${DLQ:-audit.dlq}"
```

### 3.1 Protocolo, banderas, heartbeat y memoria

```bash
echo "=== DURABILIDAD V2 ==="
docker compose exec -T php php bin/audit-execution-maintenance.php durability-check \
  || echo "FALLA: revisar appendonly/appendfsync/maxmemory-policy/aof_last_write_status/aof_rewrite_in_progress"
rcli CONFIG GET appendonly; rcli CONFIG GET appendfsync; rcli CONFIG GET maxmemory-policy
rcli INFO persistence | grep -E '^(aof_last_write_status|aof_rewrite_in_progress):'

echo "=== BANDERAS ==="
for k in audit:execution:version audit:admission:paused audit:execution:paused; do
  printf "%s = %s\n" "$k" "$(rcli GET "${PREFIX}${k}")"
done

echo "=== HEARTBEAT (edad en ms; umbral 10000) ==="
HB=$(rcli GET "${PREFIX}audit:execution:heartbeat" | tr -d '\r\n')
NOW=$(rcli TIME | head -1); NOW_US=$(rcli TIME | tail -1)
[ -n "$HB" ] && echo "edad_ms=$(( NOW * 1000 + NOW_US / 1000 - HB ))" || echo "heartbeat AUSENTE"

echo "=== MEMORIA (umbral atCapacity: 80 % de maxmemory) ==="
rcli INFO memory | grep -E '^(used_memory|maxmemory|used_memory_human|maxmemory_human|maxmemory_policy|mem_fragmentation_ratio):'
```

> La edad del heartbeat es aproximada (dos llamadas a `TIME`); la fuente canónica es `executionMaintenance.heartbeatAgeMs` en `/metrics/async`.

### 3.2 Streams, consumer groups, PEL y DLQ

```bash
for s in audit.batch.inbox audit.inbox.priority audit.inbox.batch audit.documents.priority audit.documents.batch \
         audit.semantic.priority audit.semantic.batch audit.persistence.priority audit.persistence.batch; do
  echo "=== ${s} (XLEN=$(rcli XLEN "${PREFIX}${s}")) ==="
  rcli XINFO GROUPS "${PREFIX}${s}" 2>/dev/null | paste - - | grep -E '^(name|consumers|pending|lag|last-delivered-id)' \
    || echo "(sin grupos o inexistente)"
done

echo "=== XPENDING batch-workers (id, consumer, idle_ms, entregas) ==="
rcli XPENDING "${PREFIX}audit.batch.inbox" batch-workers - + 20

echo "=== DLQ ${DLQ} ==="
echo "XLEN=$(rcli XLEN "${PREFIX}${DLQ}")"
```

Grupos por stream: `audit.batch.inbox` → `batch-workers`; `audit.inbox.*` → `orchestrator`; `audit.documents.*` → `downloaders`, `extractors`, `normalizers`, `policy`; `audit.semantic.*` → `semantic`; `audit.persistence.*` → `persistence`. Para el PEL de otro grupo, cambiar stream y grupo en `XPENDING`.

### 3.3 Snapshots `job:{id}:state` / `audit:{id}:state` (strings JSON)

```bash
docker compose exec -T php php -r '
require "/var/www/html/vendor/autoload.php";
\Core\Env::load();
$r = \Core\RedisClient::getInstance();
foreach (["job:<JOB_ID>:state", "audit:<AUDIT_ID>:state"] as $k) {
    echo "=== {$k} ===\n", $r->get($k) ?? "NULL", "\n";
}'
```

---

## Capa 4 — Dependencias externas (logs estructurados)

### 4.1 SQL Server

```bash
echo "=== ÚLTIMAS LÍNEAS POR RÉPLICA DE worker-batch ==="
for c in $(docker compose ps -q worker-batch); do
  echo "--- $(docker inspect -f '{{.Name}}' "$c") ---"; docker logs "$c" --tail=15 2>&1
done

echo "=== ERRORES SQL / 08S01 / REINTENTOS (60 min) ==="
docker compose logs --no-color --since=60m worker-batch worker-orchestrator worker-persistence php 2>&1 \
  | grep -E 'SqlServerConnectionExecutor|08S01|Login timeout|deadlock|operacion fallida' | tail -n 40 \
  || echo "Sin errores SQL en 60 min"

echo "=== CANDIDATOS DE BATCH EJECUTADOS (60 min) ==="
docker compose logs --no-color --since=60m worker-batch 2>&1 | grep 'Executed SQL audit batch candidates' | tail -n 10
```

### 4.2 Gemini, descargas y prefetch

```bash
echo "=== GEMINI / CUOTA / EXTRACCIÓN / SEMÁNTICA (60 min) ==="
docker compose logs --no-color --since=60m worker-extraction-vip worker-extraction-batch worker-semantic-vip worker-semantic-batch 2>&1 \
  | grep -iE '"level":"(ERROR|CRITICAL|WARNING)"|429|DEFERRED|GeminiResponseException|quota' | tail -n 40 \
  || echo "Sin alertas Gemini en 60 min"

echo "=== DESCARGA / DRIVE / PREFETCH / PDF (60 min) ==="
docker compose logs --no-color --since=60m worker-downloader-vip worker-downloader-batch worker-orchestrator worker-normalizer 2>&1 \
  | grep -iE '"level":"(ERROR|CRITICAL|WARNING)"|AttachmentDownload|prefetch|Drive|Rasterizer' | tail -n 40 \
  || echo "Sin alertas de descarga en 60 min"

echo "=== MANTENIMIENTO DE EJECUCIÓN (60 min) ==="
docker compose logs --no-color --since=60m worker-execution-maintenance 2>&1 | grep -iE 'ERROR|CRITICAL|failed' | tail -n 20 \
  || echo "Sin errores de mantenimiento en 60 min"
```

> `Core\Logger` emite JSON con `"level":"ERROR|WARNING|..."` en mayúsculas.

---

## Capa 5 — Cobertura de facturas antes de re-encolar

`/audit/results/{facNro}` usa **FacNro**; el detalle de job solo expone `dis_det_nro`. Comprobar cobertura por cliente y fecha:

```powershell
# Dispensas y estados del job sospechoso
node -e "fetch(process.argv[1]+'/audit/jobs/<JOB_ID>').then(r=>r.json()).then(({data:j})=>{console.log({status:j.status,sealed:j.sealed,total:j.total,done:j.done,created:j.created_at,updated:j.updated_at});console.table(j.audits.slice(0,10))})" $api

# Resultados persistidos del mismo cliente desde la fecha del job
curl.exe -s "$api/audit/results?facNitSec=<NIT_SEC>&dateFrom=<YYYY-MM-DD>&pageSize=100"
```

Comparar además con los totales de jobs posteriores del mismo cliente en `/audit/jobs`.

---

## Comandos mutantes (SOLO con aprobación explícita)

Todos vía `docker compose exec -T php php bin/audit-execution-maintenance.php <cmd>` salvo indicación. Si alguno falla, el script registra `Audit execution maintenance failed` con `error_class` y **la pausa debe conservarse** hasta entender el fallo.

| Comando | Efecto | Precondición verificada por el script |
|---|---|---|
| `pause` | `audit:admission:paused=1` y `audit:execution:paused=1` | Ninguna |
| `observe` | Admisión pausada, ejecución activa (drenaje) | Protocolo v2 y cuota Gemini válidos |
| `resume` | Borra ambas pausas | Protocolo v2, cuota válida, heartbeat ≤ 10000 ms y **sin** `atCapacity`; si no, `maintenance_unhealthy` |
| `protocol` | Fija `audit:execution:version=2` si no existe | — |
| `reconcile` | Reconstruye índices derivados del scheduler v2 | `audit:execution:paused=1`; si no, `execution_pause_required` |
| `outbox` | Publica manualmente el outbox pendiente | Normalmente lo hace `worker-execution-maintenance`; usar solo si ese servicio está caído |
| `gc` | Recolección de basura del scheduler | — |

```bash
# Reinicio acotado de un servicio con sockets colgados (ej. worker-batch)
docker compose restart worker-batch

# Reconciliación segura
docker compose exec -T php php bin/audit-execution-maintenance.php pause
docker compose exec -T php php bin/audit-execution-maintenance.php reconcile
docker compose exec -T php php bin/audit-execution-maintenance.php resume
```

Nunca podar `audit.dlq` manualmente; para reprocesar usar `POST /audit/dlq/reprocess` con aprobación.
