<?php

use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\Fixtures\User;

beforeEach(function () {
    $this->platform = (new FakePlatform)->install();
    $this->platform->grant('acme', FakePlatform::subject('ada'), [FakePlatform::role('pharmacist', ['pharmacy.dispense'])]);
});

function startSession($test, FakePlatform $platform, array $idToken = [], array $accessToken = [])
{
    $platform->idTokenClaims = $idToken + ['aud' => 'mobile-client'];

    return $test->withToken($platform->accessToken($accessToken))
        ->postJson($test->url('acme', 'api/session'), ['id_token' => $platform->idToken()]);
}

it('starts a session from the app: records the user and answers who and where', function () {
    startSession($this, $this->platform)
        ->assertOk()
        ->assertJsonPath('user.id', FakePlatform::subject('ada'))
        ->assertJsonPath('user.name', 'Ada Pharmacist')
        ->assertJsonPath('organization.slug', 'acme')
        ->assertJsonPath('organization.currency', 'SSP')
        ->assertJsonPath('is_owner', false);

    expect(User::query()->find(FakePlatform::subject('ada')))->email->toBe('ada@acme.test');
});

it('serves the API to a member with a valid access token, without a session', function () {
    startSession($this, $this->platform)->assertOk();

    $this->withToken($this->platform->accessToken())->get($this->url('acme', 'api/whoami'))
        ->assertOk()->assertSee(FakePlatform::subject('ada'));
    $this->withToken($this->platform->accessToken())->get($this->url('acme', 'api/dispense'))
        ->assertSee('can dispense');

    expect(session()->all())->toBe([]);
});

it('asks for a session before anything else', function () {
    $this->withToken($this->platform->accessToken())->getJson($this->url('acme', 'api/whoami'))
        ->assertUnauthorized()->assertJsonPath('code', 'session_required');
});

it('refuses an id token for another user than the access token', function () {
    $this->platform->grant('acme', FakePlatform::subject('grace'), [FakePlatform::role('pharmacist', ['pharmacy.dispense'])]);

    startSession($this, $this->platform, accessToken: ['sub' => FakePlatform::subject('grace')])
        ->assertUnauthorized();

    expect(User::query()->count())->toBe(0);
});

it('refuses an id token of another client', function () {
    startSession($this, $this->platform, idToken: ['aud' => 'sign-in-client'])->assertUnauthorized();
});

it('refuses access tokens that fail verification', function (array $claims, ?string $signedWith) {
    User::query()->forceCreate(['id' => FakePlatform::subject('ada'), 'name' => 'Ada', 'email' => 'ada@acme.test']);
    $this->platform->idTokenSignedWith = $signedWith;

    $this->withToken($this->platform->accessToken($claims))->getJson($this->url('acme', 'api/whoami'))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_token')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
})->with([
    'the web sign-in client' => [['aud' => 'sign-in-client'], null],
    'expired' => [['iat' => new DateTimeImmutable('-2 hours'), 'exp' => new DateTimeImmutable('-1 hour')], null],
    'forged signature' => [[], 'other'],
    'no valid subject' => [['sub' => 'not-a-uuid'], null],
]);

it('refuses a request without a token', function () {
    $this->getJson($this->url('acme', 'api/whoami'))->assertUnauthorized();
});

it('refuses a member without access to the application, or of another workspace', function () {
    User::query()->forceCreate(['id' => FakePlatform::subject('ada'), 'name' => 'Ada', 'email' => 'ada@acme.test']);

    // Not a member of globex at all.
    $this->withToken($this->platform->accessToken())->getJson($this->url('globex', 'api/whoami'))->assertForbidden();

    $this->platform->grant('acme', FakePlatform::subject('ada'), allowed: false);
    $this->withToken($this->platform->accessToken())->getJson($this->url('acme', 'api/whoami'))->assertForbidden();
});

it('has no API without a mobile client', function () {
    config(['platform-client.mobile.client_id' => null]);

    $this->withToken($this->platform->accessToken())->getJson($this->url('acme', 'api/whoami'))->assertNotFound();
});
