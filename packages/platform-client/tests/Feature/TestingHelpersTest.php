<?php

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
