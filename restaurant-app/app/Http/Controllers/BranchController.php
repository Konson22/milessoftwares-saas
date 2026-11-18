<?php

namespace App\Http\Controllers;

use App\Support\Branches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;

/**
 * The branches a member may work in, each a link to its own workspace
 * (branches/{branch}/...). Nothing is "chosen" for the session: every tab
 * is in the branch its address names.
 */
class BranchController extends Controller
{
    /**
     * With nothing to open, the page says why: the organization has no
     * branch yet (owners get a link to add one in the workspace), or the
     * member has no role here in any of them. $page: an old link's page, to
     * open in the branch picked.
     */
    public function edit(Request $request, Branches $branches, CurrentAccess $access): Response
    {
        $available = $branches->available();
        $isOwner = $access->get()->isOwner ?? false;
        $page = trim((string) $request->query('page', ''), '/');

        return Inertia::render('branch', [
            'branches' => array_map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
                'url' => route('home', ['branch' => $branch->id]).($page !== '' && ! str_contains($page, '//') ? "/{$page}" : ''),
            ], $available),
            'current' => $branches->remembered($request)?->id,
            'reason' => $available !== [] ? null : ($branches->anyActive() ? 'no_role' : 'no_branches'),
            'structureUrl' => $isOwner ? $request->getSchemeAndHttpHost().'/manage/structure' : null,
        ]);
    }

    /**
     * The old way of choosing (a form posting branch_id): it opens that
     * branch's workspace, or management for "all".
     */
    public function update(Request $request, Branches $branches): RedirectResponse
    {
        if ($request->input('branch_id') === 'all') {
            abort_unless($branches->overview(), 404);

            return redirect()->route('overview');
        }

        $validated = $request->validate(['branch_id' => ['required', 'uuid']]);
        $branch = $branches->find($validated['branch_id']);

        // Only branches open to this member: anything else looks unknown.
        abort_if($branch === null, 404);

        return redirect()->route('home', ['branch' => $branch->id]);
    }
}
