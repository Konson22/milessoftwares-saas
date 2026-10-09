<?php

use App\Models\User;
use App\Modules\Identity\Models\AuthenticationEvent;
use App\Modules\Identity\Models\OAuthClient;
use App\Modules\Identity\OpenId\SigningKey;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

beforeEach(function () {
    /** @var OAuthClient $client */
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Miles Softwares Dashboard',
        [PlatformHosts::url(PlatformHosts::app(), 'auth/callback')],
        confidential: true,
    );

    config([
        'platform.oidc.clients.app.id' => $client->getKey(),
        'platform.oidc.clients.app.secret' => $client->plainSecret,
    ]);

    $this->client = $client;
});

/**
 * Starts a sign-in on the app host and returns the authorize query.
 *
 * @return array<string, string>
 */
function startSignIn(): array
{
    $location = (string) test()->get(PlatformHosts::url(PlatformHosts::app(), 'auth/login'))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith(PlatformHosts::url(PlatformHosts::auth(), 'oauth/authorize').'?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function idToken(array $overrides = [], ?string $privateKey = null): string
{
    $config = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText($privateKey ?? (string) file_get_contents(Passport::keyPath('oauth-private.key'))),
        InMemory::plainText((string) file_get_contents(Passport::keyPath('oauth-public.key'))),
    );

    $claims = array_merge([
        'iss' => PlatformHosts::url(PlatformHosts::auth()),
        'aud' => config('platform.oidc.clients.app.id'),
        'sub' => null,
        'nonce' => null,
        'exp' => now()->addHour(),
    ], $overrides);

    $builder = $config->builder()
        ->withHeader('kid', (string) SigningKey::kid())
        ->issuedBy($claims['iss'])
        ->permittedFor($claims['aud'])
        ->relatedTo((string) $claims['sub'])
        ->issuedAt(now()->subMinute()->toDateTimeImmutable())
        ->expiresAt($claims['exp']->toDateTimeImmutable());

    if ($claims['nonce'] !== null) {
        $builder = $builder->withClaim('nonce', $claims['nonce']);
    }

    return $builder->getToken($config->signer(), $config->signingKey())->toString();
}

function fakeTokenEndpoint(string $idToken): void
{
    Http::fake([
        PlatformHosts::url(PlatformHosts::auth(), 'oauth/token') => Http::response(
            ['token_type' => 'Bearer', 'access_token' => 'x', 'id_token' => $idToken],
        ),
    ]);
}

function callback(array $query): TestResponse
{
    return test()->get(PlatformHosts::url(PlatformHosts::app(), 'auth/callback').'?'.http_build_query($query));
}

it('sends guests on the app host into the OIDC sign-in', function () {
    $this->get(route('dashboard'))->assertRedirect(PlatformHosts::url(PlatformHosts::app(), 'auth/login'));
});

it('builds an authorization request the provider accepts', function () {
    $query = startSignIn();

    expect($query)
        ->client_id->toBe($this->client->getKey())
        ->redirect_uri->toBe(PlatformHosts::url(PlatformHosts::app(), 'auth/callback'))
        ->code_challenge_method->toBe('S256')
        ->scope->toBe('openid profile email');

    $location = $this->actingAs(User::factory()->create())
        ->get(route('passport.authorizations.authorize').'?'.http_build_query($query))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith(PlatformHosts::url(PlatformHosts::app(), 'auth/callback').'?code=');
});

it('signs the user in on the app host after a valid callback', function () {
    $user = User::factory()->create();
    $query = startSignIn();
    fakeTokenEndpoint(idToken(['sub' => $user->id, 'nonce' => $query['nonce']]));

    callback(['code' => 'authorization-code', 'state' => $query['state']])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(AuthenticationEvent::query()->where('event', 'login')->value('host'))->toBe(PlatformHosts::app());

    Http::assertSent(function (HttpRequest $request) use ($query) {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) $request['code_verifier'], true)), '+/', '-_'), '=');

        return $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'authorization-code'
            && $request['redirect_uri'] === $query['redirect_uri']
            && $challenge === $query['code_challenge']
            && $request->hasHeader('Authorization', 'Basic '.base64_encode($this->client->getKey().':'.config('platform.oidc.clients.app.secret')));
    });
});

it('rejects callbacks that fail validation', function (Closure $tamper) {
    $user = User::factory()->create();
    $query = startSignIn();

    [$callbackQuery, $token] = $tamper($user, $query);
    fakeTokenEndpoint($token);

    callback($callbackQuery)
        ->assertStatus(400)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/sign-in-failed'));

    $this->assertGuest();
    expect(AuthenticationEvent::query()->where('event', 'sso_failed')->exists())->toBeTrue();
})->with([
    'state mismatch' => [fn ($user, $query) => [
        ['code' => 'c', 'state' => str_repeat('x', 40)],
        idToken(['sub' => $user->id, 'nonce' => $query['nonce']]),
    ]],
    'nonce mismatch' => [fn ($user, $query) => [
        ['code' => 'c', 'state' => $query['state']],
        idToken(['sub' => $user->id, 'nonce' => str_repeat('n', 40)]),
    ]],
    'missing nonce' => [fn ($user, $query) => [
        ['code' => 'c', 'state' => $query['state']],
        idToken(['sub' => $user->id]),
    ]],
    'wrong audience' => [fn ($user, $query) => [
        ['code' => 'c', 'state' => $query['state']],
        idToken(['sub' => $user->id, 'nonce' => $query['nonce'], 'aud' => 'another-client']),
    ]],
    'wrong issuer' => [fn ($user, $query) => [
        ['code' => 'c', 'state' => $query['state']],
        idToken(['sub' => $user->id, 'nonce' => $query['nonce'], 'iss' => 'https://evil.example.test']),
    ]],
    'expired' => [fn ($user, $query) => [
        ['code' => 'c', 'state' => $query['state']],
        idToken(['sub' => $user->id, 'nonce' => $query['nonce'], 'exp' => now()->subMinutes(5)]),
    ]],
    'foreign signing key' => [fn ($user, $query) => [
        ['code' => 'c', 'state' => $query['state']],
        idToken(['sub' => $user->id, 'nonce' => $query['nonce']], (string) file_get_contents(base_path('tests/Fixtures/foreign-signing-key.pem'))),
    ]],
    'provider error instead of code' => [fn ($user, $query) => [
        ['error' => 'access_denied', 'state' => $query['state']],
        idToken(['sub' => $user->id, 'nonce' => $query['nonce']]),
    ]],
]);

it('rejects a callback without a pending sign-in', function () {
    $user = User::factory()->create();
    fakeTokenEndpoint(idToken(['sub' => $user->id, 'nonce' => str_repeat('n', 40)]));

    callback(['code' => 'c', 'state' => str_repeat('s', 40)])->assertStatus(400);

    $this->assertGuest();
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'oauth/token'));
});

it('does not sign in suspended users', function () {
    $user = User::factory()->create();
    $user->forceFill(['status' => 'suspended'])->save();
    $query = startSignIn();
    fakeTokenEndpoint(idToken(['sub' => $user->id, 'nonce' => $query['nonce']]));

    callback(['code' => 'c', 'state' => $query['state']])->assertStatus(400);

    $this->assertGuest();
});

it('rejects a replayed callback', function () {
    $user = User::factory()->create();
    $query = startSignIn();
    fakeTokenEndpoint(idToken(['sub' => $user->id, 'nonce' => $query['nonce']]));

    callback(['code' => 'c', 'state' => $query['state']])->assertRedirect('/');
    $this->post(PlatformHosts::url(PlatformHosts::app(), 'logout'));

    callback(['code' => 'c', 'state' => $query['state']])->assertStatus(400);
    $this->assertGuest();
});

it('signs out locally and offers a central sign-out on the auth host', function () {
    $this->actingAs(User::factory()->create())
        ->post(PlatformHosts::url(PlatformHosts::app(), 'logout'))
        ->assertRedirect(PlatformHosts::url(PlatformHosts::auth(), 'signout'));

    $this->assertGuest();
});

it('shares host-aware account links with the frontend', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('platform.isAuthHost', false)
            ->where('platform.logoutUrl', PlatformHosts::url(PlatformHosts::app(), 'logout'))
            ->where('platform.accountUrl', PlatformHosts::url(PlatformHosts::auth(), 'settings/profile')));
});

it('sends Inertia sign-outs on the auth host to the public site as a location visit', function () {
    $this->actingAs(User::factory()->create())
        ->withHeader('X-Inertia', 'true')
        ->post(route('logout'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', PlatformHosts::url(PlatformHosts::root()));
});
