<?php

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\AuditLogger;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments made outside the platform. A customer reports one with its
 * reference; it counts towards the invoice only once staff confirm it. Staff
 * entering a payment they received themselves confirm it at once.
 */
final class Payments
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{method: string, amount_minor: int, reference?: string|null, paid_on: string, note?: string|null}  $details
     */
    public function report(Organization $organization, Invoice $invoice, User $reporter, array $details): Payment
    {
        $payment = $this->tenant->run($organization, fn () => DB::transaction(function () use ($invoice, $reporter, $details): Payment {
            $locked = $this->openInvoice($invoice, $details['amount_minor']);

            return $this->create($locked, $details, 'reported', $reporter->id);
        }));

        $this->audit->record('payment.reported', $payment, $this->context($invoice, $payment), actor: $reporter, organizationId: $organization->id);

        return $payment;
    }

    /**
     * @param  array{method: string, amount_minor: int, reference?: string|null, paid_on: string, note?: string|null}  $details
     */
    public function record(Organization $organization, Invoice $invoice, PlatformStaff $actor, array $details): Payment
    {
        $payment = $this->tenant->run($organization, fn () => DB::transaction(function () use ($invoice, $actor, $details): Payment {
            $locked = $this->openInvoice($invoice, $details['amount_minor']);
            $payment = $this->create($locked, $details, 'confirmed', null, $actor->user_id);
            $this->apply($locked, $payment);

            return $payment;
        }));

        $this->audit->record('payment.recorded', $payment, $this->context($invoice, $payment), actor: $actor->user, organizationId: $organization->id, actorType: 'staff');

        return $payment;
    }

    public function confirm(Organization $organization, Payment $payment, PlatformStaff $actor): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(function () use ($payment, $actor): void {
            $locked = $this->pending($payment);
            $invoice = $this->openInvoice($locked->invoice, $locked->amount_minor);

            $locked->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'decided_by_user_id' => $actor->user_id])->save();
            $this->apply($invoice, $locked);
        }));

        $this->audit->record('payment.confirmed', $payment, $this->context($payment->invoice, $payment), actor: $actor->user, organizationId: $organization->id, actorType: 'staff');
    }

    public function reject(Organization $organization, Payment $payment, PlatformStaff $actor, string $reason): void
    {
        $this->tenant->run($organization, fn () => DB::transaction(function () use ($payment, $actor, $reason): void {
            $this->pending($payment)->forceFill([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'decided_by_user_id' => $actor->user_id,
            ])->save();
        }));

        $this->audit->record('payment.rejected', $payment, $this->context($payment->invoice, $payment) + ['reason' => $reason], actor: $actor->user, organizationId: $organization->id, actorType: 'staff');
    }

    /**
     * Locks the invoice; the amount may not exceed what is still owed.
     */
    private function openInvoice(Invoice $invoice, int $amountMinor): Invoice
    {
        $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

        if ($locked->status !== 'open') {
            throw ValidationException::withMessages(['amount' => __('This invoice is no longer open for payment.')]);
        }

        if ($amountMinor > $locked->balanceMinor()) {
            throw ValidationException::withMessages(['amount' => __('The amount is more than the :balance still owed.', [
                'balance' => $locked->currency.' '.number_format($locked->balanceMinor() / 100, 2),
            ])]);
        }

        return $locked;
    }

    private function pending(Payment $payment): Payment
    {
        $locked = Payment::query()->with('invoice')->lockForUpdate()->findOrFail($payment->id);

        if ($locked->status !== 'reported') {
            throw ValidationException::withMessages(['payment' => __('This payment has already been decided.')]);
        }

        return $locked;
    }

    /**
     * @param  array{method: string, amount_minor: int, reference?: string|null, paid_on: string, note?: string|null}  $details
     */
    private function create(Invoice $invoice, array $details, string $status, ?string $reporterId, ?string $deciderId = null): Payment
    {
        $payment = new Payment;
        $payment->forceFill([
            'invoice_id' => $invoice->id,
            'status' => $status,
            'method' => $details['method'],
            'amount_minor' => $details['amount_minor'],
            'currency' => $invoice->currency,
            'reference' => $details['reference'] ?? null,
            'paid_on' => $details['paid_on'],
            'note' => $details['note'] ?? null,
            'reported_by_user_id' => $reporterId,
            'decided_by_user_id' => $deciderId,
            'confirmed_at' => $status === 'confirmed' ? now() : null,
        ])->save();

        return $payment;
    }

    private function apply(Invoice $invoice, Payment $payment): void
    {
        $paid = $invoice->amount_paid_minor + $payment->amount_minor;

        $invoice->forceFill([
            'amount_paid_minor' => $paid,
            'status' => $paid >= $invoice->total_minor ? 'paid' : 'open',
            'paid_at' => $paid >= $invoice->total_minor ? now() : null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Invoice $invoice, Payment $payment): array
    {
        return [
            'invoice' => $invoice->number,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'reference' => $payment->reference,
        ];
    }
}
