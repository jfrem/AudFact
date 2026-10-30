<#
.SYNOPSIS
    Antigravity PreToolUse Hook: Ensure Green Test Baseline & SDD Spec Before Implementation (PowerShell)
#>

$inputRaw = [Console]::In.ReadToEnd()

$hookInput = @{}
if (-not [string]::IsNullOrWhiteSpace($inputRaw)) {
    try {
        $hookInput = $inputRaw | ConvertFrom-Json
    } catch {
        $hookInput = @{}
    }
}

$rawPath = ""
if ($hookInput.toolCall -and $hookInput.toolCall.args -and $hookInput.toolCall.args.Path) {
    $rawPath = $hookInput.toolCall.args.Path
}
$normalizedPath = $rawPath -replace '\\', '/'

if ([string]::IsNullOrWhiteSpace($normalizedPath)) {
    Write-Output '{"decision":"allow"}'
    exit 0
}

# Check bypass
$lastInput = ""
if ($hookInput.lastUserInput) {
    $lastInput = $hookInput.lastUserInput
}
if ($lastInput.Contains("[skip-all]") -or $lastInput.ToLower().Contains("sin restricciones")) {
    Write-Output '{"decision":"allow"}'
    exit 0
}

# Check production code
$isProductionCode = (
    $normalizedPath.Contains("/app/") -or $normalizedPath.StartsWith("app/") -or
    $normalizedPath.Contains("/core/") -or $normalizedPath.StartsWith("core/") -or
    $normalizedPath.Contains("/database/") -or $normalizedPath.StartsWith("database/") -or
    $normalizedPath.Contains("/frontend/src/") -or $normalizedPath.StartsWith("frontend/src/") -or
    $normalizedPath.Contains("/frontend/app/") -or $normalizedPath.StartsWith("frontend/app/")
)

$isExempted = (
    $normalizedPath.Contains("/tests/") -or $normalizedPath.StartsWith("tests/") -or
    $normalizedPath.Contains("/plans/") -or $normalizedPath.StartsWith("plans/") -or
    $normalizedPath.Contains("/.agent/") -or $normalizedPath.StartsWith(".agent/") -or
    $normalizedPath.Contains("/brain/") -or
    $normalizedPath.EndsWith(".md") -or
    $normalizedPath.EndsWith(".json") -or
    $normalizedPath.EndsWith(".yml") -or
    $normalizedPath.EndsWith(".yaml")
)

if (-not $isProductionCode -or $isExempted) {
    Write-Output '{"decision":"allow"}'
    exit 0
}

# Resolve workspace root
$workspaceRoot = $null
if ($hookInput.workspacePaths -and $hookInput.workspacePaths.Count -gt 0) {
    $workspaceRoot = $hookInput.workspacePaths[0]
}
if (-not $workspaceRoot -or -not (Test-Path $workspaceRoot)) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $workspaceRoot = Resolve-Path (Join-Path $scriptDir "..\..")
}

# Check 1: Baseline tests
$skipTests = $lastInput.Contains("[skip-tests]") -or $lastInput.ToLower().Contains("sin pruebas") -or $lastInput.ToLower().Contains("skip tests")
$baselineValid = $skipTests

if (-not $baselineValid) {
    if ($hookInput.transcriptPath -and (Test-Path $hookInput.transcriptPath)) {
        try {
            $transcript = Get-Content -Raw -Path $hookInput.transcriptPath -ErrorAction Stop
            if (($transcript.Contains("phpunit") -or $transcript.Contains("composer test")) -and
                ($transcript.Contains("OK (") -or $transcript.Contains("OK, but some tests were skipped") -or $transcript.Contains("exited with code 0"))) {
                $baselineValid = $true
            }
        } catch {}
    }

    if (-not $baselineValid) {
        $cachePath = Join-Path $workspaceRoot ".phpunit.cache\test-results"
        if (Test-Path $cachePath) {
            try {
                $cacheItem = Get-Item $cachePath
                $ageMinutes = ((Get-Date) - $cacheItem.LastWriteTime).TotalMinutes
                if ($ageMinutes -le 15) {
                    $baselineValid = $true
                }
            } catch {}
        }
    }
}

$fileName = Split-Path -Leaf $normalizedPath

if (-not $baselineValid) {
    $response = @{
        decision = "deny"
        reason   = "🚨 CONTROL PREVIO DE CALIDAD (Línea Base en Verde):`nEstás intentando modificar código de producción ('$fileName') sin haber certificado previamente que la suite de pruebas unitarias está en verde.`n`nPor favor:`n1. Ejecuta la suite de pruebas unitarias mediante la herramienta 'run_command':`n   vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit`n2. Confirma que la suite finalice en verde (0 fallos, 0 errores).`n3. Una vez verificado el estado en verde, reintenta la edición del archivo."
    }
    $response | ConvertTo-Json -Compress
    exit 0
}

# Check 2: SDD Spec
$skipSpec = $lastInput.Contains("[skip-spec]") -or $lastInput.Contains("[hotfix]") -or $lastInput.ToLower().Contains("sin spec") -or $lastInput.ToLower().Contains("hotfix")
$specValid = $skipSpec

if (-not $specValid) {
    $plansDir = Join-Path $workspaceRoot "plans"
    if (Test-Path $plansDir) {
        try {
            $sddFiles = Get-ChildItem -Path $plansDir -Filter "sdd-*.md"
            foreach ($sdd in $sddFiles) {
                $ageHours = ((Get-Date) - $sdd.LastWriteTime).TotalHours
                if ($ageHours -le 4) {
                    $specValid = $true
                    break
                }
            }
        } catch {}
    }

    if (-not $specValid -and $hookInput.artifactDirectoryPath -and (Test-Path $hookInput.artifactDirectoryPath)) {
        try {
            $artifacts = Get-ChildItem -Path $hookInput.artifactDirectoryPath -Filter "*.md"
            if ($artifacts.Count -gt 0) {
                $specValid = $true
            }
        } catch {}
    }

    if (-not $specValid -and $hookInput.transcriptPath -and (Test-Path $hookInput.transcriptPath)) {
        try {
            $transcript = Get-Content -Raw -Path $hookInput.transcriptPath -ErrorAction Stop
            if ($transcript.Contains("write-sdd-spec") -or $transcript.Contains("plans/sdd-") -or $transcript.Contains('"plans/')) {
                $specValid = $true
            }
        } catch {}
    }
}

if (-not $specValid) {
    $response = @{
        decision = "deny"
        reason   = "🚨 CONTROL PREVIO DE GOBERNANZA (Especificación SDD Requerida):`nEstás intentando implementar código de producción ('$fileName') sin haber redactado previamente la especificación técnica correspondiente.`n`nPor favor, cumple con el protocolo SDD (/write-sdd-spec):`n1. Redacta la especificación en 'plans/sdd-<nombre-del-cambio>.md' o genera un artefacto de plan.`n2. Si existen supuestos o incógnitas críticas [DESCONOCIDO], acláralos con el usuario usando ask_question.`n3. Valida la compuerta arquitectónica (/clean-rebuild-policy).`n4. Una vez guardada la especificación, procede con la implementación.`n(Nota: Si es un ajuste menor que no requiere spec formal, usa la bandera [skip-spec])."
    }
    $response | ConvertTo-Json -Compress
    exit 0
}

Write-Output '{"decision":"allow"}'
exit 0
