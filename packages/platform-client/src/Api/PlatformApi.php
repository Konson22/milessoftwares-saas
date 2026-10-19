<?php

namespace Miles\PlatformClient\Api;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Miles\PlatformClient\PlatformConfig;

/**
 * The product-facing platform API (docs/integration.md, sections 2 and 5),
 * called with client-credentials tokens of the service client.
 *
 * Organization lookups and effective access are cached for at most
 * platform-client.cache_seconds (and never longer than the platform's
 * max_age). Every organization has a cache version; a webhook for that
 * organization bumps it (forget()), which drops all of its entries at once.
 */
final class PlatformApi
{
    private const PREFIX = 'platform-client:';

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    /**
     * @throws NotVisible when the organization does not exist for this application
     * @throws PlatformUnavailable
     */
    public function organization(string $slugOrId): Organization
    {
        $key = self::PREFIX.'org:'.strtolower($slugOrId);
        $cached = $this->cache->get($key);

        if (is_array($cached) && is_array($cached['org'] ?? null) && ($cached['v'] ?? null) === $this->version((string) ($cached['org']['id'] ?? ''))) {
            /** @var array<string, mixed> $data */
            $data = $cached['org'];

            return Organization::fromArray($data);
        }

        $data = $this->get('/api/v1/organizations/'.rawurlencode($slugOrId), 'access.read');
        $organization = Organization::fromArray($data);

        $this->cache->put($key, ['org' => $data, 'v' => $this->version($organization->id)], $this->config->cacheSeconds());

        return $organization;
    }

    /**
     * @throws NotVisible
     * @throws PlatformUnavailable
     */
    public function effectiveAccess(string $organizationId, string $subject): EffectiveAccess
    {
        $key = self::PREFIX.'access:'.$organizationId.':'.$this->version($organizationId).':'.$subject;
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return EffectiveAccess::fromArray($cached);
        }

        $data = $this->get('/api/v1/organizations/'.rawurlencode($organizationId).'/members/'.rawurlencode($subject).'/effective-access', 'access.read');
        $access = EffectiveAccess::fromArray($data);

        $ttl = min($this->config->cacheSeconds(), $access->maxAge > 0 ? $access->maxAge : $this->config->cacheSeconds());
        $this->cache->put($key, $data, $ttl);

        return $access;
    }

    /**
     * The organization's branches, by name. Cached like access answers.
     *
     * @return list<Branch>
     *
     * @throws NotVisible
     * @throws PlatformUnavailable
     */
    public function branches(string $organizationId): array
    {
        $key = self::PREFIX.'branches:'.$organizationId.':'.$this->version($organizationId);
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            $data = $this->get('/api/v1/organizations/'.rawurlencode($organizationId).'/branches', 'access.read');
            $cached = array_values(array_filter(is_array($data['data'] ?? null) ? $data['data'] : [], 'is_array'));
            $this->cache->put($key, $cached, $this->config->cacheSeconds());
        }

        /** @var list<array<string, mixed>> $cached */
        return array_map(fn (array $branch) => Branch::fromArray($branch), $cached);
    }

    /**
     * The organization's departments, by name. Cached like branches.
     *
     * @return list<Department>
     *
     * @throws NotVisible
     * @throws PlatformUnavailable
     */
    public function departments(string $organizationId): array
    {
        return array_map(fn (array $department) => Department::fromArray($department), $this->structure($organizationId, 'departments'));
    }

    /**
     * The organization's positions (job titles), by title. Cached like branches.
     *
     * @return list<Position>
     *
     * @throws NotVisible
     * @throws PlatformUnavailable
     */
    public function positions(string $organizationId): array
    {
        return array_map(fn (array $position) => Position::fromArray($position), $this->structure($organizationId, 'positions'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structure(string $organizationId, string $list): array
    {
        $key = self::PREFIX.$list.':'.$organizationId.':'.$this->version($organizationId);
        $cached = $this->cache->get($key);

        if (! is_array($cached)) {
            $data = $this->get('/api/v1/organizations/'.rawurlencode($organizationId).'/'.$list, 'access.read');
            $cached = array_values(array_filter(is_array($data['data'] ?? null) ? $data['data'] : [], 'is_array'));
            $this->cache->put($key, $cached, $this->config->cacheSeconds());
        }

        /** @var list<array<string, mixed>> $cached */
        return $cached;
    }

    /**
     * Short-lived token for sending one business event to a shared module
     * (docs/shared-modules.md). Never cached: one token per event.
     *
     * @throws NotVisible when the organization is not entitled
     * @throws PlatformUnavailable
     */
    public function eventToken(string $organizationId, string $audience): string
    {
        $data = $this->send('post', '/api/v1/event-tokens', 'events.issue', [
            'organization_id' => $organizationId,
            'audience' => $audience,
        ]);

        $token = $data['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new PlatformUnavailable('Event token response without a token.');
        }

        return $token;
    }

    /**
     * @param  'ok'|'degraded'|'down'  $status
     * @param  array<string, 'ok'|'degraded'|'down'>  $components
     *
     * @throws PlatformUnavailable
     */
    public function reportHealth(string $status, array $components = [], ?string $version = null, ?string $message = null): void
    {
        $this->send('post', '/api/v1/health', 'health.write', array_filter([
            'status' => $status,
            'components' => $components === [] ? null : $components,
            'version' => $version,
            'message' => $message,
        ], fn ($value) => $value !== null));
    }

    /**
     * Drops every cached organization lookup and access answer for the
     * organization (called for each webhook about it).
     */
    public function forget(string $organizationId): void
    {
        $this->cache->forever(self::PREFIX.'v:'.$organizationId, Str::random(12));
    }

    private function version(string $organizationId): string
    {
        $version = $this->cache->get(self::PREFIX.'v:'.$organizationId);

        return is_string($version) ? $version : '0';
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, string $scope): array
    {
        return $this->send('get', $path, $scope);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, string $scope, array $body = [], bool $retried = false): array
    {
        $request = $this->request()->withToken($this->serviceToken($scope));

        try {
            $response = $method === 'get' ? $request->get($path) : $request->asJson()->post($path, $body);
        } catch (ConnectionException $e) {
            throw new PlatformUnavailable("Platform unreachable: {$e->getMessage()}", previous: $e);
        }

        // A revoked or rotated service token: get a fresh one once.
        if ($response->status() === 401 && ! $retried) {
            $this->cache->forget($this->tokenKey($scope));

            return $this->send($method, $path, $scope, $body, retried: true);
        }

        return $this->json($response, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response, string $path): array
    {
        if ($response->status() === 404) {
            throw new NotVisible("Not visible to this application: {$path}");
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : '';

            throw new PlatformUnavailable("Platform API {$path} answered HTTP {$response->status()} {$error}");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function serviceToken(string $scope): string
    {
        $key = $this->tokenKey($scope);
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->request()->asForm()->post('/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config->serviceClientId(),
                'client_secret' => $this->config->serviceClientSecret(),
                'scope' => $scope,
            ]);
        } catch (ConnectionException $e) {
            throw new PlatformUnavailable("Token endpoint unreachable: {$e->getMessage()}", previous: $e);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $error = $response->json('error');

            throw new PlatformUnavailable('Service token refused: HTTP '.$response->status().' '.(is_string($error) ? $error : ''));
        }

        // Renew a little before the platform would refuse it.
        $ttl = (int) $response->json('expires_in', 300) - 30;
        if ($ttl > 0) {
            $this->cache->put($key, $token, $ttl);
        }

        return $token;
    }

    private function tokenKey(string $scope): string
    {
        return self::PREFIX.'token:'.hash('sha256', $this->config->serviceClientId().'|'.$scope);
    }

    private function request(): PendingRequest
    {
        return $this->http->baseUrl($this->config->serverUrl())
            ->withHeaders($this->config->serverHeaders())
            ->acceptJson()
            ->timeout($this->config->httpTimeout());
    }
}
