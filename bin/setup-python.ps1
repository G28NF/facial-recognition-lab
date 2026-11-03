$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$serviceRoot = Join-Path $projectRoot 'python-service'
$venv = Join-Path $serviceRoot '.venv'
$python = Join-Path $venv 'Scripts\python.exe'

if (-not (Test-Path -LiteralPath $python)) {
    if (Get-Command uv -ErrorAction SilentlyContinue) {
        uv venv --seed --python 3.12 $venv
    } else {
        py -3.12 -m venv $venv
    }
}
& $python -m pip install --disable-pip-version-check -r (Join-Path $serviceRoot 'requirements.txt')
