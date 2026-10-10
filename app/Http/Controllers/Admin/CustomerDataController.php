<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\SupportAccess;
use App\Modules\Administration\Actions\SupportAccessDenied;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Models\SupportAccessGrant;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer data, read through the tenant context of exactly one organization
 * and only under the staff member's own grant that is in effect now. Every
 * view is audited against the organization.
 */
class CustomerDataController extends Controller
{
    public function __construct(
        private readonly SupportAccess $support,
        private readonly TenantContext $tenant,
    ) {}

    public function members(Request $request, Organization $organization): Response
    {
        [$staff, $grant] = $this->authorizeView($request, $organization);
        $this->support->recordDataView($grant, $staff, 'members');

        $members = $this->tenant->run($organization, fn () => OrganizationMembership::query()
            ->with('user:id,name,email')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (OrganizationMembership $membership) => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'status' => $membership->status->value,
                'joinedAt' => $membership->joined_at?->toDateString(),
            ])
            ->all());

        return Inertia::render('admin/organizations/customer-members', [
            'organization' => ['id' => $organization->id, 'displayName' => $organization->display_name],
            'grant' => ['expiresAt' => $grant->expires_at->toIso8601String(), 'scope' => $grant->scope],
            'members' => $members,
        ]);
    }

    public function structure(Request $request, Organization $organization): Response
    {
        [$staff, $grant] = $this->authorizeView($request, $organization);
        $this->support->recordDataView($grant, $staff, 'structure');

        [$branches, $departments] = $this->tenant->run($organization, fn () => [
            Branch::query()->orderBy('name')->get(['id', 'name', 'code', 'status'])->all(),
            Department::query()->orderBy('name')->get(['id', 'name', 'code', 'branch_id', 'status'])->all(),
        ]);

        return Inertia::render('admin/organizations/customer-structure', [
            'organization' => ['id' => $organization->id, 'displayName' => $organization->display_name],
            'grant' => ['expiresAt' => $grant->expires_at->toIso8601String(), 'scope' => $grant->scope],
            'branches' => $branches,
            'departments' => $departments,
        ]);
    }

    /**
     * @return array{0: PlatformStaff, 1: SupportAccessGrant}
     */
    private function authorizeView(Request $request, Organization $organization): array
    {
        $staff = $request->attributes->get('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        try {
            return [$staff, $this->support->requireInEffect($staff, $organization)];
        } catch (SupportAccessDenied $e) {
            abort(403, $e->getMessage());
        }
    }
}
