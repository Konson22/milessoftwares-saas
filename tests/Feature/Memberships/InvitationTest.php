<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Actions\RevokeRole;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Notifications\OrganizationInvitation;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    Notification::fake();
    $this->prepareAuthorizationFixtures();
    $this->slug = $this->organization->primarySlug->slug;
    app(TenantContext::class)->clear();

    $this->invitee = User::factory()->create(['email' => 'amina@example.test']);
});

function workspaceAt(string $path): string
{
    return 'http://'.test()->slug.'.'.config('platform.root_domain').$path;
}

function invitationUrl(string $token, string $suffix = ''): string
{
    return PlatformHosts::url(PlatformHosts::app(), "invitations/{$token}{$suffix}");
}

/**
 * Sends an invitation as $inviter and returns the plain token from the email.
 */
function invite(OrganizationMembership $inviter, string $email = 'amina@example.test', array $extra = []): string
{
    test()->actingAs($inviter->user)
        ->post(workspaceAt('/manage/invitations'), ['email' => $email, ...$extra])
        ->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, OrganizationInvitation::class, function (OrganizationInvitation $mail, $channels, $notifiable) use ($email, &$token) {
        // Invitations are addressed to the normalized (lowercase) email.
        if (($notifiable->routes['mail'] ?? null) === mb_strtolower($email)) {
            $token = $mail->token;
        }

        return true;
    });

    return (string) $token;
}

function acceptAs(User $user, string $token): TestResponse
{
    return test()->actingAs($user)->post(invitationUrl($token, '/accept'));
}

function membershipOf(User $user): ?OrganizationMembership
{
    return OrganizationMembership::acrossTenants()->where('user_id', $user->id)->where('organization_id', test()->organization->id)->first();
}

it('stores only a hash of the token', function () {
    $token = invite($this->owner);
    $invitation = Invitation::acrossTenants()->sole();

    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode(Invitation::acrossTenants()->first()->getAttributes()))->not->toContain($token);
});

it('lets the invited account join', function () {
    $token = invite($this->owner);

    $this->actingAs($this->invitee)
        ->get(invitationUrl($token))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('invitations/show')
            ->where('status', 'pending')
            ->where('organization.displayName', $this->organization->display_name));

    acceptAs($this->invitee, $token)->assertRedirect(route('dashboard'));

    expect(membershipOf($this->invitee)?->status)->toBe(MembershipStatus::Active)
        ->and(Invitation::acrossTenants()->sole()->status)->toBe('accepted')
        ->and(session(CurrentOrganization::SESSION_KEY))->toBe($this->organization->id)
        ->and(AuditLog::query()->where('action', 'invitation.accepted')->value('organization_id'))->toBe($this->organization->id);
});

it('can be used only once', function () {
    $token = invite($this->owner);
    acceptAs($this->invitee, $token)->assertRedirect(route('dashboard'));

    acceptAs($this->invitee, $token)->assertSessionHasErrors(['invitation' => 'This invitation is no longer valid.']);

    $this->actingAs($this->invitee)->get(invitationUrl($token))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('status', 'accepted')
        ->where('organization', null));
});

it('expires after seven days', function () {
    $token = invite($this->owner);
    $this->travel(Invitations::VALID_DAYS)->days();
    $this->travel(1)->minutes();

    $this->actingAs($this->invitee)->get(invitationUrl($token))->assertInertia(fn (AssertableInertia $page) => $page->where('status', 'expired'));
    acceptAs($this->invitee, $token)->assertSessionHasErrors(['invitation' => 'This invitation has expired. Ask for a new one.']);

    expect(membershipOf($this->invitee))->toBeNull();

    $this->artisan('platform:expire-invitations')->assertSuccessful();
    expect(Invitation::acrossTenants()->sole()->status)->toBe('expired');
});

it('stops working when revoked', function () {
    $token = invite($this->owner);
    $this->actingAs($this->owner->user)->delete(workspaceAt('/manage/invitations/'.Invitation::acrossTenants()->sole()->id));

    acceptAs($this->invitee, $token)->assertSessionHasErrors('invitation');
    expect(membershipOf($this->invitee))->toBeNull();
});

it('invalidates the old link when resent', function () {
    $oldToken = invite($this->owner);
    Notification::fake();

    $this->actingAs($this->owner->user)->post(workspaceAt('/manage/invitations/'.Invitation::acrossTenants()->sole()->id.'/resend'))->assertSessionHasNoErrors();
    $newToken = null;
    Notification::assertSentTo(new AnonymousNotifiable, OrganizationInvitation::class, function (OrganizationInvitation $mail) use (&$newToken) {
        $newToken = $mail->token;

        return true;
    });

    acceptAs($this->invitee, $oldToken)->assertSessionHasErrors('invitation');
    acceptAs($this->invitee, $newToken)->assertRedirect(route('dashboard'));
});

it('refuses an account with a different email and does not reveal the organization', function () {
    $token = invite($this->owner);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(invitationUrl($token))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('status', 'wrong_account')
            ->where('organization', null)
            ->where('invitedEmail', 'a••••@example.test'));

    acceptAs($stranger, $token)->assertSessionHasErrors(['invitation' => 'This invitation was sent to a different email address.']);
    expect(membershipOf($stranger))->toBeNull();
});

it('matches the email without regard to case', function () {
    $token = invite($this->owner, 'Amina@Example.TEST');

    acceptAs($this->invitee, $token)->assertRedirect(route('dashboard'));
});

it('requires a verified email address', function () {
    $token = invite($this->owner);
    $this->invitee->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($this->invitee)->post(invitationUrl($token, '/accept'))->assertRedirect();
    expect(membershipOf($this->invitee))->toBeNull();
});

it('fails when the inviter has lost the authority to invite', function () {
    $admin = $this->member();
    $assignment = app(TenantContext::class)->run($this->organization, fn () => $this->grant($admin, 'platform', 'access_administrator'));
    app(TenantContext::class)->clear();
    $token = invite($admin);

    app(TenantContext::class)->run($this->organization, fn () => app(RevokeRole::class)->handle($this->owner, $assignment));
    app(TenantContext::class)->clear();

    acceptAs($this->invitee, $token)->assertSessionHasErrors(['invitation' => 'The person who invited you can no longer add members. Ask for a new invitation.']);
    expect(membershipOf($this->invitee))->toBeNull();
});

it('refuses invitations from members who cannot manage access', function () {
    $plain = $this->member();

    $this->actingAs($plain->user)->post(workspaceAt('/manage/invitations'), ['email' => 'x@example.test'])->assertForbidden();
    expect(Invitation::acrossTenants()->count())->toBe(0);
});

it('refuses to invite an existing member', function () {
    $this->actingAs($this->owner->user)
        ->post(workspaceAt('/manage/invitations'), ['email' => $this->owner->user->email])
        ->assertSessionHasErrors(['email' => 'That person is already a member.']);
});

it('gives a proposed role on acceptance, checked as the inviter', function () {
    $token = invite($this->owner, extra: [
        'role_id' => $this->role('retail', 'cashier')->id,
        'scope_type' => 'branch',
        'branch_id' => $this->juba->id,
    ]);

    acceptAs($this->invitee, $token)->assertRedirect(route('dashboard'));

    $assignment = RoleAssignment::acrossTenants()->where('membership_id', membershipOf($this->invitee)->id)->sole();
    expect($assignment->branch_id)->toBe($this->juba->id)
        ->and($assignment->granted_by_membership_id)->toBe($this->owner->id);
});

it('still adds the member but skips a proposed role the inviter can no longer give', function () {
    $branchAdmin = $this->member();
    app(TenantContext::class)->run($this->organization, function () use ($branchAdmin) {
        $this->grant($branchAdmin, 'platform', 'access_administrator', Scope::branch($this->juba->id));
        $this->grant($branchAdmin, 'retail', 'store_manager', Scope::branch($this->juba->id));
    });
    app(TenantContext::class)->clear();

    $token = invite($branchAdmin, extra: [
        'role_id' => $this->role('retail', 'cashier')->id, 'scope_type' => 'branch', 'branch_id' => $this->juba->id,
    ]);

    // The store manager role, which made cashier delegable, is taken away.
    app(TenantContext::class)->run($this->organization, fn () => RoleAssignment::query()
        ->where('membership_id', $branchAdmin->id)
        ->whereHas('role', fn ($query) => $query->where('key', 'store_manager'))
        ->update(['revoked_at' => now()]));
    app(TenantContext::class)->clear();

    acceptAs($this->invitee, $token)->assertRedirect(route('dashboard'));

    expect(membershipOf($this->invitee)?->isActive())->toBeTrue()
        ->and(RoleAssignment::acrossTenants()->where('membership_id', membershipOf($this->invitee)->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'invitation.accepted')->value('context')['role_note'])->toContain('The proposed role was not given');
});

it('returns 404 for another organization\'s invitation id', function () {
    $other = Organization::factory()->create();
    $foreign = app(TenantContext::class)->run($other, function () use ($other) {
        $inviter = OrganizationMembership::factory()->create(['organization_id' => $other->id]);
        $invitation = new Invitation;
        $invitation->forceFill([
            'email' => 'x@example.test', 'token_hash' => hash('sha256', 'x'), 'invited_by_membership_id' => $inviter->id,
            'status' => 'pending', 'expires_at' => now()->addDay(),
        ])->save();

        return $invitation;
    });
    app(TenantContext::class)->clear();

    $this->actingAs($this->owner->user)->delete(workspaceAt("/manage/invitations/{$foreign->id}"))->assertNotFound();
    expect(Invitation::acrossTenants()->find($foreign->id)->status)->toBe('pending');
});

it('rejects malformed tokens before any lookup', function () {
    $this->actingAs($this->invitee)->get(PlatformHosts::url(PlatformHosts::app(), 'invitations/short'))->assertNotFound();
});
