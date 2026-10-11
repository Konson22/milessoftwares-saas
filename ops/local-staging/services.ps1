<#
Local stand-in for the systemd units in ops/systemd (Windows rehearsal only).

  ops/local-staging/services.ps1 start|stop|status
  ops/local-staging/services.ps1 reload    # what deploy does instead of "systemctl reload php8.4-fpm"

Every service runs under a small supervisor that restarts it when it exits
(systemd's Restart=always), so `queue:restart`, `inertia:stop-ssr` and php-cgi
reaching PHP_FCGI_MAX_REQUESTS behave as they do on Linux.

  php-cgi 1..N   FastCGI on 127.0.0.1:9201.. (Apache balances across them; php-fpm pool)
  queue 1..3     queue:work redis           (miles-queue@)
  scheduler      schedule:work              (miles-scheduler.timer)
  ssr            inertia:start-ssr          (miles-ssr)
#>
param(
    [Parameter(Mandatory)][ValidateSet('start', 'stop', 'status', 'reload')][string]$Action
)

$ErrorActionPreference = 'Stop'

$Base = $env:MILES_BASE ?? 'D:\srv\miles'
$Php = $env:MILES_PHP ?? 'C:\php84\php.exe'
$PhpCgi = Join-Path (Split-Path $Php) 'php-cgi.exe'
$PhpIni = Join-Path $Base 'shared\php\php.ini'
$CgiWorkers = [int]($env:MILES_PHP_CGI_WORKERS ?? 6)
$Run = Join-Path $Base 'run'
$Logs = Join-Path $Base 'shared\storage\logs\services'
# The Laravel app inside the live release.
$Current = Join-Path $Base 'current/central-app'

function Get-Services {
    $list = @()
    for ($i = 1; $i -le $CgiWorkers; $i++) {
        $list += @{ Name = "php-cgi-$i"; Exe = $PhpCgi; Args = @('-c', $PhpIni, '-b', "127.0.0.1:$(9200 + $i)"); Group = 'php' }
    }
    for ($i = 1; $i -le 3; $i++) {
        $list += @{ Name = "queue-$i"; Exe = $Php; Args = @('-c', $PhpIni, "$Current\artisan", 'queue:work', 'redis', '--sleep=1', '--tries=3', '--max-time=3600', '--memory=256'); Group = 'app' }
    }
    $list += @{ Name = 'scheduler'; Exe = $Php; Args = @('-c', $PhpIni, "$Current\artisan", 'schedule:work'); Group = 'app' }
    $list += @{ Name = 'ssr'; Exe = $Php; Args = @("$Current\artisan", 'inertia:start-ssr'); Group = 'app' }
    return $list
}

# The supervisor loop, run in its own hidden pwsh process per service.
$supervisor = @'
param($Name, $Exe, $Run, $Logs, $Current)
$args2 = Get-Content "$Run\$Name.args.json" -Raw | ConvertFrom-Json
$env:PHP_FCGI_MAX_REQUESTS = '1000'
while (-not (Test-Path "$Run\stop")) {
    $p = Start-Process -FilePath $Exe -ArgumentList $args2 -WorkingDirectory $Current -NoNewWindow -PassThru `
        -RedirectStandardOutput "$Logs\$Name.out.log" -RedirectStandardError "$Logs\$Name.err.log"
    Set-Content "$Run\$Name.child" $p.Id
    $p.WaitForExit()
    Add-Content "$Logs\supervisor.log" "$(Get-Date -Format o) $Name exited ($($p.ExitCode)), restarting"
    Start-Sleep -Seconds 2
}
'@

function Get-Pid([string]$file) {
    if (Test-Path $file) { $id = [int](Get-Content $file); if (Get-Process -Id $id -ErrorAction SilentlyContinue) { return $id } }
    return $null
}

function Stop-Children([string]$group) {
    foreach ($svc in (Get-Services | Where-Object { -not $group -or $_.Group -eq $group })) {
        $id = Get-Pid "$Run\$($svc.Name).child"
        if ($id) { Stop-Process -Id $id -Force -ErrorAction SilentlyContinue }
    }
}

New-Item -ItemType Directory -Force $Run, $Logs | Out-Null

switch ($Action) {
    'start' {
        if (-not (Test-Path "$Current\artisan")) { throw "no release at $Current; deploy first" }
        Remove-Item "$Run\stop" -ErrorAction SilentlyContinue
        $script = Join-Path $Run 'supervise.ps1'
        Set-Content $script $supervisor
        foreach ($svc in Get-Services) {
            if (Get-Pid "$Run\$($svc.Name).sup") { Write-Output "$($svc.Name): already running"; continue }
            ConvertTo-Json -Compress @($svc.Args) | Set-Content "$Run\$($svc.Name).args.json"
            $sup = Start-Process pwsh -WindowStyle Hidden -PassThru -ArgumentList @(
                '-NoProfile', '-File', $script, $svc.Name, "`"$($svc.Exe)`"", $Run, $Logs, $Current)
            Set-Content "$Run\$($svc.Name).sup" $sup.Id
            Write-Output "$($svc.Name): started"
        }
    }
    'stop' {
        Set-Content "$Run\stop" ''
        foreach ($svc in Get-Services) {
            $id = Get-Pid "$Run\$($svc.Name).sup"
            if ($id) { Stop-Process -Id $id -Force -ErrorAction SilentlyContinue }
        }
        Stop-Children
        Write-Output 'stopped'
    }
    'status' {
        foreach ($svc in Get-Services) {
            $sup = Get-Pid "$Run\$($svc.Name).sup"
            $child = Get-Pid "$Run\$($svc.Name).child"
            '{0,-12} supervisor {1,-8} process {2}' -f $svc.Name, ($sup ?? 'down'), ($child ?? 'down')
        }
    }
    'reload' {
        # php-fpm reload: new php-cgi processes pick up the new release and an empty opcache.
        # Scheduler included: schedule:work keeps the release it started from.
        Stop-Children 'php'
        $id = Get-Pid "$Run\scheduler.child"
        if ($id) { Stop-Process -Id $id -Force -ErrorAction SilentlyContinue }
        Write-Output 'reloaded php-cgi pool and scheduler'
    }
}
