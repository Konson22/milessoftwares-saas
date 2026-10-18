<?php

namespace App\Sites\Admin\Http\Controllers;

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
        // Reports older than five minutes mean the service stopped reporting.
        $stale = now()->subMinutes(5);

        return Inertia::render('admin/overview', [
            'services' => DB::table('applications')
                ->leftJoin('application_health', 'application_health.application_id', '=', 'applications.id')
                ->where('applications.kind', '!=', 'platform')
                ->orderBy('applications.name')
                ->get(['applications.code', 'applications.name', 'application_health.status', 'application_health.version', 'application_health.message', 'application_health.reported_at', 'application_health.status_since'])
                ->map(fn (object $row) => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'status' => $row->reported_at === null || now()->parse($row->reported_at)->lt($stale) ? 'unknown' : $row->status,
                    'version' => $row->version,
                    'message' => $row->message,
                    'reportedAt' => $row->reported_at,
                    'statusSince' => $row->status_since,
                ])
                ->all(),
            'organizations' => Organization::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'pendingSupportRequests' => DB::table('support_access_grants')
                ->whereNull('approved_by_staff_id')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->count(),
            'billingQueue' => [
                'reportedPayments' => DB::table('payments')->where('status', 'reported')->count(),
                'pendingRequests' => DB::table('plan_requests')->where('status', 'pending')->count(),
                'overdueInvoices' => DB::table('invoices')->where('status', 'open')->where('due_on', '<', today())->count(),
            ],
            'activeSupportGrants' => DB::table('support_access_grants')
                ->whereNotNull('approved_by_staff_id')
                ->whereNull('revoked_at')
                ->where('starts_at', '<=', now())
                ->where('expires_at', '>', now())
                ->count(),
        ]);
    }
}
