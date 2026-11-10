<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OrganizationMembership;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration → Audit log (organization.audit.view): what happened in
 * this organization and who did it, newest first, optionally for one
 * member (their activity).
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = app(CurrentOrganization::class)->require();
        $members = $organization->memberships()->with('user:id,name')->get();
        $userId = $request->string('user')->toString();
        $userId = $members->contains('user_id', $userId) ? $userId : '';

        $events = AuditEvent::query()
            ->where('organization_id', $organization->id)
            ->when($userId !== '', fn ($query) => $query->where('user_id', $userId))
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('portal/admin/audit', [
            'events' => [
                'data' => collect($events->items())->map(fn (AuditEvent $event) => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'by' => $event->user?->name,
                    'subject' => $event->subject_type === null ? null : "{$event->subject_type} {$event->subject_id}",
                    'properties' => $event->properties,
                    'ip' => $event->ip_address,
                    'at' => $event->created_at->toIso8601String(),
                ]),
                'currentPage' => $events->currentPage(),
                'lastPage' => $events->lastPage(),
                'total' => $events->total(),
            ],
            'members' => $members->map(fn (OrganizationMembership $membership) => ['value' => $membership->user_id, 'label' => $membership->user->name])
                ->sortBy('label')->values(),
            'filters' => ['user' => $userId],
        ]);
    }
}
