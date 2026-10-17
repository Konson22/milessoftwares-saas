<?php

namespace App\Sites\Central\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Actions\TemporaryAccounts;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Sites\Central\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceAdministration;
use App\Support\Hosts\PlatformHosts;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Adding a member directly with a temporary password, and issuing a new one
 * while they have not signed in yet. The password is shown to the
 * administrator once, as flash data: it is never stored in plain text or
 * shown again.
 */
class MemberAccountController extends Controller
{
    use AuthorizesWorkspaceAdministration;

    public function __construct(
        private readonly AccessResolver $access,
        private readonly TemporaryAccounts $accounts,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->authorizeAccessManagement($request, $this->access);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role_id' => ['nullable', 'uuid'],
            'scope_type' => ['required_with:role_id', 'nullable', Rule::in(['organization', 'branch', 'department'])],
            'branch_id' => ['required_if:scope_type,branch', 'nullable', 'uuid'],
            'department_id' => ['required_if:scope_type,department', 'nullable', 'uuid'],
            'acknowledge_sod' => ['sometimes', 'boolean'],
        ]);

        $proposed = empty($validated['role_id']) ? null : [
            'role_id' => (string) $validated['role_id'],
            'scope' => [
                'scope_type' => (string) $validated['scope_type'],
                'branch_id' => $validated['branch_id'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
            ],
        ];

        try {
            $result = $this->accounts->add($actor, $validated['name'], $validated['email'], $proposed, (bool) ($validated['acknowledge_sod'] ?? false));
        } catch (MembershipActionDenied $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        } catch (SeparationOfDutiesWarning $warning) {
            throw ValidationException::withMessages(['acknowledge_sod' => $warning->conflicts]);
        }

        $this->showOnce($result['membership'], $result['password'], $result['expiresAt'], $result['roleNote']);

        return back();
    }

    public function reissue(Request $request, OrganizationMembership $membership): RedirectResponse
    {
        $actor = $this->authorizeAccessManagement($request, $this->access);

        try {
            $result = $this->accounts->reissue($actor, $membership);
        } catch (MembershipActionDenied $e) {
            throw ValidationException::withMessages(['membership' => $e->getMessage()]);
        }

        $this->showOnce($membership, $result['password'], $result['expiresAt'], null);

        return back();
    }

    private function showOnce(OrganizationMembership $membership, string $password, CarbonInterface $expiresAt, ?string $roleNote): void
    {
        Inertia::flash('credentials', [
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'password' => $password,
            'expiresAt' => $expiresAt->toDateString(),
            'signInUrl' => PlatformHosts::url(PlatformHosts::workspace((string) $membership->organization->primarySlug?->slug)),
            'roleNote' => $roleNote,
        ]);
    }
}
