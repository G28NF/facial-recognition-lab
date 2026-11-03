$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $projectRoot '.env'
$python = Join-Path $projectRoot 'python-service\.venv\Scripts\python.exe'

if (-not (Test-Path -LiteralPath $envFile)) { throw '.env nao encontrado.' }
if (-not (Test-Path -LiteralPath $python)) { throw 'Ambiente Python ausente. Execute bin\setup-python.ps1.' }

Get-Content -LiteralPath $envFile | ForEach-Object {
    $line = $_.Trim()
    if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
        $name, $value = $line.Split('=', 2)
        [Environment]::SetEnvironmentVariable($name.Trim(), $value.Trim(), 'Process')
    }
}

$hostAddress = if ($env:RFV2_PYTHON_HOST) { $env:RFV2_PYTHON_HOST } else { '127.0.0.1' }
$servicePort = if ($env:RFV2_PYTHON_PORT) { $env:RFV2_PYTHON_PORT } else { '8021' }
if ($hostAddress -notin @('127.0.0.1', 'localhost')) {
    throw 'RFV2_PYTHON_HOST deve apontar para 127.0.0.1 ou localhost.'
}
$parsedPort = 0
if (-not [int]::TryParse($servicePort, [ref]$parsedPort) -or $parsedPort -lt 1 -or $parsedPort -gt 65535) {
    throw 'RFV2_PYTHON_PORT deve ser uma porta TCP valida.'
}
Push-Location (Join-Path $projectRoot 'python-service')
try {
    & $python -m uvicorn app.main:app --host $hostAddress --port $parsedPort --no-access-log
} finally {
    Pop-Location
}
