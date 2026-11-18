<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Bills: what an order is charged, paid at once into the cashier's open
 * till, and voided whole. Finance hears of a bill as an invoice
 * (finance.invoice.issued, with each line's cost) paid at once
 * (finance.payment.received), and of a void as finance.invoice.voided with
 * the refund. Drinks leave stock with the bill and come back with its void.
 *
 * An order may be paid in parts, each its own bill (Orders::pay): some of
 * its lines, or an equal share of what is left. A share's bill has one line
 * ("Share 1 of 3"); the share that closes the order takes the drinks of
 * every line it closes. A discount (an amount or a percentage, with a
 * reason) comes off the bill's subtotal and reaches Finance as a negative
 * invoice line, so the revenue is what was charged.
 */
class Bills
{
    /** At most this many equal shares. */
    public const MAX_PARTS = 20;

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
     * Order lines' quantities by item, whatever their notes.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @return array<string, int>
     */
    public function quantities(Collection $lines): array
    {
        return $lines->groupBy('menu_item_id')
            ->map(fn (Collection $same) => (int) $same->sum('quantity'))
            ->all();
    }

    /**
     * What a discount takes off a subtotal: a percentage (whole, 1–100) or
     * an amount no more than the subtotal, always with a reason.
     *
     * @param  array{percent?: int, amount_minor?: int, reason: string}|null  $discount
     *
     * @throws ValidationException
     */
    public function discountOf(int $subtotal, ?array $discount): int
    {
        if ($discount === null) {
            return 0;
        }
        if (blank($discount['reason'])) {
            throw ValidationException::withMessages(['discount_reason' => 'Say why the bill is discounted.']);
        }

        if (isset($discount['percent'])) {
            $percent = $discount['percent'];
            if ($percent < 1 || $percent > 100) {
                throw ValidationException::withMessages(['discount' => 'A percentage from 1 to 100.']);
            }

            return intdiv($subtotal * $percent + 50, 100);
        }

        $amount = (int) ($discount['amount_minor'] ?? 0);
        if ($amount < 1) {
            throw ValidationException::withMessages(['discount' => 'Enter the discount.']);
        }
        if ($amount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'More than the bill: '.Money::format($subtotal).'.']);
        }

        return $amount;
    }

    /**
     * Pays a bill of an order: its lines at today's menu prices, or (with
     * $share) one equal share of what is left, whose drinks are $lines when
     * it closes the order. Call inside the order's transaction, with the
     * order locked.
     *
     * $takenOffline: the money was taken on a phone without a connection
     * and is only being recorded now, so nothing may refuse it. Without an
     * open till the cashier gets one (no float) to close with a count; cash
     * short of today's price (the menu changed meanwhile) is not refused
     * but shows as a shortfall when that till is counted.
     *
     * @param  Collection<int, OrderLine>  $lines  the order lines this bill pays for
     * @param  array{part: int, parts: int, amount_minor: int, label: string}|null  $share
     * @param  array{percent?: int, amount_minor?: int, reason: string}|null  $discount
     *
     * @throws ValidationException
     */
    public function pay(Order $order, Collection $lines, User $by, PaymentMethod $method, ?int $tenderedMinor, ?string $paymentReference, bool $takenOffline = false, ?array $share = null, ?array $discount = null): Bill
    {
        $till = $this->tills->openFor($by, $order->branch_id, lock: true);
        if ($till === null && $takenOffline) {
            $this->tills->open($by, $order->branch_id, 0);
            $till = $this->tills->openFor($by, $order->branch_id, lock: true);
        }
        if ($till === null) {
            throw ValidationException::withMessages(['payment_method' => 'Open your till at this branch before taking a bill.']);
        }

        ['lines' => $priced, 'total_minor' => $itemsTotal] = $this->price($this->quantities($lines));
        $subtotal = $share === null ? $itemsTotal : $share['amount_minor'];
        $discountMinor = $this->discountOf($subtotal, $discount);
        $total = $subtotal - $discountMinor;

        $change = 0;
        if ($method === PaymentMethod::Cash && $tenderedMinor !== null) {
            if ($tenderedMinor < $total && ! $takenOffline) {
                throw ValidationException::withMessages(['amount_tendered' => 'Less than the bill: '.Money::format($total).'.']);
            }
            $change = max($tenderedMinor - $total, 0);
        }

        $bill = Bill::query()->create([
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'till_shift_id' => $till->id,
            'number' => $this->numbers->next('bill', 'B', 6),
            'currency' => Money::currency(),
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discountMinor,
            'discount_reason' => $discountMinor > 0 ? trim((string) $discount['reason']) : null,
            'discounted_by' => $discountMinor > 0 ? $by->id : null,
            'part' => $share['part'] ?? null,
            'parts' => $share['parts'] ?? null,
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
        foreach ($priced as $line) {
            $cost = $line['item']->tracksStock() ? $this->stock->take($bill, $line['item'], $line['quantity'], $by) : 0;
            $costTotal += $cost;

            if ($share === null) {
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
        }
        if ($share !== null) {
            $bill->lines()->create([
                'menu_item_id' => null,
                'name' => $share['label'],
                'quantity' => 1,
                'unit_price_minor' => $subtotal,
                'total_minor' => $subtotal,
                'cost_minor' => $costTotal,
            ]);
            $invoiceLines[] = [
                'description' => "{$share['label']}, order {$order->number}",
                'quantity' => 1,
                'amount_minor' => $subtotal,
                'revenue_account' => null,
                'cost_minor' => $costTotal,
            ];
        }
        if ($discountMinor > 0) {
            $invoiceLines[] = [
                'description' => mb_substr('Discount: '.$bill->discount_reason, 0, 255),
                'quantity' => 1,
                'amount_minor' => -$discountMinor,
                'revenue_account' => null,
                'cost_minor' => null,
            ];
        }
        $bill->forceFill(['cost_minor' => $costTotal])->save();
        if ($lines->isNotEmpty()) {
            OrderLine::query()->whereKey($lines->modelKeys())->update(['bill_id' => $bill->id]);
        }

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
     * till of whoever voids it), the drinks it took back into stock. A part
     * of an order still open leaves its lines to pay again.
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
                $refundTill = $this->tills->openFor($by, $locked->branch_id, lock: true)
                    ?? throw ValidationException::withMessages(['reason' => 'Open your till at this branch: the cash is given back from it.']);
            }

            // The drinks it took, at what they cost it.
            $sold = StockMovement::query()->where('bill_id', $locked->id)->where('type', StockMovement::SALE)->with('item')->get();
            foreach ($sold as $movement) {
                $this->stock->putBack($locked, $movement->item, -$movement->quantity, $movement->unit_cost_minor, $by);
            }

            $locked->forceFill([
                'status' => Bill::VOIDED,
                'voided_by' => $by->id,
                'voided_at' => now(),
                'void_reason' => trim($reason),
                'refund_till_shift_id' => $refundTill?->id,
            ])->save();

            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            if ($order->isOpen()) {
                OrderLine::query()->where('bill_id', $locked->id)->update(['bill_id' => null]);
            }

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
