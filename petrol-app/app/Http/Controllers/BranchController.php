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
 * Choosing the branch (station) to work in. The id is accepted only when
 * the platform says this member may work there.
 */
class BranchController extends Controller
{
    public function edit(Request $request, Branches $branches, CurrentAccess $access): Response
    {
        $available = $branches->available();
        $isOwner = $access->get()->isOwner ?? false;

        return Inertia::render('branch', [
            'branches' => array_map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
            ], $available),
            'current' => $branches->current($request)?->id,
            'reason' => $available !== [] ? null : ($branches->anyActive() ? 'no_role' : 'no_branches'),
            'structureUrl' => $isOwner ? $request->getSchemeAndHttpHost().'/manage/structure' : null,
        ]);
    }

    public function update(Request $request, Branches $branches): RedirectResponse
    {
        $validated = $request->validate(['branch_id' => ['required', 'uuid']]);
        $branch = $branches->find($validated['branch_id']);
        abort_if($branch === null, 404);

        $branches->choose($request, $branch);

        return redirect()->intended(route('home'))->with('success', "Working in {$branch->name}.");
    }
}
