<?php

use App\Models\User;
use App\Modules\Applications\Support\WorkspaceShell;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

function appUrl(string $path): string
{
    return PlatformHosts::url(PlatformHosts::app(), $path);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function organizationInput(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Manggala Trading',
        'legal_name' => 'Manggala Trading Co. Ltd',
        'slug' => 'manggala',
        'country_code' => 'ss',
        'default_currency' => 'ssp',
        'timezone' => 'Africa/Juba',
    ], $overrides);
}

it('creates an active organization with the creator as owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(appUrl('organizations'), organizationInput())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $organization = Organization::query()->sole();
    $owner = OrganizationMembership::acrossTenants()->sole();

    expect($organization->status)->toBe(OrganizationStatus::Active)
        ->and($organization->activated_at)->not->toBeNull()
        ->and($organization->country_code)->toBe('SS')
        ->and($organization->default_currency)->toBe('SSP')
        ->and($organization->created_by_user_id)->toBe($user->id)
        ->and($organization->owner_membership_id)->toBe($owner->id)
        ->and($owner->user_id)->toBe($user->id)
        ->and($owner->organization_id)->toBe($organization->id)
        ->and($organization->primarySlug->slug)->toBe('manggala')
        ->and(session(CurrentOrganization::SESSION_KEY))->toBe($organization->id);

    $audit = AuditLog::query()->where('action', 'organization.created')->sole();
    expect($audit->organization_id)->toBe($organization->id)
        ->and($audit->actor_id)->toBe($user->id);
});

it('opens the new workspace for its owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(appUrl('organizations'), organizationInput());

    $this->get('http://manggala.'.config('platform.root_domain').'/')->assertOk();
});

it('registers the workspace sign-in callback for the new organization', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Workspace', [], confidential: true);
    WorkspaceShell::register((string) $client->getKey());

    $this->actingAs(User::factory()->create())->post(appUrl('organizations'), organizationInput());

    expect(DB::table('application_client_redirect_uris')->pluck('uri')->all())
        ->toBe([PlatformHosts::url(PlatformHosts::workspace('manggala'), 'auth/callback')]);
});

it('normalizes the slug to lowercase', function () {
    $this->actingAs(User::factory()->create())
        ->post(appUrl('organizations'), organizationInput(['slug' => 'Manggala']))
        ->assertSessionHasNoErrors();

    expect(OrganizationSlug::query()->value('slug'))->toBe('manggala');
});

it('rejects unusable slugs', function (string $slug) {
    $this->actingAs(User::factory()->create())
        ->post(appUrl('organizations'), organizationInput(['slug' => $slug]))
        ->assertSessionHasErrors('slug');

    expect(Organization::query()->count())->toBe(0);
})->with([
    'reserved' => 'support',
    'platform host' => 'admin',
    'blocked brand term' => 'milessoftwares-shop',
    'too short' => 'abc',
    'double hyphen' => 'my--shop',
    'leading digit' => '1shop',
]);

it('rejects a slug that is in use or was ever used', function (bool $retired) {
    $existing = Organization::factory()->withSlug('manggala')->create();
    if ($retired) {
        $existing->slugs()->update(['is_primary' => false, 'retired_at' => now()->subYear(), 'redirect_until' => now()->subMonth()]);
    }

    $this->actingAs(User::factory()->create())
        ->post(appUrl('organizations'), organizationInput())
        ->assertSessionHasErrors(['slug' => 'This slug is already taken.']);
})->with(['in use' => false, 'retired' => true]);

it('reports slug availability', function () {
    Organization::factory()->withSlug('taken-name')->create();
    $this->actingAs(User::factory()->create());

    $this->getJson(appUrl('organizations/slug-availability?slug=fresh-name'))
        ->assertOk()
        ->assertExactJson(['available' => true, 'errors' => []]);

    $this->getJson(appUrl('organizations/slug-availability?slug=taken-name'))
        ->assertJson(['available' => false, 'errors' => ['This slug is already taken.']]);

    $this->getJson(appUrl('organizations/slug-availability?slug=support'))
        ->assertJson(['available' => false, 'errors' => ['This slug is reserved.']]);
});

it('requires sign-in on the app host', function () {
    $this->post(appUrl('organizations'), organizationInput())->assertRedirect(appUrl('auth/login'));

    expect(Organization::query()->count())->toBe(0);
});
