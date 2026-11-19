<#
Windows rehearsal of ops/deploy/deploy.sh: same steps, same order, same gates.

  $env:MIGRATION_DB_USERNAME = 'miles_owner'; $env:MIGRATION_DB_PASSWORD = '...'
  ops/local-staging/deploy.ps1 main [-Bootstrap]

Differences from Linux, all forced by Windows without admin rights:
  - shared/.env is hard-linked into the release's central-app (file symlinks need admin);
    storage and current are directory junctions.
  - The switch removes and recreates the `current` junction: not atomic, a
    gap of milliseconds.
  - "php-fpm reload" restarts the php-cgi pool (services.ps1 reload).

Products ($env:MILES_PRODUCTS, default "pharmacy finance") ship in the same
release, as on Linux: shared\products\<code>\{.env,storage} per product.
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
$Products = @((($env:MILES_PRODUCTS ?? 'pharmacy finance') -split '\s+') | Where-Object { $_ })

# A value from the platform's .env, or from a product's.
function Get-EnvValue([string]$name, [string]$file = (Join-Path $Base 'shared\.env')) {
    $line = Get-Content $file | Where-Object { $_ -match "^$name=" } | Select-Object -Last 1
    return ($line -replace "^$name=", '').Trim('"', "'")
}

foreach ($code in $Products) {
    if (-not (Test-Path (Join-Path $Base "shared\products\$code\.env"))) {
        throw "missing $Base\shared\products\$code\.env (ops/local-staging/README.md, Products)"
    }
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
            -U $env:MIGRATION_DB_USERNAME -d (Get-EnvValue DB_DATABASE) -f ../ops/postgres/grants.sql
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

$app = Join-Path $release 'central-app'
New-Item -ItemType HardLink -Path (Join-Path $app '.env') -Target (Join-Path $Base 'shared\.env') | Out-Null
Remove-Item -Recurse -Force (Join-Path $app 'storage')
New-Item -ItemType Junction -Path (Join-Path $app 'storage') -Target (Join-Path $Base 'shared\storage') | Out-Null

Push-Location $app
try {
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
    npm ci --no-audit --no-fund
    # Client bundle plus the SSR bundle for the public site.
    npm run build:ssr

    # Products: install and build each in its own folder of the release.
    foreach ($code in $Products) {
        $product = Join-Path $release "$code-app"
        if (-not (Test-Path (Join-Path $product 'artisan'))) { throw "no product $code-app in $Ref" }
        $shared = Join-Path $Base "shared\products\$code"
        New-Item -ItemType HardLink -Path (Join-Path $product '.env') -Target (Join-Path $shared '.env') | Out-Null
        Remove-Item -Recurse -Force (Join-Path $product 'storage')
        New-Item -ItemType Junction -Path (Join-Path $product 'storage') -Target (Join-Path $shared 'storage') | Out-Null
        Push-Location $product
        try {
            composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
            npm ci --no-audit --no-fund
            npm run build
            # Client-only bundle: node_modules is not needed at runtime.
            Remove-Item -Recurse -Force node_modules
        }
        finally { Pop-Location }
    }

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

    # Each product's database, the same way.
    foreach ($code in $Products) {
        $productEnv = Join-Path $Base "shared\products\$code\.env"
        Push-Location (Join-Path $release "$code-app")
        try {
            $env:DB_USERNAME = $env:MIGRATION_DB_USERNAME
            $env:DB_PASSWORD = $env:MIGRATION_DB_PASSWORD
            try { Invoke-Artisan migrate --force }
            finally { Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD }

            $env:PGPASSWORD = $env:MIGRATION_DB_PASSWORD
            try {
                & $Psql --no-psqlrc -v ON_ERROR_STOP=1 -q -h (Get-EnvValue DB_HOST $productEnv) -p (Get-EnvValue DB_PORT $productEnv) `
                    -U $env:MIGRATION_DB_USERNAME -d (Get-EnvValue DB_DATABASE $productEnv) -f ../ops/postgres/product-grants.sql
            }
            finally { Remove-Item Env:PGPASSWORD }
            Invoke-Artisan optimize
        }
        finally { Pop-Location }
    }

    # The platform's own catalogs and each product's (roles and permissions).
    $catalogs = @(Get-ChildItem database/catalogs/*.json | ForEach-Object { $_.FullName })
    $catalogs += $Products | ForEach-Object { "../$_-app/platform/catalog.json" }
    Invoke-Artisan platform:catalog-import @catalogs
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
& $Php -c $PhpIni (Join-Path $current 'central-app/artisan') queue:restart --no-ansi
foreach ($code in $Products) {
    & $Php -c $PhpIni (Join-Path $current "$code-app/artisan") queue:restart --no-ansi
}
# From here on a failing command is handled, not thrown.
$PSNativeCommandUseErrorActionPreference = $false
# The supervisor restarts the SSR server from the new release.
& $Php (Join-Path $current 'central-app/artisan') inertia:stop-ssr --no-ansi 2>$null

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
        cmd /c rmdir "$($_.FullName)\central-app\storage"
        # Product storage junctions too, so the shared storage is never deleted.
        Get-ChildItem $_.FullName -Directory -Filter '*-app' | ForEach-Object {
            if (Test-Path "$($_.FullName)\storage") { cmd /c rmdir "$($_.FullName)\storage" }
        }
        Remove-Item -Recurse -Force $_.FullName
    }

Write-Output "released $id ($(Get-Content (Join-Path $release 'REVISION')))"
