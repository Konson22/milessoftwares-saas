<?php

namespace App\Modules\Identity\Console;

use App\Modules\Applications\Support\TenantRedirectUris;
use App\Modules\Applications\Support\WorkspaceShell;
use App\Modules\Identity\Models\OAuthClient;
use App\Modules\Identity\RelyingParty\FirstPartyClient;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Creates (or repairs) the confidential OIDC clients used by app., admin. and
 * the workspace hosts. Secrets are shown once; with --write-env they are
 * written to .env instead.
 */
class SyncFirstPartyClients extends Command
{
    protected $signature = 'platform:oidc-clients
        {--rotate : Issue new secrets for existing clients}
        {--write-env : Write client ids and secrets to the .env file}';

    protected $description = 'Create or repair the OpenID Connect clients for the app, admin and workspace hosts';

    private const NAMES = [
        'app' => 'Miles Global Softwares Dashboard',
        'admin' => 'Miles Global Softwares Admin',
        'workspace' => 'Miles Global Softwares Workspace',
    ];

    public function handle(ClientRepository $clients): int
    {
        $env = [];

        foreach (FirstPartyClient::KEYS as $key) {
            // Workspace callbacks are per organization (WorkspaceShell), not static.
            $redirectUris = match ($key) {
                'app' => [FirstPartyClient::redirectUriForHost(PlatformHosts::app())],
                'admin' => [FirstPartyClient::redirectUriForHost(PlatformHosts::admin())],
                default => [],
            };

            $configuredId = FirstPartyClient::configuredId($key);
            $client = $configuredId !== null && Str::isUuid($configuredId)
                ? OAuthClient::query()->find($configuredId)
                : null;

            if ($client === null) {
                /** @var OAuthClient $client */
                $client = $clients->createAuthorizationCodeGrantClient(self::NAMES[$key], $redirectUris, confidential: true);
                $this->components->info("Created the {$key} client.");
                $this->collect($env, $key, $client);
            } else {
                $client->forceFill(['redirect_uris' => $redirectUris, 'revoked' => false])->save();
                $this->components->info("The {$key} client exists; redirect URIs repaired.");

                if ($this->option('rotate')) {
                    $client->forceFill(['secret' => Str::random(40)])->save();
                    $this->components->warn("Rotated the {$key} secret.");
                    $this->collect($env, $key, $client);
                }
            }

            if ($key === 'workspace') {
                $this->syncWorkspaceShell((string) $client->getKey());
            }
        }

        if ($env === []) {
            return self::SUCCESS;
        }

        if ($this->option('write-env')) {
            $this->writeEnv($env);
            $this->components->info('Updated .env. Run `php artisan config:clear` if config is cached.');

            return self::SUCCESS;
        }

        $this->components->warn('Store these values now; secrets are not shown again:');
        foreach ($env as $name => $value) {
            $this->line("{$name}={$value}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function collect(array &$env, string $key, OAuthClient $client): void
    {
        $prefix = 'PLATFORM_'.strtoupper($key).'_CLIENT_';
        $env[$prefix.'ID'] = (string) $client->getKey();
        $env[$prefix.'SECRET'] = (string) $client->plainSecret;
    }

    private function syncWorkspaceShell(string $oauthClientId): void
    {
        WorkspaceShell::register($oauthClientId);

        $count = 0;
        Organization::query()->with('primarySlug')->chunkById(200, function ($organizations) use (&$count): void {
            foreach ($organizations as $organization) {
                TenantRedirectUris::sync($organization);
                $count++;
            }
        });

        $this->components->info("Workspace callbacks registered for {$count} organizations.");
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): void
    {
        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $name => $value) {
            $line = "{$name}={$value}";
            $pattern = '/^'.preg_quote($name, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents) === 1
                ? (string) preg_replace($pattern, $line, $contents)
                : rtrim($contents, "\n")."\n{$line}\n";
        }

        file_put_contents($path, $contents);
    }
}
