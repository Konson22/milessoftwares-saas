<?php

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Enums\ApplicationStatus;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Models\ErpProduct;
use App\Modules\Products\Support\ProductAccess;
use App\Modules\Products\Support\ProductCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function registerApplication(string $code, ApplicationStatus $status = ApplicationStatus::Beta, ApplicationKind $kind = ApplicationKind::Standalone): Application
{
    return Application::query()->create([
        'code' => $code,
        'name' => ucfirst($code),
        'category' => 'products',
        'kind' => $kind,
        'status' => $status,
    ]);
}

/**
 * @return array<string, mixed>
 */
function pharmacyProduct(array $overrides = []): array
{
    return array_merge([
        'code' => 'pharmacy',
        'name' => 'Miles Pharmacy ERP',
        'status' => 'pilot',
        'sort_order' => 10,
        'applications' => [
            ['code' => 'pharmacy', 'requirement' => 'required'],
            ['code' => 'finance', 'requirement' => 'required'],
        ],
    ], $overrides);
}

function entitle(Organization $organization, Application $application, array $attributes = []): Entitlement
{
    return app(TenantContext::class)->run($organization, function () use ($application, $attributes) {
        $entitlement = new Entitlement;
        $entitlement->forceFill(array_merge([
            'application_id' => $application->id,
            'source' => 'manual',
            'valid_from' => now()->subMinute(),
        ], $attributes))->save();

        return $entitlement;
    });
}

beforeEach(function () {
    $this->pharmacy = registerApplication('pharmacy');
    $this->finance = registerApplication('finance', kind: ApplicationKind::SharedModule);
});

describe('catalog', function () {
    it('writes a product and the applications it is made of', function () {
        $result = app(ProductCatalog::class)->sync(pharmacyProduct());

        $product = ErpProduct::query()->where('code', 'pharmacy')->sole();

        expect($result['unregistered'])->toBe([])
            ->and($product->status)->toBe(ProductStatus::Pilot)
            ->and($product->requiredApplications()->pluck('code')->sort()->values()->all())->toBe(['finance', 'pharmacy']);
    });

    it('changes nothing when the same definition is written again', function () {
        app(ProductCatalog::class)->sync(pharmacyProduct());
        app(ProductCatalog::class)->sync(pharmacyProduct());

        expect(ErpProduct::query()->count())->toBe(1)
            ->and(DB::table('erp_product_applications')->count())->toBe(2);
    });

    it('follows a changed definition, including dropped applications', function () {
        app(ProductCatalog::class)->sync(pharmacyProduct());
        app(ProductCatalog::class)->sync(pharmacyProduct([
            'name' => 'Pharmacy ERP',
            'applications' => [['code' => 'pharmacy', 'requirement' => 'required'], ['code' => 'finance', 'requirement' => 'optional']],
        ]));

        $product = ErpProduct::query()->sole();

        expect($product->name)->toBe('Pharmacy ERP')
            ->and($product->requiredApplications()->pluck('code')->all())->toBe(['pharmacy']);
    });

    it('lets a planned product name applications that do not exist yet', function () {
        $result = app(ProductCatalog::class)->sync([
            'code' => 'retail_trading',
            'name' => 'Miles Retail & Trading ERP',
            'status' => 'planned',
            'applications' => [['code' => 'retail', 'requirement' => 'required'], ['code' => 'finance', 'requirement' => 'required']],
        ]);

        expect($result['unregistered'])->toBe(['retail'])
            ->and($result['product']->applications()->pluck('code')->all())->toBe(['finance']);
    });

    it('refuses a product that is not released enough for its applications', function (string $status, ApplicationStatus $applicationStatus) {
        $this->pharmacy->forceFill(['status' => $applicationStatus])->save();

        app(ProductCatalog::class)->sync(pharmacyProduct(['status' => $status]));
    })->with([
        'pilot on planned software' => ['pilot', ApplicationStatus::Planned],
        'pilot on retired software' => ['pilot', ApplicationStatus::Retired],
        'available on beta software' => ['available', ApplicationStatus::Beta],
    ])->throws(ValidationException::class);

    it('allows an optional application that is still in beta in an available product', function () {
        $this->pharmacy->forceFill(['status' => ApplicationStatus::Available])->save();
        $this->finance->forceFill(['status' => ApplicationStatus::Available])->save();
        registerApplication('hr');

        $result = app(ProductCatalog::class)->sync(pharmacyProduct([
            'status' => 'available',
            'applications' => [...pharmacyProduct()['applications'], ['code' => 'hr', 'requirement' => 'optional']],
        ]));

        expect($result['product']->applications()->count())->toBe(3);
    });

    it('rejects invalid definitions and writes nothing', function (Closure $break, string $field) {
        $definition = pharmacyProduct();
        $break($definition);

        try {
            app(ProductCatalog::class)->sync($definition);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toContain($field);
        }

        expect(ErpProduct::query()->count())->toBe(0);
    })->with([
        'bad code' => [function (array &$d) {
            $d['code'] = 'Pharmacy-ERP';
        }, 'code'],
        'unknown status' => [function (array &$d) {
            $d['status'] = 'launched';
        }, 'status'],
        'no applications' => [function (array &$d) {
            $d['applications'] = [];
        }, 'applications'],
        'nothing required' => [function (array &$d) {
            $d['applications'] = [['code' => 'pharmacy', 'requirement' => 'optional']];
        }, 'applications'],
        'unknown requirement' => [function (array &$d) {
            $d['applications'][0]['requirement'] = 'bundled';
        }, 'applications.0.requirement'],
        'duplicate application' => [function (array &$d) {
            $d['applications'][1]['code'] = 'pharmacy';
        }, 'applications.0.code'],
        'unregistered application in a pilot' => [function (array &$d) {
            $d['applications'][] = ['code' => 'retail', 'requirement' => 'optional'];
        }, 'applications.2.code'],
        'platform application' => [function (array &$d) {
            registerApplication('workspace', kind: ApplicationKind::Platform);
            $d['applications'][] = ['code' => 'workspace', 'requirement' => 'optional'];
        }, 'applications.2.code'],
    ]);

    it('is guarded by the database as well', function (string $sql) {
        DB::statement($sql);
    })->with([
        'status' => ["INSERT INTO erp_products (id, code, name, status) VALUES (gen_random_uuid(), 'x', 'X', 'launched')"],
        'code' => ["INSERT INTO erp_products (id, code, name) VALUES (gen_random_uuid(), 'Bad Code', 'X')"],
    ])->throws(QueryException::class);

    it('keeps an application that a product uses from being deleted', function () {
        app(ProductCatalog::class)->sync(pharmacyProduct());

        $this->finance->delete();
    })->throws(QueryException::class);

    it('syncs the shipped definitions file', function () {
        $this->artisan('platform:erp-products')
            ->expectsOutputToContain('not registered yet, left out: retail')
            ->assertSuccessful();

        expect(ErpProduct::query()->orderBy('sort_order')->pluck('status', 'code')->map->value->all())->toBe([
            'pharmacy' => 'pilot',
            'retail_trading' => 'planned',
            'education' => 'planned',
            'ngo' => 'planned',
            'finance_accounting' => 'pilot',
        ]);
    });

    it('reports a product it cannot write and still writes the others', function () {
        $this->pharmacy->delete();

        $this->artisan('platform:erp-products')
            ->expectsOutputToContain('pharmacy is invalid')
            ->assertFailed();

        expect(ErpProduct::query()->pluck('code')->all())->not->toContain('pharmacy')
            ->toContain('finance_accounting');
    });
});

describe('access', function () {
    beforeEach(function () {
        app(ProductCatalog::class)->sync(pharmacyProduct());
        app(ProductCatalog::class)->sync(['code' => 'finance_accounting', 'name' => 'Miles Finance & Accounting', 'status' => 'pilot', 'sort_order' => 50, 'applications' => [['code' => 'finance', 'requirement' => 'required']]]);
        $this->organization = Organization::factory()->create();
    });

    it('holds a product only while every required application is entitled', function () {
        entitle($this->organization, $this->pharmacy);

        expect(app(ProductAccess::class)->for($this->organization)->pluck('code')->all())->toBe([]);

        entitle($this->organization, $this->finance);

        expect(app(ProductAccess::class)->for($this->organization)->pluck('code')->all())->toBe(['pharmacy', 'finance_accounting']);
    });

    it('ignores revoked, expired, future and feature-only entitlements', function (array $attributes) {
        entitle($this->organization, $this->pharmacy);
        entitle($this->organization, $this->finance, $attributes);

        expect(app(ProductAccess::class)->for($this->organization)->pluck('code')->all())->toBe([]);
    })->with([
        'revoked' => [['revoked_at' => now()]],
        'expired' => [['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay()]],
        'not started' => [['valid_from' => now()->addDay()]],
        'feature only' => [['feature_key' => 'finance.multicurrency']],
    ]);

    it('never offers a planned or retired product', function (string $status) {
        ErpProduct::query()->where('code', 'pharmacy')->update(['status' => $status]);
        entitle($this->organization, $this->pharmacy);
        entitle($this->organization, $this->finance);

        expect(app(ProductAccess::class)->for($this->organization)->pluck('code')->all())->toBe(['finance_accounting']);
    })->with(['planned', 'retired']);

    it("does not see another organization's entitlements", function () {
        $other = Organization::factory()->create();
        entitle($other, $this->pharmacy);
        entitle($other, $this->finance);

        expect(app(ProductAccess::class)->for($this->organization)->all())->toBe([])
            ->and(app(ProductAccess::class)->for($other)->pluck('code')->all())->toBe(['pharmacy', 'finance_accounting'])
            ->and(app(TenantContext::class)->has())->toBeFalse();
    });
});
