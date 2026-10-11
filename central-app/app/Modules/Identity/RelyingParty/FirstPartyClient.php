<?php

namespace App\Modules\Identity\RelyingParty;

use App\Support\Hosts\PlatformHosts;
use RuntimeException;

/**
 * A platform host acting as a confidential OpenID Connect client of the auth
 * host: app., admin., or a workspace host. All workspaces share one client;
 * each workspace's callback URI is registered per organization.
 */
final readonly class FirstPartyClient
{
    /**
     * Clients created by `platform:oidc-clients`.
     */
    public const KEYS = ['app', 'admin', 'workspace'];

    /**
     * @param  non-empty-string  $id
     * @param  non-empty-string  $secret
     */
    public function __construct(
        public string $key,
        public string $host,
        public string $id,
        public string $secret,
    ) {}

    /**
     * @param  string|null  $host  required for the workspace client: the tenant host
     */
    public static function for(string $key, ?string $host = null): self
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new RuntimeException("Unknown first-party client [{$key}].");
        }

        $id = config("platform.oidc.clients.{$key}.id");
        $secret = config("platform.oidc.clients.{$key}.secret");

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException("The {$key} OIDC client is not configured. Run `php artisan platform:oidc-clients`.");
        }

        $host ??= match ($key) {
            'app' => PlatformHosts::app(),
            'admin' => PlatformHosts::admin(),
            default => throw new RuntimeException('The workspace client needs the tenant host.'),
        };

        return new self($key, $host, $id, $secret);
    }

    public static function configuredId(string $key): ?string
    {
        $id = config("platform.oidc.clients.{$key}.id");

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function redirectUriForHost(string $host): string
    {
        return PlatformHosts::url($host, 'auth/callback');
    }

    public function redirectUri(): string
    {
        return self::redirectUriForHost($this->host);
    }

    public function loginUrl(): string
    {
        return PlatformHosts::url($this->host, 'auth/login');
    }
}
