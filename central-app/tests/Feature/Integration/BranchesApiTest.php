<?php

use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
});

it('lists the organization\'s branches for an entitled application', function () {
    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl("organizations/{$this->organization->id}/branches"))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=60, private')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->juba->id)
        ->assertJsonPath('data.0.name', 'Juba')
        ->assertJsonPath('data.0.code', 'JUB')
        ->assertJsonPath('data.1.name', 'Wau');
});

it('never shows another organization\'s branches', function () {
    $other = Organization::factory()->create();
    app(TenantContext::class)->run($other, fn () => Branch::query()->create(['name' => 'Elsewhere', 'code' => 'ELS']));
    app(TenantContext::class)->clear();

    // Never entitled to the calling application: not visible at all.
    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl("organizations/{$other->id}/branches"))
        ->assertNotFound();

    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl("organizations/{$this->organization->id}/branches"))
        ->assertJsonMissing(['name' => 'Elsewhere']);
});

it('needs a service token with access.read', function () {
    $this->getJson($this->apiUrl("organizations/{$this->organization->id}/branches"))->assertUnauthorized();

    $this->withToken($this->serviceToken('events.issue'))
        ->getJson($this->apiUrl("organizations/{$this->organization->id}/branches"))
        ->assertForbidden();
});
