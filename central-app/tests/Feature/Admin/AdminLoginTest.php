<?php

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Identity\Models\AuthenticationEvent;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);
});

/**
 * Active staff with a real authenticator secret.
 *
 * @return array{User, string}
 */
function adminWithAuthenticator(string $password = 'correct horse battery'): array
{
    $staff = test()->staffWith(['support_agent']);
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $staff->user->forceFill([
        'password' => $password,
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one', 'recovery-two'])),
    ])->save();

    return [$staff->user->refresh(), $secret];
}

function adminPassword(User $user, string $password = 'correct horse battery')
{
    return test()->post(test()->adminUrl('login'), ['email' => $user->email, 'password' => $password]);
}

it('shows guests the admin sign-in page on the admin host', function () {
    $this->get($this->adminUrl())->assertRedirect($this->adminUrl('login'));

    $this->get($this->adminUrl('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/auth/login'));
});

it('signs staff in with the password, then the authenticator code', function () {
    [$user, $secret] = adminWithAuthenticator();

    adminPassword($user)->assertRedirect($this->adminUrl('two-factor'));
    $this->assertGuest();
    $this->get($this->adminUrl('two-factor'))->assertInertia(fn (AssertableInertia $page) => $page->component('admin/auth/two-factor'));

    $this->post($this->adminUrl('two-factor'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect($this->adminUrl('/'));

    $this->assertAuthenticatedAs($user);
    $this->get($this->adminUrl())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/overview'));
    expect(AuthenticationEvent::query()->where('user_id', $user->id)->pluck('event')->all())->toContain('mfa_passed', 'login');
});

it('takes each recovery code once', function () {
    [$user] = adminWithAuthenticator();

    adminPassword($user);
    $this->post($this->adminUrl('two-factor'), ['recovery_code' => 'recovery-one'])->assertRedirect($this->adminUrl('/'));
    $this->post($this->adminUrl('logout'));

    adminPassword($user);
    $this->post($this->adminUrl('two-factor'), ['recovery_code' => 'recovery-one'])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('refuses wrong codes', function () {
    [$user] = adminWithAuthenticator();

    adminPassword($user);
    $this->post($this->adminUrl('two-factor'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post($this->adminUrl('two-factor'), [])->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(AuthenticationEvent::query()->where('user_id', $user->id)->where('event', 'mfa_failed')->count())->toBe(2);
});

it('gives the same answer to wrong passwords, customers and suspended staff', function () {
    [$user] = adminWithAuthenticator();
    $customer = User::factory()->withTwoFactor()->create(['password' => 'correct horse battery']);
    [$suspended] = adminWithAuthenticator();
    PlatformStaff::query()->where('user_id', $suspended->id)->update(['status' => 'suspended']);

    $message = 'These details do not match an administrator account.';
    adminPassword($user, 'wrong')->assertSessionHasErrors(['email' => $message]);
    adminPassword($customer)->assertSessionHasErrors(['email' => $message]);
    adminPassword($suspended)->assertSessionHasErrors(['email' => $message]);
    $this->post($this->adminUrl('login'), ['email' => 'nobody@example.com', 'password' => 'x'])->assertSessionHasErrors(['email' => $message]);

    $this->get($this->adminUrl('two-factor'))->assertRedirect($this->adminUrl('login'));
    $this->assertGuest();
    expect(AuthenticationEvent::query()->where('event', 'login_failed')->count())->toBe(4);
});

it('turns away staff without two-factor authentication', function () {
    $staff = $this->staffWith(['support_agent']);
    $staff->user->forceFill(['password' => 'correct horse battery', 'two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    adminPassword($staff->user)->assertSessionHasErrors('email');
    $this->get($this->adminUrl('two-factor'))->assertRedirect($this->adminUrl('login'));
});

it('lets the code wait five minutes, and only for people still on staff', function () {
    [$user, $secret] = adminWithAuthenticator();

    adminPassword($user);
    $this->travel(6)->minutes();
    $this->post($this->adminUrl('two-factor'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect($this->adminUrl('login'));

    adminPassword($user);
    PlatformStaff::query()->where('user_id', $user->id)->update(['status' => 'suspended']);
    $this->post($this->adminUrl('two-factor'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect($this->adminUrl('login'));

    $this->assertGuest();
});

it('signs out back to the admin sign-in page', function () {
    $this->actingAs($this->staffWith(['support_agent'])->user);

    $this->post($this->adminUrl('logout'))->assertRedirect($this->adminUrl('login'));
    $this->assertGuest();
});
