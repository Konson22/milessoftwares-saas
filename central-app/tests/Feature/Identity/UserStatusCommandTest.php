<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'Compromised@Example.test']);

    $this->session = new UserSession;
    $this->session->forceFill([
        'user_id' => $this->user->id,
        'host' => 'auth.'.config('platform.root_domain'),
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0',
    ])->save();

    // A product holds an access token and a refresh token for this user.
    $clientId = (string) Str::uuid();
    DB::table('oauth_clients')->insert([
        'id' => $clientId, 'name' => 'Retail', 'secret' => null, 'provider' => 'users',
        'redirect_uris' => '[]', 'grant_types' => '["authorization_code"]', 'revoked' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->tokenId = Str::random(80);
    DB::table('oauth_access_tokens')->insert([
        'id' => $this->tokenId, 'user_id' => $this->user->id, 'client_id' => $clientId,
        'scopes' => '["openid"]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(80), 'access_token_id' => $this->tokenId, 'revoked' => false, 'expires_at' => now()->addDays(30),
    ]);
});

it('suspends an account, signs it out everywhere and revokes product tokens', function () {
    $this->artisan('platform:user-status', ['email' => 'compromised@example.test', 'status' => 'suspended', '--reason' => 'Phished, ticket 4411'])
        ->expectsOutputToContain('1 session(s) and 1 token(s) revoked')
        ->assertSuccessful();

    expect($this->user->fresh()->status)->toBe('suspended')
        ->and($this->session->fresh()->revoked_at)->not->toBeNull()
        ->and(DB::table('oauth_access_tokens')->where('id', $this->tokenId)->value('revoked'))->toBeTrue()
        ->and(DB::table('oauth_refresh_tokens')->where('access_token_id', $this->tokenId)->value('revoked'))->toBeTrue();

    $log = AuditLog::query()->where('action', 'user.status_changed')->sole();
    expect($log->subject_id)->toBe($this->user->id)
        ->and($log->actor_type)->toBe('system')
        ->and($log->context)->toMatchArray(['from' => 'active', 'to' => 'suspended', 'reason' => 'Phished, ticket 4411']);
});

it('reactivates without touching sessions or tokens', function () {
    $this->user->forceFill(['status' => 'suspended'])->save();

    $this->artisan('platform:user-status', ['email' => $this->user->email, 'status' => 'active', '--reason' => 'Password reset, MFA re-enrolled'])
        ->assertSuccessful();

    expect($this->user->fresh()->status)->toBe('active')
        ->and(DB::table('oauth_access_tokens')->where('id', $this->tokenId)->value('revoked'))->toBeFalse();
});

it('refuses without a reason or with an unknown status', function (array $arguments) {
    $this->artisan('platform:user-status', ['email' => $this->user->email, ...$arguments])->assertFailed();

    expect($this->user->fresh()->status)->toBe('active')
        ->and(AuditLog::query()->where('action', 'user.status_changed')->exists())->toBeFalse();
})->with([
    'no reason' => [['status' => 'suspended']],
    'unknown status' => [['status' => 'banned', '--reason' => 'x']],
]);

it('reports an unknown account', function () {
    $this->artisan('platform:user-status', ['email' => 'nobody@example.test', 'status' => 'suspended', '--reason' => 'x'])
        ->expectsOutputToContain('No such account')
        ->assertFailed();
});
