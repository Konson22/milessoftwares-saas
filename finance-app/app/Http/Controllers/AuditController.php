<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, read only: there is no route that changes or deletes it.
 */
class AuditController extends Controller
{
    /** The areas the list can be narrowed to, by action prefix. */
    public const AREAS = [
        'payment_request' => 'Payment requests',
        'journal' => 'Journals',
        'journal_draft' => 'Held journals',
        'recurring_journal' => 'Recurring journals',
        'approval_rules' => 'Approval rules',
        'period' => 'Periods',
        'account' => 'Accounts',
        'petty_cash' => 'Petty cash',
    ];

    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->subDays(30));
        $area = is_string($request->query('area')) && array_key_exists($request->query('area'), self::AREAS) ? $request->query('area') : null;

        $events = AuditEvent::query()
            ->with('actor')
            ->where('created_at', '>=', $from->startOfDay())
            ->where('created_at', '<=', $to->endOfDay())
            ->when($area !== null, fn ($query) => $query->where('action', 'like', "{$area}.%"))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return Inertia::render('audit/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'area' => $area,
            'areas' => collect(self::AREAS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'events' => $events->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'at' => $event->created_at->toIso8601String(),
                'by' => $event->actor?->name,
                'action' => $event->action,
                'subject' => $event->subject_label ?? $event->subject_type,
                'before' => $event->before,
                'after' => $event->after,
            ])->values(),
        ]);
    }
}
