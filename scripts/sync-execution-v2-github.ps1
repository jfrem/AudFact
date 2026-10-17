param([switch]$Apply)

$ErrorActionPreference = 'Stop'
$repository = 'jfrem/AudFact'
$inheritedNames = @('WWWUSER_ID', 'WWWGROUP_ID', 'RUN_REDIS_INTEGRATION', 'AUDIT_EVENT_MAX_RETRIES',
    'AUDIT_JOB_TTL', 'AUDIT_PENDING_RECLAIM_IDLE_MS', 'AUDIT_PENDING_RECLAIM_INTERVAL_MS',
    'AUDIT_STREAM_BLOCK_MS', 'AUDIT_STREAM_MAXLEN')
$newValues = [ordered]@{
    AUDIT_PIPELINE_EXECUTION_VERSION = '1'
    AUDIT_EXECUTION_MAX_QUEUED_PER_STAGE = '20000'
    AUDIT_WORKER_SEMANTIC_VIP_REPLICAS = '1'
    AUDIT_WORKER_SEMANTIC_BATCH_REPLICAS = '2'
    GEMINI_QUOTA_SCOPE = 'UNCONFIGURED'
    GEMINI_QUOTA_RPM = '0'
    GEMINI_QUOTA_TPM = '0'
    GEMINI_QUOTA_RPD = '0'
    GEMINI_QUOTA_MAX_INFLIGHT = '4'
}
function Read-Variables([bool]$Production) {
    $arguments = @('variable', 'list', '--repo', $repository, '--json', 'name,value')
    if ($Production) { $arguments += @('--env', 'production') }
    $json = & gh @arguments
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron leer las variables de GitHub.' }
    $result = @{}
    foreach ($item in ($json | ConvertFrom-Json)) { $result[$item.name] = [string]$item.value }
    return $result
}
$production = Read-Variables $true
$repositoryValues = Read-Variables $false
foreach ($name in $inheritedNames) {
    if ($production.ContainsKey($name) -and $repositoryValues.ContainsKey($name) -and $production[$name] -cne $repositoryValues[$name]) {
        throw "Conflicto de valores en $name; no se modificara GitHub."
    }
}
foreach ($name in $newValues.Keys) {
    if ($production.ContainsKey($name) -and $production[$name] -cne $newValues[$name]) {
        throw "Configuracion activa distinta en $name; no se modificara GitHub."
    }
}
if (!$Apply) {
    Write-Output 'Plan: heredar nueve valores sin alterarlos y agregar nueve variables con ejecucion v1.'
    foreach ($name in $inheritedNames) { Write-Output "Herencia: $name" }
    foreach ($name in $newValues.Keys) { Write-Output "Preparacion: $name=$($newValues[$name])" }
    return
}
# Primero copiar y verificar; solo despues retirar el valor del Environment.
foreach ($name in $inheritedNames) {
    if (!$production.ContainsKey($name)) { continue }
    & gh variable set $name --body $production[$name] --repo $repository
    if ($LASTEXITCODE -ne 0) { throw "Fallo al conservar $name en el repositorio." }
}
$verified = Read-Variables $false
foreach ($name in $inheritedNames) {
    if (!$production.ContainsKey($name)) { continue }
    if ($verified[$name] -cne $production[$name]) { throw "Verificacion fallida para $name." }
    & gh variable delete $name --env production --repo $repository
    if ($LASTEXITCODE -ne 0) { throw "Fallo al trasladar $name; el valor copiado sigue disponible." }
}
foreach ($name in $newValues.Keys) {
    if ($production.ContainsKey($name)) { continue }
    & gh variable set $name --body $newValues[$name] --env production --repo $repository
    if ($LASTEXITCODE -ne 0) { throw "Fallo al crear $name; no se activa v2." }
}
$final = Read-Variables $true
foreach ($name in $newValues.Keys) {
    if ($final[$name] -cne $newValues[$name]) { throw "Verificacion final fallida para $name." }
}
Write-Output 'Configuracion sincronizada y verificada. La ejecucion permanece en v1.'
