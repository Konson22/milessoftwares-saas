<?php

namespace Miles\PlatformClient\Tests\Fixtures;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Miles\PlatformClient\Tests\TestCase;

/**
 * The platform's auth host, faked at the HTTP layer: client-credentials and
 * authorization-code tokens, a JWKS, and the organization and
 * effective-access API. id_tokens are real RS256 tokens.
 */
final class FakePlatform
{
    /** @var array<string, array<string, mixed>> keyed by slug */
    public array $organizations = [];

    /** @var array<string, array<string, mixed>> keyed by "orgId:sub" */
    public array $access = [];

    /** Key ids published in the JWKS; tokens are signed with the "current" key. */
    public array $publishedKids = ['key-1'];

    public string $signingKid = 'key-1';

    /** @var array<string, mixed> overrides for the next id_token */
    public array $idTokenClaims = [];

    public ?string $idTokenSignedWith = null;

    public bool $down = false;

    /** Answer the next API call with 401 invalid_token (a revoked service token). */
    public bool $refuseNextApiCall = false;

    /** @var array<string, string> code → PKCE verifier received at the token endpoint */
    public array $verifiers = [];

    private static ?array $keys = null;

    private static ?array $otherKeys = null;

    public function __construct()
    {
        $this->organizations = [
            'acme' => ['id' => self::uuid('acme'), 'slug' => 'acme', 'display_name' => 'Acme Pharmacy', 'status' => 'active', 'country_code' => 'SS', 'default_currency' => 'SSP', 'timezone' => 'Africa/Juba'],
            'globex' => ['id' => self::uuid('globex'), 'slug' => 'globex', 'display_name' => 'Globex', 'status' => 'active', 'country_code' => 'SS', 'default_currency' => 'USD', 'timezone' => 'Africa/Juba'],
        ];
    }

    public static function uuid(string $slug): string
    {
        return match ($slug) {
            'acme' => '0199a0b0-0000-7000-8000-000000000001',
            'globex' => '0199a0b0-0000-7000-8000-000000000002',
            default => '0199a0b0-0000-7000-8000-000000000099',
        };
    }

    public static function subject(string $who = 'ada'): string
    {
        return match ($who) {
            'ada' => '0199a0b0-1111-7000-8000-000000000001',
            'grace' => '0199a0b0-1111-7000-8000-000000000002',
            default => '0199a0b0-1111-7000-8000-000000000099',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     */
    public function grant(string $slug, string $subject, array $roles = [], bool $owner = false, bool $allowed = true): void
    {
        $orgId = $this->organizations[$slug]['id'];
        $this->access[$orgId.':'.$subject] = [
            'allowed' => $allowed,
            'organization' => ['id' => $orgId, 'slug' => $slug, 'status' => 'active'],
            'application' => ['code' => 'pharmacy', 'catalog_version' => '1.0.0'],
            'subject' => $subject,
            'entitlement' => ['active' => true, 'source' => 'subscription', 'valid_until' => null, 'features' => []],
            'membership' => ['id' => 'm-'.$subject, 'status' => 'active', 'is_owner' => $owner],
            'roles' => $roles,
            'max_age' => 60,
        ];
    }

    public static function role(string $role, array $permissions, array $scope = ['scope_type' => 'organization']): array
    {
        return ['role' => $role, 'catalog_version' => '1.0.0', 'scope' => $scope, 'permissions' => $permissions, 'expires_at' => null];
    }

    public function install(): self
    {
        Http::fake(function (Request $request) {
            if ($this->down) {
                return Http::response('upstream down', 502);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($this->refuseNextApiCall && str_starts_with($path, '/api/')) {
                $this->refuseNextApiCall = false;

                return Http::response(['error' => 'invalid_token'], 401);
            }

            return match (true) {
                $path === '/oauth/token' => $this->token($request),
                $path === '/oauth/jwks' => Http::response($this->jwks()),
                (bool) preg_match('#^/api/v1/organizations/([^/]+)/members/([^/]+)/effective-access$#', $path, $m) => isset($this->access[$m[1].':'.$m[2]])
                    ? Http::response($this->access[$m[1].':'.$m[2]])
                    : Http::response(['error' => 'not_found'], 404),
                (bool) preg_match('#^/api/v1/organizations/([^/]+)$#', $path, $m) => $this->organization($m[1]),
                $path === '/api/v1/event-tokens' => Http::response(['token' => 'evt.'.$request['audience'], 'expires_in' => 300]),
                $path === '/api/v1/health' => Http::response(['recorded' => true], 202),
                default => Http::response(['error' => 'unexpected '.$path], 500),
            };
        });

        return $this;
    }

    private function organization(string $key)
    {
        foreach ($this->organizations as $organization) {
            if ($organization['slug'] === $key || $organization['id'] === $key) {
                return Http::response($organization);
            }
        }

        return Http::response(['message' => 'Not Found'], 404);
    }

    private function token(Request $request)
    {
        if ($request['grant_type'] === 'client_credentials') {
            return Http::response(['token_type' => 'Bearer', 'access_token' => 'svc.'.$request['scope'].'.'.bin2hex(random_bytes(4)), 'expires_in' => 3600]);
        }

        $this->verifiers[(string) $request['code']] = (string) $request['code_verifier'];

        return Http::response(['token_type' => 'Bearer', 'access_token' => 'user-token', 'id_token' => $this->idToken()]);
    }

    public function idToken(): string
    {
        $claims = $this->idTokenClaims + [
            'iss' => TestCase::ISSUER,
            'aud' => 'sign-in-client',
            'sub' => self::subject('ada'),
            'name' => 'Ada Pharmacist',
            'email' => 'ada@acme.test',
            'email_verified' => true,
            'nonce' => '',
            // The application clock, so tests can travel in time.
            'iat' => now()->subSeconds(10)->toDateTimeImmutable(),
            'exp' => now()->addMinutes(5)->toDateTimeImmutable(),
        ];

        $keys = $this->idTokenSignedWith === 'other' ? self::otherKeys() : self::keys();
        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($keys['private']), InMemory::plainText($keys['public']));

        $builder = $config->builder()
            ->withHeader('kid', $this->signingKid)
            ->issuedBy($claims['iss'])
            ->relatedTo($claims['sub'])
            ->issuedAt($claims['iat'])
            ->expiresAt($claims['exp'])
            ->withClaim('nonce', $claims['nonce'])
            ->withClaim('name', $claims['name'])
            ->withClaim('email', $claims['email'])
            ->withClaim('email_verified', $claims['email_verified']);

        foreach ((array) $claims['aud'] as $audience) {
            $builder = $builder->permittedFor($audience);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    /**
     * A mobile app access token as Passport issues it: audience = the
     * client, subject, no issuer, no kid.
     *
     * @param  array<string, mixed>  $claims
     */
    public function accessToken(array $claims = []): string
    {
        $claims += [
            'aud' => 'mobile-client',
            'sub' => self::subject('ada'),
            'iat' => now()->subSeconds(10)->toDateTimeImmutable(),
            'exp' => now()->addMinutes(10)->toDateTimeImmutable(),
        ];

        $keys = $this->idTokenSignedWith === 'other' ? self::otherKeys() : self::keys();
        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($keys['private']), InMemory::plainText($keys['public']));

        return $config->builder()
            ->permittedFor($claims['aud'])
            ->identifiedBy(bin2hex(random_bytes(8)))
            ->relatedTo($claims['sub'])
            ->issuedAt($claims['iat'])
            ->canOnlyBeUsedAfter($claims['iat'])
            ->expiresAt($claims['exp'])
            ->withClaim('scopes', ['openid', 'profile', 'email'])
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    public function jwks(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public(self::keys()['public']));

        return ['keys' => array_map(fn (string $kid) => [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $kid,
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ], $this->publishedKids)];
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function keys(): array
    {
        return self::$keys ??= self::generate();
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function otherKeys(): array
    {
        return self::$otherKeys ??= self::generate();
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function generate(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => __DIR__.'/openssl.cnf'];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private, null, $options);

        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }
}
