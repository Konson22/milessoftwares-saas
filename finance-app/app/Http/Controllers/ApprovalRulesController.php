<?php

namespace App\Http\Controllers;

use App\Finance\Money;
use App\Models\AuditEvent;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * When journals and payments need approval, in one place of its own: whoever
 * sets the rules need not be able to see what waits under them. Holders of
 * finance.approvals.view (auditors among them) read the rules and how they
 * changed; only finance.approvals.configure changes them.
 */
class ApprovalRulesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspace = Workspace::current();
        $major = fn (?int $minor) => $minor === null ? '' : Money::toMajor($minor);

        return Inertia::render('approval-rules/index', [
            'canChange' => $this->user($request)->can('finance.approvals.configure'),
            'journals' => [
                'enabled' => $workspace->journal_approval ?? false,
                'threshold' => $major($workspace?->journal_approval_threshold_minor),
            ],
            'payments' => [
                'threshold' => $major($workspace?->payment_approval_threshold_minor),
                'review' => $workspace->payment_review ?? false,
            ],
            'pettyCash' => [
                'max' => $major($workspace?->petty_cash_max_minor),
            ],
            'history' => AuditEvent::query()
                ->with('actor')
                ->where('action', 'like', 'approval_rules.%')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (AuditEvent $event) => [
                    'id' => $event->id,
                    'at' => $event->created_at->toIso8601String(),
                    'by' => $event->actor?->name,
                    'rules' => match ($event->action) {
                        'approval_rules.payments_changed' => 'Payments',
                        'approval_rules.petty_cash_changed' => 'Petty cash',
                        default => 'Journals',
                    },
                    'before' => $this->describe($event->before ?? []),
                    'after' => $this->describe($event->after ?? []),
                ])
                ->values(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function describe(array $rules): string
    {
        $parts = [];
        if (array_key_exists('enabled', $rules)) {
            $parts[] = $rules['enabled'] ? 'approval on' : 'approval off';
        }
        if (array_key_exists('threshold_minor', $rules)) {
            $parts[] = $rules['threshold_minor'] === null ? 'no limit' : 'from '.Money::toMajor((int) $rules['threshold_minor']);
        }
        if (array_key_exists('review', $rules)) {
            $parts[] = $rules['review'] ? 'reviewed first' : 'no review step';
        }
        if (array_key_exists('max_minor', $rules)) {
            $parts[] = $rules['max_minor'] === null ? 'no limit set (no vouchers)' : 'up to '.Money::toMajor((int) $rules['max_minor']).' a voucher';
        }

        return implode(', ', $parts);
    }
}
