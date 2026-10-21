<?php

use App\Modules\Administration\Actions\SupportAccess;
use App\Modules\Administration\Models\SupportAccessGrant;
use App\Modules\Administration\Notifications\SupportAccessApproved;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    Notification::fake();
    // Several tests travel hours ahead; the idle timeout has its own tests.
    config(['platform.admin.idle_minutes' => 100_000]);

    $this->organization = Organization::factory()->create(['display_name' => 'Manggala Trading']);
    $this->ownerMembership = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    $this->organization->forceFill(['owner_membership_id' => $this->ownerMembership->id])->save();
    $this->secretMember = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);

    $this->agent = $this->staffWith(['support_agent']);
    $this->manager = $this->staffWith(['support_manager']);
});

function requestAccess(int $hours = 4): SupportAccessGrant
{
    test()->actingAs(test()->agent->user)->post(test()->adminUrl('/organizations/'.test()->organization->id.'/support-access'), [
        'reason' => 'Customer reports missing branch after import',
        'ticket_reference' => 'SUP-1042',
        'scope' => 'read_only',
        'hours' => $hours,
    ])->assertSessionHasNoErrors();

    return SupportAccessGrant::query()->latest()->firstOrFail();
}

function approve(SupportAccessGrant $grant): void
{
    test()->actingAs(test()->manager->user)->post(test()->adminUrl("/support-access/{$grant->id}/approve"))->assertSessionHasNoErrors();
}

function customerMembers(): TestResponse
{
    return test()->actingAs(test()->agent->user)->get(test()->adminUrl('/organizations/'.test()->organization->id.'/customer/members'));
}

it('keeps customer data hidden from every staff role without a grant', function (string $role) {
    $staff = $this->staffWith([$role]);
    $this->actingAs($staff->user);

    foreach (['members', 'structure'] as $view) {
        $this->get($this->adminUrl("/organizations/{$this->organization->id}/customer/{$view}"))->assertForbidden();
    }
})->with(['support_agent', 'support_manager', 'security_auditor', 'super_admin']);

it('shows only directory information on the organization page', function () {
    $this->actingAs($this->agent->user)
        ->get($this->adminUrl("/organizations/{$this->organization->id}"))
        ->assertOk()
        ->assertDontSee($this->secretMember->user->email)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeGrant', null)
            ->missing('members'));

    $this->get($this->adminUrl('/organizations'))->assertDontSee($this->secretMember->user->email);
});

it('opens customer data only after someone else approves the request', function () {
    $grant = requestAccess();

    customerMembers()->assertForbidden();

    approve($grant);

    customerMembers()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/organizations/customer-members')
            ->has('members', 2));
});

it('records every view of customer data against the organization', function () {
    approve(requestAccess());
    customerMembers()->assertOk();

    $view = AuditLog::query()->where('action', 'support.data_viewed')->sole();
    expect($view->organization_id)->toBe($this->organization->id)
        ->and($view->actor_id)->toBe($this->agent->user_id)
        ->and($view->actor_type)->toBe('staff')
        ->and($view->context['viewed'])->toBe('members');

    expect(AuditLog::query()->where('organization_id', $this->organization->id)->orderBy('sequence')->pluck('action')->all())
        ->toBe(['support.requested', 'support.approved', 'support.data_viewed']);
});

it('tells the organization owner when access is approved', function () {
    approve(requestAccess());

    Notification::assertSentTo($this->ownerMembership->user, SupportAccessApproved::class, fn (SupportAccessApproved $mail) => $mail->organizationName === 'Manggala Trading');
});

it('refuses self-approval, even for super admins', function () {
    $superAdmin = $this->staffWith(['super_admin']);
    $grant = app(SupportAccess::class)->request($superAdmin, $this->organization, 'Checking a reported bug', null, 'read_only', 2);

    $this->actingAs($superAdmin->user)
        ->post($this->adminUrl("/support-access/{$grant->id}/approve"))
        ->assertSessionHasErrors(['support' => 'Support access must be approved by someone else.']);

    expect($grant->refresh()->approved_by_staff_id)->toBeNull();
});

it('refuses approval from staff without the approve permission', function () {
    $grant = requestAccess();
    $otherAgent = $this->staffWith(['support_agent']);

    $this->actingAs($otherAgent->user)->post($this->adminUrl("/support-access/{$grant->id}/approve"))->assertForbidden();
});

it('caps support access at 72 hours', function () {
    $this->actingAs($this->agent->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/support-access"), [
            'reason' => 'Long-running migration help', 'scope' => 'read_only', 'hours' => 73,
        ])
        ->assertSessionHasErrors('hours');
});

it('ends access when the grant expires', function () {
    approve(requestAccess(hours: 2));
    customerMembers()->assertOk();

    $this->travel(121)->minutes();

    customerMembers()->assertForbidden();
});

it('ends access when the grant is revoked', function () {
    $grant = requestAccess();
    approve($grant);

    $this->actingAs($this->manager->user)->post($this->adminUrl("/support-access/{$grant->id}/revoke"));

    customerMembers()->assertForbidden();
});

it('does not let one staff member use another\'s grant', function () {
    approve(requestAccess());
    $colleague = $this->staffWith(['support_agent']);

    $this->actingAs($colleague->user)
        ->get($this->adminUrl("/organizations/{$this->organization->id}/customer/members"))
        ->assertForbidden();
});

it('does not carry a grant over to another organization', function () {
    approve(requestAccess());
    $other = Organization::factory()->create();

    $this->actingAs($this->agent->user)
        ->get($this->adminUrl("/organizations/{$other->id}/customer/members"))
        ->assertForbidden();
});

it('does not approve a request after its window has passed', function () {
    $grant = requestAccess(hours: 1);
    $this->travel(2)->hours();

    $this->actingAs($this->manager->user)
        ->post($this->adminUrl("/support-access/{$grant->id}/approve"))
        ->assertSessionHasErrors('support');
});
