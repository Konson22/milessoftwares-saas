<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceAdministration;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Actions\RevokeRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role assignments in the current workspace. Showing the page needs access
 * management somewhere; each assignment and revocation is then checked
 * against its own scope by AssignRole and RevokeRole.
 */
class AccessController extends Controller
{
    use AuthorizesWorkspaceAdministration;

    public function __construct(
        private readonly AccessResolver $access,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAccessManagement($request, $this->access);

        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $departments = Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        $scopeLabel = fn (RoleAssignment $assignment): string => match ($assignment->scope_type->value) {
            'organization' => 'Whole organization',
            'branch' => 'Branch: '.($branches->firstWhere('id', $assignment->branch_id)->name ?? 'archived branch'),
            'department' => 'Department: '.($departments->firstWhere('id', $assignment->department_id)->name ?? 'archived department'),
            default => "{$assignment->resource_type} {$assignment->resource_id}",
        };

        return Inertia::render('workspace/manage/access', [
            'members' => OrganizationMembership::query()
                ->with('user:id,name,email')
                ->where('status', 'active')
                ->orderBy('joined_at')
                ->get()
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'isOwner' => $this->access->isOwner($membership),
                    'assignments' => RoleAssignment::query()
                        ->live()
                        ->where('membership_id', $membership->id)
                        ->with('role.application:id,name')
                        ->get()
                        ->map(fn (RoleAssignment $assignment) => [
                            'id' => $assignment->id,
                            'role' => $assignment->role->name,
                            'application' => $assignment->role->application->name,
                            'scope' => $scopeLabel($assignment),
                            'expiresAt' => $assignment->expires_at?->toDateString(),
                        ])
                        ->all(),
                ])
                ->all(),
            'roles' => $this->assignableRoles(),
            'branches' => $branches,
            'departments' => $departments,
        ]);
    }

    public function store(Request $request, AssignRole $assign): RedirectResponse
    {
        $grantor = $this->authorizeAccessManagement($request, $this->access);
        $organizationId = $this->tenant->require()->getKey();

        $validated = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'role_id' => ['required', 'uuid'],
            'scope_type' => ['required', Rule::in(['organization', 'branch', 'department'])],
            'branch_id' => ['required_if:scope_type,branch', 'nullable', 'uuid'],
            'department_id' => ['required_if:scope_type,department', 'nullable', 'uuid'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'acknowledge_sod' => ['sometimes', 'boolean'],
        ]);

        // Tenant-scoped lookups: a foreign membership id is simply not found.
        $grantee = OrganizationMembership::query()->find($request->string('membership_id')->toString());
        $role = ApplicationRole::query()->with('application')->find($request->string('role_id')->toString());

        if ($grantee === null || $role === null || $grantee->organization_id !== $organizationId) {
            throw ValidationException::withMessages(['membership_id' => 'Choose a member and a role.']);
        }

        try {
            $assign->handle(
                $grantor,
                $grantee,
                $role,
                Scope::fromInput($validated),
                isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at'])->endOfDay() : null,
                (bool) ($validated['acknowledge_sod'] ?? false),
            );
        } catch (AssignmentDenied $e) {
            throw ValidationException::withMessages(['role_id' => $e->getMessage()]);
        } catch (SeparationOfDutiesWarning $warning) {
            throw ValidationException::withMessages(['acknowledge_sod' => $warning->conflicts]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role assigned.')]);

        return back();
    }

    public function destroy(Request $request, RoleAssignment $assignment, RevokeRole $revoke): RedirectResponse
    {
        $revoker = $this->authorizeAccessManagement($request, $this->access);

        try {
            $revoke->handle($revoker, $assignment);
        } catch (AssignmentDenied $e) {
            abort(403, $e->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role removed.')]);

        return back();
    }

    /**
     * Roles of the current catalog versions of the platform and every
     * application the organization is entitled to.
     *
     * @return list<array{id: string, name: string, application: string, scopes: list<string>, sensitivity: int, delegable: bool}>
     */
    private function assignableRoles(): array
    {
        $entitled = Entitlement::query()->current()->whereNull('feature_key')->pluck('application_id');

        return array_values(Application::query()
            ->whereNotNull('current_catalog_version_id')
            ->where(fn ($query) => $query->where('kind', ApplicationKind::Platform->value)->orWhereIn('id', $entitled))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->flatMap(fn (Application $application) => ApplicationRole::query()
                ->where('catalog_version_id', $application->current_catalog_version_id)
                ->orderBy('sensitivity')
                ->get()
                ->map(fn (ApplicationRole $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'application' => $application->name,
                    'scopes' => array_map(fn ($type) => $type->value, $role->allowedScopeTypes()),
                    'sensitivity' => $role->sensitivity,
                    'delegable' => $role->is_delegable,
                ]))
            ->all());
    }
}
