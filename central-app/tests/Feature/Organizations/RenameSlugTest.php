<?php

use App\Models\User;
use App\Modules\Applications\Support\WorkspaceShell;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;

function ownedOrganization(User $owner, string $slug = 'manggala'): Organization
{
    return app(CreateOrganization::class)->handle($owner, [
        'display_name' => 'Manggala Trading',
        'legal_name' => 'Manggala Trading Co. Ltd',
        'slug' => $slug,
        'country_code' => 'SS',
        'default_currency' => 'SSP',
        'timezone' => 'Africa/Juba',
    ]);
}

function asCurrentOwner(User $owner, Organization $organization): void
{
    test()->actingAs($owner)->post(PlatformHosts::url(PlatformHosts::app(), "organizations/{$organization->id}/switch"));
}

function renameTo(string $slug): TestResponse
{
    return test()->put(PlatformHosts::url(PlatformHosts::app(), 'organization/slug'), ['slug' => $slug]);
}

it('moves the workspace to the new slug and redirects the old one', function () {
    $owner = User::factory()->create();
    $organization = ownedOrganization($owner);
    asCurrentOwner($owner, $organization);

    renameTo('manggala-group')->assertSessionHasNoErrors();

    $old = OrganizationSlug::query()->where('slug', 'manggala')->sole();
    expect($organization->primarySlug()->value('slug'))->toBe('manggala-group')
        ->and($old->is_primary)->toBeFalse()
        ->and($old->retired_at)->not->toBeNull()
        ->and($old->redirect_until->isAfter(now()->addDays(179)))->toBeTrue();

    $this->get('http://manggala.'.config('platform.root_domain').'/members?x=1')
        ->assertStatus(301)
        ->assertRedirect('http://manggala-group.'.config('platform.root_domain').'/members?x=1');

    expect(AuditLog::query()->where('action', 'organization.slug_changed')->sole()->context)
        ->toEqual(['from' => 'manggala', 'to' => 'manggala-group']);
});

it('moves the workspace sign-in callback to the new slug', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Workspace', [], confidential: true);
    WorkspaceShell::register((string) $client->getKey());

    $owner = User::factory()->create();
    $organization = ownedOrganization($owner);
    asCurrentOwner($owner, $organization);

    renameTo('manggala-group');

    expect(DB::table('application_client_redirect_uris')->pluck('uri')->all())
        ->toBe([PlatformHosts::url(PlatformHosts::workspace('manggala-group'), 'auth/callback')]);
});

it('never releases a retired slug to another organization', function () {
    $owner = User::factory()->create();
    $organization = ownedOrganization($owner);
    asCurrentOwner($owner, $organization);
    renameTo('manggala-group');

    $this->travel(400)->days();

    $this->actingAs(User::factory()->create())
        ->post(PlatformHosts::url(PlatformHosts::app(), 'organizations'), [
            'display_name' => 'Impostor', 'legal_name' => 'Impostor Ltd', 'slug' => 'manggala',
            'country_code' => 'SS', 'default_currency' => 'SSP', 'timezone' => 'Africa/Juba',
        ])
        ->assertSessionHasErrors(['slug' => 'This slug is already taken.']);
});

it('limits how often the slug can change', function () {
    $owner = User::factory()->create();
    $organization = ownedOrganization($owner);
    asCurrentOwner($owner, $organization);

    renameTo('manggala-group')->assertSessionHasNoErrors();
    renameTo('manggala-holdings')->assertSessionHasErrors('slug');

    $this->travel(31)->days();
    renameTo('manggala-holdings')->assertSessionHasNoErrors();
});

it('only lets the owner change the slug', function () {
    $owner = User::factory()->create();
    $organization = ownedOrganization($owner);

    $member = User::factory()->create();
    $membership = new OrganizationMembership;
    $membership->forceFill(['organization_id' => $organization->id, 'user_id' => $member->id, 'status' => 'active', 'joined_at' => now()]);
    app(TenantContext::class)->run($organization, fn () => $membership->save());

    asCurrentOwner($member, $organization);

    renameTo('taken-over')->assertForbidden();
    $this->get(PlatformHosts::url(PlatformHosts::app(), 'organization/settings'))->assertForbidden();

    expect($organization->primarySlug()->value('slug'))->toBe('manggala');
});

it('needs a current organization', function () {
    $this->actingAs(User::factory()->create());

    renameTo('anything-new')->assertNotFound();
});
