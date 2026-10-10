<?php

namespace Tests\Support;

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Support\Tenancy\TenantContext;

/**
 * An organization with an owner, two branches, a department tree, the
 * platform and retail catalogs imported, and helpers to grant roles directly
 * (bypassing delegation) to set up a scenario.
 *
 * Call prepareAuthorizationFixtures() explicitly. (A method named
 * setUpAuthorizationFixtures would also be run automatically by Laravel,
 * building everything twice.)
 */
trait AuthorizationFixtures
{
    public Organization $organization;

    public OrganizationMembership $owner;

    public Branch $juba;

    public Branch $wau;

    public Department $jubaSales;

    public Department $jubaSalesFloor;

    public Department $headOffice;

    protected function prepareAuthorizationFixtures(bool $entitleRetail = true): void
    {
        $importer = app(CatalogImporter::class);
        $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
        $importer->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));

        $this->organization = Organization::factory()->create();
        app(TenantContext::class)->set($this->organization);

        $this->owner = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->forceFill(['owner_membership_id' => $this->owner->id])->save();

        $this->juba = Branch::query()->create(['name' => 'Juba', 'code' => 'JUB']);
        $this->wau = Branch::query()->create(['name' => 'Wau', 'code' => 'WAU']);
        $this->jubaSales = Department::query()->create(['name' => 'Juba sales', 'code' => 'JSALES', 'branch_id' => $this->juba->id]);
        $this->jubaSalesFloor = Department::query()->create(['name' => 'Sales floor', 'code' => 'JFLOOR', 'parent_department_id' => $this->jubaSales->id]);
        $this->headOffice = Department::query()->create(['name' => 'Head office', 'code' => 'HQ']);

        if ($entitleRetail) {
            $this->entitle('retail');
        }
    }

    protected function entitle(string $applicationCode): Entitlement
    {
        $entitlement = new Entitlement;
        $entitlement->forceFill([
            'application_id' => Application::query()->where('code', $applicationCode)->value('id'),
            'source' => 'manual',
            'valid_from' => now()->subDay(),
        ])->save();

        return $entitlement;
    }

    protected function member(): OrganizationMembership
    {
        return OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function role(string $applicationCode, string $key): ApplicationRole
    {
        $application = Application::query()->where('code', $applicationCode)->firstOrFail();

        return ApplicationRole::query()
            ->where('catalog_version_id', $application->current_catalog_version_id)
            ->where('key', $key)
            ->firstOrFail();
    }

    /**
     * Grants a role as the owner: no delegation limits, only entitlement and SoD.
     */
    protected function grant(OrganizationMembership $member, string $applicationCode, string $roleKey, ?Scope $scope = null): RoleAssignment
    {
        return app(AssignRole::class)->handle($this->owner, $member, $this->role($applicationCode, $roleKey), $scope ?? Scope::organization());
    }

    protected function assignAs(OrganizationMembership $grantor, OrganizationMembership $grantee, string $applicationCode, string $roleKey, ?Scope $scope = null, bool $acknowledge = false): RoleAssignment
    {
        return app(AssignRole::class)->handle($grantor, $grantee, $this->role($applicationCode, $roleKey), $scope ?? Scope::organization(), acknowledgeSeparationOfDuties: $acknowledge);
    }
}
