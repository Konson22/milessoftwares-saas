<?php

namespace App\Sites\Central\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Actions\Invitations;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\ProposedAccess;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Sites\Central\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    use AuthorizesWorkspaceAdministration;

    public function __construct(
        private readonly AccessResolver $access,
        private readonly Invitations $invitations,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->authorizeAccessManagement($request, $this->access);

        $entitled = Entitlement::query()->current()->whereNull('feature_key')->pluck('application_id');

        return Inertia::render('central/workspace/manage/invitations', [
            'invitations' => Invitation::query()
                ->with('inviter.user:id,name')
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (Invitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'status' => $invitation->effectiveStatus(),
                    'invitedBy' => $invitation->inviter->user->name,
                    'expiresAt' => $invitation->expires_at->toDateString(),
                    'proposedRoles' => count($invitation->proposedRoles()),
                ])
                ->all(),
            'roles' => Application::query()
                ->whereNotNull('current_catalog_version_id')
                ->where(fn ($query) => $query->where('kind', ApplicationKind::Platform->value)->orWhereIn('id', $entitled))
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
                        'scopes' => array_map(fn ($type) => $type->value, $role->allowedScopeTypes()),
                    ]))
                ->values()
                ->all(),
            // People added with a temporary password who have not chosen their own yet.
            'awaiting' => OrganizationMembership::query()
                ->where('status', MembershipStatus::Active)
                ->whereHas('user', fn ($query) => $query
                    ->where('created_by_organization_id', $actor->organization_id)
                    ->whereNotNull('password_change_required_at'))
                ->with('user')
                ->get()
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'expiresAt' => $membership->user->temporary_password_expires_at?->toDateString(),
                    'expired' => $membership->user->temporary_password_expires_at?->isPast() ?? false,
                ])
                ->all(),
            'branches' => Branch::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $inviter = $this->authorizeAccessManagement($request, $this->access);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            ...ProposedAccess::rules(),
        ]);

        $proposed = ProposedAccess::fromInput($validated);

        try {
            $this->attempt(fn () => $this->invitations->invite($inviter, $validated['email'], $proposed, (bool) ($validated['acknowledge_sod'] ?? false)), 'email');
        } catch (SeparationOfDutiesWarning $warning) {
            throw ValidationException::withMessages(['acknowledge_sod' => $warning->conflicts]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back();
    }

    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        $actor = $this->authorizeAccessManagement($request, $this->access);
        $this->attempt(fn () => $this->invitations->resend($actor, $invitation), 'invitation');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent with a new link.')]);

        return back();
    }

    public function destroy(Request $request, Invitation $invitation): RedirectResponse
    {
        $actor = $this->authorizeAccessManagement($request, $this->access);
        $this->attempt(fn () => $this->invitations->revoke($actor, $invitation), 'invitation');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return back();
    }

    private function attempt(callable $action, string $field): void
    {
        try {
            $action();
        } catch (MembershipActionDenied $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
