<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Actions\RevokeRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * An application looking after its own people from its own screens
 * (platform.application_people_managers: Logbook's Manager). The product
 * backend acts for one member (actor_user_id); every rule is the
 * workspace's own: Invitations, AssignRole and RevokeRole decide, with
 * ApplicationPeopleManagers letting the application's people managers
 * through for that application's roles only. Roles are always the calling
 * application's, at organization scope.
 *
 * Reads (members to choose from, pending invitations) use access.read;
 * changes use members.write.
 */
class AppPeopleController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Active members of the workspace, to give a role to someone already in it.
     */
    public function members(Request $request): JsonResponse
    {
        $validated = $request->validate(['organization_id' => ['required', 'uuid']]);

        return $this->inOrganization($request, $validated['organization_id'], fn (Application $application, Organization $organization) => response()->json([
            'data' => OrganizationMembership::query()
                ->where('status', MembershipStatus::Active)
                ->with('user:id,name,email,username')
                ->get()
                ->sortBy(fn (OrganizationMembership $membership) => mb_strtolower($membership->user->name))
                ->map(fn (OrganizationMembership $membership) => [
                    'membership_id' => $membership->id,
                    'sub' => $membership->user_id,
                    'name' => $membership->user->name,
                    'sign_in_name' => $membership->user->signInName(),
                    'is_owner' => $organization->owner_membership_id === $membership->id,
                ])
                ->values()
                ->all(),
        ])->header('Cache-Control', 'private, no-store'));
    }

    /**
     * Open invitations proposing one of this application's roles.
     */
    public function invitations(Request $request): JsonResponse
    {
        $validated = $request->validate(['organization_id' => ['required', 'uuid']]);

        return $this->inOrganization($request, $validated['organization_id'], function (Application $application): JsonResponse {
            $roles = ApplicationRole::query()->where('application_id', $application->id)->pluck('name', 'id');

            return response()->json([
                'data' => Invitation::query()
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->with('inviter.user:id,name')
                    ->latest()
                    ->limit(200)
                    ->get()
                    ->map(fn (Invitation $invitation) => [
                        'id' => $invitation->id,
                        'email' => $invitation->email,
                        'invited_by' => $invitation->inviter->user->name,
                        'expires_at' => $invitation->expires_at->toIso8601String(),
                        'roles' => array_values(array_filter(array_map(
                            fn (array $proposal) => $roles->get($proposal['role_id']),
                            $invitation->proposedRoles(),
                        ))),
                    ])
                    ->filter(fn (array $invitation) => $invitation['roles'] !== [])
                    ->values()
                    ->all(),
            ])->header('Cache-Control', 'private, no-store');
        });
    }

    public function invite(Request $request, Invitations $invitations): JsonResponse
    {
        $validated = $request->validate([
            ...$this->actorRules(),
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:100'],
            'acknowledge_sod' => ['sometimes', 'boolean'],
        ]);

        return $this->asActor($request, $validated, function (Application $application, OrganizationMembership $actor) use ($validated, $invitations): JsonResponse {
            $role = $this->role($application, $validated['role']);

            try {
                [$invitation] = $invitations->invite($actor, $validated['email'], [
                    ['role_id' => $role->id, 'scope' => ['scope_type' => 'organization', 'branch_id' => null, 'department_id' => null]],
                ], (bool) ($validated['acknowledge_sod'] ?? false));
            } catch (MembershipActionDenied $e) {
                throw ValidationException::withMessages(['email' => $e->getMessage()]);
            } catch (SeparationOfDutiesWarning $warning) {
                throw ValidationException::withMessages(['acknowledge_sod' => $warning->conflicts]);
            }

            return response()->json([
                'id' => $invitation->id,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ], 201);
        });
    }

    public function cancelInvitation(Request $request, string $invitation, Invitations $invitations): JsonResponse
    {
        $validated = $request->validate($this->actorRules());

        return $this->asActor($request, $validated, function (Application $application, OrganizationMembership $actor) use ($invitation, $invitations): JsonResponse {
            $record = Invitation::query()->find($invitation);
            $ours = $record !== null && ApplicationRole::query()
                ->where('application_id', $application->id)
                ->whereIn('id', array_column($record->proposedRoles(), 'role_id'))
                ->exists();
            abort_unless($ours, 404);

            try {
                $invitations->revoke($actor, $record);
            } catch (MembershipActionDenied) {
                // Invitations::revoke lets administrators through, and a people manager for the roles it proposes.
                throw ValidationException::withMessages(['invitation' => __('You cannot cancel that invitation.')]);
            }

            return response()->json(['message' => __('Invitation cancelled.')]);
        });
    }

    public function assign(Request $request, AssignRole $assign): JsonResponse
    {
        $validated = $request->validate([
            ...$this->actorRules(),
            'membership_id' => ['required', 'uuid'],
            'role' => ['required', 'string', 'max:100'],
            'acknowledge_sod' => ['sometimes', 'boolean'],
        ]);

        return $this->asActor($request, $validated, function (Application $application, OrganizationMembership $actor) use ($validated, $assign): JsonResponse {
            $role = $this->role($application, $validated['role']);
            $grantee = OrganizationMembership::query()->with('organization')->find($validated['membership_id']);
            if ($grantee === null) {
                throw ValidationException::withMessages(['membership_id' => __('That person is not a member of this workspace.')]);
            }

            try {
                $assign->handle($actor, $grantee, $role, Scope::organization(), acknowledgeSeparationOfDuties: (bool) ($validated['acknowledge_sod'] ?? false));
            } catch (AssignmentDenied $e) {
                throw ValidationException::withMessages(['role' => $e->getMessage()]);
            } catch (SeparationOfDutiesWarning $warning) {
                throw ValidationException::withMessages(['acknowledge_sod' => $warning->conflicts]);
            }

            return response()->json(['message' => __(':role given.', ['role' => $role->name])], 201);
        });
    }

    /**
     * Takes back every live assignment of the role from the member.
     */
    public function revoke(Request $request, RevokeRole $revoke): JsonResponse
    {
        $validated = $request->validate([
            ...$this->actorRules(),
            'membership_id' => ['required', 'uuid'],
            'role' => ['required', 'string', 'max:100'],
        ]);

        return $this->asActor($request, $validated, function (Application $application, OrganizationMembership $actor) use ($validated, $revoke): JsonResponse {
            $assignments = RoleAssignment::query()
                ->live()
                ->where('membership_id', $validated['membership_id'])
                ->where('application_id', $application->id)
                ->whereHas('role', fn ($query) => $query->where('key', $validated['role']))
                ->with('role', 'membership')
                ->get();

            try {
                foreach ($assignments as $assignment) {
                    $revoke->handle($actor, $assignment);
                }
            } catch (AssignmentDenied $e) {
                throw ValidationException::withMessages(['role' => $e->getMessage()]);
            }

            return response()->json(['message' => __('Role taken back.')]);
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function actorRules(): array
    {
        return [
            'organization_id' => ['required', 'uuid'],
            'actor_user_id' => ['required', 'uuid'],
        ];
    }

    private function role(Application $application, string $key): ApplicationRole
    {
        $role = ApplicationRole::query()
            ->where('catalog_version_id', $application->current_catalog_version_id)
            ->where('key', $key)
            ->first();

        return $role ?? throw ValidationException::withMessages(['role' => __('That role does not exist in this application.')]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  Closure(Application, OrganizationMembership): JsonResponse  $callback
     */
    private function asActor(Request $request, array $validated, Closure $callback): JsonResponse
    {
        return $this->inOrganization($request, (string) $validated['organization_id'], function (Application $application) use ($validated, $callback): JsonResponse {
            $actor = OrganizationMembership::query()
                ->where('user_id', $validated['actor_user_id'])
                ->where('status', MembershipStatus::Active)
                ->with('user', 'organization')
                ->first();
            if ($actor === null) {
                throw ValidationException::withMessages(['actor_user_id' => __('You are not an active member of this workspace.')]);
            }

            return $callback($application, $actor);
        });
    }

    /**
     * Only applications whose managers look after their people, for an
     * active organization currently entitled to them.
     *
     * @param  Closure(Application, Organization): JsonResponse  $callback
     */
    private function inOrganization(Request $request, string $organizationId, Closure $callback): JsonResponse
    {
        $application = $request->attributes->get('api_application');
        abort_unless($application instanceof Application, 403);
        abort_unless(array_key_exists($application->code, (array) config('platform.application_people_managers', [])), 403);

        $organization = Organization::query()->whereKey($organizationId)->where('status', OrganizationStatus::Active)->first();
        $entitled = $organization !== null && Entitlement::acrossTenants()->current()
            ->where('organization_id', $organization->id)
            ->where('application_id', $application->id)
            ->exists();
        abort_unless($entitled, 404);

        return $this->tenant->run($organization, fn () => $callback($application, $organization));
    }
}
