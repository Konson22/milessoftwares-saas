<?php

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Subscriptions\Actions\PlanRequests;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Where the current organization stands with Miles Global Softwares: what
 * it owes, what is overdue, and what is waiting on staff. Runs inside the
 * tenant context, so every query is scoped to that organization.
 */
final class AccountStanding
{
    /**
     * @return array{
     *     balances: list<array{currency: string, amountMinor: int}>,
     *     openInvoices: int,
     *     overdueInvoices: int,
     *     nextDue: array{id: string, number: string, dueOn: string, balanceMinor: int, currency: string, overdue: bool}|null,
     *     paymentsAwaitingConfirmation: int,
     *     pendingRequests: int,
     *     liveSubscriptions: int,
     *     trialEndsAt: string|null,
     * }
     */
    public function summary(): array
    {
        $open = Invoice::query()->outstanding()->orderBy('due_on')->get();
        $next = $open->first();

        return [
            'balances' => array_values($open->groupBy('currency')
                ->map(fn ($invoices, $currency) => [
                    'currency' => (string) $currency,
                    'amountMinor' => (int) $invoices->sum(fn (Invoice $invoice) => $invoice->balanceMinor()),
                ])
                ->all()),
            'openInvoices' => $open->count(),
            'overdueInvoices' => $open->filter(fn (Invoice $invoice) => $invoice->isOverdue())->count(),
            'nextDue' => $next === null ? null : [
                'id' => $next->id,
                'number' => $next->number,
                'dueOn' => $next->due_on->toDateString(),
                'balanceMinor' => $next->balanceMinor(),
                'currency' => $next->currency,
                'overdue' => $next->isOverdue(),
            ],
            'paymentsAwaitingConfirmation' => Payment::query()->where('status', 'reported')->count(),
            'pendingRequests' => PlanRequest::query()->where('status', 'pending')->count(),
            'liveSubscriptions' => Subscription::query()->whereIn('status', PlanRequests::LIVE_STATUSES)->count(),
            'trialEndsAt' => $this->firstTrialEnd(),
        ];
    }

    /**
     * The earliest end of a running trial: a trialing subscription or a
     * self-serve trial started with the workspace.
     */
    private function firstTrialEnd(): ?string
    {
        $ends = array_filter([
            Subscription::query()->where('status', 'trialing')->min('trial_ends_at'),
            Entitlement::query()->current()->where('source', 'trial')->whereNull('feature_key')->min('valid_until'),
        ]);

        return $ends === [] ? null : Carbon::parse(min($ends))->toDateString();
    }
}
