# Runbook: DNS, TLS and Host Verification

## Records

| Name                   | Type     | Value                                                           |
| ---------------------- | -------- | --------------------------------------------------------------- |
| `milessoftwares.com`   | A / AAAA | load balancer                                                   |
| `*.milessoftwares.com` | A / AAAA | load balancer                                                   |
| `milessoftwares.com`   | CAA      | `0 issuewild "letsencrypt.org"` and `0 issue "letsencrypt.org"` |
| `_acme-challenge`      | TXT      | written by the ACME client (DNS-01)                             |
| `milessoftwares.com`   | MX/TXT   | mail provider, SPF, DMARC; DKIM selector records                |

The wildcard covers `www`, `auth`, `app`, `admin` and every `{slug}` workspace. The application decides
which hosts exist: reserved names and unknown slugs answer 404, and multi-level names (`a.b.milessoftwares.com`)
are rejected (`tests/Feature/Routing`, `tests/Feature/Tenancy`). Products on their own hosts
(`retail.milessoftwares.com`) get their own records and certificates from their teams.

## Certificate

One certificate for `milessoftwares.com` and `*.milessoftwares.com`. Wildcards need the DNS-01
challenge:

```bash
certbot certonly --dns-<provider> --dns-<provider>-credentials /etc/letsencrypt/<provider>.ini \
  -d milessoftwares.com -d '*.milessoftwares.com' --deploy-hook 'systemctl reload nginx'
```

The DNS API token must be limited to TXT records under `_acme-challenge`. `certbot renew` runs from its
systemd timer; renewal starts 30 days before expiry.

If TLS ends at a cloud load balancer instead, attach the same names there, keep nginx on 443 with an
internal certificate, and set `TRUSTED_PROXIES` to the balancer's address range.

## Verification after any DNS, certificate or nginx change

```bash
ROOT=milessoftwares.com
for h in $ROOT www.$ROOT auth.$ROOT app.$ROOT admin.$ROOT <a-real-slug>.$ROOT; do
  printf '%-40s ' "$h"; curl -sS -o /dev/null -w '%{http_code} %{ssl_verify_result}\n' "https://$h/"
done
# 200/302 and ssl_verify_result 0 for every line

curl -sI "http://app.$ROOT/x?y=1" | grep -i '^location'        # → https://app.…/x?y=1
curl -sI "https://www.$ROOT/pricing" | grep -i '^location'      # → https://$ROOT/pricing (301)
curl -s -o /dev/null -w '%{http_code}\n' "https://nope-$(date +%s).$ROOT/"   # 404
curl -s -o /dev/null -w '%{http_code}\n' "https://a.b.$ROOT/"                # 404
curl -sk --resolve evil.example:443:<server ip> https://evil.example/ ; echo "exit $?"   # handshake refused
curl -sI "https://$ROOT/" | grep -iE 'strict-transport|content-security|x-frame|referrer'
openssl s_client -connect $ROOT:443 -servername $ROOT </dev/null 2>/dev/null \
  | openssl x509 -noout -subject -ext subjectAltName -enddate
```

Then run SSL Labs (grade A or better) and securityheaders.com (A or better).

## HSTS rollout

1. Every host serves a valid certificate: verification above passes.
2. `PLATFORM_HSTS=true` with `PLATFORM_HSTS_MAX_AGE=300` for one day.
3. Raise to `63072000` (two years). `includeSubDomains` stays on: every subdomain must be HTTPS.
4. Preload (`hstspreload.org`) only once nobody expects plain HTTP anywhere under the domain; it
   cannot be undone quickly.
