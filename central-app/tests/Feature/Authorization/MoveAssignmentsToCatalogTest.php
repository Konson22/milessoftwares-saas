<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    $this->prepareAuthorizationFixtures();

    $this->publishRetail = function (string $version, array $without = []) {
        $catalog = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true);
        $catalog['version'] = $version;
        $catalog['roles'] = array_values(array_filter($catalog['roles'], fn (array $role) => ! in_array($role['key'], $without, true)));

        app(CatalogImporter::class)->import($catalog);
    };
});

it('moves holders to the same role in the current catalog, with their scope and expiry', function () {
    $member = $this->member();
    $old = $this->grant($member, 'retail', 'auditor');
    $old->forceFill(['expires_at' => now()->addMonth()->startOfSecond()])->save();
    ($this->publishRetail)('99.0.0');

    $this->artisan('platform:catalog-reassign', ['application' => 'retail'])->assertSuccessful();

    $old->refresh();
    $new = RoleAssignment::query()->live()->where('membership_id', $member->id)->sole();

    expect($old->revoked_at)->not->toBeNull()
        ->and($new->id)->not->toBe($old->id)
        ->and($new->application_role_id)->toBe($this->role('retail', 'auditor')->id)
        ->and($new->scope_type)->toBe($old->scope_type)
        ->and($new->expires_at->equalTo($old->expires_at))->toBeTrue()
        ->and($new->granted_by_membership_id)->toBe($old->granted_by_membership_id)
        ->and(AuditLog::query()->where('action', 'role.moved_to_catalog')->count())->toBe(1);
});

it('changes nothing on a dry run', function () {
    $old = $this->grant($this->member(), 'retail', 'auditor');
    ($this->publishRetail)('99.0.0');

    $this->artisan('platform:catalog-reassign', ['application' => 'retail', '--dry-run' => true])
        ->expectsOutputToContain('would move')
        ->assertSuccessful();

    expect($old->refresh()->revoked_at)->toBeNull()
        ->and(RoleAssignment::query()->count())->toBe(1);
});

it('leaves a role the current catalog no longer has, and says so', function () {
    $old = $this->grant($this->member(), 'retail', 'auditor');
    ($this->publishRetail)('99.0.0', without: ['auditor']);

    $this->artisan('platform:catalog-reassign', ['application' => 'retail'])
        ->expectsOutputToContain('left 1')
        ->assertSuccessful();

    expect($old->refresh()->revoked_at)->toBeNull();
});

it('does nothing twice, and leaves revoked and current assignments alone', function () {
    $member = $this->member();
    $this->grant($member, 'retail', 'auditor');
    $revoked = $this->grant($this->member(), 'retail', 'auditor');
    $revoked->forceFill(['revoked_at' => now()])->save();
    ($this->publishRetail)('99.0.0');

    $this->artisan('platform:catalog-reassign', ['application' => 'retail'])->expectsOutputToContain('Moved 1')->assertSuccessful();
    $this->artisan('platform:catalog-reassign', ['application' => 'retail'])->expectsOutputToContain('Moved 0')->assertSuccessful();

    expect(RoleAssignment::query()->live()->where('membership_id', $member->id)->count())->toBe(1)
        ->and(RoleAssignment::query()->where('membership_id', $revoked->membership_id)->count())->toBe(1);
});

it('keeps a branch scope when it moves an assignment', function () {
    $member = $this->member();
    $this->grant($member, 'retail', 'cashier', Scope::branch($this->juba->id));
    ($this->publishRetail)('99.0.0');

    $this->artisan('platform:catalog-reassign', ['application' => 'retail'])->assertSuccessful();

    expect(RoleAssignment::query()->live()->where('membership_id', $member->id)->sole()->branch_id)->toBe($this->juba->id);
});

it('refuses an application with no such code', function () {
    $this->artisan('platform:catalog-reassign', ['application' => 'nothing'])->assertFailed();
});
