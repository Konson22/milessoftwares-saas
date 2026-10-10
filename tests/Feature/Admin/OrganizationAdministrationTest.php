<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

it('lists and searches organizations with member counts', function () {
    $manggala = Organization::factory()->withSlug('manggala')->create(['display_name' => 'Manggala Trading']);
    Organization::factory()->create(['display_name' => 'Wau Foods']);
    OrganizationMembership::factory()->count(3)->create(['organization_id' => $manggala->id]);

    $this->actingAs($this->staffWith(['support_agent'])->user)
        ->get($this->adminUrl('/organizations?search=mangg'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('organizations.data', 1)
            ->where('organizations.data.0.displayName', 'Manggala Trading')
            ->where('organizations.data.0.members', 3));
});

it('lets support managers suspend and reactivate an organization with a reason', function () {
    $organization = Organization::factory()->create();
    $manager = $this->staffWith(['support_manager']);

    $this->actingAs($manager->user)
        ->put($this->adminUrl("/organizations/{$organization->id}/status"), ['status' => 'suspended', 'reason' => 'Unpaid for 90 days'])
        ->assertSessionHasNoErrors();

    expect($organization->refresh()->status)->toBe(OrganizationStatus::Suspended)
        ->and($organization->suspended_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'organization.suspended')->value('context'))->toBe(['reason' => 'Unpaid for 90 days']);

    $this->put($this->adminUrl("/organizations/{$organization->id}/status"), ['status' => 'active', 'reason' => 'Paid'])->assertSessionHasNoErrors();

    expect($organization->refresh()->status)->toBe(OrganizationStatus::Active)
        ->and($organization->suspended_at)->toBeNull();
});

it('forbids suspension without the permission', function () {
    $organization = Organization::factory()->create();

    $this->actingAs($this->staffWith(['support_agent'])->user)
        ->put($this->adminUrl("/organizations/{$organization->id}/status"), ['status' => 'suspended', 'reason' => 'No'])
        ->assertForbidden();

    expect($organization->refresh()->status)->toBe(OrganizationStatus::Active);
});

it('requires a reason to suspend', function () {
    $organization = Organization::factory()->create();

    $this->actingAs($this->staffWith(['support_manager'])->user)
        ->put($this->adminUrl("/organizations/{$organization->id}/status"), ['status' => 'suspended'])
        ->assertSessionHasErrors('reason');
});

it('keeps the organization directory away from staff without organizations.view', function () {
    $this->actingAs($this->staffWith(['catalog_admin'])->user)
        ->get($this->adminUrl('/organizations'))
        ->assertForbidden();
});
