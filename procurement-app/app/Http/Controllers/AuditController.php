<?php

namespace App\Http\Controllers;

use App\Procurement\Models\AuditEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $type = $request->string('type')->toString();

        return Inertia::render('audit/index', [
            'type' => $type,
            'events' => AuditEvent::query()
                ->when($type !== '', fn ($q) => $q->where('subject_type', $type))
                ->latest('created_at')->limit(300)->get()
                ->map(fn (AuditEvent $e) => [
                    'id' => $e->id, 'action' => $e->getAttribute('action'), 'subjectType' => $e->getAttribute('subject_type'),
                    'subject' => $e->getAttribute('subject_label'), 'user' => $e->getAttribute('user_name'),
                    'details' => $e->getAttribute('details'), 'at' => self::time($e->created_at),
                ]),
        ]);
    }
}
