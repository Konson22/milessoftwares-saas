<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Billing customers for what was delivered: an invoice of delivered
 * shipments (finance.invoice.issued, freight as a service: no cost of
 * goods), paid in one or more payments (finance.payment.received), voided
 * while nothing is paid (finance.invoice.voided). Each shipment is invoiced
 * once; a void frees it for another invoice.
 */
class Invoices
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  list<string>  $shipmentIds
     *
     * @throws ValidationException
     */
    public function issue(Customer $customer, array $shipmentIds, ?CarbonImmutable $dueOn, User $by): Invoice
    {
        return DB::transaction(function () use ($customer, $shipmentIds, $dueOn, $by) {
            $shipments = Shipment::query()->whereKey($shipmentIds)->orderBy('number')->lockForUpdate()->get();

            if ($shipments->isEmpty() || $shipments->count() !== count(array_unique($shipmentIds))) {
                throw ValidationException::withMessages(['shipment_ids' => 'Choose delivered shipments of this customer.']);
            }
            foreach ($shipments as $shipment) {
                if ($shipment->customer_id !== $customer->id || $shipment->status !== Shipment::DELIVERED) {
                    throw ValidationException::withMessages(['shipment_ids' => "{$shipment->number} is not a delivered shipment of {$customer->name}."]);
                }
                if ($shipment->invoice_id !== null) {
                    throw ValidationException::withMessages(['shipment_ids' => "{$shipment->number} is already invoiced."]);
                }
            }

            $invoice = Invoice::query()->create([
                'number' => $this->numbers->next('invoice', 'INV'),
                'customer_id' => $customer->id,
                'currency' => Money::currency(),
                'total_minor' => (int) $shipments->sum('charge_minor'),
                'paid_minor' => 0,
                'status' => Invoice::ISSUED,
                'issued_on' => today(),
                'due_on' => $dueOn,
                'issued_by' => $by->id,
            ]);

            $lines = [];
            foreach ($shipments as $shipment) {
                $description = Str::limit("{$shipment->number}: {$shipment->goods}, {$shipment->pickup_address} to {$shipment->delivery_address}", 250);
                $invoice->lines()->create([
                    'shipment_id' => $shipment->id,
                    'description' => $description,
                    'amount_minor' => $shipment->charge_minor,
                ]);
                $shipment->forceFill(['invoice_id' => $invoice->id])->save();
                $lines[] = ['description' => $description, 'quantity' => 1, 'amount_minor' => $shipment->charge_minor, 'revenue_account' => null];
            }

            $this->outbox->record('finance', 'finance.invoice.issued', 1, "invoice:{$invoice->id}:issued", [
                'invoice_number' => $invoice->number,
                'branch_id' => null,
                'customer_reference' => Str::limit($customer->name, 125),
                'currency' => $invoice->currency,
                'total_minor' => $invoice->total_minor,
                'tax_minor' => 0,
                'due_on' => $invoice->due_on?->toDateString(),
                'lines' => $lines,
            ]);

            return $invoice;
        });
    }

    /**
     * @throws ValidationException
     */
    public function pay(Invoice $invoice, int $amountMinor, PaymentMethod $method, ?string $reference, CarbonImmutable $paidOn, User $by): Payment
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $method, $reference, $paidOn, $by) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::ISSUED) {
                throw ValidationException::withMessages(['amount' => "Invoice {$locked->number} is {$locked->status}: it takes no payments."]);
            }
            if ($amountMinor < 1 || $amountMinor > $locked->balanceMinor()) {
                throw ValidationException::withMessages(['amount' => 'Between '.Money::format(1, $locked->currency).' and the balance, '.Money::format($locked->balanceMinor(), $locked->currency).'.']);
            }

            $payment = $locked->payments()->create([
                'number' => $this->numbers->next('payment', 'RCT'),
                'amount_minor' => $amountMinor,
                'method' => $method,
                'reference' => blank($reference) ? null : trim((string) $reference),
                'paid_on' => $paidOn,
                'received_by' => $by->id,
            ]);

            $paid = $locked->paid_minor + $amountMinor;
            $locked->forceFill([
                'paid_minor' => $paid,
                'status' => $paid >= $locked->total_minor ? Invoice::PAID : Invoice::ISSUED,
            ])->save();

            $this->outbox->record('finance', 'finance.payment.received', 1, "payment:{$payment->id}", [
                'payment_reference' => $payment->number,
                'invoice_number' => $locked->number,
                'branch_id' => null,
                'currency' => $locked->currency,
                'amount_minor' => $amountMinor,
                'method' => $method->value,
                'processor_reference' => $payment->reference,
            ], $paidOn);

            return $payment;
        });
    }

    /**
     * @throws ValidationException
     */
    public function void(Invoice $invoice, string $reason, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::ISSUED || $locked->paid_minor > 0) {
                throw ValidationException::withMessages(['reason' => "Invoice {$locked->number} is ".($locked->status === Invoice::VOIDED ? 'already voided.' : 'paid in part or whole: only unpaid invoices can be voided.')]);
            }

            Shipment::query()->where('invoice_id', $locked->id)->update(['invoice_id' => null]);
            $locked->forceFill([
                'status' => Invoice::VOIDED,
                'voided_by' => $by->id,
                'voided_at' => now(),
                'void_reason' => trim($reason),
            ])->save();

            $this->outbox->record('finance', 'finance.invoice.voided', 1, "invoice:{$locked->id}:void", [
                'invoice_number' => $locked->number,
                'branch_id' => null,
                'currency' => $locked->currency,
                'total_minor' => $locked->total_minor,
                'reason' => Str::limit(trim($reason), 250),
                'refund' => null,
            ], $locked->voided_at);

            return $locked;
        });
    }
}
