<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Models\AuditEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The M&E audit trail (mel.audit.view), newest first.
 */
class AuditController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $type = $request->string('type')->toString() ?: null;

        $events = AuditEvent::query()
            ->when($type !== null, fn ($query) => $query->where('subject_type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('mel/audit/index', [
            'type' => $type,
            'types' => AuditEvent::query()->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'events' => $events->through(fn (AuditEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'subjectType' => $event->subject_type,
                'subject' => $event->subject_label,
                'user' => $event->user_name,
                'details' => $event->details,
                'at' => $event->created_at->toIso8601String(),
            ]),
        ]);
    }
}
