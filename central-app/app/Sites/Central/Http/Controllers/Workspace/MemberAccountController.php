<?php

namespace App\Sites\Central\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Actions\TemporaryAccounts;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\ProposedAccess;
use App\Sites\Central\Http\Controllers\Workspace\Concerns\AuthorizesWorkspaceAdministration;
use App\Support\Hosts\PlatformHosts;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Either an email or, for someone without one (a pump attendant, say), a username.
        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:username', 'prohibits:username', 'email', 'max:255'],
            'username' => ['nullable', 'required_without:email', 'string', 'regex:'.TemporaryAccounts::USERNAME_PATTERN],
            ...ProposedAccess::rules(),
        ], [
            'username.regex' => __('A username is 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit.'),
        ]);

        $proposed = ProposedAccess::fromInput($validated);
        $field = filled($validated['email'] ?? null) ? 'email' : 'username';

        try {
            $result = $this->accounts->add($actor, $validated['name'], $validated['email'] ?? null, $proposed, (bool) ($validated['acknowledge_sod'] ?? false), $validated['username'] ?? null);
        } catch (MembershipActionDenied $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
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
            'login' => $membership->user->signInName(),
            'loginKind' => $membership->user->hasUsernameOnly() ? 'username' : 'email',
            'password' => $password,
            'expiresAt' => $expiresAt->toDateString(),
            'signInUrl' => PlatformHosts::url(PlatformHosts::workspace((string) $membership->organization->primarySlug?->slug)),
            'roleNote' => $roleNote,
        ]);
    }
}
