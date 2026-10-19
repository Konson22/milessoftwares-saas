<?php

use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Department;
use App\Modules\Structure\Models\Position;
use App\Support\Tenancy\TenantContext;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
});

it('lists the organization\'s departments for an entitled application', function () {
    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl("organizations/{$this->organization->id}/departments"))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=60, private')
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Head office')
        ->assertJsonPath('data.0.branch_id', null)
        ->assertJsonPath('data.1.id', $this->jubaSales->id)
        ->assertJsonPath('data.1.branch_id', $this->juba->id)
        ->assertJsonPath('data.1.code', 'JSALES')
        ->assertJsonPath('data.2.parent_department_id', $this->jubaSales->id)
        ->assertJsonPath('data.2.status', 'active');
});

it('lists the organization\'s positions for an entitled application', function () {
    $clerk = app(TenantContext::class)->run($this->organization, fn () => Position::query()->create([
        'title' => 'Sales clerk', 'code' => 'CLERK', 'department_id' => $this->jubaSales->id,
    ]));
    app(TenantContext::class)->run($this->organization, fn () => Position::query()->create(['title' => 'Accountant', 'code' => 'ACC']));
    app(TenantContext::class)->clear();

    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl("organizations/{$this->organization->id}/positions"))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Accountant')
        ->assertJsonPath('data.0.department_id', null)
        ->assertJsonPath('data.1.id', $clerk->id)
        ->assertJsonPath('data.1.department_id', $this->jubaSales->id)
        ->assertJsonPath('data.1.code', 'CLERK');
});

it('never shows another organization\'s departments or positions', function () {
    $other = Organization::factory()->create();
    app(TenantContext::class)->run($other, function () {
        Department::query()->create(['name' => 'Elsewhere', 'code' => 'ELS']);
        Position::query()->create(['title' => 'Elsewhere', 'code' => 'ELS']);
    });
    app(TenantContext::class)->clear();

    foreach (['departments', 'positions'] as $list) {
        // Never entitled to the calling application: not visible at all.
        $this->withToken($this->serviceToken())
            ->getJson($this->apiUrl("organizations/{$other->id}/{$list}"))
            ->assertNotFound();

        $this->withToken($this->serviceToken())
            ->getJson($this->apiUrl("organizations/{$this->organization->id}/{$list}"))
            ->assertJsonMissing(['name' => 'Elsewhere'])
            ->assertJsonMissing(['title' => 'Elsewhere']);
    }
});

it('needs a service token with access.read', function () {
    foreach (['departments', 'positions'] as $list) {
        $this->withoutToken()->getJson($this->apiUrl("organizations/{$this->organization->id}/{$list}"))->assertUnauthorized();

        $this->withToken($this->serviceToken('events.issue'))
            ->getJson($this->apiUrl("organizations/{$this->organization->id}/{$list}"))
            ->assertForbidden();
    }
});
