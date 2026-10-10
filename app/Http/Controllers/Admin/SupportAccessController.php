<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\SupportAccess;
use App\Modules\Administration\Actions\SupportAccessDenied;
use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Models\SupportAccessGrant;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupportAccessController extends Controller
{
    public function __construct(private readonly SupportAccess $support) {}

    public function index(Request $request): Response
    {
        $staff = $this->staff($request);
        $canApprove = $staff->hasPermission(PlatformPermission::SupportApprove);

        $present = fn (SupportAccessGrant $grant) => [
            'id' => $grant->id,
            'organization' => ['id' => $grant->organization_id, 'name' => $grant->organization->display_name],
            'requestedBy' => $grant->staff->user->name,
            'approvedBy' => $grant->approver?->user->name,
            'reason' => $grant->reason,
            'ticket' => $grant->ticket_reference,
            'scope' => $grant->scope,
            'status' => $grant->status(),
            'expiresAt' => $grant->expires_at->toIso8601String(),
            'isMine' => $grant->platform_staff_id === $staff->id,
        ];

        $with = ['organization:id,display_name', 'staff.user:id,name', 'approver.user:id,name'];

        return Inertia::render('admin/support-access/index', [
            'mine' => SupportAccessGrant::query()->with($with)->where('platform_staff_id', $staff->id)->latest()->limit(50)->get()->map($present)->all(),
            'awaitingApproval' => $canApprove
                ? SupportAccessGrant::query()->with($with)
                    ->whereNull('approved_by_staff_id')->whereNull('revoked_at')->where('expires_at', '>', now())
                    ->where('platform_staff_id', '!=', $staff->id)
                    ->oldest()->get()->map($present)->all()
                : [],
            'canApprove' => $canApprove,
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'ticket_reference' => ['nullable', 'string', 'max:64'],
            'scope' => ['required', Rule::in(['read_only', 'configuration'])],
            'hours' => ['required', 'integer', 'between:1,'.SupportAccess::MAX_HOURS],
        ]);

        $this->support->request(
            $this->staff($request),
            $organization,
            $validated['reason'],
            $validated['ticket_reference'] ?? null,
            $validated['scope'],
            (int) $validated['hours'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Support access requested. Another staff member must approve it.')]);

        return back();
    }

    public function approve(Request $request, SupportAccessGrant $grant): RedirectResponse
    {
        return $this->attempt(fn () => $this->support->approve($this->staff($request), $grant), 'Support access approved.');
    }

    public function revoke(Request $request, SupportAccessGrant $grant): RedirectResponse
    {
        return $this->attempt(fn () => $this->support->revoke($this->staff($request), $grant), 'Support access revoked.');
    }

    private function attempt(callable $change, string $message): RedirectResponse
    {
        try {
            $change();
        } catch (SupportAccessDenied $e) {
            throw ValidationException::withMessages(['support' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return back();
    }

    private function staff(Request $request): PlatformStaff
    {
        $staff = $request->attributes->get('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }
}
