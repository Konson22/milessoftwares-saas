<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Plan;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000, 'platform.marketing.trial_days' => 60]);

    $importer = app(CatalogImporter::class);
    $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
    $importer->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));
    $this->retail = Application::query()->where('code', 'retail')->sole();
    $this->retail->forceFill(['status' => 'available', 'trial_enabled' => true])->save();

    [$this->organization, $this->owner] = trialOrganization();
});

/**
 * @return array{Organization, OrganizationMembership}
 */
function trialOrganization(): array
{
    $organization = Organization::factory()->create();
    $owner = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);
    $organization->forceFill(['owner_membership_id' => $owner->id])->save();

    return [$organization, $owner];
}

function trialAppUrl(string $path): string
{
    return PlatformHosts::url(PlatformHosts::app(), $path);
}

function trialWorkAs(OrganizationMembership $membership): void
{
    test()->actingAs($membership->user)->post(trialAppUrl("organizations/{$membership->organization_id}/switch"));
}

/**
 * @return Collection<int, Entitlement>
 */
function trialRows(Organization $organization, Application $application)
{
    return app(TenantContext::class)->run($organization, fn () => Entitlement::query()
        ->where('application_id', $application->id)
        ->whereNull('feature_key')
        ->get());
}

function trialIsCurrent(Organization $organization, Application $application): bool
{
    return app(TenantContext::class)->run($organization, fn () => Entitlement::query()
        ->current()
        ->where('application_id', $application->id)
        ->whereNull('feature_key')
        ->exists());
}

describe('the trial page', function () {
    it('sends guests to sign in', function () {
        $this->get(trialAppUrl('trial?app=retail'))->assertRedirect();

        expect(trialRows($this->organization, $this->retail))->toHaveCount(0);
    });

    it('sends someone without a business to create one, keeping the product', function () {
        $user = OrganizationMembership::factory()->make()->user;

        $this->actingAs($user)->get(trialAppUrl('trial?app=retail'))
            ->assertRedirect(route('organizations.create', ['app' => 'retail']));
    });

    it('shows the owner what the business may try, and starts nothing on a GET', function () {
        trialWorkAs($this->owner);

        $this->get(trialAppUrl('trial?app=retail'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('central/trial')
            ->where('isOwner', true)
            ->where('trialDays', 60)
            ->where('selected', 'retail')
            ->where('applications', fn ($applications) => collect($applications)->firstWhere('code', 'retail')['state'] === 'eligible'));

        expect(trialRows($this->organization, $this->retail))->toHaveCount(0);
    });

    it('lists nothing to try for a member who is not the owner', function () {
        $member = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
        trialWorkAs($member);

        $this->get(trialAppUrl('trial'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('isOwner', false)
            ->where('applications', []));
    });
});

describe('starting a trial', function () {
    it('starts a 60-day trial for the owner, counted on the server', function () {
        $this->freezeSecond();
        trialWorkAs($this->owner);

        $this->post(trialAppUrl('trial'), ['application' => 'retail', 'valid_until' => now()->addYears(5)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $trial = trialRows($this->organization, $this->retail)->sole();

        expect($trial->source)->toBe('trial')
            ->and($trial->valid_from->equalTo(now()))->toBeTrue()
            ->and($trial->valid_until->equalTo(now()->addDays(60)))->toBeTrue()
            ->and(trialIsCurrent($this->organization, $this->retail))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'entitlement.trial_started')->get()->firstWhere('context.application', 'retail'))->not->toBeNull();
    });

    it('treats a repeated confirmation as the same trial', function () {
        trialWorkAs($this->owner);

        $this->post(trialAppUrl('trial'), ['application' => 'retail'])->assertSessionHasNoErrors();
        $this->post(trialAppUrl('trial'), ['application' => 'retail'])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

        expect(trialRows($this->organization, $this->retail))->toHaveCount(1);
    });

    it('never gives a second trial once one has ended', function () {
        trialWorkAs($this->owner);
        $this->post(trialAppUrl('trial'), ['application' => 'retail']);

        $this->travel(61)->days();

        expect(trialIsCurrent($this->organization, $this->retail))->toBeFalse();

        trialWorkAs($this->owner);
        $this->post(trialAppUrl('trial'), ['application' => 'retail'])->assertSessionHasErrors('application');

        expect(trialRows($this->organization, $this->retail))->toHaveCount(1);
    });

    it('refuses a trial of an application the business already has', function () {
        app(TenantContext::class)->run($this->organization, function () {
            (new Entitlement)->forceFill(['application_id' => $this->retail->id, 'source' => 'manual', 'valid_from' => now()->subDay()])->save();
        });
        trialWorkAs($this->owner);

        $this->post(trialAppUrl('trial'), ['application' => 'retail'])->assertSessionHasErrors('application');

        expect(trialRows($this->organization, $this->retail)->where('source', 'trial'))->toHaveCount(0);
    });

    it('refuses a product the admin has closed to trials', function () {
        $this->retail->forceFill(['trial_enabled' => false])->save();
        trialWorkAs($this->owner);

        $this->post(trialAppUrl('trial'), ['application' => 'retail'])->assertSessionHasErrors('application');

        expect(trialRows($this->organization, $this->retail))->toHaveCount(0)
            ->and(array_column(app(MarketingCatalog::class)->trialable(), 'code'))->not->toContain('retail');
    });

    it('refuses applications that are not released', function (string $status) {
        $this->retail->forceFill(['status' => $status])->save();
        trialWorkAs($this->owner);

        $this->post(trialAppUrl('trial'), ['application' => 'retail'])->assertSessionHasErrors('application');
    })->with(['planned', 'retired']);

    it('lets only the owner start a trial', function () {
        $member = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
        trialWorkAs($member);

        $this->post(trialAppUrl('trial'), ['application' => 'retail'])->assertForbidden();

        expect(trialRows($this->organization, $this->retail))->toHaveCount(0);
    });

    it('starts the trial in the current business only, never another', function () {
        [$other] = trialOrganization();
        trialWorkAs($this->owner);

        $this->post(trialAppUrl('trial'), ['application' => 'retail', 'organization_id' => $other->id])->assertSessionHasNoErrors();

        expect(trialRows($this->organization, $this->retail))->toHaveCount(1)
            ->and(trialRows($other, $this->retail))->toHaveCount(0);
    });

    it('cannot be started by a GET', function () {
        trialWorkAs($this->owner);

        $this->get(trialAppUrl('trial?app=retail&confirm=1'))->assertOk();

        expect(trialRows($this->organization, $this->retail))->toHaveCount(0);
    });
});

describe('after the trial', function () {
    it('shows the running trial and its days left on the dashboard', function () {
        trialWorkAs($this->owner);
        $this->post(trialAppUrl('trial'), ['application' => 'retail']);
        $this->travel(10)->days();

        trialWorkAs($this->owner);
        $this->get(trialAppUrl('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('current.billing.trials', fn ($trials) => collect($trials)->firstWhere('code', 'retail')['daysLeft'] === 50));
    });

    it('converts to a paid plan, keeping the data and recording the conversion', function () {
        trialWorkAs($this->owner);
        $this->post(trialAppUrl('trial'), ['application' => 'retail']);

        $plan = new Plan;
        $plan->forceFill([
            'code' => 'retail-standard', 'name' => 'Retail Standard', 'billing_interval' => 'monthly',
            'price_minor' => 2500, 'currency' => 'USD', 'status' => 'active', 'is_public' => true,
        ])->save();
        $plan->applications()->attach($this->retail->id);

        app(ManageEntitlements::class)->assignPlan($this->organization, $plan->load('applications'), null);

        $rows = trialRows($this->organization, $this->retail);

        expect($rows->firstWhere('source', 'trial')->revoked_at)->not->toBeNull()
            ->and($rows->firstWhere('source', 'subscription'))->not->toBeNull()
            ->and(trialIsCurrent($this->organization, $this->retail))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'entitlement.trial_converted')->sole()->context['plan'] ?? null)->not->toBeNull();
    });
});

it('lets catalog admins close a product to trials', function () {
    $this->actingAs($this->staffWith(['catalog_admin'])->user)
        ->put($this->adminUrl("/applications/{$this->retail->id}"), [
            'name' => 'Retail',
            'status' => 'available',
            'sort_order' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect($this->retail->refresh()->trial_enabled)->toBeFalse();

    $this->actingAs($this->staffWith(['catalog_admin'])->user)
        ->put($this->adminUrl("/applications/{$this->retail->id}"), [
            'name' => 'Retail',
            'status' => 'available',
            'sort_order' => 1,
            'trial_enabled' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($this->retail->refresh()->trial_enabled)->toBeTrue();
});
