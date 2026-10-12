<?php

use App\Models\Medicine;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

function medicinePayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Amoxil',
        'generic_name' => 'amoxicillin',
        'form' => 'capsule',
        'strength' => '500 mg',
        'unit' => 'capsule',
        'barcode' => '6001234500017',
        'prescription_only' => true,
        'controlled' => false,
        'sell_price' => '12.50',
        'reorder_level' => 40,
        'active' => true,
    ];
}

beforeEach(function () {
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
});

it('adds a medicine to the catalogue with its price in minor units', function () {
    $this->actingAsMember('acme', ['pharmacy.medicines.view', 'pharmacy.medicines.manage']);

    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload())
        ->assertRedirect($this->workspaceUrl('acme', 'medicines'));

    $medicine = $this->asTenant('acme', fn () => Medicine::query()->sole());
    expect($medicine)
        ->label()->toBe('Amoxil 500 mg capsule')
        ->sell_price_minor->toBe(1250)
        ->prescription_only->toBeTrue()
        ->organization_id->toBe($this->platform()->organization('acme')['id']);
});

it('makes every controlled medicine prescription-only', function () {
    $this->actingAsMember('acme', ['pharmacy.medicines.manage']);

    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload(['name' => 'Morphine', 'controlled' => true, 'prescription_only' => false]));

    expect($this->asTenant('acme', fn () => Medicine::query()->sole()->prescription_only))->toBeTrue();
});

it('validates prices and refuses a barcode used twice in the organization', function () {
    $this->actingAsMember('acme', ['pharmacy.medicines.manage']);

    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload(['sell_price' => '12.505']))->assertSessionHasErrors('sell_price');
    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload(['form' => 'potion']))->assertSessionHasErrors('form');

    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload())->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload(['name' => 'Other']))->assertSessionHasErrors('barcode');
});

it('lets another organization reuse the same barcode', function () {
    $this->asTenant('globex', fn () => Medicine::factory()->create(['barcode' => '6001234500017']));
    $this->actingAsMember('acme', ['pharmacy.medicines.manage']);

    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload())->assertSessionHasNoErrors();
});

it('shows only the organization\'s catalogue, and changing it needs manage', function () {
    $own = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Acme Med']));
    $this->asTenant('globex', fn () => Medicine::factory()->create(['name' => 'Globex Med']));
    $this->actingAsMember('acme', ['pharmacy.medicines.view']);

    $this->get($this->workspaceUrl('acme', 'medicines'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('medicines', 1)
            ->where('medicines.0.name', 'Acme Med')
            ->where('canManage', false));

    $this->post($this->workspaceUrl('acme', 'medicines'), medicinePayload())->assertForbidden();
    $this->get($this->workspaceUrl('acme', 'medicines/'.$own->id.'/edit'))->assertForbidden();
});

it('never opens another organization\'s medicine', function () {
    $foreign = $this->asTenant('globex', fn () => Medicine::factory()->create());
    $this->actingAsMember('acme', ['pharmacy.medicines.manage']);

    $this->get($this->workspaceUrl('acme', 'medicines/'.$foreign->id.'/edit'))->assertNotFound();
    $this->put($this->workspaceUrl('acme', 'medicines/'.$foreign->id), medicinePayload())->assertNotFound();
});
