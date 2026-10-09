<?php

use App\Models\User;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;

function workspace(string $slug, string $path = '/'): string
{
    return 'http://'.$slug.'.'.config('platform.root_domain').$path;
}

function memberOf(Organization $organization): User
{
    $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);

    return $membership->user;
}

it('resolves an active organization for its members', function () {
    $org = Organization::factory()->withSlug('manggala')->create(['display_name' => 'Manggala Trading']);

    $this->actingAs(memberOf($org))
        ->get(workspace('manggala'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/home')
            ->where('organization.id', $org->id)
            ->where('organization.displayName', 'Manggala Trading'));
});

it('returns 404 for unknown business subdomains', function () {
    Organization::factory()->withSlug('manggala')->create();

    $this->get(workspace('unknownbiz'))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(workspace('unknownbiz'))->assertNotFound();
});

it('returns 404 for malformed slugs', function (string $slug) {
    $this->get(workspace($slug))->assertNotFound();
})->with(['ab', '1abc', 'abc-', 'ab--cd', str_repeat('a', 40)]);

it('hides pending and archived organizations', function (OrganizationStatus $status) {
    $org = Organization::factory()->withSlug('hiddenbiz')->status($status)->create();

    $this->actingAs(memberOf($org))->get(workspace('hiddenbiz'))->assertNotFound();
})->with([OrganizationStatus::Pending, OrganizationStatus::Archived]);

it('shows the suspension page to members of a suspended organization', function () {
    $org = Organization::factory()->withSlug('pausedbiz')->status(OrganizationStatus::Suspended)->create();

    $this->actingAs(memberOf($org))
        ->get(workspace('pausedbiz'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('workspace/suspended'));
});

it('does not reveal suspension to non-members', function () {
    Organization::factory()->withSlug('pausedbiz')->status(OrganizationStatus::Suspended)->create();

    $this->actingAs(User::factory()->create())
        ->get(workspace('pausedbiz'))
        ->assertForbidden()
        ->assertDontSee('suspended');
});

it('sends guests into the workspace OIDC sign-in', function () {
    Organization::factory()->withSlug('manggala')->create();

    $this->get(workspace('manggala'))->assertRedirect(workspace('manggala', '/auth/login'));
});

it('forbids authenticated users who are not members', function () {
    Organization::factory()->withSlug('manggala')->create();

    $this->actingAs(User::factory()->create())->get(workspace('manggala'))->assertForbidden();
});

it('forbids suspended members', function () {
    $org = Organization::factory()->withSlug('manggala')->create();
    $membership = OrganizationMembership::factory()->suspended()->create(['organization_id' => $org->id]);

    $this->actingAs($membership->user)->get(workspace('manggala'))->assertForbidden();
});

it('forbids a member of one organization from entering another', function () {
    $mine = Organization::factory()->withSlug('mybiz')->create();
    Organization::factory()->withSlug('theirbiz')->create();
    $user = memberOf($mine);

    $this->actingAs($user)->get(workspace('mybiz'))->assertOk();
    $this->actingAs($user)->get(workspace('theirbiz'))->assertForbidden();
});

it('redirects a retired slug to the primary slug during the grace period', function () {
    $org = Organization::factory()->withSlug('newname')->create();
    $old = new OrganizationSlug(['slug' => 'oldname', 'is_primary' => false, 'retired_at' => now(), 'redirect_until' => now()->addDays(30)]);
    $old->organization()->associate($org)->save();

    $this->get(workspace('oldname', '/?tab=apps'))
        ->assertStatus(301)
        ->assertRedirect(workspace('newname', '/?tab=apps'));
});

it('returns 404 for a retired slug after the grace period', function () {
    $org = Organization::factory()->withSlug('newname')->create();
    $old = new OrganizationSlug(['slug' => 'oldname', 'is_primary' => false, 'retired_at' => now()->subYear(), 'redirect_until' => now()->subDay()]);
    $old->organization()->associate($org)->save();

    $this->get(workspace('oldname'))->assertNotFound();
});

it('treats host names case-insensitively', function () {
    $org = Organization::factory()->withSlug('manggala')->create();

    $this->actingAs(memberOf($org))->get(workspace('MANGGALA'))->assertOk();
});
