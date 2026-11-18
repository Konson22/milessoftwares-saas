<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Products\Support\ProductCatalog;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\RegistrationIntent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

/*
 * NGO Suite (ADR-0024): one ERP product whose required applications are
 * Projects, Finance, M&E and Procurement. Started as one package through a
 * registration intent or sold as one bundle plan; never takes away access
 * an organization holds another way.
 */

const NGO_APPS = ['projects', 'finance', 'mel', 'procurement'];

beforeEach(function () {
    Notification::fake();
    config(['platform.marketing.trial_days' => 60]);

    $importer = app(CatalogImporter::class);
    $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
    foreach (['projects', 'finance', 'mel'] as $code) {
        $importer->import(json_decode((string) file_get_contents(base_path("tests/Fixtures/catalogs/{$code}.json")), true));
    }
    if (! Application::query()->where('code', 'procurement')->exists()) {
        Application::query()->create(['code' => 'procurement', 'name' => 'Tatook Procurement', 'category' => 'products', 'kind' => 'standalone', 'status' => 'beta']);
    }
    Application::query()->whereIn('code', NGO_APPS)->update(['status' => 'beta', 'trial_enabled' => true]);
    Application::query()->where('code', 'projects')->update(['route_prefix' => 'projects']);

    // The NGO product exactly as the registry file defines it.
    $definition = collect(json_decode((string) file_get_contents(database_path('erp-products.json')), true)['products'])->firstWhere('code', 'ngo');
    app(ProductCatalog::class)->sync($definition);

    $this->organization = Organization::factory()->create();
    $this->owner = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    $this->organization->forceFill(['owner_membership_id' => $this->owner->id])->save();
});

function ngoCurrent(Organization $organization): array
{
    $ids = Application::query()->whereIn('code', NGO_APPS)->pluck('code', 'id');

    return app(TenantContext::class)->run($organization, fn () => Entitlement::query()->current()->whereNull('feature_key')
        ->whereIn('application_id', $ids->keys())->get()
        ->map(fn (Entitlement $e) => $ids[$e->application_id].':'.$e->source)->sort()->values()->all());
}

function ngoStart(): RegistrationIntent
{
    $location = test()->get(test()->authUrl('trial?product=ngo'))->assertRedirectContains('/get-started/')->headers->get('Location');
    test()->actingAs(test()->owner->user)->get((string) $location);

    return RegistrationIntent::query()->sole();
}

it('is defined with Projects, Finance, M&E and Procurement required, and payroll optional', function () {
    $definition = collect(json_decode((string) file_get_contents(database_path('erp-products.json')), true)['products'])->firstWhere('code', 'ngo');
    $requirements = collect($definition['applications'])->pluck('requirement', 'code')->all();

    expect($definition['name'])->toBe('NGO Suite')
        ->and(array_keys(array_filter($requirements, fn ($r) => $r === 'required')))->toEqualCanonicalizing(NGO_APPS)
        ->and($requirements['payroll'])->toBe('optional');
});

it('starts every included application from one choice, and opens Projects', function () {
    $intent = ngoStart();

    $this->post($this->authUrl("get-started/selection/{$intent->id}"), ['organization_id' => $this->organization->id])
        ->assertRedirect(test()->businessUrl('/projects', $this->organization));

    expect(ngoCurrent($this->organization))->toBe(['finance:trial', 'mel:trial', 'procurement:trial', 'projects:trial'])
        ->and($intent->refresh()->outcome)->toBe('trial_started')
        ->and($intent->outcome_details['applications'])->toEqualCanonicalizing(NGO_APPS);
});

it('adds only what is missing, keeping an application the organization already pays for', function () {
    $finance = Application::query()->where('code', 'finance')->sole();
    app(TenantContext::class)->run($this->organization, function () use ($finance) {
        $paid = new Entitlement;
        $paid->forceFill(['application_id' => $finance->id, 'source' => 'manual', 'effect' => 'grant', 'valid_from' => now()->subYear()])->save();
    });

    $intent = ngoStart();
    $this->post($this->authUrl("get-started/selection/{$intent->id}"), ['organization_id' => $this->organization->id])->assertRedirect();

    expect(ngoCurrent($this->organization))->toBe(['finance:manual', 'mel:trial', 'procurement:trial', 'projects:trial']);
});

it('sold as one bundle plan: ending it keeps access granted another way', function () {
    $plan = new Plan;
    $plan->forceFill(['code' => 'ngo-suite', 'name' => 'NGO Suite', 'billing_interval' => 'monthly', 'price_minor' => 10000, 'currency' => 'USD',
        'trial_days' => 0, 'status' => 'active', 'is_public' => false, 'is_bundle' => true])->save();
    $plan->applications()->attach(Application::query()->whereIn('code', NGO_APPS)->pluck('id'));

    $finance = Application::query()->where('code', 'finance')->sole();
    app(TenantContext::class)->run($this->organization, function () use ($finance) {
        $standalone = new Entitlement;
        $standalone->forceFill(['application_id' => $finance->id, 'source' => 'manual', 'effect' => 'grant', 'valid_from' => now()->subYear()])->save();
    });

    $subscription = app(ManageEntitlements::class)->assignPlan($this->organization, $plan, null);
    expect(ngoCurrent($this->organization))->toBe(['finance:manual', 'finance:subscription', 'mel:subscription', 'procurement:subscription', 'projects:subscription'])
        // One subscription for the package, not one per application.
        ->and(app(TenantContext::class)->run($this->organization, fn () => $subscription->items()->count()))->toBe(1);

    app(ManageEntitlements::class)->cancel($this->organization, $subscription, null, 'Bundle ended');

    expect(ngoCurrent($this->organization))->toBe(['finance:manual']);
});
