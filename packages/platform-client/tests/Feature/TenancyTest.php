<?php

use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CrossTenantWrite;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Miles\PlatformClient\Tenancy\MissingTenant;
use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\Fixtures\Prescription;

function organization(string $slug): Organization
{
    return Organization::fromArray((new FakePlatform)->organizations[$slug]);
}

beforeEach(function () {
    $this->tenant = app(CurrentTenant::class);
});

it('fails closed: no tenant, no query', function () {
    expect(fn () => Prescription::query()->count())->toThrow(MissingTenant::class);
});

it('reads and writes only the current tenant', function () {
    $this->tenant->run(organization('acme'), fn () => Prescription::query()->create(['reference' => 'A-1']));
    $this->tenant->run(organization('globex'), fn () => Prescription::query()->create(['reference' => 'G-1']));

    $acme = $this->tenant->run(organization('acme'), fn () => Prescription::query()->pluck('reference')->all());

    expect($acme)->toBe(['A-1'])
        ->and(Prescription::acrossTenants()->count())->toBe(2);
});

it('ignores organization_id from mass assignment', function () {
    $prescription = $this->tenant->run(organization('acme'), fn () => Prescription::query()->create([
        'reference' => 'A-2',
        'organization_id' => FakePlatform::uuid('globex'),
    ]));

    expect($prescription->organization_id)->toBe(FakePlatform::uuid('acme'));
});

it('refuses to write into or move a record to another organization', function () {
    $this->tenant->run(organization('acme'), function () {
        $foreign = new Prescription(['reference' => 'X']);
        $foreign->forceFill(['organization_id' => FakePlatform::uuid('globex')]);

        expect(fn () => $foreign->save())->toThrow(CrossTenantWrite::class);

        $own = Prescription::query()->create(['reference' => 'A-3']);
        $own->forceFill(['organization_id' => FakePlatform::uuid('globex')]);

        expect(fn () => $own->save())->toThrow(CrossTenantWrite::class);
    });
});

it('restores the previous tenant after run()', function () {
    $this->tenant->set(organization('acme'));

    $this->tenant->run(organization('globex'), fn () => null);

    expect($this->tenant->id())->toBe(FakePlatform::uuid('acme'));
});
