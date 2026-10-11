<?php

namespace App\Modules\Operations\Console;

use App\Modules\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Throwable;

/**
 * Release gate: checks the running configuration against the production
 * checklist (docs/production-checklist.md). Exits non-zero on any failure so
 * deploy pipelines stop before switching traffic.
 */
class Preflight extends Command
{
    protected $signature = 'platform:preflight
        {--skip-database : Skip the checks that need a database connection}';

    protected $description = 'Check that this environment is configured safely for production';

    /**
     * @var list<array{string, string, string}>
     */
    private array $results = [];

    public function handle(AuditLogger $audit): int
    {
        $this->application();
        $this->sessions();
        $this->infrastructure();
        $this->identity();
        $this->headers();

        if (! $this->option('skip-database')) {
            $this->database($audit);
        }

        $this->table(['', 'Check', 'Detail'], array_map(
            fn (array $row) => [match ($row[0]) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            }, $row[1], $row[2]],
            $this->results,
        ));

        $failed = count(array_filter($this->results, fn (array $row) => $row[0] === 'fail'));
        $warned = count(array_filter($this->results, fn (array $row) => $row[0] === 'warn'));

        if ($failed > 0) {
            $this->components->error("{$failed} check(s) failed, {$warned} warning(s).");

            return self::FAILURE;
        }

        $this->components->info("All checks passed, {$warned} warning(s).");

        return self::SUCCESS;
    }

    private function application(): void
    {
        $this->check(app()->environment('production'), 'APP_ENV is production', (string) app()->environment());
        $this->check(config('app.debug') === false, 'APP_DEBUG is off', config('app.debug') ? 'on: stack traces would leak' : 'off');
        $this->check(is_string(config('app.key')) && config('app.key') !== '', 'APP_KEY is set', config('app.key') ? 'set' : 'missing');

        $url = (string) config('app.url');
        $this->check(str_starts_with($url, 'https://'), 'APP_URL uses HTTPS', $url);

        $root = (string) config('platform.root_domain');
        $this->check(
            preg_match('/\.(localhost|local|test|example|invalid)$/', $root) !== 1,
            'Root domain is a public domain',
            $root,
        );

        $level = (string) config('logging.channels.'.config('logging.default').'.level', 'debug');
        $this->check($level !== 'debug', 'Log level is above debug', $level, warnOnly: true);
    }

    private function sessions(): void
    {
        $cookie = (string) config('session.cookie');
        $this->check(config('session.secure') === true, 'Session cookie is Secure', var_export(config('session.secure'), true));
        $this->check(config('session.http_only') === true, 'Session cookie is HttpOnly', var_export(config('session.http_only'), true));
        $this->check(in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session cookie SameSite is lax or strict', (string) config('session.same_site'));
        $this->check(config('session.domain') === null || config('session.domain') === '', 'Session cookie is host-only (no Domain)', (string) (config('session.domain') ?? 'null'));
        $this->check(str_starts_with($cookie, '__Host-'), 'Session cookie uses the __Host- prefix', $cookie);
        $this->check(config('session.encrypt') === true, 'Session payload is encrypted', var_export(config('session.encrypt'), true), warnOnly: true);
    }

    private function infrastructure(): void
    {
        $this->check(! in_array(config('session.driver'), ['array', 'file', 'cookie'], true), 'Session store is shared', (string) config('session.driver'));
        $this->check(! in_array(config('cache.default'), ['array', 'file', 'null'], true), 'Cache store is shared', (string) config('cache.default'));
        $this->check(config('queue.default') !== 'sync' && config('queue.default') !== 'null', 'Queue runs asynchronously', (string) config('queue.default'));
        $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'Mail is actually delivered', (string) config('mail.default'));

        $auditDriver = (string) config('filesystems.disks.audit.driver');
        $this->check(
            $auditDriver !== 'local' && config('filesystems.disks.audit.bucket'),
            'Audit export goes off-site',
            $auditDriver === 'local' ? 'local disk: a compromised server could rewrite the copy' : $auditDriver.' '.(string) config('filesystems.disks.audit.bucket'),
        );

        $proxies = config('trustedproxy.proxies');
        $this->check(
            $proxies !== null && $proxies !== '' && $proxies !== '*' && $proxies !== '**',
            'Trusted proxies are listed explicitly',
            is_string($proxies) && $proxies !== '' ? $proxies : 'not set (HTTPS and client IPs behind a load balancer will be wrong)',
            warnOnly: true,
        );
    }

    private function identity(): void
    {
        $configured = config('passport.private_key');
        $hasKey = (is_string($configured) && $configured !== '') || is_file(Passport::keyPath('oauth-private.key'));
        $this->check($hasKey, 'Token signing key is present', $hasKey ? 'present' : 'missing');

        $missing = [];
        foreach (['app', 'admin', 'workspace'] as $client) {
            if (! config("platform.oidc.clients.{$client}.id") || ! config("platform.oidc.clients.{$client}.secret")) {
                $missing[] = $client;
            }
        }
        $this->check($missing === [], 'First-party OIDC clients are configured', $missing === [] ? 'app, admin, workspace' : 'missing: '.implode(', ', $missing));
    }

    private function headers(): void
    {
        $this->check(config('platform.security_headers.csp') === true, 'Content-Security-Policy is on', var_export(config('platform.security_headers.csp'), true));
        $this->check(config('platform.security_headers.csp_report_only') !== true, 'CSP is enforced, not report-only', var_export(config('platform.security_headers.csp_report_only'), true), warnOnly: true);
        $this->check(config('platform.security_headers.hsts') === true, 'HSTS is on', var_export(config('platform.security_headers.hsts'), true));
    }

    private function database(AuditLogger $audit): void
    {
        try {
            /** @var object{rolsuper: bool, can_update: bool, can_delete: bool, can_truncate: bool} $role */
            $role = DB::selectOne(<<<'SQL'
                SELECT r.rolsuper,
                       has_table_privilege(current_user, 'audit_logs', 'UPDATE') AS can_update,
                       has_table_privilege(current_user, 'audit_logs', 'DELETE') AS can_delete,
                       has_table_privilege(current_user, 'audit_logs', 'TRUNCATE') AS can_truncate
                FROM pg_roles r WHERE r.rolname = current_user
            SQL);
        } catch (Throwable $e) {
            $this->check(false, 'Database is reachable', $e->getMessage());

            return;
        }

        $this->check(! $role->rolsuper, 'Application database role is not a superuser', $role->rolsuper ? 'superuser' : 'ordinary role');

        $granted = array_keys(array_filter([
            'UPDATE' => $role->can_update,
            'DELETE' => $role->can_delete,
            'TRUNCATE' => $role->can_truncate,
        ]));
        $this->check(
            $granted === [],
            'Application role cannot change audit_logs',
            $granted === [] ? 'INSERT/SELECT only' : 'has '.implode(', ', $granted).' (apply ops/postgres/roles.sql)',
        );

        $broken = $audit->verifyChain();
        $this->check($broken === null, 'Audit hash chain is intact', $broken === null ? 'intact' : "broken at sequence {$broken}");
    }

    private function check(bool $ok, string $label, string $detail, bool $warnOnly = false): void
    {
        $this->results[] = [$ok ? 'pass' : ($warnOnly ? 'warn' : 'fail'), $label, $detail];
    }
}
