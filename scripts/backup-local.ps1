param(
    [ValidateRange(1, 3650)]
    [int] $RetentionDays = 30
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$backupDirectory = Join-Path $projectRoot 'backups'
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$fileName = "nenial-local-$timestamp.dump"
$containerPath = "/tmp/$fileName"
$destination = Join-Path $backupDirectory $fileName

New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
Push-Location $projectRoot

try {
    docker compose -f docker-compose.local.yml exec -T postgres `
        pg_dump -U nenial -d nenial_local --format=custom --file=$containerPath
    if ($LASTEXITCODE -ne 0) {
        throw 'PostgreSQL backup failed.'
    }

    docker compose -f docker-compose.local.yml cp "postgres:$containerPath" $destination
    if ($LASTEXITCODE -ne 0) {
        throw 'Copying the PostgreSQL backup to Windows failed.'
    }

    Get-ChildItem -LiteralPath $backupDirectory -Filter 'nenial-local-*.dump' -File |
        Where-Object LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) |
        Remove-Item -Force

    Write-Output "Backup created: $destination"
}
finally {
    docker compose -f docker-compose.local.yml exec -T postgres rm -f $containerPath 2>$null
    Pop-Location
}
