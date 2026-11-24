<?php

use App\Logbook\Models\LogResource;
use App\Logbook\Models\ResourceCategory;
use App\Logbook\ResourceCategories;
use Illuminate\Support\Str;

/*
 * Asset categories (ADR-0031 addendum): each workspace keeps a list of
 * broad groups (Vehicles, Generators…) above each asset's type (Car,
 * Pickup…). A sheet field may ask for a type or a whole category.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 00:05:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Fleet');
});

function categoryNamed(string $name): ResourceCategory
{
    return inTenant(fn () => ResourceCategory::query()->where('name', $name)->sole());
}

function categorisedAsset(string $code, string $type, array $extra = []): LogResource
{
    test()->post(p('resources'), ['code' => $code, 'name' => "{$code} unit", 'type' => $type, ...$extra])->assertSessionHasNoErrors();

    return inTenant(fn () => LogResource::query()->where('code', $code)->sole());
}

it('places a type in a default category by its words', function (string $type, string $key) {
    expect(ResourceCategories::guessKey($type))->toBe($key);
})->with([
    ['Car', 'vehicles'], ['Cars', 'vehicles'], ['Toyota Hilux', 'vehicles'], ['Land Cruiser Ambulance', 'vehicles'],
    ['Diesel generator', 'generators'], ['UPS', 'power'], ['Vaccine fridge', 'cooling'], ['Cold storage room', 'cooling'],
    ['Water tank', 'water'], ['IT equipment', 'it'], ['Oxygen concentrator', 'medical'],
    ['Item', 'other'], ['Scarf', 'other'], ['Kitchen equipment', 'other'],
]);

it('starts every workspace with the default categories and groups the register by them', function () {
    $this->get(p('resources'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('categories', count(ResourceCategories::DEFAULTS))
        ->where('categories.0.name', 'Vehicles')->where('categories.0.assets', 0));

    $car = categorisedAsset('SSD-101A', 'Car');
    $gen = categorisedAsset('GEN-01', 'Diesel generator');
    $other = categorisedAsset('X-1', 'Widget');

    expect(inTenant(fn () => $car->category->name))->toBe('Vehicles')
        ->and(inTenant(fn () => $gen->category->name))->toBe('Generators')
        ->and(inTenant(fn () => $other->category->name))->toBe('Other equipment');
    $this->get(p('resources'))->assertInertia(fn ($page) => $page
        ->where('categories.0.assets', 1)
        ->where('resources', fn ($rows) => collect($rows)->firstWhere('code', 'SSD-101A')['category'] === 'Vehicles'));
});

it('keeps the category chosen, and refuses one that is not the workspace\'s', function () {
    $this->get(p('resources'));
    $power = categoryNamed('Power and solar');
    $asset = categorisedAsset('INV-1', 'Car', ['category_id' => $power->id]);
    expect($asset->category_id)->toBe($power->id);

    $this->post(p('resources'), ['code' => 'X-2', 'name' => 'X', 'type' => 'Car', 'category_id' => (string) Str::uuid7()])
        ->assertSessionHasErrors('category_id');

    // Changing other things keeps the category; changing it is audited like the rest.
    $this->put(p("resources/{$asset->id}"), ['code' => 'INV-1', 'name' => 'Inverter', 'type' => 'Inverter'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $asset->fresh()->category_id))->toBe($power->id);
    $vehicles = categoryNamed('Vehicles');
    $this->put(p("resources/{$asset->id}"), ['code' => 'INV-1', 'name' => 'Inverter', 'type' => 'Inverter', 'category_id' => $vehicles->id])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $asset->fresh()->category_id))->toBe($vehicles->id);
});

it('gives starter templates the workspace category they name', function () {
    $this->get(p('resources/create'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('templates', fn ($templates) => collect($templates)->firstWhere('key', 'starter:vehicle')['categoryId'] === categoryNamed('Vehicles')->id)
        ->has('categories', count(ResourceCategories::DEFAULTS)));

    // An organization template takes a category too, or the one its type falls in.
    $this->post(p('resources/templates'), ['name' => 'Motorbike', 'type' => 'Motorbike'])->assertSessionHasNoErrors();
    $this->post(p('resources/templates'), ['name' => 'Inverter set', 'type' => 'Thing', 'category_id' => categoryNamed('Power and solar')->id])->assertSessionHasNoErrors();
    $this->get(p('resources/templates'))->assertInertia(fn ($page) => $page
        ->where('templates', fn ($t) => collect($t)->firstWhere('name', 'Motorbike')['categoryId'] === categoryNamed('Vehicles')->id
            && collect($t)->firstWhere('name', 'Inverter set')['categoryId'] === categoryNamed('Power and solar')->id));
});

it('lets a sheet field ask for a whole category', function () {
    $car = categorisedAsset('SSD-101A', 'Car');
    $pickup = categorisedAsset('SSD-214B', 'Pickup');
    $gen = categorisedAsset('GEN-01', 'Diesel generator');
    $sheet = publishedSheet($this->logbook, 'TRP', review: false, fields: [
        ['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'resource', 'required' => true, 'resource_type' => 'Vehicles'],
        ['name' => 'km', 'label' => 'Distance', 'type' => 'decimal', 'required' => true, 'unit' => 'km'],
    ]);
    $trip = fn (string $id) => $this->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T06:10', 'values' => ['vehicle' => $id, 'km' => '12'],
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ]);

    as_($this->team['writer'], 'logbook_contributor');
    $trip($car->id)->assertRedirect()->assertSessionHasNoErrors();
    $trip($pickup->id)->assertRedirect()->assertSessionHasNoErrors();
    $trip($gen->id)->assertSessionHasErrors('values.vehicle');

    // Linking follows the same rule.
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("resources/{$gen->id}/sheets"), ['sheet_id' => $sheet->id])->assertSessionHasErrors('link');
    $this->post(p("resources/{$car->id}/sheets"), ['sheet_id' => $sheet->id])->assertSessionHasNoErrors();

    // Reports filter by category.
    $this->get(p('reports?view=table&category='.categoryNamed('Vehicles')->id))->assertInertia(fn ($page) => $page->where('counts.total', 2));
    $this->get(p('reports?view=table&category='.categoryNamed('Generators')->id))->assertInertia(fn ($page) => $page->where('counts.total', 0));
});

it('lets managers add, rename and switch off categories, never two with one name', function () {
    $this->get(p('resources'));
    $this->post(p('resources/categories'), ['name' => 'Boats', 'types' => ['Speedboat', 'Barge']])->assertSessionHasNoErrors();
    $boats = categoryNamed('Boats');
    expect($boats->types)->toBe(['Speedboat', 'Barge'])->and($boats->key)->toBeNull();

    $this->post(p('resources/categories'), ['name' => 'vehicles'])->assertSessionHasErrors('name');
    $this->put(p("resources/categories/{$boats->id}"), ['name' => 'Boats and canoes', 'types' => 'Speedboat, Canoe', 'active' => false])->assertSessionHasNoErrors();
    $fresh = inTenant(fn () => $boats->fresh());
    expect($fresh->name)->toBe('Boats and canoes')->and($fresh->types)->toBe(['Speedboat', 'Canoe'])->and($fresh->active)->toBeFalse();

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('resources'))->assertOk();
    $this->post(p('resources/categories'), ['name' => 'Mine'])->assertForbidden();
    $this->put(p("resources/categories/{$boats->id}"), ['name' => 'Mine'])->assertForbidden();
});

it('keeps each workspace\'s categories its own', function () {
    $this->get(p('resources'));
    $theirs = categoryNamed('Vehicles');
    $other = team('globex');
    as_($other['manager'], 'logbook_manager', 'globex');
    $this->get(p('resources', 'globex'))->assertOk();
    $this->post(p('resources', 'globex'), ['code' => 'C-1', 'name' => 'Car', 'type' => 'Car', 'category_id' => $theirs->id])
        ->assertSessionHasErrors('category_id');
    $this->put(p("resources/categories/{$theirs->id}", 'globex'), ['name' => 'Hijacked'])->assertNotFound();
    expect(categoryNamed('Vehicles')->name)->toBe('Vehicles');
});
