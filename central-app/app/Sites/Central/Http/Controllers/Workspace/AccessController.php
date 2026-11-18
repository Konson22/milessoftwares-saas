<?php

namespace App\Sites\Central\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Actions\RevokeRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Actions\SetApplicationAccess;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Sites\Central\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceAdministration;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role assignments in the current workspace. Showing the page needs access
 * management somewhere; each assignment and revocation is then checked
 * against its own scope by AssignRole and RevokeRole.
 *
 * The owner holds every permission in every application the business has
 * (Integration\Support\EffectiveAccess), so the page shows that instead of
 * roles and no role is given to them. Roles they already hold can still be
 * removed.
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

        // Login state per member and application; no row means enabled.
        $grants = DB::table('application_access_grants')
            ->join('applications', 'applications.id', '=', 'application_access_grants.application_id')
            ->whereIn('application_access_grants.status', ['disabled', 'suspended'])
            ->get(['application_access_grants.membership_id', 'applications.code', 'application_access_grants.status'])
            ->groupBy('membership_id');

        return Inertia::render('central/workspace/manage/access', [
            'members' => OrganizationMembership::query()
                ->with('user:id,name,email')
                ->where('status', 'active')
                ->orderBy('joined_at')
                ->get()
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->signInName(),
                    'isOwner' => $this->access->isOwner($membership),
                    'applicationAccess' => collect($grants->get($membership->id, []))->pluck('status', 'code')->all(),
                    'assignments' => RoleAssignment::query()
                        ->live()
                        ->where('membership_id', $membership->id)
                        ->with('role.application:id,code,name')
                        ->get()
                        ->map(fn (RoleAssignment $assignment) => [
                            'id' => $assignment->id,
                            'role' => $assignment->role->name,
                            'application' => $assignment->role->application->name,
                            'applicationCode' => $assignment->role->application->code,
                            'scope' => $scopeLabel($assignment),
                            'expiresAt' => $assignment->expires_at?->toDateString(),
                        ])
                        ->all(),
                ])
                ->all(),
            'roles' => $this->assignableRoles(),
            'applications' => $this->entitledApplications(),
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
            // Several roles at once; a single role_id is still accepted.
            'role_id' => ['required_without:role_ids', 'nullable', 'uuid'],
            'role_ids' => ['required_without:role_id', 'array', 'list', 'max:20'],
            'role_ids.*' => ['uuid', 'distinct'],
            'scope_type' => ['required', Rule::in(['organization', 'branch', 'department'])],
            'branch_id' => ['required_if:scope_type,branch', 'nullable', 'uuid'],
            'department_id' => ['required_if:scope_type,department', 'nullable', 'uuid'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'acknowledge_sod' => ['sometimes', 'boolean'],
        ]);

        $roleIds = array_values(array_unique(array_filter([...($validated['role_ids'] ?? []), $validated['role_id'] ?? null])));

        // Tenant-scoped lookups: a foreign membership id is simply not found.
        $grantee = OrganizationMembership::query()->find($request->string('membership_id')->toString());
        $roles = ApplicationRole::query()->with('application')->whereIn('id', $roleIds)->get();

        if ($grantee === null || $roles->count() !== count($roleIds) || $grantee->organization_id !== $organizationId) {
            throw ValidationException::withMessages(['membership_id' => 'Choose a member and a role.']);
        }

        if ($this->access->isOwner($grantee)) {
            throw ValidationException::withMessages(['membership_id' => 'The owner already has full access to every application.']);
        }

        $scope = Scope::fromInput($validated);
        $expiresAt = isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at'])->endOfDay() : null;

        // All or none: each role is checked against the ones given before it,
        // so separation of duties sees the whole set.
        try {
            DB::transaction(function () use ($assign, $grantor, $grantee, $roles, $scope, $expiresAt, $validated): void {
                foreach ($roles as $role) {
                    try {
                        $assign->handle($grantor, $grantee, $role, $scope, $expiresAt, (bool) ($validated['acknowledge_sod'] ?? false));
                    } catch (AssignmentDenied $e) {
                        throw ValidationException::withMessages(['role_id' => $roles->count() > 1 ? "{$role->name}: {$e->getMessage()}" : $e->getMessage()]);
                    }
                }
            });
        } catch (SeparationOfDutiesWarning $warning) {
            throw ValidationException::withMessages(['acknowledge_sod' => $warning->conflicts]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $roles->count() > 1 ? __(':count roles assigned.', ['count' => $roles->count()]) : __('Role assigned.')]);

        return back();
    }

    /**
     * Enable, disable or suspend a member's sign-in to one application.
     */
    public function application(Request $request, SetApplicationAccess $set): RedirectResponse
    {
        $actor = $this->authorizeAccessManagement($request, $this->access);

        $validated = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'application' => ['required', 'string', 'max:64'],
            'status' => ['required', Rule::in(SetApplicationAccess::STATES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $member = OrganizationMembership::query()->whereKey((string) $validated['membership_id'])->first();
        $application = Application::query()->where('code', $validated['application'])->where('kind', '!=', ApplicationKind::Platform->value)->first();
        if ($member === null || $application === null) {
            throw ValidationException::withMessages(['membership_id' => 'Choose a member and an application.']);
        }

        try {
            $set->handle($actor, $member, $application, $validated['status'], $validated['reason'] ?? null);
        } catch (AssignmentDenied $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($validated['status']) {
            'active' => __(':name can sign in to :app.', ['name' => $member->user->name, 'app' => $application->name]),
            'disabled' => __(':name can no longer sign in to :app.', ['name' => $member->user->name, 'app' => $application->name]),
            default => __(':name is suspended from :app.', ['name' => $member->user->name, 'app' => $application->name]),
        }]);

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
     * The products the business has now, which the owner has full access to.
     *
     * @return list<array{code: string, name: string}>
     */
    private function entitledApplications(): array
    {
        $entitled = Entitlement::query()->current()->whereNull('feature_key')->pluck('application_id');

        return array_values(Application::query()
            ->whereIn('id', $entitled)
            ->where('kind', '!=', ApplicationKind::Platform->value)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (Application $application) => ['code' => $application->code, 'name' => $application->name])
            ->all());
    }

    /**
     * Roles of the current catalog versions of the platform and every
     * application the organization is entitled to.
     *
     * @return list<array{id: string, name: string, applicationId: string, application: string, scopes: list<string>, sensitivity: int, delegable: bool}>
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
                    'applicationId' => $application->id,
                    'application' => $application->name,
                    'applicationCode' => $application->code,
                    'scopes' => array_map(fn ($type) => $type->value, $role->allowedScopeTypes()),
                    'sensitivity' => $role->sensitivity,
                    'delegable' => $role->is_delegable,
                ]))
            ->all());
    }
}
