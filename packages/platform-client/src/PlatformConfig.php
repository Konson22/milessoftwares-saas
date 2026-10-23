<?php

namespace Miles\PlatformClient;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Typed access to config/platform-client.php. A missing value fails loudly
 * with the env variable to set, instead of a confusing HTTP error later.
 *
 * A deployment is registered as one application (PLATFORM_APPLICATION) and
 * may host more (platform-client.hosted), each with its own path, clients
 * and webhook secret. The values of the application being served, the
 * current one, are answered: the primary unless runAs() selected another.
 */
final class PlatformConfig
{
    private ?string $current = null;

    public function __construct(private readonly Repository $config) {}

    /**
     * Whether the product is connected at all. Without it (a fresh checkout
     * before .env exists) the app still boots, but registers no product or
     * platform routes.
     */
    public function configured(): bool
    {
        $application = $this->config->get('platform-client.application');

        return is_string($application) && $application !== '';
    }

    /**
     * The application being served.
     */
    public function application(): string
    {
        return $this->current ?? $this->primary();
    }

    /**
     * The application the deployment is registered as (PLATFORM_APPLICATION).
     */
    public function primary(): string
    {
        return $this->required('application', 'PLATFORM_APPLICATION');
    }

    /**
     * Every application served here, the primary first.
     *
     * @return list<string>
     */
    public function applications(): array
    {
        $hosted = $this->config->get('platform-client.hosted');

        return [$this->primary(), ...array_values(array_filter(array_keys(is_array($hosted) ? $hosted : []), 'is_string'))];
    }

    public function serves(string $application): bool
    {
        return in_array($application, $this->applications(), true);
    }

    /**
     * Runs $callback as $application: its path, clients and secret.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(string $application, callable $callback): mixed
    {
        if (! $this->serves($application)) {
            throw new RuntimeException("Platform client does not serve [{$application}]: add it to platform-client.hosted.");
        }

        $previous = $this->current;
        $this->current = $application === $this->primary() ? null : $application;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    /**
     * The name of a platform-client route of the current application:
     * "platform.login" for the primary, "platform.retail.login" for a hosted one.
     */
    public function routeName(string $name): string
    {
        return $this->current === null ? "platform.{$name}" : "platform.{$this->current}.{$name}";
    }

    public function rootDomain(): string
    {
        return strtolower(rtrim($this->required('root_domain', 'PLATFORM_ROOT_DOMAIN'), '.'));
    }

    /**
     * Public auth host URL, without a trailing slash.
     *
     * @return non-empty-string
     */
    public function issuer(): string
    {
        $issuer = rtrim($this->required('issuer', 'PLATFORM_ISSUER'), '/');

        if ($issuer === '') {
            throw new RuntimeException('Platform client is not configured: PLATFORM_ISSUER must be a URL.');
        }

        return $issuer;
    }

    /**
     * Base URL for server-to-server calls: the back-channel when set.
     */
    public function serverUrl(): string
    {
        $backchannel = $this->config->get('platform-client.backchannel_url');

        return is_string($backchannel) && $backchannel !== '' ? rtrim($backchannel, '/') : $this->issuer();
    }

    /**
     * Host header for back-channel calls: the platform routes on the host.
     *
     * @return array<string, string>
     */
    public function serverHeaders(): array
    {
        $backchannel = $this->config->get('platform-client.backchannel_url');

        return is_string($backchannel) && $backchannel !== ''
            ? ['Host' => (string) parse_url($this->issuer(), PHP_URL_HOST).(($port = parse_url($this->issuer(), PHP_URL_PORT)) ? ':'.$port : '')]
            : [];
    }

    /**
     * @return non-empty-string
     */
    public function signInClientId(): string
    {
        return $this->own('sign_in.client_id', 'SIGN_IN_CLIENT_ID');
    }

    public function signInClientSecret(): string
    {
        return $this->own('sign_in.client_secret', 'SIGN_IN_CLIENT_SECRET');
    }

    /**
     * The mobile app's public client, whose access tokens the product's API
     * accepts; null when the application has no mobile app.
     *
     * @return non-empty-string|null
     */
    public function mobileClientId(): ?string
    {
        $value = $this->config->get($this->key('mobile.client_id'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function serviceClientId(): string
    {
        return $this->own('service.client_id', 'SERVICE_CLIENT_ID');
    }

    public function serviceClientSecret(): string
    {
        return $this->own('service.client_secret', 'SERVICE_CLIENT_SECRET');
    }

    public function webhookSecret(): string
    {
        return $this->own('webhook_secret', 'WEBHOOK_SECRET');
    }

    /**
     * @return class-string<Model&Authenticatable>
     */
    public function userModel(): string
    {
        /** @var class-string<Model&Authenticatable> */
        return $this->required('user_model', 'PLATFORM_USER_MODEL');
    }

    /**
     * The product's path on workspace hosts, without slashes ("pharmacy").
     */
    public function path(): string
    {
        $path = $this->config->get($this->key('path'));
        $path = trim(is_string($path) && $path !== '' ? $path : $this->application(), '/');

        if (preg_match('/^[a-z][a-z0-9-]*$/', $path) !== 1) {
            throw new RuntimeException("Platform client path [{$path}] must be lowercase letters, digits and dashes.");
        }

        return $path;
    }

    public function home(): string
    {
        $home = $this->config->get($this->key('home'));

        return is_string($home) && $home !== '' ? $home : '/'.$this->path();
    }

    public function cacheSeconds(): int
    {
        return max(1, (int) $this->config->get('platform-client.cache_seconds', 60));
    }

    public function httpTimeout(): int
    {
        return max(1, (int) $this->config->get('platform-client.http_timeout', 10));
    }

    /**
     * A value of the current application: PLATFORM_<NAME> for the primary,
     * PLATFORM_<APPLICATION>_<NAME> (platform-client.hosted.<application>)
     * for a hosted one.
     *
     * @return non-empty-string
     */
    private function own(string $key, string $name): string
    {
        return $this->current === null
            ? $this->required($key, 'PLATFORM_'.$name)
            : $this->required('hosted.'.$this->current.'.'.$key, 'PLATFORM_'.strtoupper($this->current).'_'.$name);
    }

    /**
     * The full config key of a value of the current application.
     */
    private function key(string $key): string
    {
        return $this->current === null ? 'platform-client.'.$key : 'platform-client.hosted.'.$this->current.'.'.$key;
    }

    /**
     * @return non-empty-string
     */
    private function required(string $key, string $env): string
    {
        $value = $this->config->get('platform-client.'.$key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Platform client is not configured: set {$env} (platform-client.{$key}).");
        }

        return $value;
    }
}
