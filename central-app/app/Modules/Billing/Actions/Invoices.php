<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Models\Payment;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionItem;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff issue and void invoices. An invoice is never edited after issue:
 * a mistake is voided and issued again, so what the customer saw stays on
 * record.
 */
final class Invoices
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One line per active plan on the subscription, at the plan's price.
     */
    public function issueForSubscription(Organization $organization, Subscription $subscription, ?PlatformStaff $actor, CarbonInterface $dueOn, ?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null, ?string $notes = null): Invoice
    {
        $items = $this->tenant->run($organization, fn () => $subscription->items()->with('plan')->where('status', 'active')->get());
        $currencies = $items->map(fn (SubscriptionItem $item) => $item->plan->currency)->unique();

        if ($items->isEmpty() || $currencies->count() !== 1) {
            throw ValidationException::withMessages(['subscription_id' => __('The subscription has no active plans in a single currency to invoice.')]);
        }

        $period = $periodStart && $periodEnd ? ' ('.$periodStart->toDateString().' – '.$periodEnd->toDateString().')' : '';

        return $this->issue(
            $organization,
            $actor,
            array_values($items->map(fn (SubscriptionItem $item) => [
                'description' => $item->plan->name.$period,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->plan->price_minor,
                'plan_id' => $item->plan_id,
            ])->all()),
            (string) $currencies->first(),
            $dueOn,
            $subscription,
            $periodStart,
            $periodEnd,
            $notes,
        );
    }

    /**
     * @param  list<array{description: string, quantity: int, unit_price_minor: int, plan_id?: string|null}>  $lines
     */
    public function issue(Organization $organization, ?PlatformStaff $actor, array $lines, string $currency, CarbonInterface $dueOn, ?Subscription $subscription = null, ?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null, ?string $notes = null): Invoice
    {
        $total = array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_price_minor'], $lines));

        if ($total <= 0) {
            throw ValidationException::withMessages(['amount' => __('An invoice must be for more than zero.')]);
        }

        $invoice = $this->tenant->run($organization, fn () => DB::transaction(function () use ($actor, $lines, $currency, $dueOn, $subscription, $periodStart, $periodEnd, $notes, $total): Invoice {
            $invoice = new Invoice;
            $invoice->forceFill([
                'subscription_id' => $subscription?->id,
                'number' => 'INV-'.now()->format('Y').'-'.str_pad((string) DB::scalar("SELECT nextval('invoice_number_seq')"), 6, '0', STR_PAD_LEFT),
                'status' => 'open',
                'currency' => strtoupper($currency),
                'total_minor' => $total,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issued_at' => now(),
                'due_on' => $dueOn,
                'notes' => $notes,
                'issued_by_user_id' => $actor?->user_id,
            ])->save();

            foreach ($lines as $line) {
                (new InvoiceLine)->forceFill([
                    'invoice_id' => $invoice->id,
                    'plan_id' => $line['plan_id'] ?? null,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'amount_minor' => $line['quantity'] * $line['unit_price_minor'],
                ])->save();
            }

            return $invoice;
        }));

        $this->audit->record('invoice.issued', $invoice, [
            'number' => $invoice->number,
            'total_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'due_on' => $invoice->due_on->toDateString(),
        ], actor: $actor?->user, organizationId: $organization->id, actorType: 'staff');

        return $invoice;
    }

    /**
     * Only an invoice nothing has been confirmed against; reports still
     * waiting are rejected with it.
     */
    public function void(Organization $organization, Invoice $invoice, PlatformStaff $actor, string $reason): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(function () use ($invoice, $actor, $reason): void {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== 'open' || $locked->amount_paid_minor > 0) {
                throw ValidationException::withMessages(['reason' => __('Only an open invoice with no confirmed payments can be voided.')]);
            }

            $locked->forceFill(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason])->save();

            Payment::query()->where('invoice_id', $locked->id)->where('status', 'reported')->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejection_reason' => __('The invoice was voided.'),
                'decided_by_user_id' => $actor->user_id,
            ]);
        }));

        $this->audit->record('invoice.voided', $invoice, ['number' => $invoice->number, 'reason' => $reason], actor: $actor->user, organizationId: $organization->id, actorType: 'staff');
    }
}
