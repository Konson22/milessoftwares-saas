<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Identity\OpenId\UserIdentity;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Actions\TemporaryAccounts;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    Notification::fake();
    $this->prepareAuthorizationFixtures();
    $this->slug = $this->organization->primarySlug->slug;
    app(TenantContext::class)->clear();
});

function membersAt(string $path): string
{
    return 'http://'.test()->slug.'.'.config('platform.root_domain').$path;
}

/**
 * Adds someone as the owner and returns the credentials shown once.
 *
 * @return array{name: string, email: string, password: string, expiresAt: string, signInUrl: string, roleNote: string|null}
 */
function addMember(string $email = 'deng@example.test', array $extra = []): array
{
    test()->actingAs(test()->owner->user)
        ->post(membersAt('/manage/members'), ['name' => 'Deng Garang', 'email' => $email, ...$extra])
        ->assertSessionHasNoErrors();

    $credentials = session(SessionKey::FLASH_DATA)['credentials'] ?? null;
    expect($credentials)->not->toBeNull();

    return $credentials;
}

function addedUser(string $email = 'deng@example.test'): User
{
    return User::query()->where('email', $email)->firstOrFail();
}

it('adds a member with a temporary password shown once to the administrator', function () {
    $credentials = addMember('Deng@Example.test');

    $user = addedUser();
    expect($credentials['email'])->toBe('deng@example.test')
        ->and($credentials['password'])->toMatch('/^[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}$/')
        ->and(Hash::check($credentials['password'], $user->password))->toBeTrue()
        ->and($user->mustChangePassword())->toBeTrue()
        ->and($user->temporary_password_expires_at->isFuture())->toBeTrue()
        ->and($user->created_by_organization_id)->toBe($this->organization->id);

    $membership = OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', $user->id)->sole();
    expect($membership->organization_id)->toBe($this->organization->id)
        ->and($membership->status)->toBe(MembershipStatus::Active);

    // The audit trail records the addition, never the password.
    $audit = AuditLog::query()->where('action', 'member.added_with_temporary_password')->sole();
    expect(json_encode($audit->getAttributes()))->not->toContain($credentials['password']);
});

it('asks before adding someone with a role that conflicts with itself, and creates nothing until confirmed', function () {
    $role = ['role_id' => $this->role('retail', 'shift_supervisor')->id, 'scope_type' => 'branch', 'branch_id' => $this->juba->id];

    $this->actingAs($this->owner->user)
        ->post(membersAt('/manage/members'), ['name' => 'Deng Garang', 'email' => 'deng@example.test', ...$role])
        ->assertSessionHasErrors('acknowledge_sod');
    expect(User::query()->where('email', 'deng@example.test')->exists())->toBeFalse();

    $credentials = addMember(extra: [...$role, 'acknowledge_sod' => '1']);

    $membership = OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', addedUser()->id)->sole();
    expect($credentials['roleNote'])->toBeNull()
        ->and(RoleAssignment::acrossTenants()->where('membership_id', $membership->id)->sole()->role->key)->toBe('shift_supervisor');
});

it('refuses an email that already has an account', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($this->owner->user)
        ->post(membersAt('/manage/members'), ['name' => 'Someone', 'email' => 'TAKEN@example.test'])
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

it('lets only people who manage access add members', function () {
    $plain = $this->member();

    $this->actingAs($plain->user)
        ->post(membersAt('/manage/members'), ['name' => 'Someone', 'email' => 'x@example.test'])
        ->assertForbidden();

    expect(User::query()->where('email', 'x@example.test')->exists())->toBeFalse();
});

it('lists people still on their temporary password on the invitations page', function () {
    addMember();

    $this->actingAs($this->owner->user)
        ->get(membersAt('/manage/invitations'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('awaiting', 1)
            ->where('awaiting.0.email', 'deng@example.test')
            ->where('awaiting.0.expired', false));
});

it('sends someone on a temporary password to choose their own before anything else', function () {
    $credentials = addMember();
    auth()->logout();

    $this->post(route('login.store'), ['email' => 'deng@example.test', 'password' => $credentials['password']]);
    $this->assertAuthenticatedAs(addedUser());

    $setup = PlatformHosts::url(PlatformHosts::auth(), 'password/setup');
    $this->get(membersAt('/'))->assertRedirect($setup);
    $this->get(PlatformHosts::url(PlatformHosts::app(), 'dashboard'))->assertRedirect($setup);

    // Not the temporary password again.
    $this->post($setup, ['password' => $credentials['password'], 'password_confirmation' => $credentials['password']])
        ->assertSessionHasErrors('password');

    $this->post($setup, ['password' => 'a-Much-better-one-42', 'password_confirmation' => 'a-Much-better-one-42'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $user = addedUser();
    expect($user->mustChangePassword())->toBeFalse()
        ->and($user->temporary_password_expires_at)->toBeNull()
        ->and(Hash::check('a-Much-better-one-42', $user->password))->toBeTrue();

    $this->get(PlatformHosts::url(PlatformHosts::app(), 'dashboard'))->assertOk();
});

it('refuses a temporary password after it expires', function () {
    $credentials = addMember();
    auth()->logout();

    $this->travel(TemporaryAccounts::VALID_DAYS + 1)->days();

    $this->post(route('login.store'), ['email' => 'deng@example.test', 'password' => $credentials['password']])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('issues a new temporary password while the person has not chosen their own', function () {
    $first = addMember();
    $membership = OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', addedUser()->id)->sole();

    $this->actingAs($this->owner->user)
        ->post(membersAt("/manage/members/{$membership->id}/temporary-password"))
        ->assertSessionHasNoErrors();

    $second = session(SessionKey::FLASH_DATA)['credentials']['password'];
    expect($second)->not->toBe($first['password'])
        ->and(Hash::check($first['password'], addedUser()->password))->toBeFalse()
        ->and(Hash::check($second, addedUser()->password))->toBeTrue();
});

it('never resets the password of someone who chose their own, or who was not added here', function () {
    addMember();
    $added = OrganizationMembership::query()->withoutGlobalScopes()->where('user_id', addedUser()->id)->sole();
    addedUser()->forceFill(['password_change_required_at' => null, 'temporary_password_expires_at' => null])->save();

    $this->actingAs($this->owner->user)
        ->post(membersAt("/manage/members/{$added->id}/temporary-password"))
        ->assertSessionHasErrors('membership');

    // An ordinary member with their own account.
    $regular = $this->member();
    $this->actingAs($this->owner->user)
        ->post(membersAt("/manage/members/{$regular->id}/temporary-password"))
        ->assertSessionHasErrors('membership');
});

it('does not reach another organization\'s members', function () {
    $other = Organization::factory()->create();
    $theirs = OrganizationMembership::factory()->create(['organization_id' => $other->id]);
    app(TenantContext::class)->clear();

    $this->actingAs($this->owner->user)
        ->post(membersAt("/manage/members/{$theirs->id}/temporary-password"))
        ->assertNotFound();
});

it('treats the administrator\'s word as enough here but not as a proven email', function () {
    addMember();
    $user = addedUser();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and((new UserIdentity($user))->getClaims(['email'])['email_verified'] ?? null)->toBeFalse();

    // Another organization's invitation needs a proven address.
    $other = Organization::factory()->create();
    $inviter = OrganizationMembership::factory()->create(['organization_id' => $other->id]);
    $other->forceFill(['owner_membership_id' => $inviter->id])->save();
    app(TenantContext::class)->clear();
    $token = app(TenantContext::class)->run($other, fn () => app(Invitations::class)->invite($inviter, 'deng@example.test')[1]);

    expect(fn () => app(Invitations::class)->accept($user, $token))
        ->toThrow(MembershipActionDenied::class, 'Verify your email address');
});
