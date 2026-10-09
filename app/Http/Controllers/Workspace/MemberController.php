<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Memberships\Models\OrganizationMembership;
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
