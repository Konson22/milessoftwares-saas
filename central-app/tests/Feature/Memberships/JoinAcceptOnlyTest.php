<?php

use App\Models\User;
use App\Modules\Identity\Http\Controllers\GoogleSignInController;
use App\Modules\Identity\Support\Avatars;
use App\Modules\Identity\Support\InviteeNames;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->prepareAuthorizationFixtures();
});

function inviteToJoin(object $test, string $email): string
{
    [, $token] = app(Invitations::class)->invite($test->owner, $email);
    app(TenantContext::class)->clear();

    return $token;
}

function joinPage(string $token): string
{
    return PlatformHosts::url(PlatformHosts::auth(), 'join/'.$token);
}

function joinedMemberOf(User $user, string $organizationId): bool
{
    return OrganizationMembership::query()->withoutGlobalScopes()
        ->where('user_id', $user->id)->where('organization_id', $organizationId)->exists();
}

it('lets a Gmail address join with Accept alone: no name, no password', function () {
    $token = inviteToJoin($this, 'nyandeng.akol@gmail.com');

    $this->get(joinPage($token))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('status', 'new')->where('gmail', true));

    $this->post(joinPage($token))->assertRedirect((string) $this->organization->workspaceUrl());

    $user = User::query()->where('email', 'nyandeng.akol@gmail.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Nyandeng Akol')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(joinedMemberOf($user, $this->organization->id))->toBeTrue();
});

it('still asks a Gmail address that already has an account to sign in', function () {
    User::factory()->create(['email' => 'nyandeng@gmail.com']);
    $token = inviteToJoin($this, 'nyandeng@gmail.com');

    $this->post(joinPage($token))->assertRedirect(PlatformHosts::url(PlatformHosts::auth(), 'invitations/'.$token));
    $this->assertGuest();
});

it('asks any other address for a name and a password', function () {
    $token = inviteToJoin($this, 'nyandeng@example.test');

    $this->get(joinPage($token))->assertInertia(fn (AssertableInertia $page) => $page->where('gmail', false));
    $this->post(joinPage($token))->assertSessionHasErrors(['name', 'password']);

    expect(User::query()->where('email', 'nyandeng@example.test')->exists())->toBeFalse();
});

it('keeps the profile picture chosen when joining, re-encoded and served from the auth host', function () {
    $token = inviteToJoin($this, 'nyandeng@example.test');

    $this->post(joinPage($token), [
        'name' => 'Nyandeng Akol',
        'password' => 'a-Much-better-one-42',
        'password_confirmation' => 'a-Much-better-one-42',
        'avatar' => UploadedFile::fake()->image('me.png', 640, 480),
    ])->assertRedirect((string) $this->organization->workspaceUrl());

    $user = User::query()->where('email', 'nyandeng@example.test')->sole();
    expect(Avatars::isUploaded($user->avatar_url))->toBeTrue();

    $file = basename((string) parse_url((string) $user->avatar_url, PHP_URL_PATH));
    Storage::disk('local')->assertExists('avatars/'.$file);
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get('avatars/'.$file));
    expect([$width, $height])->toBe([Avatars::SIZE, Avatars::SIZE]);

    $this->get($user->avatar_url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('joins without a picture, which is optional', function () {
    $token = inviteToJoin($this, 'nyandeng@example.test');

    $this->post(joinPage($token), [
        'name' => 'Nyandeng Akol',
        'password' => 'a-Much-better-one-42',
        'password_confirmation' => 'a-Much-better-one-42',
    ])->assertRedirect();

    expect(User::query()->where('email', 'nyandeng@example.test')->sole()->avatar_url)->toBeNull();
});

it('refuses a file that is not a picture and keeps nothing', function () {
    $token = inviteToJoin($this, 'nyandeng@example.test');

    $this->post(joinPage($token), [
        'name' => 'Nyandeng Akol',
        'password' => 'a-Much-better-one-42',
        'password_confirmation' => 'a-Much-better-one-42',
        'avatar' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
    ])->assertSessionHasErrors('avatar');

    expect(User::query()->where('email', 'nyandeng@example.test')->exists())->toBeFalse()
        ->and(Storage::disk('local')->files('avatars'))->toBe([]);
});

it('serves only picture names it creates', function () {
    $this->get(PlatformHosts::url(PlatformHosts::auth(), 'avatars/'.str_repeat('a', 8).'-aaaa-aaaa-aaaa-'.str_repeat('a', 12).'.jpg'))->assertNotFound();
    $this->get(PlatformHosts::url(PlatformHosts::auth(), 'avatars/..%2F.env'))->assertNotFound();
});

it('gives a Gmail joiner their Google name on the first Google sign-in, and keeps an uploaded picture', function () {
    $joined = User::factory()->create(['email' => 'nyandeng.akol@gmail.com', 'name' => 'Nyandeng Akol', 'email_verified_at' => now()]);
    $joined->forceFill(['name' => InviteeNames::fromEmail('nyandeng.akol@gmail.com')])->save();

    $user = app(GoogleSignInController::class)->resolveUser('g-1', 'nyandeng.akol@gmail.com', true, 'Nyandeng A. Akol', 'https://lh3.googleusercontent.com/a/x');
    expect($user?->name)->toBe('Nyandeng A. Akol');

    $uploaded = Avatars::url('0b0c3a3e-1111-4222-8333-944455556666.jpg');
    $other = User::factory()->create(['email' => 'deng@example.test', 'name' => 'Deng Garang', 'avatar_url' => $uploaded, 'email_verified_at' => now()]);
    $user = app(GoogleSignInController::class)->resolveUser('g-2', 'deng@example.test', true, 'Deng G.', 'https://lh3.googleusercontent.com/a/y');

    expect($user?->avatar_url)->toBe($uploaded)
        ->and($user?->name)->toBe('Deng Garang')
        ->and($other->fresh()->avatar_url)->toBe($uploaded);
});

it('names an account after its address', function (string $email, string $name) {
    expect(InviteeNames::fromEmail($email))->toBe($name);
})->with([
    ['nyandeng.akol@gmail.com', 'Nyandeng Akol'],
    ['deng_garang+work@gmail.com', 'Deng Garang'],
    ['john99@gmail.com', 'John'],
    ['1234@gmail.com', '1234'],
]);

it('treats only Google mail domains as Gmail', function () {
    expect(InviteeNames::isGmail('A@Gmail.com'))->toBeTrue()
        ->and(InviteeNames::isGmail('a@googlemail.com'))->toBeTrue()
        ->and(InviteeNames::isGmail('a@gmail.com.evil.test'))->toBeFalse()
        ->and(InviteeNames::isGmail('a@example.test'))->toBeFalse();
});
