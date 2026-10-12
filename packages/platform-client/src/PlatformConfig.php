<?php

namespace Miles\PlatformClient;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Typed access to config/platform-client.php. A missing value fails loudly
 * with the env variable to set, instead of a confusing HTTP error later.
 */
final class PlatformConfig
{
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

    public function application(): string
    {
        return $this->required('application', 'PLATFORM_APPLICATION');
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
        return $this->required('sign_in.client_id', 'PLATFORM_SIGN_IN_CLIENT_ID');
    }

    public function signInClientSecret(): string
    {
        return $this->required('sign_in.client_secret', 'PLATFORM_SIGN_IN_CLIENT_SECRET');
    }

    public function serviceClientId(): string
    {
        return $this->required('service.client_id', 'PLATFORM_SERVICE_CLIENT_ID');
    }

    public function serviceClientSecret(): string
    {
        return $this->required('service.client_secret', 'PLATFORM_SERVICE_CLIENT_SECRET');
    }

    public function webhookSecret(): string
    {
        return $this->required('webhook_secret', 'PLATFORM_WEBHOOK_SECRET');
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
        $path = $this->config->get('platform-client.path');
        $path = trim(is_string($path) && $path !== '' ? $path : $this->application(), '/');

        if (preg_match('/^[a-z][a-z0-9-]*$/', $path) !== 1) {
            throw new RuntimeException("Platform client path [{$path}] must be lowercase letters, digits and dashes.");
        }

        return $path;
    }

    public function home(): string
    {
        $home = $this->config->get('platform-client.home');

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
