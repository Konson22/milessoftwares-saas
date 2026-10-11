<?php

use App\Models\User;
use App\Modules\Administration\Actions\StaffChangeDenied;
use App\Modules\Administration\Actions\StaffDirectory;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\OAuthClient;
use App\Support\Hosts\PlatformHosts;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\ClientRepository;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

it('limits each admin area to the staff permission it needs', function (string $path, string $role, int $status) {
    $staff = $this->staffWith([$role]);

    $this->actingAs($staff->user)->get($this->adminUrl($path))->assertStatus($status);
})->with([
    'super admin manages staff' => ['/staff', 'super_admin', 200],
    'support agent cannot manage staff' => ['/staff', 'support_agent', 403],
    'security auditor cannot manage staff' => ['/staff', 'security_auditor', 403],
    'every staff role sees the overview' => ['/', 'catalog_admin', 200],
]);

it('shares only the signed-in staff member\'s permissions', function () {
    $staff = $this->staffWith(['support_agent']);

    $this->actingAs($staff->user)
        ->get($this->adminUrl())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('staff.permissions', ['organizations.view', 'support.request']));
});

it('adds an existing user as staff and grants a role', function () {
    $admin = $this->staffWith(['super_admin']);
    $user = User::factory()->create();

    $this->actingAs($admin->user)->post($this->adminUrl('/staff'), ['email' => $user->email])->assertSessionHasNoErrors();
    $staff = PlatformStaff::query()->where('user_id', $user->id)->sole();

    $this->post($this->adminUrl("/staff/{$staff->id}/roles"), ['role' => 'support_agent'])->assertSessionHasNoErrors();

    expect($staff->refresh()->hasRole('support_agent'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'staff.role_granted')->latest('sequence')->value('actor_type'))->toBe('staff');
});

it('refuses unknown accounts', function () {
    $admin = $this->staffWith(['super_admin']);

    $this->actingAs($admin->user)
        ->post($this->adminUrl('/staff'), ['email' => 'nobody@example.test'])
        ->assertSessionHasErrors('email');
});

it('stops staff from changing their own roles or status', function () {
    $admin = $this->staffWith(['super_admin']);
    $this->staffWith(['super_admin']);

    $this->actingAs($admin->user);

    $this->post($this->adminUrl("/staff/{$admin->id}/roles"), ['role' => 'billing_admin'])->assertSessionHasErrors('staff');
    $this->delete($this->adminUrl("/staff/{$admin->id}/roles/super_admin"))->assertSessionHasErrors('staff');
    $this->put($this->adminUrl("/staff/{$admin->id}/status"), ['status' => 'suspended'])->assertSessionHasErrors('staff');

    expect($admin->refresh()->hasRole('billing_admin'))->toBeFalse()
        ->and($admin->status)->toBe('active');
});

it('refuses to demote or suspend the last active super admin', function () {
    $last = $this->staffWith(['super_admin']);
    // A second super admin exists but is suspended, so $last is the only active one.
    $actor = $this->staffWith(['super_admin'], status: 'suspended');
    $directory = app(StaffDirectory::class);

    expect(fn () => $directory->revokeRole($last, 'super_admin', $actor))
        ->toThrow(StaffChangeDenied::class, 'The last active super admin cannot be demoted.')
        ->and(fn () => $directory->setStatus($last, 'suspended', $actor))
        ->toThrow(StaffChangeDenied::class, 'The last active super admin cannot be suspended.');

    // With a second active super admin, the change is allowed.
    $actor->forceFill(['status' => 'active'])->save();
    $directory->setStatus($last, 'suspended', $actor);

    expect($last->refresh()->status)->toBe('suspended');
});

it('signs staff out after the idle timeout', function () {
    config(['platform.admin.idle_minutes' => 30]);
    $staff = $this->staffWith(['support_agent']);

    $this->actingAs($staff->user)->get($this->adminUrl())->assertOk();

    $this->travel(31)->minutes();

    $this->get($this->adminUrl())->assertRedirect($this->adminUrl('auth/login'));
    $this->assertGuest();
});

it('keeps active staff signed in', function () {
    $staff = $this->staffWith(['support_agent']);

    $this->actingAs($staff->user)->get($this->adminUrl())->assertOk();
    $this->travel(20)->minutes();
    $this->get($this->adminUrl())->assertOk();
    $this->travel(20)->minutes();
    $this->get($this->adminUrl())->assertOk();
});

it('asks staff to sign in afresh for every admin session', function () {
    /** @var OAuthClient $client */
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Admin', [PlatformHosts::url(PlatformHosts::admin(), 'auth/callback')]);
    config(['platform.oidc.clients.admin.id' => $client->getKey(), 'platform.oidc.clients.admin.secret' => $client->plainSecret]);

    $location = (string) $this->get($this->adminUrl('auth/login'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['prompt'] ?? null)->toBe('login');
});
