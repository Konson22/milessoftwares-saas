<?php

use App\Models\User;
use App\Modules\Identity\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    config([
        'services.google.client_id' => 'web-client',
        'services.google.native_client_ids' => 'ios-client',
    ]);
});

function fakeTokenInfo(array $claims = [], int $status = 200): void
{
    Http::fake([
        'oauth2.googleapis.com/tokeninfo*' => Http::response([
            'iss' => 'https://accounts.google.com',
            'aud' => 'web-client',
            'sub' => 'google-123',
            'email' => 'person@example.com',
            'email_verified' => 'true',
            'name' => 'Pat Person',
            'exp' => (string) (time() + 600),
            ...$claims,
        ], $status),
    ]);
}

it('signs in with a Google ID token and links the account', function () {
    $user = User::factory()->create(['email' => 'person@example.com']);
    fakeTokenInfo();

    $this->postJson(route('google.native'), ['id_token' => 'token'])
        ->assertOk()
        ->assertExactJson(['two_factor' => false]);

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::query()->sole()->provider_user_id)->toBe('google-123');
});

it('accepts tokens issued for a native client ID', function () {
    $user = User::factory()->create(['email' => 'person@example.com']);
    fakeTokenInfo(['aud' => 'ios-client']);

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('refuses tokens for another client', function () {
    User::factory()->create(['email' => 'person@example.com']);
    fakeTokenInfo(['aud' => 'someone-else']);

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('refuses tokens Google rejects', function () {
    fakeTokenInfo(status: 400);

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertUnprocessable();

    $this->assertGuest();
});

it('refuses an unverified address and suspended accounts', function () {
    $user = User::factory()->create(['email' => 'person@example.com']);
    fakeTokenInfo(['email_verified' => 'false']);
    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertUnprocessable();

    $user->forceFill(['status' => 'suspended'])->save();
    fakeTokenInfo();
    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertUnprocessable();

    $this->assertGuest();
});

it('still asks for the two-factor code', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'person@example.com']);
    fakeTokenInfo();

    $this->postJson(route('google.native'), ['id_token' => 'token'])
        ->assertOk()
        ->assertExactJson(['two_factor' => true])
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

it('is not available until configured', function () {
    config(['services.google.client_id' => null, 'services.google.native_client_ids' => null]);

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertNotFound();
});
