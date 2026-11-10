<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Integration\Support\IntegrationEvents;
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

it('revokes holders of a retired role, gives them nothing, and records what they held', function () {
    $member = $this->member();
    $old = $this->grant($member, 'retail', 'payments_clerk');
    ($this->publishRetail)('99.0.0', without: ['payments_clerk']);
    $version = IntegrationEvents::cacheVersion($this->organization->id);

    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'payments_clerk'])
        ->expectsOutputToContain('held retail.payments.create, retail.reports.view')
        ->expectsOutputToContain('Revoked 1 assignment(s); gave 0')
        ->assertSuccessful();

    $log = AuditLog::query()->where('action', 'role.retired')->sole();
    expect($old->refresh()->revoked_at)->not->toBeNull()
        ->and(RoleAssignment::query()->live()->where('membership_id', $member->id)->count())->toBe(0)
        ->and($log->subject_id)->toBe($old->id)
        ->and($log->organization_id)->toBe($this->organization->id)
        ->and($log->context['permissions'])->toBe(['retail.payments.create', 'retail.reports.view'])
        ->and($log->context['replaced_by'])->toBeNull()
        ->and(IntegrationEvents::cacheVersion($this->organization->id))->toBeGreaterThan($version);
});

it('changes and records nothing on a dry run', function () {
    $old = $this->grant($this->member(), 'retail', 'payments_clerk');
    ($this->publishRetail)('99.0.0', without: ['payments_clerk']);

    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'payments_clerk', '--map' => 'auditor', '--dry-run' => true])
        ->expectsOutputToContain('would revoke, auditor would be given')
        ->assertSuccessful();

    expect($old->refresh()->revoked_at)->toBeNull()
        ->and(RoleAssignment::query()->count())->toBe(1)
        ->and(AuditLog::query()->whereIn('action', ['role.retired', 'role.replaced_after_retirement'])->count())->toBe(0);
});

it('gives the mapped role only when asked, with the same scope and expiry', function () {
    $member = $this->member();
    $old = $this->grant($member, 'retail', 'payments_clerk', Scope::branch($this->juba->id));
    $old->forceFill(['expires_at' => now()->addMonth()->startOfSecond()])->save();
    ($this->publishRetail)('99.0.0', without: ['payments_clerk']);

    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'payments_clerk', '--map' => 'auditor'])
        ->expectsOutputToContain('gave 1')
        ->assertSuccessful();

    $new = RoleAssignment::query()->live()->where('membership_id', $member->id)->sole();
    expect($new->application_role_id)->toBe($this->role('retail', 'auditor')->id)
        ->and($new->branch_id)->toBe($this->juba->id)
        ->and($new->expires_at->equalTo($old->expires_at))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'role.replaced_after_retirement')->sole()->context['to_role'])->toBe('auditor');
});

it('reuses a replacement the member already holds', function () {
    $member = $this->member();
    $this->grant($member, 'retail', 'payments_clerk');
    $held = $this->grant($member, 'retail', 'auditor');
    ($this->publishRetail)('99.0.0', without: ['payments_clerk']);
    $held->forceFill(['application_role_id' => $this->role('retail', 'auditor')->id])->save();

    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'payments_clerk', '--map' => 'auditor'])->assertSuccessful();

    expect(RoleAssignment::query()->live()->where('membership_id', $member->id)->sole()->id)->toBe($held->id);
});

it('does not count the retired role against its own replacement', function () {
    $member = $this->member();
    $this->grant($member, 'retail', 'payments_clerk');
    ($this->publishRetail)('99.0.0', without: ['payments_clerk']);

    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'payments_clerk', '--map' => 'payments_approver'])
        ->expectsOutputToContain('gave 1')
        ->assertSuccessful();

    expect(RoleAssignment::query()->live()->where('membership_id', $member->id)->sole()->application_role_id)
        ->toBe($this->role('retail', 'payments_approver')->id);
});

it('revokes but does not give a mapped role a blocking duty pair refuses', function () {
    $member = $this->member();
    $this->grant($member, 'retail', 'payments_clerk');
    $old = $this->grant($member, 'retail', 'auditor');
    ($this->publishRetail)('99.0.0', without: ['auditor']);

    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'auditor', '--map' => 'payments_approver'])
        ->expectsOutputToContain('not given payments_approver')
        ->expectsOutputToContain('1 refused')
        ->assertSuccessful();

    $live = RoleAssignment::query()->live()->where('membership_id', $member->id)->with('role')->get();
    expect($old->refresh()->revoked_at)->not->toBeNull()
        ->and($live->pluck('role.key')->all())->toBe(['payments_clerk']);
});

it('does nothing twice', function () {
    $this->grant($this->member(), 'retail', 'payments_clerk');
    ($this->publishRetail)('99.0.0', without: ['payments_clerk']);

    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'payments_clerk'])->expectsOutputToContain('Revoked 1')->assertSuccessful();
    $this->artisan('platform:catalog-retire-role', ['application' => 'retail', 'role' => 'payments_clerk'])->expectsOutputToContain('Revoked 0')->assertSuccessful();

    expect(AuditLog::query()->where('action', 'role.retired')->count())->toBe(1);
});

it('refuses a role the current catalog still has, a map target it lacks, and an unknown application', function (array $arguments) {
    $old = $this->grant($this->member(), 'retail', 'payments_clerk');
    ($this->publishRetail)('99.0.0', without: ['payments_clerk']);

    $this->artisan('platform:catalog-retire-role', $arguments)->assertFailed();

    expect($old->refresh()->revoked_at)->toBeNull();
})->with([
    'still current' => [['application' => 'retail', 'role' => 'auditor']],
    'map not in catalog' => [['application' => 'retail', 'role' => 'payments_clerk', '--map' => 'payments_clerk']],
    'unknown application' => [['application' => 'nothing', 'role' => 'payments_clerk']],
]);
