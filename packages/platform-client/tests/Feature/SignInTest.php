<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\Fixtures\User;
use Miles\PlatformClient\Tests\TestCase;

beforeEach(function () {
    $this->platform = (new FakePlatform)->install();
    $this->platform->grant('acme', FakePlatform::subject('ada'), [FakePlatform::role('pharmacist', ['pharmacy.dispense'])]);
});

it('starts sign-in at the platform with PKCE, state and nonce', function () {
    $response = $this->get($this->url('acme', 'auth/login'));

    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith(TestCase::ISSUER.'/oauth/authorize?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)
        ->client_id->toBe('sign-in-client')
        ->redirect_uri->toBe('http://acme.'.TestCase::ROOT.'/auth/callback')
        ->response_type->toBe('code')
        ->scope->toBe('openid profile email')
        ->code_challenge_method->toBe('S256')
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(16)
        ->and(strlen($query['nonce']))->toBeGreaterThanOrEqual(16)
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/');
});

it('signs in a member with access and lands on the product', function () {
    $response = signIn($this, $this->platform);

    $response->assertRedirect('http://acme.'.TestCase::ROOT);
    expect(Auth::id())->toBe(FakePlatform::subject('ada'));

    $user = User::query()->find(FakePlatform::subject('ada'));
    expect($user)->name->toBe('Ada Pharmacist')->email->toBe('ada@acme.test');

    $this->get($this->url('acme'))->assertOk()->assertSee('pharmacy home');
});

it('sends the PKCE verifier matching the challenge to the back-channel', function () {
    $login = $this->get($this->url('acme', 'auth/login'));
    parse_str((string) parse_url((string) $login->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->platform->idTokenClaims['nonce'] = $query['nonce'];

    $this->get($this->url('acme', 'auth/callback?'.http_build_query(['code' => 'the-code', 'state' => $query['state']])));

    $verifier = $this->platform->verifiers['the-code'];
    expect(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))->toBe($query['code_challenge']);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), TestCase::BACKCHANNEL.'/oauth/token')
        && $request->hasHeader('Host', 'auth.'.TestCase::ROOT)
        && $request['grant_type'] === 'authorization_code');
});

it('updates the local user from the platform on every sign-in', function () {
    User::query()->forceCreate(['id' => FakePlatform::subject('ada'), 'name' => 'Old Name', 'email' => 'old@acme.test']);

    signIn($this, $this->platform);

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->find(FakePlatform::subject('ada'))->name)->toBe('Ada Pharmacist');
});

it('refuses a callback whose state does not match', function () {
    signIn($this, $this->platform, tamper: fn ($callback) => ['state' => 'forged-state-value'] + $callback)
        ->assertForbidden();

    expect(Auth::check())->toBeFalse();
});

it('refuses an id_token with another nonce (replay)', function () {
    $this->platform->idTokenClaims['nonce'] = 'nonce-from-another-sign-in';

    signIn($this, $this->platform)->assertForbidden();
    expect(Auth::check())->toBeFalse();
});

it('refuses id_tokens that fail verification', function (array $claims, ?string $signedWith) {
    $this->platform->idTokenClaims = $claims;
    $this->platform->idTokenSignedWith = $signedWith;

    signIn($this, $this->platform)->assertForbidden();
    expect(Auth::check())->toBeFalse();
})->with([
    'another audience' => [['aud' => 'some-other-client'], null],
    'another issuer' => [['iss' => 'https://evil.example'], null],
    'expired' => [['iat' => new DateTimeImmutable('-2 hours'), 'exp' => new DateTimeImmutable('-1 hour')], null],
    'forged signature' => [[], 'other'],
    'no valid subject' => [['sub' => 'not-a-uuid'], null],
]);

it('refuses a callback without a pending sign-in', function () {
    $this->get($this->url('acme', 'auth/callback?code=abc&state=xyz'))->assertForbidden();
});

it('refuses a pending sign-in older than ten minutes', function () {
    $login = $this->get($this->url('acme', 'auth/login'));
    parse_str((string) parse_url((string) $login->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->platform->idTokenClaims['nonce'] = $query['nonce'];

    $this->travel(11)->minutes();

    $this->get($this->url('acme', 'auth/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']])))->assertForbidden();
});

it('does not sign in a platform user without access to the product', function () {
    $this->platform->grant('acme', FakePlatform::subject('ada'), allowed: false);

    signIn($this, $this->platform)->assertForbidden();

    expect(Auth::check())->toBeFalse()
        ->and(User::query()->count())->toBe(0);
});

it('follows a key rotation by refetching the JWKS once', function () {
    signIn($this, $this->platform)->assertRedirect();
    Auth::logout();

    // The platform rotates: new tokens use key-2, which the cached JWKS lacks.
    $this->platform->publishedKids = ['key-1', 'key-2'];
    $this->platform->signingKid = 'key-2';
    $this->platform->idTokenClaims = [];

    signIn($this, $this->platform)->assertRedirect();
    expect(Auth::check())->toBeTrue();
});

it('refuses an unknown key id without refetching the JWKS more than once a minute', function () {
    $this->platform->signingKid = 'forged-kid';

    signIn($this, $this->platform)->assertForbidden();
    $this->platform->idTokenClaims = [];
    signIn($this, $this->platform)->assertForbidden();

    $jwksCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/oauth/jwks'))->count();
    expect($jwksCalls)->toBe(2); // first load + one refresh, not one per attempt
});

it('signs out of the product only and returns to the workspace', function () {
    signIn($this, $this->platform);

    $this->post($this->url('acme', 'logout'))->assertRedirect('http://acme.'.TestCase::ROOT.'/');

    expect(Auth::check())->toBeFalse();
});
