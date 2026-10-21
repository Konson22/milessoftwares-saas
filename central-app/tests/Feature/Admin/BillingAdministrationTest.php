<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationCatalogVersion;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);

    $importer = app(CatalogImporter::class);
    $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
    $importer->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));
    $this->retail = Application::query()->where('code', 'retail')->sole();

    $this->organization = Organization::factory()->create();
    $this->owner = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    $this->organization->forceFill(['owner_membership_id' => $this->owner->id])->save();

    $this->billing = $this->staffWith(['billing_admin']);
});

function createPlan(array $overrides = []): Plan
{
    test()->actingAs(test()->billing->user)->post(test()->adminUrl('/plans'), array_merge([
        'code' => 'retail-standard',
        'name' => 'Retail Standard',
        'billing_interval' => 'monthly',
        'price_minor' => 2500,
        'currency' => 'usd',
        'trial_days' => 14,
        'status' => 'active',
        'application_ids' => [test()->retail->id],
    ], $overrides))->assertSessionHasNoErrors();

    return Plan::query()->latest()->firstOrFail();
}

function entitlementsFor(Organization $organization)
{
    return app(TenantContext::class)->run($organization, fn () => Entitlement::query()->get());
}

function canAssignCashier(Organization $organization, OrganizationMembership $owner): bool
{
    return app(TenantContext::class)->run($organization, function () use ($owner) {
        $branch = Branch::query()->firstOrCreate(['code' => 'JUB'], ['name' => 'Juba']);
        $cashier = ApplicationRole::query()->where('key', 'cashier')->where('catalog_version_id', test()->retail->refresh()->current_catalog_version_id)->sole();

        try {
            app(AssignRole::class)->handle($owner, OrganizationMembership::factory()->create(['organization_id' => $owner->organization_id]), $cashier, Scope::branch($branch->id));

            return true;
        } catch (AssignmentDenied $e) {
            expect($e->reason)->toBe('not_entitled');

            return false;
        }
    });
}

it('creates a plan covering one application', function () {
    $plan = createPlan();

    expect($plan->currency)->toBe('USD')
        ->and($plan->is_bundle)->toBeFalse()
        ->and($plan->applications()->pluck('code')->all())->toBe(['retail']);
});

it('does not sell the platform application', function () {
    $this->actingAs($this->billing->user)->post($this->adminUrl('/plans'), [
        'code' => 'sneaky', 'name' => 'Sneaky', 'billing_interval' => 'monthly', 'price_minor' => 0,
        'currency' => 'USD', 'trial_days' => 0, 'status' => 'active',
        'application_ids' => [Application::query()->where('code', 'platform')->value('id')],
    ])->assertSessionHasErrors('application_ids.0');
});

it('turns an assigned plan into entitlements that unlock role assignment', function () {
    expect(canAssignCashier($this->organization, $this->owner))->toBeFalse();

    $plan = createPlan();
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/subscriptions"), ['plan_id' => $plan->id])->assertSessionHasNoErrors();

    $entitlement = entitlementsFor($this->organization)->sole();
    expect($entitlement->application_id)->toBe($this->retail->id)
        ->and($entitlement->source)->toBe('subscription')
        ->and($entitlement->valid_until)->toBeNull();

    expect(canAssignCashier($this->organization, $this->owner))->toBeTrue();
});

it('ends trial entitlements when the trial does', function () {
    $plan = createPlan();
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/subscriptions"), ['plan_id' => $plan->id, 'trial' => '1']);

    $entitlement = entitlementsFor($this->organization)->sole();
    expect($entitlement->source)->toBe('trial')
        ->and($entitlement->valid_until->isSameDay(now()->addDays(14)))->toBeTrue();

    $this->travel(15)->days();

    expect(canAssignCashier($this->organization, $this->owner))->toBeFalse();
});

it('revokes the entitlements of a cancelled subscription', function () {
    $plan = createPlan();
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/subscriptions"), ['plan_id' => $plan->id]);
    $subscription = app(TenantContext::class)->run($this->organization, fn () => Subscription::query()->sole());

    $this->post($this->adminUrl("/organizations/{$this->organization->id}/subscriptions/{$subscription->id}/cancel"), ['reason' => 'Customer request'])
        ->assertSessionHasNoErrors();

    expect(entitlementsFor($this->organization)->sole()->revoked_at)->not->toBeNull()
        ->and(canAssignCashier($this->organization, $this->owner))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'subscription.cancelled')->value('organization_id'))->toBe($this->organization->id);
});

it('refuses draft plans', function () {
    $plan = createPlan(['status' => 'draft']);

    $this->post($this->adminUrl("/organizations/{$this->organization->id}/subscriptions"), ['plan_id' => $plan->id])
        ->assertSessionHasErrors('plan_id');
});

it('grants and revokes manual entitlements with a reason', function () {
    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/entitlements"), [
            'application_id' => $this->retail->id, 'reason' => 'Pilot customer',
        ])
        ->assertSessionHasNoErrors();

    $entitlement = entitlementsFor($this->organization)->sole();
    expect($entitlement->source)->toBe('manual')
        ->and(canAssignCashier($this->organization, $this->owner))->toBeTrue();

    $this->post($this->adminUrl("/organizations/{$this->organization->id}/entitlements/{$entitlement->id}/revoke"), ['reason' => 'Pilot over'])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', 'entitlement.granted')->value('context')['reason'])->toBe('Pilot customer')
        ->and(canAssignCashier($this->organization, $this->owner))->toBeFalse();
});

it('returns 404 for another organization\'s subscription or entitlement', function () {
    $other = Organization::factory()->create();
    $plan = createPlan();
    $this->post($this->adminUrl("/organizations/{$other->id}/subscriptions"), ['plan_id' => $plan->id]);
    $foreignSubscription = app(TenantContext::class)->run($other, fn () => Subscription::query()->sole());
    $foreignEntitlement = entitlementsFor($other)->sole();

    $this->post($this->adminUrl("/organizations/{$this->organization->id}/subscriptions/{$foreignSubscription->id}/cancel"), ['reason' => 'x'])->assertNotFound();
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/entitlements/{$foreignEntitlement->id}/revoke"), ['reason' => 'x'])->assertNotFound();

    expect(entitlementsFor($other)->sole()->revoked_at)->toBeNull();
});

it('keeps billing and registry permissions apart', function () {
    $this->actingAs($this->billing->user)->get($this->adminUrl('/applications'))->assertForbidden();
    $this->actingAs($this->staffWith(['catalog_admin'])->user)->get($this->adminUrl('/plans'))->assertForbidden();
    $this->actingAs($this->staffWith(['support_agent'])->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/entitlements"), ['application_id' => $this->retail->id, 'reason' => 'x'])
        ->assertForbidden();
});

it('imports a catalog file from the registry page', function () {
    $catalogAdmin = $this->staffWith(['catalog_admin']);
    $definition = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
    $definition['version'] = '2.0.0';

    $this->actingAs($catalogAdmin->user)
        ->post($this->adminUrl('/applications/catalogs'), ['catalog' => UploadedFile::fake()->createWithContent('retail.json', json_encode($definition))])
        ->assertSessionHasNoErrors();

    $current = ApplicationCatalogVersion::query()->find($this->retail->refresh()->current_catalog_version_id);

    expect($current->version)->toBe('2.0.0');
});

it('reports catalog conflicts on upload', function () {
    $catalogAdmin = $this->staffWith(['catalog_admin']);
    $definition = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
    $definition['roles'][0]['name'] = 'Changed without a new version';

    $this->actingAs($catalogAdmin->user)
        ->post($this->adminUrl('/applications/catalogs'), ['catalog' => UploadedFile::fake()->createWithContent('retail.json', json_encode($definition))])
        ->assertSessionHasErrors('catalog');
});

it('explains the plan code format', function () {
    $this->actingAs($this->billing->user)->post($this->adminUrl('/plans'), [
        'code' => 'Finance Standard', 'name' => 'Finance Standard', 'billing_interval' => 'monthly', 'price_minor' => 0,
        'currency' => 'USD', 'trial_days' => 0, 'status' => 'draft', 'application_ids' => [$this->retail->id],
    ])->assertSessionHasErrors(['code' => 'The code must start with a lowercase letter and use only lowercase letters, digits, - and _ (for example finance-standard).']);
});
