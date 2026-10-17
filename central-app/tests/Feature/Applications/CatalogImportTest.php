<?php

use App\Modules\Applications\Catalog\CatalogConflict;
use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationCatalogVersion;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Applications\Models\SodConstraint;
use App\Modules\Authorization\Enums\ScopeType;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function retailCatalog(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
}

it('imports a catalog as the current published version', function () {
    $version = app(CatalogImporter::class)->import(retailCatalog());

    $application = Application::query()->where('code', 'retail')->sole();
    $cashier = ApplicationRole::query()->where('key', 'cashier')->sole();

    expect($application->current_catalog_version_id)->toBe($version->id)
        ->and($version->status)->toBe('published')
        ->and($version->permissions()->count())->toBe(5)
        ->and($version->roles()->count())->toBe(7)
        ->and($cashier->allowedScopeTypes())->toBe([ScopeType::Branch])
        ->and($cashier->permissions()->pluck('key')->all())->toBe(['retail.sales.create'])
        ->and(SodConstraint::query()->count())->toBe(2);
});

it('treats re-importing identical content as a no-op', function () {
    $first = app(CatalogImporter::class)->import(retailCatalog());
    $second = app(CatalogImporter::class)->import(retailCatalog());

    expect($second->id)->toBe($first->id)
        ->and(ApplicationCatalogVersion::query()->count())->toBe(1);
});

it('refuses to change a published version', function () {
    app(CatalogImporter::class)->import(retailCatalog());

    $changed = retailCatalog();
    $changed['roles'][0]['permissions'][] = 'retail.payments.approve';

    app(CatalogImporter::class)->import($changed);
})->throws(CatalogConflict::class);

it('publishes a new version and retires the previous one', function () {
    $first = app(CatalogImporter::class)->import(retailCatalog());

    $next = retailCatalog();
    $next['version'] = '1.1.0';
    $second = app(CatalogImporter::class)->import($next);

    expect($first->refresh()->status)->toBe('retired')
        ->and(Application::query()->where('code', 'retail')->value('current_catalog_version_id'))->toBe($second->id)
        ->and(ApplicationRole::query()->where('key', 'cashier')->count())->toBe(2);
});

it('rejects invalid catalogs', function (Closure $break, string $field) {
    $catalog = retailCatalog();
    $break($catalog);

    try {
        app(CatalogImporter::class)->import($catalog);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain($field);
    }

    expect(Application::query()->count())->toBe(0);
})->with([
    'undeclared permission in a role' => [function (array &$c) {
        $c['roles'][0]['permissions'][] = 'retail.ghost';
    }, 'roles.0.permissions'],
    'unknown scope type' => [function (array &$c) {
        $c['roles'][0]['scopes'] = ['galaxy'];
    }, 'roles.0.scopes.0'],
    'duplicate scope in a list' => [function (array &$c) {
        $c['roles'][0]['scopes'] = ['branch', 'branch'];
    }, 'roles.0.scopes'],
    'duplicate permission key' => [function (array &$c) {
        $c['permissions'][1]['key'] = $c['permissions'][0]['key'];
    }, 'permissions.1.key'],
    'sensitivity out of range' => [function (array &$c) {
        $c['roles'][0]['sensitivity'] = 9;
    }, 'roles.0.sensitivity'],
    'self-conflicting constraint' => [function (array &$c) {
        $c['separation_of_duties'][0]['permissions'] = ['retail.sales.create', 'retail.sales.create'];
    }, 'separation_of_duties.0'],
    'bad application code' => [function (array &$c) {
        $c['application']['code'] = 'Retail App';
    }, 'application.code'],
]);
