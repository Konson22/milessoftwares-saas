# Local development (Windows)

`apache-dev.conf` serves `milesoftwares.localhost` and every `*.milesoftwares.localhost` on port 80,
forwarding to `php artisan serve` on :8100 (see `docs/PROGRESS.md`, Decisions).

## Workspace subdomains resolve by themselves

Each organization gets its own host (`{slug}.milesoftwares.localhost`). The Windows hosts file has no
wildcards, so this machine runs [Acrylic DNS Proxy](https://mayakron.altervista.org/) (installed
2026-10-16 with `winget install Mayakron.AcrylicDNS`) as its DNS server:

- `C:\Program Files (x86)\Acrylic DNS Proxy\AcrylicHosts.txt` ends with

    ```
    127.0.0.1 milesoftwares.localhost
    127.0.0.1 *.milesoftwares.localhost
    ```

  Loopback, not the LAN address: Apache listens on every interface, so the entry survives network
  changes (it pointed at `192.168.88.28` until 2026-10-16 and broke on a new network).

- The Wi-Fi adapter's DNS is `127.0.0.1` / `::1` (Acrylic); Acrylic forwards every other name to
  8.8.8.8 / 8.8.4.4 (`AcrylicConfiguration.ini`).

New workspaces need no further setup. After editing the lines above, restart the service
(elevated): `Restart-Service AcrylicDNSProxySvc`.

If subdomains stop resolving, check the Wi-Fi DNS first: `Get-DnsClientServerAddress -InterfaceAlias Wi-Fi`
must show `127.0.0.1` / `::1`. To restore it (elevated):
`Set-DnsClientServerAddress -InterfaceAlias Wi-Fi -ServerAddresses 127.0.0.1,::1`.

Check: `Resolve-DnsName anything.milesoftwares.localhost -Server 127.0.0.1`.

### Undo

Elevated PowerShell:

```powershell
Set-DnsClientServerAddress -InterfaceAlias Wi-Fi -ResetServerAddresses   # DNS from the router again
winget uninstall Mayakron.AcrylicDNS
```

The old per-subdomain lines in `C:\Windows\System32\drivers\etc\hosts` still work without Acrylic.
