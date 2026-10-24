<?php

use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Support\WorkspaceShell;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Products\Support\ProductCatalog;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
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
    'blocked brand term' => 'milesoftwares-shop',
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

it('starts a free trial of the application chosen on the public site', function () {
    config(['platform.marketing.trial_days' => 60]);
    $retail = Application::query()->forceCreate(['code' => 'retail', 'name' => 'Retail', 'category' => 'operations', 'kind' => 'standalone', 'status' => 'available']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(appUrl('organizations/create?app=retail'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('trial.days', 60)
            ->where('trial.selected', 'retail')
            ->where('trial.applications.0.code', 'retail'));

    $this->post(appUrl('organizations'), organizationInput(['trial_application' => 'retail']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $organization = Organization::query()->sole();
    $trial = app(TenantContext::class)->run($organization, fn () => Entitlement::query()->current()->sole());

    expect($trial->application_id)->toBe($retail->id)
        ->and($trial->source)->toBe('trial')
        ->and($trial->valid_until->isSameDay(now()->addDays(60)))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'entitlement.trial_started')->sole();
    expect($audit->actor_id)->toBe($user->id)
        ->and($audit->organization_id)->toBe($organization->id);
});

it('creates a workspace without a trial when none is chosen', function () {
    Application::query()->forceCreate(['code' => 'retail', 'name' => 'Retail', 'category' => 'operations', 'kind' => 'standalone', 'status' => 'available']);

    $this->actingAs(User::factory()->create())
        ->post(appUrl('organizations'), organizationInput(['trial_application' => '']))
        ->assertSessionHasNoErrors();

    $organization = Organization::query()->sole();
    expect(app(TenantContext::class)->run($organization, fn () => Entitlement::query()->count()))->toBe(0);
});

it('offers trials only of released or beta applications', function (string $code) {
    Application::query()->forceCreate(['code' => 'school', 'name' => 'School', 'category' => 'education', 'kind' => 'standalone', 'status' => 'planned']);
    Application::query()->forceCreate(['code' => 'platform', 'name' => 'Platform', 'category' => 'platform', 'kind' => 'platform', 'status' => 'available']);

    $this->actingAs(User::factory()->create())
        ->post(appUrl('organizations'), organizationInput(['trial_application' => $code]))
        ->assertSessionHasErrors('trial_application');

    expect(Organization::query()->count())->toBe(0);
})->with(['announced only' => 'school', 'the platform itself' => 'platform', 'unknown' => 'nothing']);

it('never gives an organization a second trial of the same application', function () {
    $retail = Application::query()->forceCreate(['code' => 'retail', 'name' => 'Retail', 'category' => 'operations', 'kind' => 'standalone', 'status' => 'available']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(appUrl('organizations'), organizationInput(['trial_application' => 'retail']));
    $organization = Organization::query()->sole();

    expect(fn () => app(ManageEntitlements::class)->startTrial($organization, $retail, $user))
        ->toThrow(RuntimeException::class);
});

describe('trials of an ERP product', function () {
    beforeEach(function () {
        $this->pharmacy = Application::query()->forceCreate(['code' => 'pharmacy', 'name' => 'Pharmacy', 'category' => 'products', 'kind' => 'standalone', 'status' => 'available']);
        $this->finance = Application::query()->forceCreate(['code' => 'finance', 'name' => 'Finance', 'category' => 'shared', 'kind' => 'shared_module', 'status' => 'available']);
        app(ProductCatalog::class)->sync([
            'code' => 'pharmacy', 'name' => 'Pharmacy ERP', 'status' => 'pilot', 'sort_order' => 10,
            'applications' => [['code' => 'pharmacy', 'requirement' => 'required'], ['code' => 'finance', 'requirement' => 'required']],
        ]);
        $this->user = User::factory()->create();
    });

    function trialsOf(Organization $organization): array
    {
        $ids = app(TenantContext::class)->run($organization, fn () => Entitlement::query()->current()->where('source', 'trial')->pluck('application_id'));

        return Application::query()->whereIn('id', $ids)->orderBy('code')->pluck('code')->all();
    }

    it('tries the shared modules the product needs alongside the application', function () {
        $this->actingAs($this->user)->post(appUrl('organizations'), organizationInput(['trial_application' => 'pharmacy']))
            ->assertSessionHasNoErrors();

        $organization = Organization::query()->sole();

        expect(trialsOf($organization))->toBe(['finance', 'pharmacy'])
            ->and(AuditLog::query()->where('action', 'entitlement.trial_started')->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'entitlement.trial_started')->get()->firstWhere('context.application', 'finance')?->context['with'])->toBe('pharmacy');
    });

    it('adds nothing to a trial of the shared module itself', function () {
        $this->actingAs($this->user)->post(appUrl('organizations'), organizationInput(['trial_application' => 'finance']));

        expect(trialsOf(Organization::query()->sole()))->toBe(['finance']);
    });

    it('does not try a shared module again that the organization has already tried', function () {
        $this->actingAs($this->user)->post(appUrl('organizations'), organizationInput(['trial_application' => 'finance']));
        $organization = Organization::query()->sole();

        app(ManageEntitlements::class)->startTrial($organization, $this->pharmacy, $this->user);

        expect(app(TenantContext::class)->run($organization, fn () => Entitlement::query()->where('application_id', $this->finance->id)->count()))->toBe(1)
            ->and(trialsOf($organization))->toBe(['finance', 'pharmacy']);
    });
});
