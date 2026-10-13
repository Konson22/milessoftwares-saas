<#
Windows rehearsal of ops/deploy/rollback.sh: point `current` back at the
previous release. Database migrations are not reversed.
#>
$ErrorActionPreference = 'Stop'

$Base = $env:MILES_BASE ?? 'D:\srv\miles'
$Php = $env:MILES_PHP ?? 'C:\php84\php.exe'
$PhpIni = Join-Path $Base 'shared\php\php.ini'
$current = Join-Path $Base 'current'

$active = (Get-Item $current).Target | Select-Object -First 1
$activeId = Split-Path $active -Leaf
# The newest release older than the current one. A newer directory can be
# a deploy that failed before switching, so it is never a candidate.
$previous = Get-ChildItem (Join-Path $Base 'releases') -Directory | Sort-Object Name |
    Where-Object { $_.Name -lt $activeId } | Select-Object -Last 1

if (-not $previous) {
    Write-Error 'no previous release to roll back to'
    exit 1
}

cmd /c rmdir "$current"
New-Item -ItemType Junction -Path $current -Target $previous.FullName | Out-Null

& (Join-Path $PSScriptRoot 'services.ps1') reload
& $Php -c $PhpIni (Join-Path $current 'central-app/artisan') queue:restart --no-ansi
& $Php (Join-Path $current 'central-app/artisan') inertia:stop-ssr --no-ansi 2>$null
# Products in that release (the same list deploy.ps1 used).
foreach ($code in (($env:MILES_PRODUCTS ?? 'pharmacy finance') -split '\s+' | Where-Object { $_ })) {
    $artisan = Join-Path $current "$code-app/artisan"
    if (Test-Path $artisan) { & $Php -c $PhpIni $artisan queue:restart --no-ansi }
}

$revision = Get-Content (Join-Path $previous.FullName 'REVISION') -ErrorAction SilentlyContinue
Write-Output "rolled back to $($previous.Name) ($($revision ?? 'unknown'))"
