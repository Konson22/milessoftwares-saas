<?php

use App\Modules\Applications\Models\Application;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Support\CurrentOrganization;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    $this->setUpAuthorizationFixtures();
    Application::query()->where('code', 'retail')->update(['route_prefix' => 'retail', 'status' => 'available']);

    $this->slug = $this->organization->primarySlug->slug;
    $this->cashier = $this->member();
    $this->grant($this->cashier, 'retail', 'cashier', Scope::branch($this->juba->id));
    $this->plainMember = $this->member();
    app(TenantContext::class)->clear();
});

function home(): string
{
    return 'http://'.test()->slug.'.'.config('platform.root_domain').'/';
}

function asCurrent($membership): void
{
    test()->actingAs($membership->user)->post(PlatformHosts::url(PlatformHosts::app(), "organizations/{$membership->organization_id}/switch"));
}

it('lists an entitled application for members who hold a role in it', function () {
    $this->actingAs($this->cashier->user)
        ->get(home())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('modules', 1)
            ->where('modules.0.code', 'retail')
            ->where('modules.0.href', rtrim(home(), '/').'/retail'));
});

it('hides applications the member holds no role in', function () {
    $this->actingAs($this->plainMember->user)
        ->get(home())
        ->assertInertia(fn (AssertableInertia $page) => $page->has('modules', 0));
});

it('shows owners every entitled application', function () {
    $this->actingAs($this->owner->user)
        ->get(home())
        ->assertInertia(fn (AssertableInertia $page) => $page->has('modules', 1));
});

it('hides applications that are not entitled or not released', function (Closure $change) {
    $change($this);

    $this->actingAs($this->owner->user)
        ->get(home())
        ->assertInertia(fn (AssertableInertia $page) => $page->has('modules', 0));
})->with([
    'entitlement revoked' => [fn ($test) => app(TenantContext::class)->run($test->organization, fn () => Entitlement::query()->update(['revoked_at' => now()]))],
    'application planned' => [fn () => Application::query()->where('code', 'retail')->update(['status' => 'planned'])],
    'no workspace path' => [fn () => Application::query()->where('code', 'retail')->update(['route_prefix' => null])],
]);

it('summarizes the current organization on the dashboard', function () {
    asCurrent($this->owner);

    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('current.displayName', $this->organization->display_name)
        ->where('current.counts.members', 3)
        ->where('current.counts.branches', 2)
        ->where('current.isOwner', true)
        ->has('current.applications', 1));
});

it('lets the owner edit organization details', function () {
    asCurrent($this->owner);

    $this->put(PlatformHosts::url(PlatformHosts::app(), 'organization/details'), [
        'display_name' => 'Manggala Group',
        'legal_name' => 'Manggala Group Ltd',
        'timezone' => 'Africa/Nairobi',
        'default_currency' => 'kes',
        'contact_email' => 'hello@manggala.test',
    ])->assertSessionHasNoErrors();

    expect($this->organization->refresh()->display_name)->toBe('Manggala Group')
        ->and($this->organization->default_currency)->toBe('KES')
        ->and(AuditLog::query()->where('action', 'organization.updated')->value('organization_id'))->toBe($this->organization->id);
});

it('keeps details and the subscription view to the owner', function () {
    asCurrent($this->cashier);
    expect(session(CurrentOrganization::SESSION_KEY))->toBe($this->organization->id);

    $this->put(PlatformHosts::url(PlatformHosts::app(), 'organization/details'), [
        'display_name' => 'Hijacked', 'legal_name' => 'X', 'timezone' => 'UTC', 'default_currency' => 'USD',
    ])->assertForbidden();
    $this->get(PlatformHosts::url(PlatformHosts::app(), 'organization/subscription'))->assertForbidden();

    expect($this->organization->refresh()->display_name)->not->toBe('Hijacked');
});

it('shows the owner which applications the organization can use', function () {
    asCurrent($this->owner);

    $this->get(PlatformHosts::url(PlatformHosts::app(), 'organization/subscription'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('organizations/subscription')
            ->has('entitlements', 1)
            ->where('entitlements.0.application', 'Retail')
            ->where('entitlements.0.source', 'manual'));
});
