<?php

use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Testing\InteractsWithPlatform;
use Miles\PlatformClient\Tests\Fixtures\Prescription;

uses(InteractsWithPlatform::class);

it('signs a member in to a workspace without any network', function () {
    $this->actingAsMember('acme', ['pharmacy.dispense']);

    $this->get($this->workspaceUrl('acme'))->assertOk();
    $this->get($this->workspaceUrl('acme', 'dispense'))->assertSee('can dispense');
});

it('fakes members without the permission and without access', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create']);
    $this->get($this->workspaceUrl('acme', 'dispense'))->assertSee('cannot dispense');

    $user = $this->platformUser();
    $this->platform()->grant('acme', $user->getAuthIdentifier(), allowed: false);
    $this->actingAs($user)->get($this->workspaceUrl('acme'))->assertForbidden();
});

it('keeps workspaces apart', function () {
    $this->actingAsMember('acme', ['pharmacy.dispense']);
    $this->platform()->organization('globex');

    $this->get($this->workspaceUrl('globex'))->assertRedirect($this->workspaceUrl('globex', 'auth/login'));
});

it('runs code inside a tenant', function () {
    $this->asTenant('acme', fn () => Prescription::query()->create(['reference' => 'A-1']));
    $this->asTenant('globex', fn () => Prescription::query()->create(['reference' => 'G-1']));

    expect($this->asTenant('acme', fn () => Prescription::query()->pluck('reference')->all()))->toBe(['A-1']);
});

it('lists an organization\'s branches, cached until the organization changes', function () {
    $juba = $this->platform()->branch('acme', 'Juba', 'JUB');
    $orgId = $this->platform()->organization('acme')['id'];
    $api = app(PlatformApi::class);

    $branches = $api->branches($orgId);
    expect($branches)->toHaveCount(1)
        ->and($branches[0]->id)->toBe($juba)
        ->and($branches[0]->name)->toBe('Juba')
        ->and($branches[0]->isActive())->toBeTrue();

    $this->platform()->branch('acme', 'Wau');
    expect($api->branches($orgId))->toHaveCount(1); // cached

    $api->forget($orgId);
    expect(array_map(fn ($b) => $b->name, $api->branches($orgId)))->toBe(['Juba', 'Wau']);
});

it('treats branches of an organization it cannot see as not visible', function () {
    $this->platform()->organization('acme');

    expect(fn () => app(PlatformApi::class)->branches('0199a0b0-0000-7000-8000-00000000dead'))
        ->toThrow(NotVisible::class);
});

it('binds tenant-owned models from the URL inside the right workspace', function () {
    Route::prefix('pharmacy')->middleware(['web', 'platform'])
        ->get('prescriptions/{prescription}', fn (Prescription $prescription) => $prescription->reference);

    $acme = $this->asTenant('acme', fn () => Prescription::query()->create(['reference' => 'A-1']));
    $globex = $this->asTenant('globex', fn () => Prescription::query()->create(['reference' => 'G-1']));

    $this->actingAsMember('acme', ['pharmacy.dispense']);

    $this->get($this->workspaceUrl('acme', 'prescriptions/'.$acme->id))->assertOk()->assertSee('A-1');
    // Another workspace's record id: not found, never shown.
    $this->get($this->workspaceUrl('acme', 'prescriptions/'.$globex->id))->assertNotFound();
});
