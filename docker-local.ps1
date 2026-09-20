param(
    [string]$EnvFile = '.env.docker.local'
)

$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

if (-not (Test-Path -LiteralPath $EnvFile)) {
    throw "Missing $EnvFile. Copy your existing .env to $EnvFile and add the Docker-local settings first."
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is not available. Start Docker Desktop and try again.'
}

function Get-EnvironmentValue {
    param([string]$Name)

    $line = Get-Content -LiteralPath $EnvFile |
        Where-Object { $_ -match "^$([regex]::Escape($Name))=" } |
        Select-Object -Last 1

    if (-not $line) {
        throw "Missing $Name in $EnvFile."
    }

    return (($line -split '=', 2)[1].Trim()).Trim('"').Trim("'")
}

$databaseName = Get-EnvironmentValue 'DB_DATABASE'
$databaseUser = Get-EnvironmentValue 'DB_USERNAME'
$databasePassword = Get-EnvironmentValue 'DB_PASSWORD'

function Invoke-Compose {
    & docker compose --env-file $EnvFile @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose failed with exit code $LASTEXITCODE."
    }
}

Write-Host 'Building WisperBot containers...'
Invoke-Compose build

Write-Host 'Starting MariaDB and Redis...'
Invoke-Compose up -d db redis

Write-Host 'Waiting for MariaDB...'
$databaseReady = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    & docker compose --env-file $EnvFile exec -T db mariadb-admin ping -h 127.0.0.1 "-u$databaseUser" "-p$databasePassword" --silent *> $null
    if ($LASTEXITCODE -eq 0) {
        $databaseReady = $true
        break
    }
    Start-Sleep -Seconds 2
}

if (-not $databaseReady) {
    throw 'MariaDB did not become ready.'
}

$tableCountOutput = & docker compose --env-file $EnvFile exec -T db mariadb -N "-u$databaseUser" "-p$databasePassword" $databaseName -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
if ($LASTEXITCODE -ne 0) {
    throw 'Could not inspect the local database.'
}
$tableCount = ($tableCountOutput | Select-Object -Last 1).Trim()

if ($tableCount -eq '0') {
    if (-not (Test-Path -LiteralPath 'wisperbot.sql')) {
        throw 'The database is empty and wisperbot.sql is missing from the project root.'
    }

    Write-Host 'Importing wisperbot.sql...'
    $databaseContainer = (& docker compose --env-file $EnvFile ps -q db).Trim()
    & docker cp (Resolve-Path -LiteralPath 'wisperbot.sql').Path "${databaseContainer}:/tmp/wisperbot.sql"
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not copy wisperbot.sql into the database container.'
    }

    & docker compose --env-file $EnvFile exec -T db mariadb "-u$databaseUser" "-p$databasePassword" $databaseName -e 'source /tmp/wisperbot.sql'
    if ($LASTEXITCODE -ne 0) {
        throw 'Database import failed.'
    }
    & docker compose --env-file $EnvFile exec -T db rm -f /tmp/wisperbot.sql
} else {
    Write-Host "Database already contains $tableCount tables; import skipped."
}

Write-Host 'Starting Laravel and running migrations...'
Invoke-Compose up -d app
Invoke-Compose exec -T --user www-data app php artisan migrate --force
Invoke-Compose exec -T --user www-data app php artisan optimize

Write-Host 'Starting all WisperBot services...'
Invoke-Compose up -d

Write-Host ''
Write-Host 'WisperBot is starting at http://localhost:8080'
Write-Host 'Run: docker compose --env-file .env.docker.local ps'
