<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Bills: what an order is charged, paid at once into the cashier's open
 * till, and voided whole. Finance hears of a bill as an invoice
 * (finance.invoice.issued, with each line's cost) paid at once
 * (finance.payment.received), and of a void as finance.invoice.voided with
 * the refund. Drinks leave stock with the bill and come back with its void.
 */
class Bills
{
    public function __construct(
        private readonly Stock $stock,
        private readonly Tills $tills,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * What the lines come to at today's menu prices, by item.
     *
     * @param  array<string, int>  $quantities  menu item id => quantity
     * @return array{lines: list<array{item: MenuItem, quantity: int, unit_price_minor: int, total_minor: int}>, total_minor: int}
     */
    public function price(array $quantities): array
    {
        $items = MenuItem::query()->whereKey(array_keys($quantities))->get()->keyBy('id');

        $lines = [];
        $total = 0;
        foreach ($quantities as $itemId => $quantity) {
            $item = $items->get($itemId);
            if ($item === null) {
                continue;
            }

            $lines[] = [
                'item' => $item,
                'quantity' => $quantity,
                'unit_price_minor' => $item->price_minor,
                'total_minor' => $quantity * $item->price_minor,
            ];
            $total += $quantity * $item->price_minor;
        }

        return ['lines' => $lines, 'total_minor' => $total];
    }

    /**
     * Pays an order's bill; call inside the order's transaction, with the
     * order locked.
     *
     * @param  array<string, int>  $quantities  menu item id => quantity
     *
     * @throws ValidationException
     */
    public function pay(Order $order, array $quantities, User $by, PaymentMethod $method, ?int $tenderedMinor, ?string $paymentReference): Bill
    {
        $till = $this->tills->openFor($by, $order->branch_id)
            ?? throw ValidationException::withMessages(['payment_method' => 'Open your till at this branch before taking a bill.']);

        ['lines' => $lines, 'total_minor' => $total] = $this->price($quantities);

        $change = 0;
        if ($method === PaymentMethod::Cash && $tenderedMinor !== null) {
            if ($tenderedMinor < $total) {
                throw ValidationException::withMessages(['amount_tendered' => 'Less than the bill: '.Money::format($total).'.']);
            }
            $change = $tenderedMinor - $total;
        }

        $bill = Bill::query()->create([
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'till_shift_id' => $till->id,
            'number' => $this->numbers->next('bill', 'B', 6),
            'currency' => Money::currency(),
            'total_minor' => $total,
            'cost_minor' => 0,
            'payment_method' => $method,
            'tendered_minor' => $method === PaymentMethod::Cash ? $tenderedMinor : null,
            'change_minor' => $change,
            'payment_reference' => blank($paymentReference) ? null : trim((string) $paymentReference),
            'customer_name' => $order->customer_name,
            'status' => Bill::PAID,
            'paid_by' => $by->id,
            'paid_at' => now(),
        ]);

        $invoiceLines = [];
        $costTotal = 0;
        foreach ($lines as $line) {
            $cost = $line['item']->tracksStock() ? $this->stock->take($bill, $line['item'], $line['quantity'], $by) : 0;
            $costTotal += $cost;

            $bill->lines()->create([
                'menu_item_id' => $line['item']->id,
                'name' => $line['item']->name,
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unit_price_minor'],
                'total_minor' => $line['total_minor'],
                'cost_minor' => $cost,
            ]);
            $invoiceLines[] = [
                'description' => $line['item']->name,
                'quantity' => $line['quantity'],
                'amount_minor' => $line['total_minor'],
                'revenue_account' => null,
                'cost_minor' => $cost,
            ];
        }
        $bill->forceFill(['cost_minor' => $costTotal])->save();

        $this->outbox->record('finance', 'finance.invoice.issued', 1, "bill:{$bill->id}:invoice", [
            'invoice_number' => $bill->number,
            'branch_id' => $bill->branch_id,
            'customer_reference' => $bill->customer_name,
            'currency' => $bill->currency,
            'total_minor' => $total,
            'tax_minor' => 0,
            'due_on' => null,
            'lines' => $invoiceLines,
        ], $bill->paid_at);

        if ($total > 0) {
            $this->outbox->record('finance', 'finance.payment.received', 1, "bill:{$bill->id}:payment", [
                'payment_reference' => $bill->number,
                'invoice_number' => $bill->number,
                'branch_id' => $bill->branch_id,
                'currency' => $bill->currency,
                'amount_minor' => $total,
                'method' => $method->value,
                'processor_reference' => $bill->payment_reference,
            ], $bill->paid_at);
        }

        return $bill;
    }

    /**
     * Voids a paid bill whole: the money goes back (cash out of the open
     * till of whoever voids it), the drinks back into stock.
     *
     * @throws ValidationException
     */
    public function void(Bill $bill, User $by, string $reason): Bill
    {
        return DB::transaction(function () use ($bill, $by, $reason) {
            $locked = Bill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Bill::PAID) {
                throw ValidationException::withMessages(['reason' => "Bill {$locked->number} is already voided."]);
            }

            $refundTill = null;
            if ($locked->payment_method === PaymentMethod::Cash && $locked->total_minor > 0) {
                $refundTill = $this->tills->openFor($by, $locked->branch_id)
                    ?? throw ValidationException::withMessages(['reason' => 'Open your till at this branch: the cash is given back from it.']);
            }

            foreach ($locked->lines()->get() as $line) {
                $item = MenuItem::query()->findOrFail($line->menu_item_id);
                if ($item->tracksStock()) {
                    $this->stock->putBack($locked, $item, $line->quantity, intdiv($line->cost_minor, max($line->quantity, 1)), $by);
                }
            }

            $locked->forceFill([
                'status' => Bill::VOIDED,
                'voided_by' => $by->id,
                'voided_at' => now(),
                'void_reason' => trim($reason),
                'refund_till_shift_id' => $refundTill?->id,
            ])->save();

            $this->outbox->record('finance', 'finance.invoice.voided', 1, "bill:{$locked->id}:void", [
                'invoice_number' => $locked->number,
                'branch_id' => $locked->branch_id,
                'currency' => $locked->currency,
                'total_minor' => $locked->total_minor,
                'reason' => trim($reason),
                'refund' => $locked->total_minor > 0 ? [
                    'method' => $locked->payment_method->value,
                    'amount_minor' => $locked->total_minor,
                    'payment_reference' => $locked->number,
                ] : null,
            ], $locked->voided_at);

            return $locked;
        });
    }
}
