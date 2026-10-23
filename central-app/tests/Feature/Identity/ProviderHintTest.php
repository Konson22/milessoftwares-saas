<?php

use App\Models\User;
use App\Modules\Identity\Models\OAuthClient;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
| idp_hint=google on /oauth/authorize (SignInWithHintedProvider): apps with
| their own "Continue with Google" button send the browser straight to Google.
*/

const HINT_CALLBACK = 'http://127.0.0.1:48217/oauth';

beforeEach(function () {
    Notification::fake();
    config([
        'services.google.client_id' => 'test-client',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
    ]);

    /** @var OAuthClient $client */
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Pharmacy desktop', [HINT_CALLBACK], confidential: false);
    $this->client = $client;
});

function hintedAuthorizeUrl(OAuthClient $client, array $overrides = []): string
{
    return route('passport.authorizations.authorize').'?'.http_build_query(array_merge([
        'client_id' => $client->getKey(),
        'redirect_uri' => HINT_CALLBACK,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => Str::random(32),
        'nonce' => Str::random(32),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'prompt' => 'login',
        'idp_hint' => 'google',
    ], $overrides));
}

it('sends the browser straight to Google, then on to the app with a code', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $url = hintedAuthorizeUrl($this->client);

    $this->get($url)->assertRedirect(route('google.redirect'));

    $google = (new SocialiteUser)->setRaw(['email_verified' => true])->map(['id' => 'g-1', 'name' => 'Pat', 'email' => 'pat@example.com']);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $back = (string) $this->get(route('google.callback'))->assertRedirect()->headers->get('Location');
    expect($back)->toStartWith(route('passport.authorizations.authorize').'?');
    $this->assertAuthenticatedAs($user);

    // Signed in just now: prompt=login does not ask again.
    expect($this->get($back)->assertRedirect()->headers->get('Location'))->toStartWith(HINT_CALLBACK.'?code=');
});

it('signs out whoever was signed in when the app asks for a fresh sign-in', function () {
    $this->actingAs(User::factory()->create())
        ->get(hintedAuthorizeUrl($this->client))
        ->assertRedirect(route('google.redirect'));

    $this->assertGuest();
});

it('keeps the sign-in page without the hint, without Google, or for a request the server refuses', function () {
    $this->get(hintedAuthorizeUrl($this->client, ['idp_hint' => null]))->assertRedirect(route('login'));

    // An unregistered redirect URI: refused by the OAuth server as before, not sent to Google.
    $this->get(hintedAuthorizeUrl($this->client, ['redirect_uri' => 'http://127.0.0.1:9999/elsewhere']))
        ->assertStatus(401);

    config(['services.google.client_id' => null]);
    $this->get(hintedAuthorizeUrl($this->client))->assertRedirect(route('login'));
});
