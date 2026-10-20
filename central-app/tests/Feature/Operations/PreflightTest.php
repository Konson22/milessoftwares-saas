<?php

use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A configuration that satisfies every non-database check.
 */
function productionLikeConfig(): void
{
    app()->detectEnvironment(fn () => 'production');

    config([
        'app.debug' => false,
        'app.url' => 'https://milesoftwares.com',
        'platform.root_domain' => 'milesoftwares.com',
        'logging.default' => 'stack',
        'logging.channels.stack.level' => 'warning',
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'session.domain' => null,
        'session.cookie' => '__Host-miles-session',
        'session.encrypt' => true,
        'session.driver' => 'redis',
        'cache.default' => 'redis',
        'queue.default' => 'redis',
        'mail.default' => 'smtp',
        'trustedproxy.proxies' => '10.0.0.0/8',
        'filesystems.disks.audit.driver' => 's3',
        'filesystems.disks.audit.bucket' => 'miles-audit',
        'platform.oidc.clients.app' => ['id' => 'a', 'secret' => 's'],
        'platform.oidc.clients.admin' => ['id' => 'b', 'secret' => 's'],
        'platform.oidc.clients.workspace' => ['id' => 'c', 'secret' => 's'],
        'platform.security_headers.csp' => true,
        'platform.security_headers.csp_report_only' => false,
        'platform.security_headers.hsts' => true,
    ]);
}

function preflight(array $options = []): array
{
    $code = Artisan::call('platform:preflight', $options);

    return [$code, Artisan::output()];
}

it('passes a production-ready configuration', function () {
    productionLikeConfig();

    [$code, $output] = preflight(['--skip-database' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('All checks passed, 0 warning(s)')
        ->and($output)->not->toContain('FAIL');
});

it('fails the release on unsafe settings and names each one', function (string $key, mixed $value, string $label) {
    productionLikeConfig();
    config([$key => $value]);

    [$code, $output] = preflight(['--skip-database' => true]);

    expect($code)->toBe(1)
        ->and($output)->toMatch('/FAIL\S*\s*\|\s*'.preg_quote($label, '/').'/');
})->with([
    'debug on' => ['app.debug', true, 'APP_DEBUG is off'],
    'plain http' => ['app.url', 'http://milesoftwares.com', 'APP_URL uses HTTPS'],
    'dev domain' => ['platform.root_domain', 'milesoftwares.localhost', 'Root domain is a public domain'],
    'insecure cookie' => ['session.secure', null, 'Session cookie is Secure'],
    'shared cookie domain' => ['session.domain', '.milesoftwares.com', 'Session cookie is host-only'],
    'no __Host- prefix' => ['session.cookie', 'miles-session', 'Session cookie uses the __Host- prefix'],
    'file sessions' => ['session.driver', 'file', 'Session store is shared'],
    'sync queue' => ['queue.default', 'sync', 'Queue runs asynchronously'],
    'mail to log' => ['mail.default', 'log', 'Mail is actually delivered'],
    'audit export on local disk' => ['filesystems.disks.audit.driver', 'local', 'Audit export goes off-site'],
    'no CSP' => ['platform.security_headers.csp', false, 'Content-Security-Policy is on'],
    'no HSTS' => ['platform.security_headers.hsts', false, 'HSTS is on'],
    'missing OIDC client' => ['platform.oidc.clients.admin.secret', null, 'First-party OIDC clients are configured'],
]);

it('fails outside the production environment', function () {
    productionLikeConfig();
    app()->detectEnvironment(fn () => 'staging');

    [$code, $output] = preflight(['--skip-database' => true]);

    expect($code)->toBe(1)->and($output)->toContain('staging');
});

it('only warns when trusting every proxy', function () {
    productionLikeConfig();
    config(['trustedproxy.proxies' => '*']);

    [$code, $output] = preflight(['--skip-database' => true]);

    expect($code)->toBe(0)
        ->and($output)->toMatch('/WARN\S*\s*\|\s*Trusted proxies are listed explicitly/');
});

it('fails when the database role could rewrite the audit trail', function () {
    productionLikeConfig();

    // The test database is owned by the connecting role, so it holds every
    // privilege on audit_logs: exactly what ops/postgres/grants.sql removes.
    [$code, $output] = preflight();

    expect($code)->toBe(1)
        ->and($output)->toMatch('/FAIL\S*\s*\|\s*Application role cannot change audit_logs\s*\|\s*has UPDATE, DELETE, TRUNCATE/');
});

it('passes the audit check for a role limited to INSERT and SELECT', function () {
    // CI connects as the container superuser; a local development role may
    // lack CREATEROLE.
    $role = DB::selectOne('SELECT rolsuper OR rolcreaterole AS allowed FROM pg_roles WHERE rolname = current_user');
    if (! $role->allowed) {
        $this->markTestSkipped('The database role cannot create roles.');
    }

    productionLikeConfig();
    app(AuditLogger::class)->record('user.login');

    // The same narrowing grants.sql applies to miles_app, on a throwaway role
    // that only lives inside this test's transaction.
    DB::statement('CREATE ROLE preflight_probe NOLOGIN');
    DB::statement('GRANT SELECT ON ALL TABLES IN SCHEMA public TO preflight_probe');
    DB::statement('GRANT SELECT, INSERT ON audit_logs TO preflight_probe');
    DB::statement('SET LOCAL ROLE preflight_probe');

    [, $output] = preflight();

    DB::statement('RESET ROLE');

    expect($output)->toMatch('/PASS\S*\s*\|\s*Application role cannot change audit_logs\s*\|\s*INSERT\/SELECT only/')
        ->and($output)->toMatch('/PASS\S*\s*\|\s*Application database role is not a superuser/')
        ->and($output)->toMatch('/PASS\S*\s*\|\s*Audit hash chain is intact/');
});

it('fails when the audit chain is broken', function () {
    productionLikeConfig();
    $logger = app(AuditLogger::class);
    $logger->record('user.login');
    $logger->record('user.logout');

    $first = AuditLog::query()->min('sequence');
    DB::transaction(function () use ($first) {
        DB::statement("SET LOCAL miles.audit_purge = 'on'");
        DB::table('audit_logs')->where('sequence', $first)->delete();
    });

    [$code, $output] = preflight();

    expect($code)->toBe(1)
        ->and($output)->toMatch('/FAIL\S*\s*\|\s*Audit hash chain is intact\s*\|\s*broken at sequence/');
});
