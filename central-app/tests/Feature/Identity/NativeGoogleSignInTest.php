<?php

use App\Models\User;
use App\Modules\Identity\Models\SocialAccount;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

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

it('registers a new person with nothing to confirm: Google has proven the address', function () {
    fakeTokenInfo();

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertOk();

    $user = User::query()->where('email', 'person@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

it('takes an unconfirmed registration away from whoever made it', function () {
    $user = User::factory()->unverified()->create(['email' => 'person@example.com', 'name' => 'Someone Else', 'password' => 'their-password']);
    $accessToken = Str::random(80);
    DB::table('oauth_access_tokens')->insert(['id' => $accessToken, 'user_id' => $user->id, 'client_id' => Str::uuid()->toString(), 'revoked' => false]);
    DB::table('oauth_refresh_tokens')->insert(['id' => Str::random(80), 'access_token_id' => $accessToken, 'revoked' => false]);
    fakeTokenInfo();

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertOk();

    $user->refresh();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Pat Person')
        ->and(Hash::check('their-password', $user->password))->toBeFalse()
        ->and(DB::table('oauth_access_tokens')->where('revoked', false)->exists())->toBeFalse()
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->exists())->toBeFalse();
});

it('keeps the password of an account with a proven address', function () {
    $user = User::factory()->create(['email' => 'person@example.com', 'name' => 'Pat P', 'password' => 'their-password']);
    fakeTokenInfo();

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertOk();

    $user->refresh();
    expect($user->name)->toBe('Pat P')
        ->and(Hash::check('their-password', $user->password))->toBeTrue();
});

it('keeps the temporary password of an account an administrator added', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'person@example.com',
        'password' => 'temporary-password',
        'created_by_organization_id' => Organization::factory()->create()->id,
    ]);
    fakeTokenInfo();

    $this->postJson(route('google.native'), ['id_token' => 'token'])->assertOk();

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('temporary-password', $user->password))->toBeTrue();
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
