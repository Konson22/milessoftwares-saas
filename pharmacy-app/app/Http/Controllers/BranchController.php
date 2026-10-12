<?php

namespace App\Http\Controllers;

use App\Pharmacy\Branches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\Branch;

/**
 * Choosing the branch to work in.
 */
class BranchController extends Controller
{
    public function edit(Request $request, Branches $branches): Response
    {
        return Inertia::render('branch', [
            'branches' => array_map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
            ], $branches->available()),
            'current' => $branches->current($request)?->id,
        ]);
    }

    public function update(Request $request, Branches $branches): RedirectResponse
    {
        $validated = $request->validate(['branch_id' => ['required', 'uuid']]);
        $branch = $branches->find($validated['branch_id']);

        // Only branches open to this member: anything else looks unknown.
        abort_if($branch === null, 404);

        $branches->choose($request, $branch);

        return redirect()->intended(route('home'))->with('success', "Working in {$branch->name}.");
    }
}
