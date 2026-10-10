<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Memberships\Actions\ManageMembers;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Member directory of the current workspace. Memberships are tenant-scoped:
 * route model binding runs inside the tenant context, so another
 * organization's membership id resolves to 404 here.
 */
class MemberController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('workspace/members/index', [
            'members' => OrganizationMembership::query()
                ->with('user:id,name,email')
                ->orderBy('joined_at')
                ->get()
                ->map(fn (OrganizationMembership $membership) => $this->present($membership))
                ->all(),
        ]);
    }

    public function show(OrganizationMembership $membership): Response
    {
        return Inertia::render('workspace/members/show', [
            'member' => $this->present($membership->load('user:id,name,email')),
        ]);
    }

    public function updateStatus(Request $request, OrganizationMembership $membership, ManageMembers $members): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'removed'])]]);
        $actor = $request->attributes->get('membership');
        abort_unless($actor instanceof OrganizationMembership, 403);

        try {
            $members->setStatus($actor, $membership, MembershipStatus::from($validated['status']));
        } catch (MembershipActionDenied $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member updated.')]);

        return back();
    }

    /**
     * @return array{id: string, name: string, email: string, status: string, joinedAt: string|null}
     */
    private function present(OrganizationMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'status' => $membership->status->value,
            'joinedAt' => $membership->joined_at?->toDateString(),
        ];
    }
}
