<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll's audit trail: newest first, filtered by what changed.
 */
class AuditController extends Controller
{
    public const SUBJECTS = ['run', 'employee', 'component', 'rule'];

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'subject' => ['nullable', Rule::in(self::SUBJECTS)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $subject = $validated['subject'] ?? null;
        $search = trim((string) ($validated['search'] ?? ''));

        $events = AuditEvent::query()
            ->when($subject !== null, fn ($query) => $query->where('subject_type', $subject))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('subject_label', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('user_name', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditEvent $event) => [
                'id' => $event->id,
                'at' => $event->created_at->toIso8601String(),
                'action' => $event->action,
                'subjectType' => $event->subject_type,
                'subjectId' => $event->subject_id,
                'subject' => $event->subject_label,
                'user' => $event->user_name,
                'details' => $event->details ?? (object) [],
            ]);

        return Inertia::render('audit/index', [
            'events' => $events,
            'filters' => ['subject' => $subject, 'search' => $search],
        ]);
    }
}
