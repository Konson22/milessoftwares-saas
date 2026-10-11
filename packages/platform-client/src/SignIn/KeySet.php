<?php

namespace Miles\PlatformClient\SignIn;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Miles\PlatformClient\PlatformConfig;
use Miles\SharedModuleKit\Jwks;
use RuntimeException;

/**
 * The platform's signing keys (GET {issuer}/oauth/jwks), cached for an
 * hour. A token signed with an unknown key id refetches the set once (key
 * rotation), at most once a minute so forged kids cannot hammer the
 * platform.
 */
final class KeySet
{
    private const KEY = 'platform-client:jwks';

    private const REFRESH_LOCK = 'platform-client:jwks-refreshed';

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    /**
     * PEM public key for the key id.
     *
     * @return non-empty-string
     *
     * @throws SignInFailed
     */
    public function publicKey(?string $kid): string
    {
        $jwks = $this->cache->get(self::KEY);

        if (! is_string($jwks)) {
            $jwks = $this->fetch();
        }

        try {
            $pem = Jwks::publicKeyPem($jwks, $kid);
        } catch (RuntimeException) {
            if (! $this->cache->add(self::REFRESH_LOCK, true, 60)) {
                throw new SignInFailed('id_token signed with an unknown key.');
            }

            try {
                $pem = Jwks::publicKeyPem($this->fetch(), $kid);
            } catch (RuntimeException $e) {
                throw new SignInFailed('id_token signed with an unknown key.', previous: $e);
            }
        }

        if ($pem === '') {
            throw new SignInFailed('Empty signing key.');
        }

        return $pem;
    }

    private function fetch(): string
    {
        try {
            $response = $this->http->baseUrl($this->config->serverUrl())
                ->withHeaders($this->config->serverHeaders())
                ->acceptJson()
                ->timeout($this->config->httpTimeout())
                ->get('/oauth/jwks');
        } catch (ConnectionException $e) {
            throw new SignInFailed('JWKS unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful() || ! is_array($response->json('keys'))) {
            throw new SignInFailed('JWKS request failed: HTTP '.$response->status());
        }

        $this->cache->put(self::KEY, $response->body(), 3600);

        return $response->body();
    }
}
