<?php

namespace App\Http\Controllers;

use App\Support\Branches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Miles\PlatformClient\PlatformConfig;

/**
 * Where the restaurant starts, and the old addresses of branch pages.
 *
 * /restaurant/ opens the workspace the member works in: management for
 * those who oversee every branch, their branch for someone in one, the
 * branch list for someone in several.
 *
 * Branch pages used to live at /restaurant/floor, /restaurant/orders/...
 * with the branch kept aside. Bookmarks and old links still work: they
 * open the same page in the branch last worked in (or the only one), and
 * the branch list when there is no telling.
 */
class WorkspaceController extends Controller
{
    public function start(Request $request, Branches $branches): RedirectResponse
    {
        if ($branches->overview()) {
            return redirect()->route('overview');
        }

        $available = $branches->available();

        return count($available) === 1
            ? redirect()->route('home', ['branch' => $available[0]->id])
            : redirect()->route('branch.edit');
    }

    public function legacyBranchPage(Request $request, Branches $branches, PlatformConfig $platform): RedirectResponse
    {
        $branch = $branches->remembered($request);
        $page = ltrim(substr($request->path(), strlen(trim($platform->path(), '/'))), '/');
        $query = $request->getQueryString();

        if ($branch === null) {
            return redirect()->route('branch.edit', ['page' => $page.($query ? "?{$query}" : '')]);
        }

        return redirect()->to(route('home', ['branch' => $branch->id]).'/'.$page.($query ? "?{$query}" : ''));
    }
}
