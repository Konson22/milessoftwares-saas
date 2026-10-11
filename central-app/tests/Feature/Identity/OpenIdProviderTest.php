<?php

use App\Models\User;
use App\Modules\Identity\Models\OAuthClient;
use App\Modules\Identity\OpenId\SigningKey;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

const RP_CALLBACK = 'https://rp.example.test/auth/callback';

function relyingParty(array $redirectUris = [RP_CALLBACK]): OAuthClient
{
    /** @var OAuthClient */
    return app(ClientRepository::class)->createAuthorizationCodeGrantClient('Retail', $redirectUris, confidential: false);
}

/**
 * @return array{query: array<string, string>, verifier: string}
 */
function authorizationRequest(OAuthClient $client, array $overrides = []): array
{
    $verifier = Str::random(64);

    $query = array_merge([
        'client_id' => $client->getKey(),
        'redirect_uri' => RP_CALLBACK,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => Str::random(32),
        'nonce' => Str::random(32),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ], $overrides);

    return ['query' => array_filter($query, fn ($value) => $value !== null), 'verifier' => $verifier];
}

function authorizeUrl(array $query): string
{
    return route('passport.authorizations.authorize').'?'.http_build_query($query);
}

/**
 * @return array<string, string>
 */
function callbackParameters(string $location): array
{
    expect($location)->toStartWith(RP_CALLBACK.'?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $parameters);

    return $parameters;
}

function verifiedIdToken(string $jwt): Plain
{
    $config = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText('unused-private-key-placeholder-for-verification-only'),
        InMemory::file(Passport::keyPath('oauth-public.key')),
    );

    $token = $config->parser()->parse($jwt);
    assert($token instanceof Plain);

    expect($config->validator()->validate($token, new SignedWith($config->signer(), $config->verificationKey())))->toBeTrue();

    return $token;
}

it('publishes discovery metadata that only advertises what the platform accepts', function () {
    $this->get(PlatformHosts::url(PlatformHosts::auth(), '.well-known/openid-configuration'))
        ->assertOk()
        ->assertJson([
            'issuer' => PlatformHosts::url(PlatformHosts::auth()),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'jwks_uri' => route('openid.jwks'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]);
});

it('publishes the signing key with its RFC 7638 thumbprint as kid', function () {
    $this->get(route('openid.jwks'))
        ->assertOk()
        ->assertJsonPath('keys.0.kty', 'RSA')
        ->assertJsonPath('keys.0.alg', 'RS256')
        ->assertJsonPath('keys.0.kid', SigningKey::kid());
});

it('serves OAuth and discovery endpoints on the auth host only', function (string $host) {
    $this->get(PlatformHosts::url($host, '.well-known/openid-configuration'))->assertNotFound();
    $this->get(PlatformHosts::url($host, 'oauth/jwks'))->assertNotFound();
    $this->post(PlatformHosts::url($host, 'oauth/token'))->assertNotFound();
})->with([
    'apex' => fn () => PlatformHosts::root(),
    'app' => fn () => PlatformHosts::app(),
    'admin' => fn () => PlatformHosts::admin(),
]);

it('sends guests to sign in before authorizing', function () {
    $request = authorizationRequest(relyingParty());

    $this->get(authorizeUrl($request['query']))->assertRedirect(route('login'));
});

it('completes the authorization code flow with PKCE and returns a signed id_token', function () {
    $user = User::factory()->create();
    $client = relyingParty();
    $request = authorizationRequest($client);

    $location = $this->actingAs($user)
        ->get(authorizeUrl($request['query']))
        ->assertRedirect()
        ->headers->get('Location');

    $callback = callbackParameters((string) $location);
    expect($callback['state'])->toBe($request['query']['state']);

    $response = $this->post(route('passport.token'), [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => RP_CALLBACK,
        'code' => $callback['code'],
        'code_verifier' => $request['verifier'],
    ])->assertOk();

    expect($response->json('token_type'))->toBe('Bearer')
        ->and($response->json('expires_in'))->toBeLessThanOrEqual(600)
        ->and($response->json('refresh_token'))->toBeString();

    $idToken = verifiedIdToken($response->json('id_token'));
    $claims = $idToken->claims();

    expect($idToken->headers()->get('kid'))->toBe(SigningKey::kid())
        ->and($claims->get('iss'))->toBe(PlatformHosts::url(PlatformHosts::auth()))
        ->and($claims->get('aud'))->toBe([$client->getKey()])
        ->and($claims->get('sub'))->toBe($user->id)
        ->and($claims->get('nonce'))->toBe($request['query']['nonce'])
        ->and($claims->get('email'))->toBe($user->email)
        ->and($claims->get('email_verified'))->toBeTrue()
        ->and($claims->get('name'))->toBe($user->name);
});

it('rejects a token request with the wrong PKCE verifier', function () {
    $client = relyingParty();
    $request = authorizationRequest($client);

    $location = $this->actingAs(User::factory()->create())
        ->get(authorizeUrl($request['query']))
        ->headers->get('Location');

    $this->post(route('passport.token'), [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => RP_CALLBACK,
        'code' => callbackParameters((string) $location)['code'],
        'code_verifier' => Str::random(64),
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('rejects authorization requests that break platform rules', function (array $overrides, string $message) {
    $request = authorizationRequest(relyingParty(), $overrides);

    $this->actingAs(User::factory()->create())
        ->get(authorizeUrl($request['query']))
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request')
        ->assertJsonPath('error_description', fn (string $description) => str_contains($description, $message));
})->with([
    'no PKCE' => [['code_challenge' => null, 'code_challenge_method' => null], 'code_challenge'],
    'plain PKCE' => [['code_challenge_method' => 'plain'], 'S256'],
    'no state' => [['state' => null], 'state'],
    'short state' => [['state' => 'abc'], 'state'],
    'openid without nonce' => [['nonce' => null], 'nonce'],
    'implicit flow' => [['response_type' => 'token'], 'response_type'],
]);

it('rejects redirect URIs that are not registered exactly', function (string $redirectUri) {
    $request = authorizationRequest(relyingParty(), ['redirect_uri' => $redirectUri]);

    $response = $this->actingAs(User::factory()->create())->get(authorizeUrl($request['query']));

    $response->assertStatus(401)->assertJsonPath('error', 'invalid_client');
    expect((string) $response->headers->get('Location'))->not->toContain('evil');
})->with([
    'other host' => 'https://evil.example.test/auth/callback',
    'path suffix' => RP_CALLBACK.'/evil',
    'extra query' => RP_CALLBACK.'?next=https://evil.example.test',
    'scheme downgrade' => 'http://rp.example.test/auth/callback',
]);

it('accepts per-tenant redirect URIs registered in the application registry', function () {
    $client = relyingParty([]);
    $tenantCallback = 'https://manggala.'.config('platform.root_domain').'/retail/auth/callback';

    $applicationId = (string) Str::uuid();
    DB::table('applications')->insert([
        'id' => $applicationId, 'code' => 'retail', 'name' => 'Retail', 'category' => 'operations',
        'kind' => 'standalone', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $applicationClientId = (string) Str::uuid();
    DB::table('application_clients')->insert([
        'id' => $applicationClientId, 'application_id' => $applicationId, 'name' => 'Retail web',
        'client_identifier' => 'retail-web', 'client_type' => 'public', 'oauth_client_ref' => $client->getKey(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('application_client_redirect_uris')->insert([
        'id' => (string) Str::uuid(), 'application_client_id' => $applicationClientId, 'uri' => $tenantCallback,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $request = authorizationRequest($client, ['redirect_uri' => $tenantCallback]);

    $location = $this->actingAs(User::factory()->create())
        ->get(authorizeUrl($request['query']))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith($tenantCallback.'?code=');
});

it('shows the consent screen to third-party clients', function () {
    $owner = User::factory()->create();
    $client = relyingParty();
    $client->forceFill(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id])->save();

    $request = authorizationRequest($client);

    $this->actingAs(User::factory()->create())
        ->get(authorizeUrl($request['query']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('central/auth/authorize')
            ->where('client.name', 'Retail')
            ->where('nonce', $request['query']['nonce'])
            ->has('scopes', 3));
});
