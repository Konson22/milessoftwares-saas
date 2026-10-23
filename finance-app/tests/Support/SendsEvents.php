<?php

namespace Tests\Support;

use App\Platform\SetUpWorkspace;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Miles\PlatformClient\Api\Organization;

/**
 * Products' business events, as the platform would vouch for them: an RS256
 * event token from a test key the fake JWKS publishes.
 */
trait SendsEvents
{
    /** @var array{private: string, public: string}|null */
    private static ?array $eventKeys = null;

    /**
     * Provisions Finance for the organization (workspace and chart).
     */
    protected function setUpFinance(string $slug = 'acme'): void
    {
        $organization = Organization::fromArray($this->platform()->organization($slug));
        $this->asTenant($slug, fn () => app(SetUpWorkspace::class)->provision($organization));
    }

    /**
     * The platform fake already answers the JWKS (with the mobile app's
     * key), so the event key is published there rather than in a second
     * HTTP fake, which would never be reached.
     */
    protected function publishEventKeys(): void
    {
        $this->platform()->publishKey('test-key', self::eventKeys()['public']);
    }

    /**
     * @param  array{source?: string, audience?: string, org?: string, expires?: string}  $overrides
     */
    protected function eventToken(string $slug = 'acme', array $overrides = []): string
    {
        $keys = self::eventKeys();
        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($keys['private']), InMemory::plainText($keys['public']));
        $now = now()->toDateTimeImmutable();

        return $config->builder()
            ->withHeader('kid', 'test-key')
            ->issuedBy((string) config('platform-client.issuer'))
            ->permittedFor($overrides['audience'] ?? 'finance')
            ->relatedTo($overrides['source'] ?? 'pharmacy')
            ->identifiedBy((string) Str::uuid())
            ->issuedAt($now->modify('-10 seconds'))
            ->canOnlyBeUsedAfter($now->modify('-10 seconds'))
            ->expiresAt($now->modify($overrides['expires'] ?? '+5 minutes'))
            ->withClaim('org', $overrides['org'] ?? $this->platform()->organization($slug)['id'])
            ->withClaim('scope', 'events.write')
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    /**
     * POSTs an event to Finance the way a product's outbox does.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function sendEvent(string $type, string $transactionId, array $payload, string $slug = 'acme', ?string $occurredAt = null, ?string $token = null, string $source = 'pharmacy'): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->eventToken($slug, ['source' => $source])))
            ->postJson('http://product.internal/finance/events', [
                'event_id' => (string) Str::uuid(),
                'event_type' => $type,
                'schema_version' => 1,
                'organization_id' => $this->platform()->organization($slug)['id'],
                'source_app' => $source,
                'source_transaction_id' => $transactionId,
                'occurred_at' => $occurredAt ?? now()->toIso8601String(),
                'payload' => $payload,
            ]);
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function eventKeys(): array
    {
        if (self::$eventKeys === null) {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => __DIR__.'/openssl.cnf'];
            $key = openssl_pkey_new($options);
            openssl_pkey_export($key, $private, null, $options);
            self::$eventKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$eventKeys;
    }
}
