<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\ProposedAccess;
use App\Modules\Structure\Models\Branch;
use App\Sites\Central\Http\Controllers\Api\Concerns\RunsAsWorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Invitations from an application's own app (the mobile counter): the
 * workspace's "Invitations" page, for the roles of the calling application
 * only. Same rules as the web: owners and members who manage access, each
 * role at a scope they may grant, one pending invitation per address.
 */
class AppInvitationController extends Controller
{
    use RunsAsWorkspaceMember;

    public function __construct(
        private readonly Invitations $invitations,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        return $this->as($request, $organization, function (OrganizationMembership $membership, Application $application): JsonResponse {
            $entitled = Entitlement::query()->current()->whereNull('feature_key')->where('application_id', $application->id)->exists();

            return response()->json([
                'invitations' => Invitation::query()
                    ->with('inviter.user:id,name')
                    ->latest()
                    ->limit(100)
                    ->get()
                    ->map(fn (Invitation $invitation) => [
                        'id' => $invitation->id,
                        'email' => $invitation->email,
                        'status' => $invitation->effectiveStatus(),
                        'invited_by' => $invitation->inviter->user->name,
                        'expires_at' => $invitation->expires_at->toDateString(),
                        'roles' => ApplicationRole::query()
                            ->whereIn('id', array_column($invitation->proposedRoles(), 'role_id'))
                            ->pluck('name')
                            ->all(),
                    ])
                    ->all(),
                'roles' => $entitled && $application->current_catalog_version_id !== null
                    ? ApplicationRole::query()
                        ->where('catalog_version_id', $application->current_catalog_version_id)
                        ->orderBy('sensitivity')
                        ->get()
                        ->map(fn (ApplicationRole $role) => [
                            'id' => $role->id,
                            'name' => $role->name,
                            'scopes' => array_map(fn ($type) => $type->value, $role->allowedScopeTypes()),
                        ])
                        ->values()
                        ->all()
                    : [],
                'branches' => Branch::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            ]);
        });
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            ...ProposedAccess::rules(),
        ]);

        return $this->as($request, $organization, function (OrganizationMembership $membership, Application $application) use ($validated): JsonResponse {
            $proposed = ProposedAccess::fromInput($validated);

            // This app grants its own application's roles; the rest stays on the web.
            $foreign = ApplicationRole::query()
                ->whereIn('id', array_column($proposed, 'role_id'))
                ->where('catalog_version_id', '!=', $application->current_catalog_version_id)
                ->exists();
            if ($foreign) {
                throw ValidationException::withMessages(['role_ids' => __('Choose a role of this app.')]);
            }

            try {
                [$invitation] = $this->attempt(
                    fn () => $this->invitations->invite($membership, $validated['email'], $proposed, (bool) ($validated['acknowledge_sod'] ?? false)),
                    'email',
                );
            } catch (SeparationOfDutiesWarning $warning) {
                throw ValidationException::withMessages(['acknowledge_sod' => $warning->conflicts]);
            }

            return response()->json([
                'invitation' => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'expires_at' => $invitation->expires_at->toDateString(),
                ],
            ], 201);
        });
    }

    public function resend(Request $request, string $organization, string $invitation): JsonResponse
    {
        return $this->as($request, $organization, function (OrganizationMembership $membership) use ($invitation): JsonResponse {
            $record = Invitation::query()->findOrFail($invitation);
            $this->attempt(fn () => $this->invitations->resend($membership, $record), 'invitation');

            return response()->json(['message' => __('Invitation resent with a new link.')]);
        });
    }

    public function destroy(Request $request, string $organization, string $invitation): JsonResponse
    {
        return $this->as($request, $organization, function (OrganizationMembership $membership) use ($invitation): JsonResponse {
            $record = Invitation::query()->findOrFail($invitation);
            $this->attempt(fn () => $this->invitations->revoke($membership, $record), 'invitation');

            return response()->json(['message' => __('Invitation revoked.')]);
        });
    }

    /**
     * As a member who may manage access in the workspace.
     *
     * @param  callable(OrganizationMembership, Application): JsonResponse  $callback
     */
    private function as(Request $request, string $slug, callable $callback): JsonResponse
    {
        return $this->asMember(
            $request,
            $slug,
            fn (WorkspaceAbilities $abilities, OrganizationMembership $membership): bool => $abilities->manageAccess($membership),
            $callback,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action, string $field): mixed
    {
        try {
            return $action();
        } catch (MembershipActionDenied $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
