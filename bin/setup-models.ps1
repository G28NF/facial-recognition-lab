[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = Split-Path -Parent $PSScriptRoot
$faceApiDirectory = Join-Path $projectRoot 'public\assets\models'
$openCvDirectory = Join-Path $projectRoot 'python-service\models'

$models = @(
    [pscustomobject]@{ Name = 'tiny_face_detector_model-weights_manifest.json'; Directory = $faceApiDirectory; Url = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/tiny_face_detector_model-weights_manifest.json'; Type = 'json'; MinimumBytes = 64; RequiredText = 'tiny_face_detector_model-shard1' },
    [pscustomobject]@{ Name = 'tiny_face_detector_model-shard1'; Directory = $faceApiDirectory; Url = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/tiny_face_detector_model-shard1'; Type = 'binary'; MinimumBytes = 1024; RequiredText = '' },
    [pscustomobject]@{ Name = 'face_landmark_68_model-weights_manifest.json'; Directory = $faceApiDirectory; Url = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_landmark_68_model-weights_manifest.json'; Type = 'json'; MinimumBytes = 64; RequiredText = 'face_landmark_68_model-shard1' },
    [pscustomobject]@{ Name = 'face_landmark_68_model-shard1'; Directory = $faceApiDirectory; Url = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_landmark_68_model-shard1'; Type = 'binary'; MinimumBytes = 1024; RequiredText = '' },
    [pscustomobject]@{ Name = 'face_recognition_model-weights_manifest.json'; Directory = $faceApiDirectory; Url = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_recognition_model-weights_manifest.json'; Type = 'json'; MinimumBytes = 64; RequiredText = 'face_recognition_model-shard1' },
    [pscustomobject]@{ Name = 'face_recognition_model-shard1'; Directory = $faceApiDirectory; Url = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_recognition_model-shard1'; Type = 'binary'; MinimumBytes = 1024; RequiredText = '' },
    [pscustomobject]@{ Name = 'face_recognition_model-shard2'; Directory = $faceApiDirectory; Url = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/face_recognition_model-shard2'; Type = 'binary'; MinimumBytes = 1024; RequiredText = '' },
    [pscustomobject]@{ Name = 'face_detection_yunet_2023mar.onnx'; Directory = $openCvDirectory; Url = 'https://media.githubusercontent.com/media/opencv/opencv_zoo/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx'; Type = 'binary'; MinimumBytes = 1024; RequiredText = '' },
    [pscustomobject]@{ Name = 'face_recognition_sface_2021dec.onnx'; Directory = $openCvDirectory; Url = 'https://media.githubusercontent.com/media/opencv/opencv_zoo/main/models/face_recognition_sface/face_recognition_sface_2021dec.onnx'; Type = 'binary'; MinimumBytes = 1024; RequiredText = '' }
)

function Test-ModelFile {
    param([Parameter(Mandatory)]$Model, [Parameter(Mandatory)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $false }
    if ((Get-Item -LiteralPath $Path).Length -lt $Model.MinimumBytes) { return $false }

    if ($Model.Type -eq 'json') {
        try {
            $content = Get-Content -LiteralPath $Path -Raw -Encoding UTF8
            $null = $content | ConvertFrom-Json -ErrorAction Stop
            return $content.Contains($Model.RequiredText)
        } catch {
            return $false
        }
    }

    $sampleLength = [Math]::Min(512, (Get-Item -LiteralPath $Path).Length)
    $stream = [System.IO.File]::OpenRead($Path)
    try {
        $buffer = New-Object byte[] $sampleLength
        $read = $stream.Read($buffer, 0, $buffer.Length)
    } finally {
        $stream.Dispose()
    }
    $prefix = [Text.Encoding]::UTF8.GetString($buffer, 0, $read)
    return $prefix -notmatch '(?i)<html|<!doctype|accessdenied'
}

foreach ($model in $models) {
    New-Item -ItemType Directory -Path $model.Directory -Force | Out-Null
    $target = Join-Path $model.Directory $model.Name

    if (Test-ModelFile -Model $model -Path $target) {
        Write-Host "OK: $($model.Name) ja esta valido."
        continue
    }

    $temporary = "$target.download-$PID"
    try {
        if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
        Write-Host "Baixando: $($model.Name)"
        Invoke-WebRequest -Uri $model.Url -OutFile $temporary -MaximumRedirection 5
        if (-not (Test-ModelFile -Model $model -Path $temporary)) {
            throw "O arquivo recebido nao passou na validacao: $($model.Name)."
        }
        Move-Item -LiteralPath $temporary -Destination $target -Force
        Write-Host "Concluido: $($model.Name)"
    } catch {
        if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
        throw "Falha ao obter $($model.Name): $($_.Exception.Message)"
    }
}

Write-Host 'Modelos Face API.js, YuNet e SFace prontos para uso.'
