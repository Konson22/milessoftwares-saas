<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-level counts only; no customer data.
 */
class OverviewController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/overview', [
            'organizations' => Organization::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'pendingSupportRequests' => DB::table('support_access_grants')
                ->whereNull('approved_by_staff_id')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->count(),
            'activeSupportGrants' => DB::table('support_access_grants')
                ->whereNotNull('approved_by_staff_id')
                ->whereNull('revoked_at')
                ->where('starts_at', '<=', now())
                ->where('expires_at', '>', now())
                ->count(),
        ]);
    }
}
