<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\ApplicationPermission;

/*
| Every catalog a product folder of this repository publishes imports as it
| is, including the applications a folder hosts (pharmacy-app/platform/retail.json).
*/

dataset('repository catalogs', function () {
    $root = dirname(__DIR__, 4);

    return array_map(fn (string $path) => [substr($path, strlen($root) + 1)], glob($root.'/*-app/platform/*.json') ?: []);
});

it('imports the catalog', function (string $path) {
    $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/'.$path), true, flags: JSON_THROW_ON_ERROR);

    $version = app(CatalogImporter::class)->import($catalog);

    // Each application grants only its own permissions.
    expect(ApplicationPermission::query()->where('catalog_version_id', $version->id)->pluck('key')->all())
        ->not->toBeEmpty()
        ->each->toStartWith($catalog['application']['code'].'.');
})->with('repository catalogs');
