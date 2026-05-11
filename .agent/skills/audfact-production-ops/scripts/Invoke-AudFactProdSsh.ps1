[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string] $Command,

    [string] $HostName = '172.16.0.3',
    [string] $User = 'admon',
    [string] $KnownHostsFile = 'C:\tmp\audfact_known_hosts',
    [int] $ConnectTimeout = 10
)

$ErrorActionPreference = 'Stop'

$sshExe = Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'
if (-not (Test-Path -LiteralPath $sshExe)) {
    throw "OpenSSH client not found at $sshExe"
}

$password = [Environment]::GetEnvironmentVariable('AUDFACT_SSH_PASSWORD', 'Process')
if ([string]::IsNullOrWhiteSpace($password)) {
    throw 'Set AUDFACT_SSH_PASSWORD in the current process before running this script.'
}

$knownHostsDir = Split-Path -Parent $KnownHostsFile
if ($knownHostsDir -and -not (Test-Path -LiteralPath $knownHostsDir)) {
    New-Item -ItemType Directory -Path $knownHostsDir -Force | Out-Null
}

$askpass = Join-Path ([System.IO.Path]::GetTempPath()) ("audfact-ssh-askpass-{0}.cmd" -f $PID)
$askpassContent = @'
@echo off
powershell -NoProfile -Command "[Console]::Out.Write($env:AUDFACT_SSH_PASSWORD)"
'@

$previousAskpass = $env:SSH_ASKPASS
$previousAskpassRequire = $env:SSH_ASKPASS_REQUIRE
$previousDisplay = $env:DISPLAY
$exitCode = 1

try {
    Set-Content -LiteralPath $askpass -Value $askpassContent -Encoding ASCII

    $env:SSH_ASKPASS = $askpass
    $env:SSH_ASKPASS_REQUIRE = 'force'
    $env:DISPLAY = 'none'

    $remote = '{0}@{1}' -f $User, $HostName
    & $sshExe `
        -o BatchMode=no `
        -o NumberOfPasswordPrompts=1 `
        -o ConnectTimeout=$ConnectTimeout `
        -o StrictHostKeyChecking=accept-new `
        -o UserKnownHostsFile=$KnownHostsFile `
        $remote `
        $Command

    $exitCode = $LASTEXITCODE
}
finally {
    $env:SSH_ASKPASS = $previousAskpass
    $env:SSH_ASKPASS_REQUIRE = $previousAskpassRequire
    $env:DISPLAY = $previousDisplay

    if (Test-Path -LiteralPath $askpass) {
        Remove-Item -LiteralPath $askpass -Force
    }
}

exit $exitCode
