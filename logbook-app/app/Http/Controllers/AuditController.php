<?php

namespace App\Http\Controllers;

use App\Logbook\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, read-only: no route changes or removes an event.
 */
class AuditController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['logbook', 'log_sheet', 'entry'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return Inertia::render('audit/index', [
            'filters' => $data,
            'events' => AuditEvent::query()
                ->when($data['type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                    ->where('subject_label', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('user_name', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('action', 'like', '%'.addcslashes($term, '%_\\').'%')))
                ->latest('created_at')->paginate(50)->withQueryString()
                ->through(fn (AuditEvent $e) => [
                    'id' => $e->id, 'action' => $e->action, 'subjectType' => $e->subject_type, 'subjectId' => $e->subject_id,
                    'subject' => $e->subject_label, 'user' => $e->user_name, 'details' => $e->details, 'at' => self::time($e->created_at),
                ]),
        ]);
    }
}
