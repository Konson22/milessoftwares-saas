<?php

use App\Models\User;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use Inertia\Testing\AssertableInertia;

/*
| Known-ID attacks: a member of one organization uses ids that belong to
| another organization. Every case must behave as if the record does not
| exist, never leak data, and never reveal whether the id is real.
*/

function workspaceUrl(string $slug, string $path = '/'): string
{
    return 'http://'.$slug.'.'.config('platform.root_domain').$path;
}

beforeEach(function () {
    $this->alpha = Organization::factory()->withSlug('alpha-co')->create();
    $this->beta = Organization::factory()->withSlug('beta-co')->create();

    $this->alphaMember = OrganizationMembership::factory()->create(['organization_id' => $this->alpha->id]);
    $this->betaMember = OrganizationMembership::factory()->create(['organization_id' => $this->beta->id]);
});

it('lists only the current workspace members', function () {
    $this->alpha->forceFill(['owner_membership_id' => $this->alphaMember->id])->save();

    $this->actingAs($this->alphaMember->user)
        ->get(workspaceUrl('alpha-co', '/members'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('members', 1)
            ->where('members.0.id', $this->alphaMember->id));
});

it('returns 404 for another organization\'s membership id on your own workspace', function () {
    $this->actingAs($this->alphaMember->user)
        ->get(workspaceUrl('alpha-co', '/members/'.$this->betaMember->id))
        ->assertNotFound()
        ->assertDontSee($this->betaMember->user->email);
});

it('answers a foreign id exactly like an unknown id', function () {
    $this->actingAs($this->alphaMember->user);

    $foreign = $this->get(workspaceUrl('alpha-co', '/members/'.$this->betaMember->id));
    $unknown = $this->get(workspaceUrl('alpha-co', '/members/01a1aaaa-0000-7000-8000-000000000000'));

    expect($foreign->status())->toBe(404)->and($unknown->status())->toBe(404);
});

it('forbids opening another organization\'s workspace', function () {
    $this->actingAs($this->alphaMember->user)
        ->get(workspaceUrl('beta-co', '/members/'.$this->betaMember->id))
        ->assertForbidden();
});

it('shows a member of both organizations only the members of the host they are on', function () {
    $both = OrganizationMembership::factory()->create(['organization_id' => $this->alpha->id]);
    $inBeta = OrganizationMembership::factory()->create(['organization_id' => $this->beta->id, 'user_id' => $both->user_id]);
    $this->beta->forceFill(['owner_membership_id' => $inBeta->id])->save();

    $this->actingAs($both->user)
        ->get(workspaceUrl('beta-co', '/members'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('members', 2)
            ->where('members', fn ($members) => collect($members)->pluck('id')->doesntContain($this->alphaMember->id)));
});

it('cannot make a foreign organization current on the app host', function () {
    $this->actingAs($this->alphaMember->user)
        ->post('http://app.'.config('platform.root_domain').'/organizations/'.$this->beta->id.'/switch')
        ->assertNotFound();
});

it('does not expose a user\'s other organizations to non-members', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(workspaceUrl('alpha-co', '/members'))
        ->assertForbidden()
        ->assertDontSee($this->alphaMember->user->email);
});
