<#
Windows rehearsal of ops/deploy/deploy.sh: same steps, same order, same gates.

  $env:MIGRATION_DB_USERNAME = 'miles_owner'; $env:MIGRATION_DB_PASSWORD = '...'
  ops/local-staging/deploy.ps1 main [-Bootstrap]

Differences from Linux, all forced by Windows without admin rights:
  - shared/.env is hard-linked into the release (file symlinks need admin);
    storage and current are directory junctions.
  - The switch removes and recreates the `current` junction: not atomic, a
    gap of milliseconds.
  - "php-fpm reload" restarts the php-cgi pool (services.ps1 reload).
#>
param(
    [Parameter(Mandatory)][string]$Ref,
    [switch]$Bootstrap
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $true

$Base = $env:MILES_BASE ?? 'D:\srv\miles'
$Keep = [int]($env:KEEP_RELEASES ?? 5)
$Php = $env:MILES_PHP ?? 'C:\php84\php.exe'
$PhpIni = Join-Path $Base 'shared\php\php.ini'
$Psql = $env:MILES_PSQL ?? 'C:\Program Files\PostgreSQL\17\bin\psql.exe'
$HealthUrl = $env:HEALTH_URL ?? 'https://staging.milessoftwares.com/up'
$CaFile = Join-Path $Base 'shared\php\cacert.pem'
if (-not $env:MIGRATION_DB_USERNAME) { throw 'set MIGRATION_DB_USERNAME' }
if (-not $env:MIGRATION_DB_PASSWORD) { throw 'set MIGRATION_DB_PASSWORD' }
$Services = Join-Path $PSScriptRoot 'services.ps1'

function Get-EnvValue([string]$name) {
    $line = Get-Content (Join-Path $Base 'shared\.env') | Where-Object { $_ -match "^$name=" } | Select-Object -Last 1
    return ($line -replace "^$name=", '').Trim('"', "'")
}

function Invoke-Artisan {
    & $Php -c $PhpIni artisan @args --no-ansi
}

function Invoke-MigrateAndGrant {
    $env:DB_USERNAME = $env:MIGRATION_DB_USERNAME
    $env:DB_PASSWORD = $env:MIGRATION_DB_PASSWORD
    try { Invoke-Artisan migrate --force }
    finally { Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD }

    $env:PGPASSWORD = $env:MIGRATION_DB_PASSWORD
    try {
        & $Psql --no-psqlrc -v ON_ERROR_STOP=1 -q -h (Get-EnvValue DB_HOST) -p (Get-EnvValue DB_PORT) `
            -U $env:MIGRATION_DB_USERNAME -d (Get-EnvValue DB_DATABASE) -f ops/postgres/grants.sql
    }
    finally { Remove-Item Env:PGPASSWORD }
}

$id = (Get-Date).ToUniversalTime().ToString('yyyyMMddHHmmss')
$release = Join-Path $Base "releases\$id"
$repo = Join-Path $Base 'repo'

# A bare clone has no fetch refspec: name it, or branches would never move.
git --git-dir=$repo fetch --quiet --prune --tags origin '+refs/heads/*:refs/heads/*'
New-Item -ItemType Directory $release | Out-Null
git --git-dir=$repo --work-tree=$release checkout --quiet --force $Ref -- .
git --git-dir=$repo rev-parse $Ref | Set-Content (Join-Path $release 'REVISION')

New-Item -ItemType HardLink -Path (Join-Path $release '.env') -Target (Join-Path $Base 'shared\.env') | Out-Null
Remove-Item -Recurse -Force (Join-Path $release 'storage')
New-Item -ItemType Junction -Path (Join-Path $release 'storage') -Target (Join-Path $Base 'shared\storage') | Out-Null

Push-Location $release
try {
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
    npm ci --no-audit --no-fund
    # Client bundle plus the SSR bundle for the public site.
    npm run build:ssr

    if ($Bootstrap) {
        Invoke-MigrateAndGrant
        # Creates only the clients that are missing; safe to repeat.
        Invoke-Artisan platform:oidc-clients --write-env
    }

    # Stop here if the environment is not production-safe.
    Invoke-Artisan platform:preflight --skip-database

    Invoke-MigrateAndGrant

    # As the runtime role: not a superuser, audit_logs append-only, chain intact.
    Invoke-Artisan platform:preflight

    Invoke-Artisan platform:catalog-import
    Invoke-Artisan optimize
}
finally { Pop-Location }

# Switch (not atomic on Windows: remove the junction, create the new one).
$current = Join-Path $Base 'current'
if (Test-Path $current) { cmd /c rmdir "$current" }
New-Item -ItemType Junction -Path $current -Target $release | Out-Null

# php-fpm is always running on Linux; here the pool may not be yet.
& $Services start | Out-Null
& $Services reload
& $Php -c $PhpIni (Join-Path $current 'artisan') queue:restart --no-ansi
# From here on a failing command is handled, not thrown.
$PSNativeCommandUseErrorActionPreference = $false
# The supervisor restarts the SSR server from the new release.
& $Php (Join-Path $current 'artisan') inertia:stop-ssr --no-ansi 2>$null

$ok = $false
for ($i = 0; $i -lt 10 -and -not $ok; $i++) {
    Start-Sleep -Seconds 1
    & curl.exe --fail --silent --show-error --max-time 10 --cacert $CaFile $HealthUrl -o NUL 2>$null
    $ok = $LASTEXITCODE -eq 0
}
if (-not $ok) {
    Write-Error 'health check failed: run ops/local-staging/rollback.ps1'
    exit 1
}

# Keep the newest $Keep releases (ids sort by time; current is the newest).
Get-ChildItem (Join-Path $Base 'releases') -Directory | Sort-Object Name | Select-Object -SkipLast $Keep |
    ForEach-Object {
        cmd /c rmdir "$($_.FullName)\storage"
        Remove-Item -Recurse -Force $_.FullName
    }

Write-Output "released $id ($(Get-Content (Join-Path $release 'REVISION')))"
