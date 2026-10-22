<?php

use App\Modules\Applications\Models\Application;
use App\Modules\Products\Support\ProductCatalog;
use App\Modules\Subscriptions\Models\Plan;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);

    $this->pharmacyApp = Application::query()->forceCreate(['code' => 'pharmacy', 'name' => 'Pharmacy', 'category' => 'products', 'kind' => 'standalone', 'status' => 'beta', 'sort_order' => 1]);
    $this->financeApp = Application::query()->forceCreate(['code' => 'finance', 'name' => 'Finance', 'category' => 'shared', 'kind' => 'shared_module', 'status' => 'beta', 'sort_order' => 2]);
    $this->hrApp = Application::query()->forceCreate(['code' => 'hr', 'name' => 'HR', 'category' => 'products', 'kind' => 'standalone', 'status' => 'planned', 'sort_order' => 3]);

    $catalog = app(ProductCatalog::class);
    $this->pharmacy = $catalog->sync(['code' => 'pharmacy', 'name' => 'Miles Pharmacy ERP', 'tagline' => 'Medicines and the books.', 'status' => 'pilot', 'sort_order' => 10, 'applications' => [
        ['code' => 'pharmacy', 'requirement' => 'required'],
        ['code' => 'finance', 'requirement' => 'required'],
        ['code' => 'hr', 'requirement' => 'optional'],
    ]])['product'];
    $this->ngo = $catalog->sync(['code' => 'ngo', 'name' => 'Miles NGO ERP', 'status' => 'planned', 'sort_order' => 40, 'applications' => [
        ['code' => 'finance', 'requirement' => 'required'],
    ]])['product'];

    $this->billing = $this->staffWith(['billing_admin']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPlanForm(array $overrides = []): array
{
    return array_merge([
        'erp_product_id' => test()->pharmacy->id,
        'code' => 'pharmacy-std',
        'name' => 'Pharmacy Standard',
        'billing_interval' => 'monthly',
        'price_minor' => 5000,
        'currency' => 'USD',
        'trial_days' => 0,
        'is_public' => '1',
        'status' => 'active',
        'application_ids' => [test()->pharmacyApp->id, test()->financeApp->id],
    ], $overrides);
}

function apex(string $path = ''): string
{
    return PlatformHosts::url(PlatformHosts::root(), $path);
}

describe('plans', function () {
    it('creates a plan that sells a product', function () {
        $this->actingAs($this->billing->user)->post($this->adminUrl('/plans'), productPlanForm())->assertSessionHasNoErrors();

        $plan = Plan::query()->sole();

        expect($plan->erp_product_id)->toBe($this->pharmacy->id)
            ->and($plan->is_bundle)->toBeTrue();
    });

    it('refuses a plan that does not fit its product', function (Closure $overrides, string $field) {
        $this->actingAs($this->billing->user)
            ->post($this->adminUrl('/plans'), productPlanForm($overrides()))
            ->assertSessionHasErrors($field);

        expect(Plan::query()->count())->toBe(0);
    })->with([
        'missing a required application' => [fn () => ['application_ids' => [test()->pharmacyApp->id]], 'application_ids'],
        'an application outside the product' => [function () {
            $retail = Application::query()->forceCreate(['code' => 'retail', 'name' => 'Retail', 'category' => 'products', 'kind' => 'standalone', 'status' => 'beta']);

            return ['application_ids' => [test()->pharmacyApp->id, test()->financeApp->id, $retail->id]];
        }, 'application_ids'],
        'active plan for a planned product' => [fn () => ['erp_product_id' => test()->ngo->id, 'application_ids' => [test()->financeApp->id]], 'erp_product_id'],
        'unknown product' => [fn () => ['erp_product_id' => '0190a000-0000-7000-8000-000000000000'], 'erp_product_id'],
    ]);

    it('allows an optional application and a draft plan for a planned product', function () {
        $this->actingAs($this->billing->user)
            ->post($this->adminUrl('/plans'), productPlanForm(['application_ids' => [$this->pharmacyApp->id, $this->financeApp->id, $this->hrApp->id]]))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->billing->user)
            ->post($this->adminUrl('/plans'), productPlanForm(['code' => 'ngo-std', 'erp_product_id' => $this->ngo->id, 'status' => 'draft', 'application_ids' => [$this->financeApp->id]]))
            ->assertSessionHasNoErrors();

        expect(Plan::query()->count())->toBe(2);
    });

    it('keeps plans without a product working as before', function () {
        $this->actingAs($this->billing->user)
            ->post($this->adminUrl('/plans'), productPlanForm(['erp_product_id' => '', 'application_ids' => [$this->pharmacyApp->id]]))
            ->assertSessionHasNoErrors();

        expect(Plan::query()->sole()->erp_product_id)->toBeNull();
    });

    it('stops the catalog from changing a product underneath its plans', function (array $change) {
        Application::query()->forceCreate(['code' => 'inventory', 'name' => 'Inventory', 'category' => 'products', 'kind' => 'standalone', 'status' => 'beta']);
        $this->actingAs($this->billing->user)->post($this->adminUrl('/plans'), productPlanForm())->assertSessionHasNoErrors();

        try {
            app(ProductCatalog::class)->sync(array_merge([
                'code' => 'pharmacy', 'name' => 'Miles Pharmacy ERP', 'status' => 'pilot', 'applications' => [
                    ['code' => 'pharmacy', 'requirement' => 'required'],
                    ['code' => 'finance', 'requirement' => 'required'],
                ],
            ], $change));
            $this->fail('Expected the plan guard to refuse the change.');
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['plans'])
                ->and($this->pharmacy->refresh()->status->value)->toBe('pilot');
        }
    })->with([
        'back to planned' => [['status' => 'planned']],
        'a new required application' => [['applications' => [
            ['code' => 'pharmacy', 'requirement' => 'required'],
            ['code' => 'finance', 'requirement' => 'required'],
            ['code' => 'inventory', 'requirement' => 'required'],
        ]]],
    ]);

    it('lets the catalog change a product whose plans still fit or are retired', function () {
        $this->actingAs($this->billing->user)->post($this->adminUrl('/plans'), productPlanForm())->assertSessionHasNoErrors();
        Plan::query()->update(['status' => 'retired']);

        app(ProductCatalog::class)->sync(['code' => 'pharmacy', 'name' => 'Miles Pharmacy ERP', 'status' => 'planned', 'applications' => [['code' => 'pharmacy', 'requirement' => 'required']]]);

        expect($this->pharmacy->refresh()->status->value)->toBe('planned');
    });

    it('lists products for staff who manage plans, and nobody else', function () {
        $this->actingAs($this->billing->user)->get($this->adminUrl('/products'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/products/index')
                ->where('products.0.code', 'pharmacy')
                ->where('products.0.applications.0.requirement', 'required')
                ->has('products', 2));

        $this->actingAs($this->staffWith(['security_auditor'])->user)->get($this->adminUrl('/products'))->assertForbidden();
    });
});

describe('public site', function () {
    beforeEach(function () {
        $plan = Plan::query()->forceCreate(['erp_product_id' => $this->pharmacy->id, 'code' => 'pharmacy-std', 'name' => 'Pharmacy Standard', 'billing_interval' => 'monthly', 'price_minor' => 5000, 'currency' => 'USD', 'is_public' => true, 'status' => 'active', 'is_bundle' => true]);
        $plan->applications()->attach([$this->pharmacyApp->id, $this->financeApp->id]);
    });

    it('lists ERP solutions with their real status on the home page', function () {
        $this->get(apex())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('catalog', function ($catalog) {
                $bySlug = collect($catalog)->keyBy('slug');

                // Pilot reads as early access; the trial starts the pharmacy application.
                return $bySlug['pharmacy']['status'] === 'early_access'
                    && $bySlug['pharmacy']['trial'] === 'pharmacy'
                    // Planned in the registry, or not in it at all: coming soon, no trial.
                    && $bySlug['ngo']['status'] === 'coming_soon'
                    && $bySlug['ngo']['trial'] === null
                    && $bySlug['logistics']['status'] === 'coming_soon';
            }));
    });

    it('shows an ERP solution with its applications and plans', function () {
        $this->get(apex('erp/pharmacy'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/landing')
            ->where('product.name', 'Pharmacy ERP')
            ->where('product.includes.0.code', 'pharmacy')
            ->where('product.includes.2.requirement', 'optional')
            // Pharmacy (beta) has a public page; HR (planned, unlisted here) only if listed.
            ->where('product.includes.0.listed', true)
            ->where('plans.0.code', 'pharmacy-std')
            ->where('plans.0.product.status', 'pilot')
            ->where('canonical', apex('erp/pharmacy')));
    });

    it('offers no plans on a solution that is coming soon', function () {
        $this->get(apex('erp/ngo'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/landing')
            ->where('product.status', 'coming_soon')
            ->where('product.trial', null)
            ->where('plans', []));
    });

    it('moves old links by registry code to the solution page', function () {
        app(ProductCatalog::class)->sync(['code' => 'retail_trading', 'name' => 'Miles Retail & Trading ERP', 'status' => 'planned', 'sort_order' => 20, 'applications' => [
            ['code' => 'finance', 'requirement' => 'required'],
        ]]);

        $this->get(apex('erp/retail_trading'))->assertMovedPermanently()->assertRedirect(apex('erp/retail'));
    });

    it('hides retired and unknown products', function () {
        $this->pharmacy->forceFill(['status' => 'retired'])->save();

        $this->get(apex('erp/pharmacy'))->assertNotFound();
        $this->get(apex('erp/nothing'))->assertNotFound();
    });

    it('labels product plans on the pricing page', function () {
        $this->get(apex('pricing'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.product.name', 'Miles Pharmacy ERP'));
    });

    it('lists ERP product pages in the sitemap', function () {
        $this->get(apex('sitemap.xml'))->assertOk()
            ->assertSee(apex('erp/pharmacy'), false)
            ->assertSee(apex('erp/ngo'), false);
    });
});
