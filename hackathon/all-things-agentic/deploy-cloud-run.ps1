[CmdletBinding()]
param(
    [switch]$Deploy
)

$projectId = 'prontagenda-agent-hackathon'
$region = 'southamerica-east1'
$service = 'prontagenda-ai-hackathon'
$repository = 'prontagenda-hackathon'
$image = "${region}-docker.pkg.dev/$projectId/$repository/prontagenda-ai:latest"
$serviceAccount = "prontagenda-hackathon-agent@$projectId.iam.gserviceaccount.com"
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$buildConfig = Join-Path $PSScriptRoot 'cloudbuild.yaml'
$gcloudCommand = Get-Command gcloud -ErrorAction SilentlyContinue
$gcloud = if ($gcloudCommand) {
    $gcloudCommand.Source
} else {
    'C:\Program Files (x86)\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd'
}

if (-not (Test-Path $gcloud) -and -not $gcloudCommand) {
    throw 'Google Cloud CLI não encontrada.'
}

if (-not $Deploy) {
    Write-Host 'Modo de conferência: nenhum recurso será criado ou alterado.'
    Write-Host "Projeto: $projectId"
    Write-Host "Região: $region"
    Write-Host "Serviço: $service"
    Write-Host "Imagem: $image"
    Write-Host 'Para construir e publicar, execute novamente com -Deploy.'
    exit 0
}

$activeProject = (& $gcloud config get-value project 2>$null).Trim()
if ($activeProject -ne $projectId) {
    throw "Projeto ativo incorreto: '$activeProject'. Ative a configuração prontagenda-hackathon."
}

Push-Location $root
try {
    & $gcloud builds submit . `
        --project=$projectId `
        --config=$buildConfig `
        --substitutions="_IMAGE=$image"

    & $gcloud run deploy $service `
        --project=$projectId `
        --region=$region `
        --image=$image `
        --service-account=$serviceAccount `
        --allow-unauthenticated `
        --min=0 `
        --max=1 `
        --cpu=1 `
        --memory=512Mi `
        --concurrency=8 `
        --timeout=60 `
        --set-env-vars="GOOGLE_GENAI_USE_VERTEXAI=TRUE,GOOGLE_CLOUD_PROJECT=$projectId,GOOGLE_CLOUD_LOCATION=global,PRONTAGENDA_API_BASE_URL=https://www.prontagenda.com.br,PRONTAGENDA_AI_SESSION_DB=/tmp/prontagenda-ai/sessions.db" `
        --set-secrets="PRONTAGENDA_AI_GATEWAY_TOKEN=prontagenda-ai-gateway-token:latest" `
        --quiet
}
finally {
    Pop-Location
}
