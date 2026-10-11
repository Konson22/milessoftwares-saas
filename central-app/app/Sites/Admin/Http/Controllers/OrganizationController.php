<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\SupportAccess;
use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Models\SupportAccessGrant;
use App\Modules\Audit\AuditLogger;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization directory for staff. Shows platform-owned metadata only (name,
 * address, status, counts, entitlements). Members, structure and anything
 * else the customer owns needs an active support access grant.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $organizations = Organization::query()
            ->with('primarySlug:id,organization_id,slug')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('display_name', 'ilike', "%{$search}%")
                ->orWhere('legal_name', 'ilike', "%{$search}%")
                ->orWhereHas('slugs', fn ($slugs) => $slugs->where('slug', 'like', '%'.strtolower($search).'%'))))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $memberCounts = DB::table('organization_memberships')
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->where('status', 'active')
            ->selectRaw('organization_id, count(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id');

        return Inertia::render('admin/organizations/index', [
            'search' => $search,
            'organizations' => $organizations->through(fn (Organization $organization) => [
                'id' => $organization->id,
                'displayName' => $organization->display_name,
                'slug' => $organization->primarySlug?->slug,
                'status' => $organization->status->value,
                'createdAt' => $organization->created_at?->toDateString(),
                'members' => (int) ($memberCounts[$organization->id] ?? 0),
            ]),
        ]);
    }

    public function show(Request $request, Organization $organization, SupportAccess $support): Response
    {
        $staff = $this->staff($request);
        $grant = $support->inEffect($staff, $organization);

        return Inertia::render('admin/organizations/show', [
            'organization' => [
                'id' => $organization->id,
                'displayName' => $organization->display_name,
                'legalName' => $organization->legal_name,
                'slug' => $organization->primarySlug?->slug,
                'status' => $organization->status->value,
                'countryCode' => $organization->country_code,
                'currency' => $organization->default_currency,
                'createdAt' => $organization->created_at?->toDateString(),
                'suspendedAt' => $organization->suspended_at?->toDateString(),
            ],
            'entitlements' => DB::table('entitlements')
                ->join('applications', 'applications.id', '=', 'entitlements.application_id')
                ->where('entitlements.organization_id', $organization->id)
                ->orderByDesc('entitlements.valid_from')
                ->get(['entitlements.id', 'applications.name as application', 'entitlements.feature_key', 'entitlements.source', 'entitlements.valid_from', 'entitlements.valid_until', 'entitlements.revoked_at'])
                ->all(),
            'supportGrants' => SupportAccessGrant::query()
                ->where('organization_id', $organization->id)
                ->with(['staff.user:id,name', 'approver.user:id,name'])
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (SupportAccessGrant $grant) => [
                    'id' => $grant->id,
                    'requestedBy' => $grant->staff->user->name,
                    'approvedBy' => $grant->approver?->user->name,
                    'reason' => $grant->reason,
                    'scope' => $grant->scope,
                    'status' => $grant->status(),
                    'expiresAt' => $grant->expires_at->toIso8601String(),
                ])
                ->all(),
            'subscriptions' => DB::table('subscriptions')
                ->where('subscriptions.organization_id', $organization->id)
                ->latest()
                ->get(['id', 'status', 'source', 'trial_ends_at', 'cancelled_at', 'created_at'])
                ->map(fn (object $subscription) => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'trialEndsAt' => $subscription->trial_ends_at,
                    'startedAt' => $subscription->created_at,
                    'plans' => DB::table('subscription_items')->join('plans', 'plans.id', '=', 'subscription_items.plan_id')
                        ->where('subscription_items.subscription_id', $subscription->id)->pluck('plans.name')->all(),
                ])
                ->all(),
            'plans' => DB::table('plans')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'trial_days'])->all(),
            'applications' => DB::table('applications')->where('kind', '!=', 'platform')->orderBy('name')->get(['id', 'name'])->all(),
            'activeGrant' => $grant === null ? null : ['id' => $grant->id, 'expiresAt' => $grant->expires_at->toIso8601String()],
            'can' => [
                'suspend' => $staff->hasPermission(PlatformPermission::OrganizationsSuspend),
                'requestSupport' => $staff->hasPermission(PlatformPermission::SupportRequest),
                'manageEntitlements' => $staff->hasPermission(PlatformPermission::EntitlementsManage),
            ],
        ]);
    }

    public function updateStatus(Request $request, Organization $organization, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([OrganizationStatus::Active->value, OrganizationStatus::Suspended->value])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        abort_unless(in_array($organization->status, [OrganizationStatus::Active, OrganizationStatus::Suspended], true), 422);

        $suspend = $validated['status'] === OrganizationStatus::Suspended->value;
        $organization->forceFill([
            'status' => $validated['status'],
            'suspended_at' => $suspend ? now() : null,
        ])->save();

        app(IntegrationEvents::class)->organizationStatusChanged($organization->id, $validated['status']);

        $audit->record(
            $suspend ? 'organization.suspended' : 'organization.reactivated',
            $organization,
            ['reason' => $validated['reason']],
            actor: $this->staff($request)->user,
            organizationId: $organization->id,
            actorType: 'staff',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $suspend ? __('Organization suspended.') : __('Organization reactivated.')]);

        return back();
    }

    private function staff(Request $request): PlatformStaff
    {
        $staff = $request->attributes->get('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }
}
