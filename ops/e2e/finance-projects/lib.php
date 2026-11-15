<?php

/**
 * Shared by the E2E drivers: the environment each app runs with, and how a
 * driver boots its app. Every app gets its own e2e_* PostgreSQL database
 * and its own port; nothing here reads or writes the development databases.
 *
 * The run state (issued client ids and secrets, organization ids) lives in
 * E2E_RUN_DIR/state.json, outside the repository.
 */
const E2E_PORTS = ['central' => 8911, 'finance' => 8912, 'projects' => 8913];
const E2E_FOLDERS = ['central' => 'central-app', 'finance' => 'finance-app', 'projects' => 'project-management'];

function e2e_root(): string
{
    return dirname(__DIR__, 3);
}

function e2e_run_dir(): string
{
    $dir = getenv('E2E_RUN_DIR') ?: sys_get_temp_dir().'/tatook-e2e';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

/** @return array<string, mixed> */
function e2e_state(): array
{
    $file = e2e_run_dir().'/state.json';

    return is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
}

/** @param array<string, mixed> $values */
function e2e_save(array $values): void
{
    file_put_contents(e2e_run_dir().'/state.json', json_encode([...e2e_state(), ...$values], JSON_PRETTY_PRINT));
}

/** A value from the development .env of Central (database host and role only). */
function e2e_dev_env(string $key): string
{
    preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents(e2e_root().'/central-app/.env'), $m);

    return trim($m[1] ?? '', " \t\"'\r");
}

/**
 * The environment of one app in the run. Real environment variables win
 * over each app's .env (Laravel reads .env immutably), so every setting
 * that could reach a development resource is overridden here.
 *
 * @return array<string, string>
 */
function e2e_env(string $app): array
{
    $state = e2e_state();
    $common = [
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => e2e_dev_env('DB_HOST'),
        'DB_PORT' => e2e_dev_env('DB_PORT'),
        'DB_USERNAME' => e2e_dev_env('DB_USERNAME'),
        'DB_PASSWORD' => e2e_dev_env('DB_PASSWORD'),
        'DB_URL' => '',
        'DB_DATABASE' => 'e2e_'.$app,
        // Per process: nothing is shared with the development caches.
        'CACHE_STORE' => 'array',
        'CACHE_PREFIX' => 'e2e_'.$app.'_',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'LOG_CHANNEL' => 'single',
        'LOG_LEVEL' => 'debug',
        'TELESCOPE_ENABLED' => 'false',
        'PLATFORM_ROOT_DOMAIN' => 'tatook.localhost',
        'PLATFORM_ISSUER' => 'http://auth.tatook.localhost',
    ];

    if ($app === 'central') {
        return $common + [
            'APP_URL' => 'http://tatook.localhost',
            'PLATFORM_OIDC_BACKCHANNEL_URL' => 'http://127.0.0.1:'.E2E_PORTS['central'],
        ];
    }

    $creds = $state['clients'][$app] ?? [];

    return $common + [
        'APP_URL' => 'http://tatook.localhost',
        'PLATFORM_APPLICATION' => $app,
        'PLATFORM_PATH' => $app,
        'PLATFORM_BACKCHANNEL_URL' => 'http://127.0.0.1:'.E2E_PORTS['central'],
        'PLATFORM_SIGN_IN_CLIENT_ID' => (string) ($creds['sign_in_id'] ?? ''),
        'PLATFORM_SIGN_IN_CLIENT_SECRET' => (string) ($creds['sign_in_secret'] ?? ''),
        'PLATFORM_SERVICE_CLIENT_ID' => (string) ($creds['service_id'] ?? ''),
        'PLATFORM_SERVICE_CLIENT_SECRET' => (string) ($creds['service_secret'] ?? ''),
        'PLATFORM_WEBHOOK_SECRET' => (string) ($creds['webhook_secret'] ?? ''),
        'PLATFORM_MOBILE_CLIENT_ID' => '',
        'PLATFORM_FINANCE_EVENTS_URL' => $app === 'projects' ? 'http://127.0.0.1:'.E2E_PORTS['finance'].'/finance/events' : '',
        'PLATFORM_PROJECTS_EVENTS_URL' => $app === 'finance' ? 'http://127.0.0.1:'.E2E_PORTS['projects'].'/projects/events' : '',
        'PROJECTS_FINANCE_INTEGRATION' => 'true',
    ];
}

/** Applies an app's environment to this process (before its app boots). */
function e2e_apply_env(string $app): void
{
    foreach (e2e_env($app) as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

/** Boots an app in this process, with its E2E environment. */
function e2e_boot(string $app): Illuminate\Foundation\Application
{
    e2e_apply_env($app);
    $root = e2e_root().'/'.E2E_FOLDERS[$app];
    chdir($root);
    require $root.'/vendor/autoload.php';
    /** @var Illuminate\Foundation\Application $laravel */
    $laravel = require $root.'/bootstrap/app.php';
    $laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($laravel['config']->get('database.connections.pgsql.database') !== 'e2e_'.$app) {
        fwrite(STDERR, "Refusing to run: {$app} is not on its e2e database.\n");
        exit(2);
    }

    return $laravel;
}

/** Prints a JSON result line and exits. */
function e2e_out(mixed $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
}
