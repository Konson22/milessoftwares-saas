<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationFeature;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Entitlements\Support\EffectiveFeatures;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class, AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);

    $this->prepareIntegrationFixtures();

    // Retail 2.0.0 declares plan features.
    $definition = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
    $definition['version'] = '2.0.0';
    $definition['features'] = [
        ['key' => 'retail.loyalty', 'name' => 'Loyalty points'],
        ['key' => 'retail.branches', 'name' => 'Branches', 'limit_unit' => 'branches'],
        ['key' => 'retail.kiosk', 'name' => 'Self-service kiosk', 'status' => 'deprecated'],
    ];
    app(CatalogImporter::class)->import($definition);

    $this->billing = $this->staffWith(['billing_admin']);
});

/**
 * @param  list<array{key: string, limit?: int|null}>  $features
 */
function featurePlan(string $code, array $features, array $overrides = []): Plan
{
    test()->actingAs(test()->billing->user)->post(test()->adminUrl('/plans'), array_merge([
        'code' => $code,
        'name' => ucfirst($code),
        'billing_interval' => 'monthly',
        'price_minor' => 2500,
        'currency' => 'USD',
        'trial_days' => 14,
        'status' => 'active',
        'application_ids' => [test()->retail->id],
        'manage_features' => '1',
        'features' => $features,
    ], $overrides))->assertSessionHasNoErrors();

    return Plan::query()->where('code', $code)->sole();
}

/**
 * @param  list<array{key: string, limit?: int|null}>  $features
 */
function updateFeaturePlan(Plan $plan, array $features): void
{
    test()->actingAs(test()->billing->user)->put(test()->adminUrl("/plans/{$plan->id}"), [
        'code' => $plan->code,
        'name' => $plan->name,
        'billing_interval' => 'monthly',
        'price_minor' => 2500,
        'currency' => 'USD',
        'trial_days' => 14,
        'status' => 'active',
        'application_ids' => [test()->retail->id],
        'manage_features' => '1',
        'features' => $features,
    ])->assertSessionHasNoErrors();
}

function subscribeTo(Plan $plan, ?Organization $organization = null, bool $trial = false): Subscription
{
    return app(ManageEntitlements::class)->assignPlan($organization ?? test()->organization, $plan->load('applications'), null, $trial);
}

/**
 * Features as the retail backend receives them for the owner.
 *
 * @return array<string, int|null> key => limit
 */
function servedFeatures(?Organization $organization = null, ?string $token = null): array
{
    $organization ??= test()->organization;
    $subject = OrganizationMembership::query()->withoutGlobalScopes()->whereKey($organization->owner_membership_id)->value('user_id');

    $features = test()->withToken($token ?? test()->serviceToken())
        ->getJson(test()->apiUrl("organizations/{$organization->id}/members/{$subject}/effective-access"))
        ->assertOk()
        ->json('entitlement.features');

    return collect($features)->mapWithKeys(fn (array $feature) => [$feature['key'] => $feature['limit']])->sortKeys()->all();
}

function overrideFeature(string $key, string $effect, array $extra = [], ?Organization $organization = null)
{
    $organization ??= test()->organization;

    return test()->actingAs(test()->billing->user)->post(test()->adminUrl("/organizations/{$organization->id}/features"), [
        'feature_key' => $key,
        'effect' => $effect,
        'reason' => 'Customer request #42',
    ] + $extra);
}

function overrideOf(string $key, ?Organization $organization = null): Entitlement
{
    return app(TenantContext::class)->run($organization ?? test()->organization, fn () => Entitlement::query()
        ->current()->where('feature_key', $key)->where('source', 'manual')->sole());
}

function otherOrganization(): Organization
{
    $organization = Organization::factory()->create();
    $owner = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);
    $organization->forceFill(['owner_membership_id' => $owner->id])->save();

    return $organization;
}

it('registers the features a catalog declares, keyed to the application', function () {
    expect(ApplicationFeature::query()->registered()->orderBy('key')->pluck('limit_unit', 'key')->all())
        ->toBe(['retail.branches' => 'branches', 'retail.kiosk' => null, 'retail.loyalty' => null]);

    $definition = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
    $definition['version'] = '3.0.0';
    $definition['features'] = [['key' => 'finance.multicurrency', 'name' => 'Borrowed']];

    expect(fn () => app(CatalogImporter::class)->import($definition))->toThrow(ValidationException::class);
});

it('lets plans include only declared features of their own applications', function () {
    $this->actingAs($this->billing->user)
        ->get($this->adminUrl('/plans'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('features', 3)->where('features.0.key', 'retail.branches'));

    $base = ['code' => 'bad', 'name' => 'Bad', 'billing_interval' => 'monthly', 'price_minor' => 1, 'currency' => 'USD', 'trial_days' => 0, 'status' => 'active', 'application_ids' => [$this->retail->id]];
    $post = fn (array $features) => $this->actingAs($this->billing->user)->post($this->adminUrl('/plans'), $base + ['features' => $features]);

    $post([['key' => 'retail.made_up']])->assertSessionHasErrors('features.0.key');
    $post([['key' => 'finance.multicurrency']])->assertSessionHasErrors('features.0.key');
    $post([['key' => 'retail.kiosk']])->assertSessionHasErrors('features.0.key');
    $post([['key' => 'retail.loyalty', 'limit' => 5]])->assertSessionHasErrors('features.0.limit');
    $post([['key' => 'retail.loyalty'], ['key' => 'retail.loyalty']])->assertSessionHasErrors('features.1.key');

    // Retail's feature on a plan that sells only Finance.
    app(CatalogImporter::class)->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/finance.json')), true));
    $finance = Application::query()->where('code', 'finance')->sole();
    $this->actingAs($this->billing->user)
        ->post($this->adminUrl('/plans'), ['application_ids' => [$finance->id], 'features' => [['key' => 'retail.loyalty']]] + $base)
        ->assertSessionHasErrors(['features.0.key' => 'The feature [retail.loyalty] needs Retail on the plan.']);

    expect(Plan::query()->where('code', 'bad')->exists())->toBeFalse();

    $plan = featurePlan('retail-plus', [['key' => 'retail.loyalty'], ['key' => 'retail.branches', 'limit' => 3]]);

    expect(DB::table('plan_features')->where('plan_id', $plan->id)->orderBy('feature_key')->pluck('limit_value', 'feature_key')->all())
        ->toBe(['retail.branches' => 3, 'retail.loyalty' => null])
        ->and(DB::table('plan_features')->where('plan_id', $plan->id)->pluck('application_id')->unique()->all())->toBe([$this->retail->id])
        ->and(AuditLog::query()->where('action', 'plan.created')->latest('sequence')->first()->context['features'])->toBe(['retail.loyalty', 'retail.branches']);
});

it('keeps existing plans saving, with or without features', function () {
    $plan = featurePlan('retail-plus', [['key' => 'retail.loyalty']]);

    // A plan form that predates features leaves them alone.
    $this->actingAs($this->billing->user)->put($this->adminUrl("/plans/{$plan->id}"), [
        'code' => 'retail-plus', 'name' => 'Renamed', 'billing_interval' => 'monthly', 'price_minor' => 2500,
        'currency' => 'USD', 'trial_days' => 14, 'status' => 'active', 'application_ids' => [$this->retail->id],
    ])->assertSessionHasNoErrors();

    expect(DB::table('plan_features')->where('plan_id', $plan->id)->pluck('feature_key')->all())->toBe(['retail.loyalty']);

    // A feature the product has since deprecated stays on the plans that have it.
    DB::table('plan_features')->insert(['id' => (string) str()->uuid(), 'plan_id' => $plan->id, 'application_id' => $this->retail->id, 'feature_key' => 'retail.kiosk', 'created_at' => now(), 'updated_at' => now()]);
    updateFeaturePlan($plan, [['key' => 'retail.loyalty'], ['key' => 'retail.kiosk']]);

    // Ticking nothing removes them all.
    updateFeaturePlan($plan, []);
    expect(DB::table('plan_features')->where('plan_id', $plan->id)->count())->toBe(0);
});

it('serves plan features to products, and only with the application', function () {
    $plan = featurePlan('retail-plus', [['key' => 'retail.loyalty'], ['key' => 'retail.branches', 'limit' => 3]]);

    expect(servedFeatures())->toBe([]);

    subscribeTo($plan);
    expect(servedFeatures())->toBe(['retail.branches' => 3, 'retail.loyalty' => null]);

    // No application access, no features: the fixture's manual grant and the plan both end.
    app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->whereNull('feature_key')->update(['revoked_at' => now()]));
    app(TenantContext::class)->run($this->organization, fn () => app(ManageEntitlements::class)->revoke($this->organization, Entitlement::query()->whereNotNull('feature_key')->firstOrFail(), $this->billing, 'test'));
    app(TenantContext::class)->clear();

    expect(servedFeatures())->toBe([]);
});

it('ends trial features with the trial', function () {
    $plan = featurePlan('retail-plus', [['key' => 'retail.loyalty']]);
    subscribeTo($plan, trial: true);

    expect(servedFeatures())->toBe(['retail.loyalty' => null])
        ->and(app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->where('feature_key', 'retail.loyalty')->sole()->source))->toBe('trial');

    $this->travel(15)->days();

    expect(servedFeatures())->toBe([]);
});

it('grants a feature to one organization outside its plan, with reason, expiry and who did it', function () {
    $other = otherOrganization();

    overrideFeature('retail.loyalty', 'grant', ['valid_until' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();

    $grant = overrideOf('retail.loyalty');
    expect(servedFeatures())->toBe(['retail.loyalty' => null])
        ->and($grant->effect)->toBe('grant')
        ->and($grant->reason)->toBe('Customer request #42')
        ->and($grant->created_by)->toBe($this->billing->user_id)
        ->and(app(TenantContext::class)->run($other, fn () => Entitlement::query()->count()))->toBe(0);

    $audit = AuditLog::query()->where('action', 'entitlement.feature_granted')->sole();
    expect($audit->organization_id)->toBe($this->organization->id)
        ->and($audit->actor_id)->toBe($this->billing->user_id)
        ->and($audit->context)->toMatchArray(['feature' => 'retail.loyalty', 'reason' => 'Customer request #42']);

    // A limit for a feature that counts; none for one that is on or off.
    overrideFeature('retail.branches', 'grant', ['limit' => 7])->assertSessionHasNoErrors();
    overrideFeature('retail.loyalty', 'grant', ['limit' => 7])->assertSessionHasErrors('limit');
    expect(servedFeatures())->toBe(['retail.branches' => 7, 'retail.loyalty' => null]);

    $this->travel(11)->days();
    expect(servedFeatures())->toBe(['retail.branches' => 7]);
});

it('lets a denial win over the plan and a grant, and expire back to the plan', function () {
    subscribeTo(featurePlan('retail-plus', [['key' => 'retail.loyalty'], ['key' => 'retail.branches', 'limit' => 3]]));

    overrideFeature('retail.loyalty', 'deny', ['valid_until' => now()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
    overrideFeature('retail.branches', 'deny', ['limit' => 2])->assertSessionHasErrors('limit');

    expect(servedFeatures())->toBe(['retail.branches' => 3]);

    // A new override replaces the one in effect, so at most one is live.
    overrideFeature('retail.loyalty', 'grant')->assertSessionHasNoErrors();
    expect(servedFeatures())->toBe(['retail.branches' => 3, 'retail.loyalty' => null]);
    overrideFeature('retail.loyalty', 'deny', ['valid_until' => now()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
    expect(servedFeatures())->toBe(['retail.branches' => 3])
        ->and(app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->current()->where('source', 'manual')->whereNotNull('feature_key')->count()))->toBe(1);

    $this->travel(6)->days();
    expect(servedFeatures())->toBe(['retail.branches' => 3, 'retail.loyalty' => null]);
});

it('resolves denial, grant, plan, then nothing', function () {
    $row = fn (string $effect, string $source, ?int $limit = null) => (new Entitlement)->forceFill([
        'feature_key' => 'retail.branches', 'effect' => $effect, 'source' => $source, 'limit_value' => $limit, 'valid_from' => now(),
    ]);

    expect(EffectiveFeatures::resolve([]))->toBe([])
        ->and(EffectiveFeatures::resolve([$row('grant', 'subscription', 3), $row('grant', 'trial', 5)])['retail.branches'])->toMatchArray(['granted' => true, 'limit' => 5])
        ->and(EffectiveFeatures::resolve([$row('grant', 'subscription', 3), $row('grant', 'subscription')])['retail.branches']['limit'])->toBeNull()
        ->and(EffectiveFeatures::resolve([$row('grant', 'subscription', 3), $row('grant', 'manual', 1)])['retail.branches'])->toMatchArray(['granted' => true, 'source' => 'manual', 'limit' => 1])
        ->and(EffectiveFeatures::resolve([$row('grant', 'manual'), $row('deny', 'manual'), $row('grant', 'subscription')])['retail.branches'])->toMatchArray(['granted' => false, 'source' => 'denied']);
});

it('applies plan changes to active subscribers without touching their overrides', function () {
    $plan = featurePlan('retail-plus', [['key' => 'retail.loyalty'], ['key' => 'retail.branches', 'limit' => 3]]);
    subscribeTo($plan);
    $other = otherOrganization();
    subscribeTo($plan, $other);
    $cancelled = otherOrganization();
    app(ManageEntitlements::class)->cancel($cancelled, subscribeTo($plan, $cancelled), null, 'left');

    overrideFeature('retail.loyalty', 'grant')->assertSessionHasNoErrors();
    overrideFeature('retail.branches', 'deny', organization: $other)->assertSessionHasNoErrors();

    // Downgrade: loyalty leaves the plan, branches drop to 2.
    updateFeaturePlan($plan, [['key' => 'retail.branches', 'limit' => 2]]);

    expect(servedFeatures())->toBe(['retail.branches' => 2, 'retail.loyalty' => null])
        ->and(servedFeatures($other))->toBe([])
        ->and(app(TenantContext::class)->run($cancelled, fn () => Entitlement::query()->current()->count()))->toBe(0)
        ->and(AuditLog::query()->where('action', 'plan.updated')->latest('sequence')->first()->context['features'])
        ->toEqual(['removed' => ['retail.loyalty'], 'limits' => ['retail.branches' => ['from' => 3, 'to' => 2]]]);

    // Upgrade: loyalty back on the plan.
    updateFeaturePlan($plan, [['key' => 'retail.branches', 'limit' => 2], ['key' => 'retail.loyalty']]);
    $this->actingAs($this->billing->user)->post($this->adminUrl("/organizations/{$this->organization->id}/entitlements/".overrideOf('retail.loyalty')->id.'/revoke'), ['reason' => 'Plan covers it'])->assertSessionHasNoErrors();

    expect(servedFeatures())->toBe(['retail.branches' => 2, 'retail.loyalty' => null])
        ->and(servedFeatures($other))->toBe(['retail.loyalty' => null])
        ->and(overrideOf('retail.branches', $other)->effect)->toBe('deny');
});

it('syncs idempotently and catches up after a failed run', function () {
    $plan = featurePlan('retail-plus', [['key' => 'retail.loyalty']]);
    subscribeTo($plan);
    $rows = fn () => app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->whereNotNull('feature_key')->count());

    // The plan changes but the queued sync never ran (worker down).
    DB::table('plan_features')->insert(['id' => (string) str()->uuid(), 'plan_id' => $plan->id, 'application_id' => $this->retail->id, 'feature_key' => 'retail.branches', 'limit_value' => 4, 'created_at' => now(), 'updated_at' => now()]);
    expect(servedFeatures())->toBe(['retail.loyalty' => null]);

    expect(Artisan::call('platform:sync-plan-features'))->toBe(0)
        ->and(servedFeatures())->toBe(['retail.branches' => 4, 'retail.loyalty' => null])
        ->and($rows())->toBe(2);

    $synced = AuditLog::query()->where('action', 'subscription.features_synced')->count();

    expect(app(ManageEntitlements::class)->syncPlanFeatures($plan))->toBe(0)
        ->and(app(ManageEntitlements::class)->syncPlanFeatures($plan))->toBe(0)
        ->and($rows())->toBe(2)
        ->and(AuditLog::query()->where('action', 'subscription.features_synced')->count())->toBe($synced);
});

it('drops cached access as soon as a feature changes', function () {
    $token = $this->serviceToken();
    subscribeTo(featurePlan('retail-plus', [['key' => 'retail.loyalty']]));

    expect(servedFeatures(token: $token))->toBe(['retail.loyalty' => null]);

    overrideFeature('retail.loyalty', 'deny')->assertSessionHasNoErrors();

    expect(servedFeatures(token: $token))->toBe([]);
});

it('keeps plan features to the plan, and overrides to staff who manage entitlements', function () {
    subscribeTo(featurePlan('retail-plus', [['key' => 'retail.loyalty']]));
    $planRow = app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->where('feature_key', 'retail.loyalty')->sole());

    // Revoking a plan's feature row would be undone by the next sync: deny instead.
    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/entitlements/{$planRow->id}/revoke"), ['reason' => 'x'])
        ->assertSessionHasErrors('reason');

    overrideFeature('retail.unknown', 'grant')->assertSessionHasErrors('feature_key');

    $this->actingAs($this->staffWith(['support_manager'])->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/features"), ['feature_key' => 'retail.loyalty', 'effect' => 'deny', 'reason' => 'x'])
        ->assertForbidden();
    $this->actingAs($this->staffWith(['support_agent'])->user)
        ->post($this->adminUrl('/plans'), ['code' => 'x'])
        ->assertForbidden();
    $this->actingAs($this->billing->user)
        ->post($this->adminUrl('/organizations/'.str()->uuid().'/features'), ['feature_key' => 'retail.loyalty', 'effect' => 'deny', 'reason' => 'x'])
        ->assertNotFound();

    // The organization's own people have no route to it.
    $this->actingAs($this->owner->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/features"), ['feature_key' => 'retail.loyalty', 'effect' => 'grant', 'reason' => 'x'])
        ->assertForbidden();

    expect(servedFeatures())->toBe(['retail.loyalty' => null]);
});

it('shows staff each feature as products see it', function () {
    subscribeTo(featurePlan('retail-plus', [['key' => 'retail.branches', 'limit' => 3]]));
    overrideFeature('retail.loyalty', 'grant')->assertSessionHasNoErrors();

    $this->actingAs($this->billing->user)
        ->get($this->adminUrl("/organizations/{$this->organization->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('features.0.key', 'retail.branches')
            ->where('features.0.source', 'subscription')
            ->where('features.0.limit', 3)
            ->where('features.1.key', 'retail.kiosk')
            ->where('features.1.granted', false)
            ->where('features.2.key', 'retail.loyalty')
            ->where('features.2.source', 'manual')
            ->where('features.2.override.by', $this->billing->user->name)
            ->where('features.2.override.reason', 'Customer request #42'));
});
