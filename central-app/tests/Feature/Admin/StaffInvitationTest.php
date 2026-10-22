<?php

use App\Models\User;
use App\Modules\Administration\Actions\StaffDirectory;
use App\Modules\Administration\Actions\StaffInvitations;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Models\StaffInvitation;
use App\Modules\Administration\Notifications\StaffInvitationSent;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);
    Notification::fake();
    $this->admin = $this->staffWith(['super_admin']);
});

/** Invites through the staff page and returns the emailed token. */
function inviteStaff(string $email, ?string $role = 'support_agent', ?string $name = 'Achol Garang'): string
{
    test()->actingAs(test()->admin->user)
        ->post(test()->adminUrl('/staff'), ['email' => $email, 'name' => $name, 'role' => $role])
        ->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, StaffInvitationSent::class, function (StaffInvitationSent $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token) {
        if ($notifiable->routes['mail'] === mb_strtolower($email)) {
            $token = $notification->token;
        }

        return true;
    });

    return (string) $token;
}

/** The current code for the secret the setup page shows. */
function currentCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt((string) $user->refresh()->two_factor_secret));
}

it('emails an invitation and lists it on the staff page', function () {
    inviteStaff('Achol@Example.test');

    $this->actingAs($this->admin->user)->get($this->adminUrl('/staff'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('invitations.0.email', 'achol@example.test')
        ->where('invitations.0.role', 'support_agent')
        ->where('invitations.0.status', 'pending'));

    expect(AuditLog::query()->where('action', 'staff.invited')->count())->toBe(1);
});

it('takes someone new through a password and two-factor, then makes them staff', function () {
    $token = inviteStaff('achol@example.test');

    $this->get($this->adminUrl("invitation/{$token}"))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('admin/auth/invitation')
        ->where('step', 'account')
        ->where('hasAccount', false)
        ->where('name', 'Achol Garang'));

    $this->post($this->adminUrl("invitation/{$token}"), [
        'name' => 'Achol Garang',
        'password' => 'a long Passw0rd!',
        'password_confirmation' => 'a long Passw0rd!',
    ])->assertSessionHasNoErrors()->assertRedirect($this->adminUrl("invitation/{$token}"));

    $user = User::query()->where('email', 'achol@example.test')->sole();

    // Nothing is staff yet, and admin is still closed.
    expect(PlatformStaff::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull();

    $this->get($this->adminUrl("invitation/{$token}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('step', 'two-factor')
        ->has('recoveryCodes', 8));

    $this->post($this->adminUrl("invitation/{$token}/two-factor"), ['code' => '000000'])->assertSessionHasErrors('code');

    $this->post($this->adminUrl("invitation/{$token}/two-factor"), ['code' => currentCode($user)])
        ->assertSessionHasNoErrors()
        ->assertRedirect($this->adminUrl('/'));

    $staff = PlatformStaff::query()->where('user_id', $user->id)->sole();

    expect($staff->isActive())->toBeTrue()
        ->and($staff->hasRole('support_agent'))->toBeTrue()
        ->and($user->refresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(Auth::id())->toBe($user->id)
        ->and(StaffInvitation::query()->sole()->status())->toBe('accepted');

    // The link is spent.
    Auth::guard('web')->logout();
    $this->get($this->adminUrl("invitation/{$token}"))->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'invalid'));
});

it('asks someone with an account for their own password, never a new one', function () {
    $user = User::factory()->create(['email' => 'deng@example.test', 'password' => 'their own password']);
    $token = inviteStaff('deng@example.test');

    $this->get($this->adminUrl("invitation/{$token}"))->assertInertia(fn (AssertableInertia $page) => $page->where('hasAccount', true));

    $this->post($this->adminUrl("invitation/{$token}"), ['password' => 'a guess'])->assertSessionHasErrors('password');
    $this->post($this->adminUrl("invitation/{$token}"), ['password' => 'their own password'])->assertSessionHasNoErrors();

    $this->post($this->adminUrl("invitation/{$token}/two-factor"), ['code' => currentCode($user)])->assertRedirect($this->adminUrl('/'));

    expect(PlatformStaff::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('lets staff added before two-factor existed finish setting it up', function () {
    $user = User::factory()->create(['email' => 'nyandeng@example.test', 'password' => 'their own password']);
    app(StaffDirectory::class)->add($user, null);

    $token = inviteStaff('nyandeng@example.test', role: null);
    $this->post($this->adminUrl("invitation/{$token}"), ['password' => 'their own password']);
    $this->post($this->adminUrl("invitation/{$token}/two-factor"), ['code' => currentCode($user)])->assertRedirect($this->adminUrl('/'));

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(PlatformStaff::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses to invite staff who can already sign in', function () {
    $this->actingAs($this->admin->user)
        ->post($this->adminUrl('/staff'), ['email' => $this->staffWith([])->user->email])
        ->assertSessionHasErrors('email');
});

it('stops an old link working once a new one is sent or it is cancelled', function () {
    $first = inviteStaff('achol@example.test');
    $second = inviteStaff('achol@example.test');

    $this->get($this->adminUrl("invitation/{$first}"))->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'invalid'));
    $this->get($this->adminUrl("invitation/{$second}"))->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'account'));

    $invitation = StaffInvitation::query()->whereNull('revoked_at')->sole();
    $this->delete($this->adminUrl("/staff/invitations/{$invitation->id}"))->assertSessionHasNoErrors();

    expect($invitation->refresh()->status())->toBe('revoked');

    $this->get($this->adminUrl("invitation/{$second}"))->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'invalid'));
    $this->post($this->adminUrl("invitation/{$second}"), ['name' => 'X', 'password' => 'a long Passw0rd!', 'password_confirmation' => 'a long Passw0rd!'])
        ->assertSessionHasErrors('invitation');

    expect(User::query()->where('email', 'achol@example.test')->exists())->toBeFalse();
});

it('expires after a week', function () {
    $token = inviteStaff('achol@example.test');
    $this->travel(StaffInvitations::VALID_DAYS + 1)->days();

    $this->get($this->adminUrl("invitation/{$token}"))->assertInertia(fn (AssertableInertia $page) => $page->where('step', 'invalid'));
});

it('lapses when the inviter can no longer manage staff', function () {
    $token = inviteStaff('achol@example.test');
    $this->post($this->adminUrl("invitation/{$token}"), ['name' => 'Achol', 'password' => 'a long Passw0rd!', 'password_confirmation' => 'a long Passw0rd!']);
    $user = User::query()->where('email', 'achol@example.test')->sole();

    $this->admin->forceFill(['status' => 'suspended'])->save();

    $this->post($this->adminUrl("invitation/{$token}/two-factor"), ['code' => currentCode($user)])->assertSessionHasErrors('invitation');

    expect(PlatformStaff::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('keeps invitation management to staff managers', function () {
    $this->staffWith(['support_agent']);
    $agent = $this->staffWith(['support_agent']);

    $this->actingAs($agent->user)->post($this->adminUrl('/staff'), ['email' => 'x@example.test'])->assertForbidden();

    expect(StaffInvitation::query()->count())->toBe(0);
});
