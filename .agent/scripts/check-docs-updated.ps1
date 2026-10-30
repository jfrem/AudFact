<#
.SYNOPSIS
    Antigravity Stop Hook: Comprehensive Implementation Closure Verification (PowerShell)
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

# Safeguard 1: Only intervene on NO_TOOL_CALL
if ($hookInput.terminationReason -and $hookInput.terminationReason -ne "NO_TOOL_CALL") {
    Write-Output "{}"
    exit 0
}

# Safeguard 2: Prevent infinite loops
if ($hookInput.executionNum -and $hookInput.executionNum -ge 3) {
    Write-Output "{}"
    exit 0
}

# Bypass flags
$lastInput = ""
if ($hookInput.lastUserInput) {
    $lastInput = $hookInput.lastUserInput
}
if ($lastInput.Contains("[skip-all]") -or $lastInput.ToLower().Contains("sin restricciones")) {
    Write-Output "{}"
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

# Check transcript for file modifications
$sessionModifiedFiles = $false
if ($hookInput.transcriptPath -and (Test-Path $hookInput.transcriptPath)) {
    try {
        $transcript = Get-Content -Raw -Path $hookInput.transcriptPath -ErrorAction Stop
        if ($transcript -match '"edit_file"' -or $transcript -match '"write_file"' -or $transcript -match '"toolName":"edit_file"' -or $transcript -match '"toolName":"write_file"') {
            $sessionModifiedFiles = $true
        }
    } catch {
        $sessionModifiedFiles = $true
    }
} else {
    $sessionModifiedFiles = $true
}

if (-not $sessionModifiedFiles) {
    Write-Output "{}"
    exit 0
}

# Check git status
try {
    $gitStatus = git -C "$workspaceRoot" status --porcelain 2>$null
} catch {
    Write-Output "{}"
    exit 0
}

if (-not $gitStatus -or [string]::IsNullOrWhiteSpace($gitStatus)) {
    Write-Output "{}"
    exit 0
}

$statusLines = $gitStatus -split "`r?`n" | Where-Object { $_.Trim().Length -gt 0 }

$codeRegex = '^\s*..\s+(app/|core/|frontend/|docker/|database/|public/|bin/|\.github/|docker-compose.*\.ya?ml)'
$docRegex  = '^\s*..\s+(plans/changelog\.md|plans/|\.agent/skills/|AGENTS\.md|README\.md|CATALOG\.md)'

$modifiedCode = @()
$latestCodeMtime = [DateTime]::MinValue
$hasDocModified = $false

foreach ($line in $statusLines) {
    $normalized = $line -replace '\\', '/'
    if ($normalized -match $codeRegex) {
        $relPath = $normalized.Substring(3).Trim()
        $modifiedCode += $relPath
        $fullPath = Join-Path $workspaceRoot ($relPath -replace '/', '\')
        if (Test-Path $fullPath) {
            $fItem = Get-Item $fullPath
            if ($fItem.LastWriteTime -gt $latestCodeMtime) {
                $latestCodeMtime = $fItem.LastWriteTime
            }
        }
    }
    if ($normalized -match $docRegex) {
        $hasDocModified = $true
    }
}

if ($modifiedCode.Count -eq 0) {
    Write-Output "{}"
    exit 0
}

$missingEvidences = @()

# Evidence 1: Post-implementation tests in green
$skipTests = $lastInput.Contains("[skip-tests]") -or $lastInput.ToLower().Contains("sin pruebas")
if (-not $skipTests) {
    $postTestsPassed = $false
    $cachePath = Join-Path $workspaceRoot ".phpunit.cache\test-results"
    if (Test-Path $cachePath) {
        $cItem = Get-Item $cachePath
        if ($cItem.LastWriteTime -ge $latestCodeMtime.AddSeconds(-2)) {
            $postTestsPassed = $true
        }
    }

    if (-not $postTestsPassed) {
        $missingEvidences += "PRUEBAS FINALES EN VERDE: Has modificado archivos de código pero no has vuelto a ejecutar la suite de pruebas unitarias ('vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit') para certificar que no existen regresiones tras tus cambios."
    }
}

# Evidence 2: Documentation updated
if (-not $hasDocModified) {
    $missingEvidences += "DOCUMENTACIÓN Y BITÁCORA: Debes registrar en 'plans/changelog.md' bajo la fecha de hoy los cambios técnicos realizados y sincronizar la documentación afectada (audfact-docs-sync)."
}

# Evidence 3: Implementation report artifact
$skipArtifact = $lastInput.Contains("[no-artifact]") -or $lastInput.ToLower().Contains("sin artefacto")
if (-not $skipArtifact -and $hookInput.artifactDirectoryPath -and (Test-Path $hookInput.artifactDirectoryPath)) {
    $artifacts = Get-ChildItem -Path $hookInput.artifactDirectoryPath -Filter "*.md"
    if ($artifacts.Count -eq 0) {
        $missingEvidences += "ARTEFACTO DE REPORTE: Debes generar un artefacto Markdown en la carpeta de artefactos con el reporte final de la implementación (resumen, componentes modificados, decisiones arquitectónicas según /clean-rebuild-policy y estado final de pruebas)."
    }
}

if ($missingEvidences.Count -gt 0) {
    $sample = ($modifiedCode | Select-Object -First 4) -join ", "
    $more = if ($modifiedCode.Count -gt 4) { " y $($modifiedCode.Count - 4) más" } else { "" }

    $formatted = ""
    for ($i = 0; $i -lt $missingEvidences.Count; $i++) {
        $formatted += "$($i + 1). $($missingEvidences[$i])`n`n"
    }

    $response = @{
        decision = "continue"
        reason   = "🚨 CONTROL DE CIERRE DE IMPLEMENTACIÓN (Faltan Evidencias):`nHas modificado código fuente ($sample$more), pero faltan las siguientes evidencias obligatorias para concluir la tarea:`n`n$formatted`nPor favor, completa los pasos anteriores antes de dar por finalizada la respuesta."
    }
    $response | ConvertTo-Json -Compress
    exit 0
}

Write-Output "{}"
exit 0
