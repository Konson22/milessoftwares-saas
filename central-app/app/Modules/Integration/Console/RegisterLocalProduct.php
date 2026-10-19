<?php

namespace App\Modules\Integration\Console;

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Wires a product folder of this repository (e.g. pharmacy-app) to the
 * local platform, as staff would in admin → Applications → Integration:
 * catalog, workspace path, sign-in client with the tenant callback path,
 * service client, webhook and provisioning endpoint. The issued values go
 * into the product's .env. Local development only.
 */
class RegisterLocalProduct extends Command
{
    protected $signature = 'platform:local-product
        {path : Product folder, relative to the repository root (e.g. pharmacy-app)}
        {--url=http://127.0.0.1:8400 : Where the product answers platform calls (webhooks, provisioning)}
        {--prefix= : Path on workspace hosts (default: the application code)}
        {--entitle=* : Workspace slugs to entitle to the product}
        {--fresh : Issue new clients and secrets even if the product .env has them}
        {--catalog=platform/catalog.json : Catalog to register, relative to the folder. Another application than the folder\'s own is hosted by it}';

    protected $description = 'Register a product folder with the local platform and write its .env';

    // After PLATFORM_ (the folder's own application) or PLATFORM_<CODE>_ (one it hosts).
    private const ENV_KEYS = [
        'SIGN_IN_CLIENT_ID', 'SIGN_IN_CLIENT_SECRET',
        'SERVICE_CLIENT_ID', 'SERVICE_CLIENT_SECRET',
        'WEBHOOK_SECRET',
    ];

    public function handle(CatalogImporter $catalogs, ManageIntegration $integration, ManageEntitlements $entitlements, TenantContext $tenant): int
    {
        if (app()->isProduction()) {
            $this->components->error('Products are registered by staff in admin on production.');

            return self::FAILURE;
        }

        $folder = $this->folder((string) $this->argument('path'));
        if ($folder === null) {
            return self::FAILURE;
        }

        $catalog = json_decode((string) file_get_contents($folder.'/'.$this->catalogPath()), true, flags: JSON_THROW_ON_ERROR);
        $catalogs->import($catalog);
        $application = Application::query()->where('code', $catalog['application']['code'])->sole();

        $prefix = (string) ($this->option('prefix') ?: $application->code);
        $validator = Validator::make(['route_prefix' => $prefix], ['route_prefix' => [
            'regex:/^[a-z][a-z0-9-]*$/',
            Rule::notIn((array) config('platform.workspace_paths')),
            Rule::unique('applications', 'route_prefix')->ignore($application->id),
        ]]);
        if ($validator->fails()) {
            $this->components->error("[{$prefix}] cannot be a workspace path: ".$validator->errors()->first());

            return self::FAILURE;
        }

        $base = rtrim((string) $this->option('url'), '/');
        $application->forceFill([
            'route_prefix' => $prefix,
            'provisioning_method' => 'sync_api',
            'integration_endpoint' => "{$base}/{$prefix}/platform",
        ])->save();

        $envPath = $folder.'/.env';
        $env = $this->readEnv($envPath, $folder.'/.env.example');
        // A folder registered as another application serves this one too
        // (platform-client.hosted): only its own values, under its prefix.
        $primary = $this->envValue($env, 'PLATFORM_APPLICATION') ?? '';
        $hosted = $primary !== '' && $primary !== $application->code;
        $key = $hosted ? 'PLATFORM_'.strtoupper($application->code).'_' : 'PLATFORM_';

        $values = $hosted ? [
            'PLATFORM_HOSTED' => $this->withCode((string) $this->envValue($env, 'PLATFORM_HOSTED'), $application->code),
            $key.'PATH' => $prefix,
        ] : [
            'PLATFORM_APPLICATION' => $application->code,
            'PLATFORM_ROOT_DOMAIN' => PlatformHosts::root(),
            'PLATFORM_ISSUER' => PlatformHosts::url(PlatformHosts::auth()),
            'PLATFORM_BACKCHANNEL_URL' => (string) config('platform.oidc.backchannel_url'),
        ];

        $registered = collect(self::ENV_KEYS)->every(fn (string $name) => ($this->envValue($env, $key.$name) ?? '') !== '');

        if (! $registered || $this->option('fresh')) {
            DB::table('application_clients')->where('application_id', $application->id)->where('status', 'active')->get()
                ->each(fn (object $row) => $integration->disableClient($application, $row->id, null));

            $signIn = $integration->registerSignInClient($application, "{$application->name} web", true, [], "/{$prefix}/auth/callback", null);
            $service = $integration->registerServiceClient($application, "{$application->name} backend", null);

            $values += [
                $key.'SIGN_IN_CLIENT_ID' => $signIn['client_id'],
                $key.'SIGN_IN_CLIENT_SECRET' => (string) $signIn['client_secret'],
                $key.'SERVICE_CLIENT_ID' => $service['client_id'],
                $key.'SERVICE_CLIENT_SECRET' => (string) $service['client_secret'],
                $key.'WEBHOOK_SECRET' => (string) $integration->configureWebhook($application, "{$base}/{$prefix}/platform/webhooks", true, null),
            ];
            $this->components->info("Registered clients for {$application->name}".($hosted ? ", hosted by {$primary}." : '.'));
        } else {
            $integration->configureWebhook($application, "{$base}/{$prefix}/platform/webhooks", false, null);
            $this->components->info("{$application->name} is already registered (use --fresh to issue new clients).");
        }

        file_put_contents($envPath, $this->writeEnv($env, $values));
        $this->components->info('Wrote '.$this->argument('path').'/.env');

        foreach ((array) $this->option('entitle') as $slug) {
            $organization = OrganizationSlug::query()->where('slug', (string) $slug)->where('is_primary', true)->first()?->organization;

            if ($organization === null) {
                $this->components->error("No organization with the workspace address [{$slug}].");

                return self::FAILURE;
            }

            $entitled = $tenant->run($organization, fn () => Entitlement::query()->current()
                ->where('application_id', $application->id)->whereNull('feature_key')->exists());

            if (! $entitled) {
                $entitlements->grantManual($organization, $application, null, 'Local product development');
            }
            $this->components->info("{$organization->display_name} is entitled: ".PlatformHosts::url(PlatformHosts::workspace((string) $slug), $prefix));
        }

        return self::SUCCESS;
    }

    private function folder(string $path): ?string
    {
        $folder = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : base_path('../'.trim($path, '/\\'));

        if (! is_file($folder.'/'.$this->catalogPath())) {
            $this->components->error("No product at [{$path}]: expected {$path}/{$this->catalogPath()}.");

            return null;
        }

        return rtrim($folder, '/\\');
    }

    /**
     * PLATFORM_HOSTED (comma-separated codes) with $code added once.
     */
    private function withCode(string $list, string $code): string
    {
        $codes = array_filter(array_map('trim', explode(',', $list)));

        return implode(',', array_values(array_unique([...$codes, $code])));
    }

    private function catalogPath(): string
    {
        return trim((string) ($this->option('catalog') ?: 'platform/catalog.json'), '/\\');
    }

    private function readEnv(string $path, string $example): string
    {
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }

        return is_file($example) ? (string) file_get_contents($example) : '';
    }

    private function envValue(string $env, string $key): ?string
    {
        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m) === 1 ? trim($m[1], " \t\"'") : null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnv(string $env, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $env = preg_match($pattern, $env) === 1
                ? (string) preg_replace_callback($pattern, fn () => $line, $env)
                : rtrim($env, "\n")."\n{$line}\n";
        }

        return $env;
    }
}
