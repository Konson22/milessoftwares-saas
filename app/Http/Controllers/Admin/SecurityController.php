<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\AuthenticationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign-in events and the platform audit log, for security auditors.
 */
class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        $event = (string) $request->query('event', '');
        $action = (string) $request->query('action', '');

        return Inertia::render('admin/security/index', [
            'filters' => ['event' => $event, 'action' => $action],
            'eventTypes' => AuthenticationEvent::query()->distinct()->orderBy('event')->pluck('event'),
            'authenticationEvents' => AuthenticationEvent::query()
                ->with('user:id,email')
                ->when($event !== '', fn ($query) => $query->where('event', $event))
                ->latest('occurred_at')
                ->paginate(30, pageName: 'events_page')
                ->withQueryString()
                ->through(fn (AuthenticationEvent $record) => [
                    'id' => $record->id,
                    'event' => $record->event,
                    'user' => $record->user?->email,
                    'host' => $record->host,
                    'ip' => $record->ip_address,
                    'occurredAt' => $record->occurred_at->toIso8601String(),
                ]),
            'auditLogs' => AuditLog::query()
                ->when($action !== '', fn ($query) => $query->where('action', 'like', $action.'%'))
                ->orderByDesc('sequence')
                ->paginate(30, pageName: 'audit_page')
                ->withQueryString()
                ->through(fn (AuditLog $log) => [
                    'sequence' => $log->sequence,
                    'action' => $log->action,
                    'actorType' => $log->actor_type,
                    'actorId' => $log->actor_id,
                    'organizationId' => $log->organization_id,
                    'subject' => $log->subject_type === null ? null : "{$log->subject_type} {$log->subject_id}",
                    'occurredAt' => $log->occurred_at->toIso8601String(),
                ]),
            'chainCheck' => $request->session()->get('chain_check'),
        ]);
    }

    public function verifyChain(Request $request, AuditLogger $audit): RedirectResponse
    {
        $broken = $audit->verifyChain();

        $audit->record('audit.chain_verified', context: ['intact' => $broken === null, 'first_broken_sequence' => $broken], actor: $this->staff($request)->user, actorType: 'staff');

        return back()->with('chain_check', [
            'intact' => $broken === null,
            'firstBrokenSequence' => $broken,
            'checkedAt' => now()->toIso8601String(),
        ]);
    }

    private function staff(Request $request): PlatformStaff
    {
        $staff = $request->attributes->get('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }
}
