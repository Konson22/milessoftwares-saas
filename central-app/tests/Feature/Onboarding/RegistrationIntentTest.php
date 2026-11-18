<?php

use App\Models\User;
use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Products\Support\ProductCatalog;
use App\Modules\Subscriptions\Actions\RegistrationIntents;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\RegistrationIntent;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Support\IntentRefused;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * Registration intents: a product, ERP product (NGO) or plan chosen on the
 * public site survives registering, verifying, signing in and choosing the
 * organization, and is applied through the existing trial and plan
 * lifecycle exactly once.
 */

beforeEach(function () {
    Notification::fake();
    config(['platform.marketing.trial_days' => 60]);

    $importer = app(CatalogImporter::class);
    $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
    $importer->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));
    $this->retail = Application::query()->where('code', 'retail')->sole();
    $this->retail->forceFill(['status' => 'available', 'trial_enabled' => true, 'route_prefix' => 'retail'])->save();

    [$this->organization, $this->owner] = intentOrganization();
});

/**
 * @return array{Organization, OrganizationMembership}
 */
function intentOrganization(?User $user = null): array
{
    $organization = Organization::factory()->create();
    $owner = OrganizationMembership::factory()->create(['organization_id' => $organization->id] + ($user === null ? [] : ['user_id' => $user->id]));
    $organization->forceFill(['owner_membership_id' => $owner->id])->save();

    return [$organization, $owner];
}

/** Opens a public-site link as a guest; returns the token it carries. */
function intentLink(string $query): string
{
    $response = test()->get(test()->authUrl('trial?'.$query))->assertRedirect();
    preg_match('#get-started/([A-Za-z0-9]{48})$#', (string) $response->headers->get('Location'), $match);
    $token = $match[1] ?? throw new LogicException('No intent link: '.$response->headers->get('Location'));

    // The browser follows it to the page with the token.
    test()->get((string) $response->headers->get('Location'));

    return $token;
}

function intentRetailCurrent(Organization $organization, Application $application): bool
{
    return app(TenantContext::class)->run($organization, fn () => Entitlement::query()->current()
        ->where('application_id', $application->id)->whereNull('feature_key')->exists());
}

function intentEntitlements(Organization $organization, Application $application): int
{
    return app(TenantContext::class)->run($organization, fn () => Entitlement::query()
        ->where('application_id', $application->id)->whereNull('feature_key')->count());
}

function intentPlan(array $attributes = []): Plan
{
    $plan = new Plan;
    $plan->forceFill(array_merge([
        'code' => 'retail-standard', 'name' => 'Retail Standard', 'billing_interval' => 'monthly',
        'price_minor' => 2500, 'currency' => 'USD', 'trial_days' => 14, 'status' => 'active', 'is_public' => true,
    ], $attributes))->save();
    $plan->applications()->attach(Application::query()->where('code', 'retail')->sole()->id);

    return $plan;
}

function intentStore(RegistrationIntent|string $intent, Organization $organization): TestResponse
{
    $id = $intent instanceof RegistrationIntent ? $intent->id : $intent;

    return test()->post(test()->authUrl("get-started/selection/{$id}"), ['organization_id' => $organization->id]);
}

describe('choosing on the public site', function () {
    it('keeps the choice as an intent behind an opaque token, granting nothing', function () {
        $token = intentLink('app=retail&utm_source=newsletter&utm_campaign=launch');

        $intent = RegistrationIntent::query()->sole();
        expect($intent->token_hash)->toBe(hash('sha256', $token))
            ->and($intent->token_hash)->not->toContain($token)
            ->and([$intent->target_type, $intent->target_code, $intent->action])->toBe(['application', 'retail', 'trial'])
            ->and($intent->campaign)->toBe(['utm_source' => 'newsletter', 'utm_campaign' => 'launch'])
            ->and($intent->user_id)->toBeNull()
            ->and($intent->expires_at->isAfter(now()->addDays(6)))->toBeTrue()
            ->and(intentEntitlements($this->organization, $this->retail))->toBe(0)
            ->and(AuditLog::query()->where('action', 'registration_intent.created')->count())->toBe(1);

        $this->get($this->authUrl("get-started/{$token}"))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('central/auth/get-started')
            ->where('state', 'guest')
            ->where('selection.name', 'Retail'));
    });

    it('creates nothing for codes the catalog does not offer, or tampered ones', function (string $query) {
        $this->get($this->authUrl('trial?'.$query))->assertRedirect(PlatformHosts::url(PlatformHosts::www(), 'start'));

        expect(RegistrationIntent::query()->count())->toBe(0);
    })->with([
        'unknown application' => 'app=nothing_here',
        'not open to trials' => 'app=workspace',
        'unknown product' => 'product=nothing',
        'bad characters' => 'app=retail%27%3B--',
        'no choice' => '',
    ]);

    it('refuses a plan trial when the plan has none, and a product that is not released', function () {
        intentPlan(['code' => 'no-trial', 'trial_days' => 0]);
        app(ProductCatalog::class)->sync(['code' => 'ngo', 'name' => 'NGO ERP', 'status' => 'planned', 'sort_order' => 1, 'applications' => [['code' => 'retail', 'requirement' => 'required']]]);

        $this->get($this->authUrl('get-started?plan=no-trial'))->assertRedirect();
        $this->get($this->authUrl('trial?product=ngo'))->assertRedirect();

        expect(RegistrationIntent::query()->count())->toBe(0);
    });

    it('never takes a destination from the link', function () {
        $token = intentLink('app=retail&redirect=https://evil.example&to=https://evil.example');
        $this->actingAs($this->owner->user)->get($this->authUrl("get-started/{$token}"));

        $intent = RegistrationIntent::query()->sole();
        intentStore($intent, $this->organization)->assertRedirect(test()->businessUrl('/retail', $this->organization));

        // Even a tampered stored destination stays inside the workspace.
        $intent->forceFill(['outcome_details' => ['destination' => '//evil.example/x']])->save();
        expect(RegistrationIntents::destination($intent->refresh()))->toBe(test()->businessUrl('/', $this->organization));
    });
});

describe('signing up and signing in', function () {
    it('carries a new account through registration and email verification to the choice', function () {
        $token = intentLink('app=retail');

        $this->post(route('register.store'), [
            'name' => 'Achol Deng', 'email' => 'achol@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect($this->authUrl("get-started/{$token}"));

        $user = User::query()->where('email', 'achol@example.com')->sole();

        // Unverified: claimed for this account, then off to verify first.
        $this->get($this->authUrl("get-started/{$token}"))->assertRedirect(route('verification.notice'));
        $intent = RegistrationIntent::query()->sole();
        expect($intent->user_id)->toBe($user->id);

        // Verified on another device: signing in here resumes it.
        $user->markEmailAsVerified();
        $this->app['session']->flush();
        $this->actingAs($user->refresh())->get(route('workspaces.continue'))
            ->assertRedirect(route('get-started.show', ['intent' => $intent->id]));

        $this->get(route('get-started.show', ['intent' => $intent->id]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('central/get-started')
            ->where('state', 'choose')
            ->where('selection.name', 'Retail')
            ->has('organizations', 0)
            ->where('createUrl', fn ($url) => str_contains((string) $url, 'intent='.$intent->id)));
    });

    it('brings an existing account back to the choice after signing in', function () {
        $token = intentLink('app=retail');
        $user = $this->owner->user;

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($this->authUrl("get-started/{$token}"));

        $intent = RegistrationIntent::query()->sole();
        $this->get($this->authUrl("get-started/{$token}"))->assertRedirect(route('get-started.show', ['intent' => $intent->id]));
        expect($intent->refresh()->user_id)->toBe($user->id);
    });

    it('keeps two tabs apart: each choice is its own intent', function () {
        $first = intentLink('app=retail');
        intentPlan();
        $second = intentLink('plan=retail-standard');
        $this->get($this->authUrl("get-started/{$first}"));
        $this->get($this->authUrl("get-started/{$second}"));

        $this->actingAs($this->owner->user)->get(route('workspaces.continue'))->assertRedirect();

        $intents = RegistrationIntent::query()->orderBy('created_at')->get();
        expect($intents)->toHaveCount(2)
            ->and($intents->pluck('user_id')->unique()->all())->toBe([$this->owner->user_id])
            ->and($intents->pluck('target_type')->all())->toBe(['application', 'plan']);

        $this->get(route('get-started.show', ['intent' => $intents[0]->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selection.name', 'Retail')
            ->has('others', 1)
            ->where('others.0.name', 'Retail Standard'));
    });

    it('leaves sign-in without a choice as it was', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('workspaces.continue'))->assertRedirect(route('organizations.create'));
    });

    it('never lets another account use or see a claimed choice', function () {
        $token = intentLink('app=retail');
        $this->actingAs($this->owner->user)->get($this->authUrl("get-started/{$token}"));
        $intent = RegistrationIntent::query()->sole();

        [$theirs] = intentOrganization($stranger = User::factory()->create());
        $this->actingAs($stranger)->get($this->authUrl("get-started/{$token}"))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'other_account'));
        $this->get(route('get-started.show', ['intent' => $intent->id]))->assertNotFound();
        intentStore($intent, $theirs)->assertNotFound();

        expect($intent->refresh()->user_id)->toBe($this->owner->user_id)
            ->and(intentEntitlements($theirs, $this->retail))->toBe(0);
    });
});

describe('choosing the organization and starting', function () {
    beforeEach(function () {
        $token = intentLink('app=retail');
        $this->actingAs($this->owner->user)->get($this->authUrl("get-started/{$token}"));
        $this->intent = RegistrationIntent::query()->sole();
    });

    it('starts the trial in the chosen organization and opens the product there', function () {
        intentStore($this->intent, $this->organization)
            ->assertRedirect(test()->businessUrl('/retail', $this->organization));

        $intent = $this->intent->refresh();
        expect(intentRetailCurrent($this->organization, $this->retail))->toBeTrue()
            ->and($intent->status)->toBe('completed')
            ->and($intent->outcome)->toBe('trial_started')
            ->and($intent->organization_id)->toBe($this->organization->id)
            ->and(AuditLog::query()->where('action', 'registration_intent.completed')->where('organization_id', $this->organization->id)->count())->toBe(1);

        $this->get(route('get-started.show', ['intent' => $intent->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'completed')
            ->where('completed.url', test()->businessUrl('/retail', $this->organization)));
    });

    it('asks for an explicit choice when there are several organizations', function () {
        intentOrganization($this->owner->user);

        $this->get(route('get-started.show', ['intent' => $this->intent->id]))->assertInertia(fn (AssertableInertia $page) => $page->has('organizations', 2));
        $this->post($this->authUrl("get-started/selection/{$this->intent->id}"), [])->assertSessionHasErrors('organization_id');

        expect($this->intent->refresh()->status)->toBe('pending');
    });

    it('starts once: a repeated or retried confirmation changes nothing', function () {
        intentStore($this->intent, $this->organization)->assertRedirect();
        intentStore($this->intent, $this->organization)->assertRedirect(test()->businessUrl('/retail', $this->organization));
        app(RegistrationIntents::class)->complete($this->intent->refresh(), $this->owner->user, $this->organization);

        expect(intentEntitlements($this->organization, $this->retail))->toBe(1)
            ->and(AuditLog::query()->where('action', 'registration_intent.completed')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'entitlement.trial_started')->count())->toBe(1);
    });

    it('cannot be reused for a second organization', function () {
        [$second] = intentOrganization($this->owner->user);
        intentStore($this->intent, $this->organization)->assertRedirect();

        intentStore($this->intent, $second)->assertSessionHasErrors('organization_id');
        expect(intentEntitlements($second, $this->retail))->toBe(0);
    });

    it('refuses an organization the account is not the owner of', function () {
        [$theirs] = intentOrganization();
        OrganizationMembership::factory()->create(['organization_id' => $theirs->id, 'user_id' => $this->owner->user_id]);

        intentStore($this->intent, $theirs)->assertSessionHasErrors('organization_id');
        expect(session('errors')->first('organization_id'))->toContain('Only the owner');
        expect(intentEntitlements($theirs, $this->retail))->toBe(0)
            ->and($this->intent->refresh()->status)->toBe('pending');
    });

    it('refuses an organization the account is not a member of', function () {
        [$theirs] = intentOrganization();

        intentStore($this->intent, $theirs)->assertSessionHasErrors('organization_id');
        expect(intentEntitlements($theirs, $this->retail))->toBe(0);
    });

    it('fails safely once expired', function () {
        $this->intent->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(route('get-started.show', ['intent' => $this->intent->id]))->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired'));
        intentStore($this->intent, $this->organization)->assertSessionHasErrors('organization_id');
        expect(session('errors')->first('organization_id'))->toContain('expired');
        expect(intentEntitlements($this->organization, $this->retail))->toBe(0);
    });

    it('rechecks the catalog: a product closed to trials since cannot be started', function () {
        $this->retail->forceFill(['trial_enabled' => false])->save();

        intentStore($this->intent, $this->organization)->assertSessionHasErrors('organization_id');
        expect(intentEntitlements($this->organization, $this->retail))->toBe(0);
    });

    it('keeps a standalone subscription the organization already has', function () {
        $manual = app(TenantContext::class)->run($this->organization, function () {
            $entitlement = new Entitlement;
            $entitlement->forceFill(['application_id' => $this->retail->id, 'source' => 'manual', 'effect' => 'grant', 'valid_from' => now()->subMonth()])->save();

            return $entitlement;
        });

        intentStore($this->intent, $this->organization)->assertRedirect();

        expect($this->intent->refresh()->outcome)->toBe('already_active')
            ->and(intentEntitlements($this->organization, $this->retail))->toBe(1)
            ->and($manual->refresh()->source)->toBe('manual');
    });

    it('does not give a second trial', function () {
        app(TenantContext::class)->run($this->organization, function () {
            $old = new Entitlement;
            $old->forceFill(['application_id' => $this->retail->id, 'source' => 'trial', 'effect' => 'grant', 'valid_from' => now()->subDays(70), 'valid_until' => now()->subDay()])->save();
        });

        intentStore($this->intent, $this->organization)->assertSessionHasErrors('organization_id');
        expect(session('errors')->first('organization_id'))->toContain('already had its free trial');
        expect($this->intent->refresh()->status)->toBe('pending');
    });

    it('starts in a new organization created for it', function () {
        $this->get($this->authUrl('workspaces/create?intent='.$this->intent->id))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('intent.name', 'Retail'));

        $this->post($this->authUrl('workspaces'), [
            'display_name' => 'Gudele Shop', 'slug' => 'gudele', 'country_code' => 'ss', 'default_currency' => 'ssp',
            'timezone' => 'Africa/Juba', 'intent' => $this->intent->id,
        ])->assertRedirect(PlatformHosts::url(PlatformHosts::workspace('gudele'), '/retail'));

        $created = Organization::query()->where('display_name', 'Gudele Shop')->sole();
        expect(intentRetailCurrent($created, $this->retail))->toBeTrue()
            ->and($this->intent->refresh()->organization_id)->toBe($created->id);
    });

    it('can be put aside', function () {
        $this->post($this->authUrl("get-started/selection/{$this->intent->id}/cancel"))->assertRedirect(route('workspaces'));

        expect($this->intent->refresh()->status)->toBe('cancelled');
        $this->get(route('workspaces.continue'))->assertRedirect();
        expect(intentEntitlements($this->organization, $this->retail))->toBe(0);
    });
});

describe('products and plans', function () {
    it('starts an ERP product (NGO) through the applications it requires', function () {
        $projects = Application::query()->where('code', 'retail')->sole();
        app(ProductCatalog::class)->sync(['code' => 'ngo', 'name' => 'NGO ERP', 'status' => 'pilot', 'sort_order' => 1, 'applications' => [['code' => 'retail', 'requirement' => 'required']]]);

        $token = intentLink('product=ngo');
        $this->actingAs($this->owner->user)->get($this->authUrl("get-started/{$token}"));
        $intent = RegistrationIntent::query()->sole();

        $this->get(route('get-started.show', ['intent' => $intent->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selection.kind', 'product')
            ->where('selection.name', 'NGO ERP'));

        intentStore($intent, $this->organization)->assertRedirect(test()->businessUrl('/retail', $this->organization));
        expect(intentRetailCurrent($this->organization, $projects))->toBeTrue()
            ->and($intent->refresh()->outcome_details['applications'])->toBe(['retail']);
    });

    it('starts a plan trial through the plan lifecycle, once', function () {
        $plan = intentPlan();
        $token = intentLink('plan=retail-standard');
        $this->actingAs($this->owner->user)->get($this->authUrl("get-started/{$token}"));
        $intent = RegistrationIntent::query()->sole();

        intentStore($intent, $this->organization)->assertRedirect();
        intentStore($intent, $this->organization)->assertRedirect();

        expect(app(TenantContext::class)->run($this->organization, fn () => Subscription::query()->whereHas('items', fn ($items) => $items->where('plan_id', $plan->id))->count()))->toBe(1)
            ->and($intent->refresh()->outcome)->toBe('trial_started');
    });

    it('turns a paid plan into a request for staff, switching nothing on', function () {
        $plan = intentPlan(['trial_days' => 0]);
        $this->get($this->authUrl('get-started?plan=retail-standard&action=subscribe'))->assertRedirect();
        $intent = RegistrationIntent::query()->sole();
        $this->actingAs($this->owner->user)->get(route('workspaces.continue'));
        $intent->forceFill(['user_id' => $this->owner->user_id])->save();

        intentStore($intent, $this->organization)->assertRedirect(test()->businessUrl('/manage/subscription', $this->organization));
        intentStore($intent, $this->organization)->assertRedirect();

        expect(app(TenantContext::class)->run($this->organization, fn () => PlanRequest::query()->where('plan_id', $plan->id)->where('status', 'pending')->count()))->toBe(1)
            ->and(intentEntitlements($this->organization, $this->retail))->toBe(0)
            ->and($intent->refresh()->outcome)->toBe('requested');
    });

    it('refuses completion with a reason the person can act on', function () {
        $intent = app(RegistrationIntents::class)->create('application', 'retail', 'trial')[0];

        expect(fn () => app(RegistrationIntents::class)->complete($intent, $this->owner->user, $this->organization))
            ->toThrow(IntentRefused::class, 'another account');
    });
});
