<?php

use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(); // provisioning calls to the product

    $this->folder = storage_path('framework/testing/products/retail-app');
    File::ensureDirectoryExists($this->folder.'/platform');
    File::copy(base_path('tests/Fixtures/catalogs/retail.json'), $this->folder.'/platform/catalog.json');
    File::put($this->folder.'/.env.example', "APP_NAME=Retail\nPLATFORM_APPLICATION=\nPLATFORM_WEBHOOK_SECRET=\n");
    File::delete($this->folder.'/.env');

    $this->organization = Organization::factory()->withSlug('acme')->create();
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/products'));
});

function env_of(string $folder): array
{
    $values = [];
    foreach (file($folder.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = $value;
    }

    return $values;
}

function activeClients(Application $application): array
{
    return DB::table('application_clients')->where('application_id', $application->id)->where('status', 'active')
        ->orderBy('client_type')->pluck('client_type')->all();
}

it('registers a product folder and writes its .env', function () {
    $this->artisan('platform:local-product', ['path' => $this->folder, '--entitle' => ['acme']])->assertSuccessful();

    $retail = Application::query()->where('code', 'retail')->sole();
    expect($retail)
        ->route_prefix->toBe('retail')
        ->provisioning_method->toBe('sync_api')
        ->integration_endpoint->toBe('http://127.0.0.1:8400/retail/platform')
        ->and(activeClients($retail))->toBe(['confidential', 'service']);

    $env = env_of($this->folder);
    expect($env)
        ->APP_NAME->toBe('Retail')
        ->PLATFORM_APPLICATION->toBe('retail')
        ->PLATFORM_ROOT_DOMAIN->toBe(config('platform.root_domain'))
        ->and($env['PLATFORM_WEBHOOK_SECRET'])->toStartWith('whsec_')
        ->and($env['PLATFORM_SIGN_IN_CLIENT_SECRET'])->not->toBe('')
        ->and($env['PLATFORM_SERVICE_CLIENT_ID'])->not->toBe($env['PLATFORM_SIGN_IN_CLIENT_ID']);

    // The workspace callback exists for the entitled organization only.
    $callbacks = DB::table('application_client_redirect_uris')->pluck('uri')->all();
    expect($callbacks)->toContain('http://acme.'.config('platform.root_domain').(parse_url((string) config('app.url'), PHP_URL_PORT) ? ':'.parse_url((string) config('app.url'), PHP_URL_PORT) : '').'/retail/auth/callback');

    $entitled = app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->current()->where('application_id', $retail->id)->count());
    expect($entitled)->toBe(1);
});

it('keeps clients and secrets when run again, and issues new ones with --fresh', function () {
    $this->artisan('platform:local-product', ['path' => $this->folder, '--entitle' => ['acme']])->assertSuccessful();
    $first = env_of($this->folder);

    $this->artisan('platform:local-product', ['path' => $this->folder, '--entitle' => ['acme']])->assertSuccessful();
    $retail = Application::query()->where('code', 'retail')->sole();

    expect(env_of($this->folder))->toBe($first)
        ->and(activeClients($retail))->toBe(['confidential', 'service'])
        ->and(app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->current()->count()))->toBe(1);

    $this->artisan('platform:local-product', ['path' => $this->folder, '--fresh' => true])->assertSuccessful();
    $fresh = env_of($this->folder);

    expect($fresh['PLATFORM_SIGN_IN_CLIENT_ID'])->not->toBe($first['PLATFORM_SIGN_IN_CLIENT_ID'])
        ->and($fresh['PLATFORM_WEBHOOK_SECRET'])->not->toBe($first['PLATFORM_WEBHOOK_SECRET'])
        ->and(activeClients($retail))->toBe(['confidential', 'service']);
});

it('refuses a path the workspace shell owns', function () {
    $this->artisan('platform:local-product', ['path' => $this->folder, '--prefix' => 'members'])->assertFailed();

    expect(Application::query()->where('code', 'retail')->value('route_prefix'))->toBeNull();
});

it('refuses on production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('platform:local-product', ['path' => $this->folder])->assertFailed();
    expect(Application::query()->where('code', 'retail')->exists())->toBeFalse();
});
