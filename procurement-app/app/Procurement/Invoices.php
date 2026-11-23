<?php

namespace App\Procurement;

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Supplier invoices against orders (ADR-0023 §2.7). Procurement tracks
 * them; it is not an accounting system.
 *
 * Three-way match: order + accepted receipts (or accepted service) +
 * invoice. Live invoices on an order never exceed the order; together
 * they are "matched" while within the value accepted so far (plus the
 * tolerance in settings), otherwise "awaiting receipt" until more is
 * accepted. Duplicates (same supplier, same number) are refused.
 *
 * With Finance connected (setting, off by default) a matched invoice goes
 * to Finance once, as finance.bill.received through the outbox, keyed by
 * the invoice: Finance then owns the liability and its payment, and the
 * invoice can no longer be voided or marked paid here. Without Finance,
 * payment made elsewhere may be noted by hand: it is shown as recorded by
 * hand, never as verified.
 */
final class Invoices
{
    /**
     * @param  array<string, mixed>  $data  number, invoice_date, due_date, amount
     */
    public static function record(PurchaseOrder $order, array $data, User $by): SupplierInvoice
    {
        return DB::transaction(function () use ($order, $data, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with('supplier')->findOrFail($order->id);
            if (! in_array($order->status, ['issued', 'partially_received', 'received', 'closed'], true)) {
                throw ValidationException::withMessages(['order' => 'Invoices are recorded against an issued order.']);
            }

            $key = self::numberKey((string) $data['number']);
            $duplicate = SupplierInvoice::query()->where('supplier_id', $order->supplier_id)->where('number_key', $key)->where('status', 'recorded')->with('order')->first();
            if ($duplicate !== null) {
                throw ValidationException::withMessages(['number' => "{$order->supplier->name} invoice {$duplicate->number} is already recorded on {$duplicate->order->number}."]);
            }

            $amount = Money::parse($data['amount'], $order->currency, 'amount');
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the invoiced amount.']);
            }
            $invoiced = (int) $order->invoices()->where('status', 'recorded')->sum('amount_minor');
            if ($invoiced + $amount > $order->total_minor) {
                throw ValidationException::withMessages(['amount' => 'Invoices would exceed the order: '.Money::format($order->total_minor - $invoiced, $order->currency).' is left to invoice.']);
            }

            $invoice = SupplierInvoice::query()->create([
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'number' => trim((string) $data['number']),
                'number_key' => $key,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency' => $order->currency,
                'amount_minor' => $amount,
                'amount_base_minor' => Money::toBase($amount, $order->currency, $order->rate_minor),
                'match_status' => 'awaiting_receipt',
                'recorded_by' => $by->id,
            ]);
            $invoice->setRelation('supplier', $order->supplier)->setRelation('order', $order);
            AuditTrail::record('invoice.recorded', $invoice, $by, ['amount' => Money::format($amount, $order->currency), 'order' => $order->number]);

            self::rematch($order, $by);

            return $invoice->fresh(['supplier', 'order']) ?? $invoice;
        });
    }

    public static function void(SupplierInvoice $invoice, string $reason, User $by): void
    {
        DB::transaction(function () use ($invoice, $reason, $by) {
            $invoice = SupplierInvoice::query()->lockForUpdate()->with(['supplier', 'order'])->findOrFail($invoice->id);
            if (! $invoice->live()) {
                throw ValidationException::withMessages(['invoice' => 'This invoice is voided already.']);
            }
            if ($invoice->finance_status === 'sent') {
                throw ValidationException::withMessages(['invoice' => 'Finance holds this invoice; correct it in Finance.']);
            }
            if ($invoice->payment_status !== 'unpaid') {
                throw ValidationException::withMessages(['invoice' => 'A paid invoice is not voided.']);
            }

            $invoice->forceFill(['status' => 'voided', 'void_reason' => $reason])->save();
            AuditTrail::record('invoice.voided', $invoice, $by, ['reason' => $reason]);
            self::rematch($invoice->order, $by);
        });
    }

    /**
     * Notes a payment made outside Tatook. Never verified, and never on an
     * invoice Finance holds.
     */
    public static function markPaid(SupplierInvoice $invoice, string $paidOn, ?string $reference, User $by): void
    {
        DB::transaction(function () use ($invoice, $paidOn, $reference, $by) {
            $invoice = SupplierInvoice::query()->lockForUpdate()->with(['supplier', 'order'])->findOrFail($invoice->id);
            if (! $invoice->live() || $invoice->payment_status !== 'unpaid') {
                throw ValidationException::withMessages(['invoice' => 'Only an unpaid, recorded invoice is marked paid.']);
            }
            if ($invoice->finance_status === 'sent') {
                throw ValidationException::withMessages(['invoice' => 'Finance holds this invoice: its payment is recorded in Finance.']);
            }

            $invoice->forceFill([
                'payment_status' => 'paid_externally',
                'paid_on' => $paidOn,
                'payment_reference' => $reference,
                'payment_recorded_by' => $by->id,
            ])->save();
            AuditTrail::record('invoice.paid_externally', $invoice, $by, ['paid_on' => $paidOn, 'reference' => $reference]);
        });
    }

    /**
     * Re-matches the order's live invoices, oldest first, against what has
     * been accepted, and sends newly matched ones to Finance when connected.
     */
    public static function rematch(PurchaseOrder $order, User $by): void
    {
        $settings = Workspace::settings();
        $limit = intdiv(Orders::acceptedValue($order) * (100 + $settings->invoice_tolerance_percent), 100);

        $running = 0;
        foreach ($order->invoices()->where('status', 'recorded')->orderBy('invoice_date')->orderBy('created_at')->get() as $invoice) {
            $running += $invoice->amount_minor;
            $status = $running <= $limit ? 'matched' : 'awaiting_receipt';
            if ($invoice->match_status !== $status) {
                $invoice->forceFill(['match_status' => $status])->save();
            }
            if ($status === 'matched' && $settings->finance_bills_enabled && $invoice->finance_status === 'none' && $invoice->payment_status === 'unpaid') {
                self::sendToFinance($invoice->setRelation('order', $order), $settings, $by);
            }
        }
    }

    private static function sendToFinance(SupplierInvoice $invoice, Workspace $settings, User $by): void
    {
        $order = $invoice->order;
        $order->loadMissing('supplier');
        $base = Money::base();

        app(Outbox::class)->record('finance', 'finance.bill.received', 1, 'supplier_invoice:'.$invoice->id, [
            'bill_number' => mb_substr($order->number.'/'.$invoice->number, 0, 64),
            'supplier_reference' => $invoice->number,
            'supplier_name' => $order->supplier->name,
            'supplier_id' => $order->supplier_id,
            'branch_id' => null,
            'currency' => $base,
            'total_minor' => $invoice->amount_base_minor,
            'tax_minor' => 0,
            'received_on' => $invoice->getAttribute('invoice_date')->toDateString(),
            'due_on' => $invoice->getAttribute('due_date')?->toDateString(),
            'lines' => [[
                'description' => "{$order->number} · supplier invoice {$invoice->number}",
                'quantity' => 1,
                'amount_minor' => $invoice->amount_base_minor,
                'expense_account' => $settings->finance_expense_account,
            ]],
        ]);

        $invoice->forceFill(['finance_status' => 'sent', 'finance_sent_at' => now()])->save();
        AuditTrail::record('invoice.sent_to_finance', $invoice->loadMissing('supplier'), $by);
    }

    public static function numberKey(string $number): string
    {
        return mb_substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($number)), 0, 64);
    }
}
