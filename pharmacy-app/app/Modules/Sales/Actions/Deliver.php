<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Goods leaving a branch against a sales order, invoiced as they go.
 *
 * A delivery note takes stock (oldest expiry first) for so much of each
 * order line as is still to deliver, never more, and invoices exactly that:
 * a sale on account to the order's customer at the order's prices, due
 * after their payment terms, within their credit limit (CustomerAccounts).
 * Finance gets the invoice (finance.invoice.issued) with its due date; the
 * customer pays it like any sale on account. Returns go through the sale
 * (ReturnSale); a delivered invoice is never voided.
 *
 * Line discounts are shared over the deliveries so that delivering a whole
 * line invoices its total exactly (SalesOrderLine::amountFor).
 *
 * The order and the customer are locked while delivering: two deliveries
 * never ship the same remainder, and the credit check never works from a
 * stale balance. A delivery submitted again with its $submissionId is that
 * delivery, not a second one.
 */
class Deliver
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
        private readonly CustomerAccounts $accounts,
        private readonly Industry $industry,
    ) {}

    /**
     * @param  array<string, int>  $quantities  packs (or units) by order line id; lines left out or at 0 are not delivered now
     *
     * @throws ValidationException
     * @throws InsufficientStock
     */
    public function handle(
        User $by,
        SalesOrder $order,
        array $quantities,
        ?string $receivedByName = null,
        ?string $note = null,
        ?string $submissionId = null,
    ): DeliveryNote {
        if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
            return $earlier;
        }

        try {
            return $this->deliver($by, $order, $quantities, $receivedByName, $note, $submissionId);
        } catch (UniqueConstraintViolationException $e) {
            // The same submission, committed by a request running alongside.
            if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
                return $earlier;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, int>  $quantities
     */
    private function deliver(User $by, SalesOrder $order, array $quantities, ?string $receivedByName, ?string $note, ?string $submissionId): DeliveryNote
    {
        return DB::transaction(function () use ($by, $order, $quantities, $receivedByName, $note, $submissionId) {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->application !== $this->industry->code()) {
                throw ValidationException::withMessages(['lines' => 'This order is not delivered here.']);
            }
            if (! $locked->status->canDeliver()) {
                throw ValidationException::withMessages(['lines' => "{$locked->reference()} is ".mb_strtolower($locked->status->label()).': nothing more is delivered on it.']);
            }

            $customer = $this->accounts->lock($locked->customer_id, 'lines');
            $lines = $locked->lines()->with('item')->get()->keyBy('id');

            // What goes now, checked against what is left before touching stock.
            $going = [];
            $total = 0;
            $gross = 0;
            foreach ($quantities as $lineId => $quantity) {
                if ($quantity === 0) {
                    continue;
                }

                $line = $lines[$lineId] ?? null;
                if ($line === null) {
                    throw ValidationException::withMessages(["lines.{$lineId}" => 'Not a line of this order.']);
                }
                if ($quantity < 0 || $quantity > $line->remaining()) {
                    throw ValidationException::withMessages(["lines.{$lineId}" => "Only {$line->remaining()} of {$line->item->name}".($line->pack_name === null ? '' : " ({$line->packLabel()})").' are still to deliver.']);
                }

                $amount = $line->amountFor($quantity, $line->quantity_delivered);
                $going[] = [$line, $quantity, $amount];
                $total += $amount;
                $gross += $quantity * $line->unit_price_minor;
            }

            if ($going === []) {
                throw ValidationException::withMessages(['lines' => 'Enter what is being delivered.']);
            }

            $this->accounts->ensureCredit($customer, $total);

            $sale = Sale::query()->create([
                'branch_id' => $locked->branch_id,
                'application' => $locked->application,
                'number' => $this->numbers->next('sale', 'S', 6),
                'status' => Sale::COMPLETED,
                'customer_name' => $customer->name,
                'customer_id' => $customer->id,
                'due_on' => today()->addDays($customer->payment_terms_days),
                'currency' => Money::currency(),
                'total_minor' => $total,
                'discount_minor' => $gross - $total,
                'payment_method' => PaymentMethod::Account,
                'sold_by' => $by->id,
                'sold_at' => now(),
            ]);

            $delivery = DeliveryNote::query()->create([
                'application' => $locked->application,
                'branch_id' => $locked->branch_id,
                'sales_order_id' => $locked->id,
                'number' => $this->numbers->next('delivery_note', 'DN'),
                'sale_id' => $sale->id,
                'received_by_name' => $receivedByName,
                'note' => $note,
                'delivered_by' => $by->id,
                'delivered_at' => $sale->sold_at,
                'submission_id' => $submissionId,
            ]);

            $invoiceLines = [];
            foreach ($going as [$line, $quantity, $amount]) {
                /** @var SalesOrderLine $line */
                $invoiceLines[] = $this->invoiceLine($sale, $line, $quantity, $amount, $by);

                $delivery->lines()->create(['sales_order_line_id' => $line->id, 'quantity' => $quantity, 'amount_minor' => $amount]);
                $line->quantity_delivered += $quantity;
                $line->save();
            }

            $locked->forceFill([
                'status' => $lines->every(fn (SalesOrderLine $line) => $line->remaining() === 0)
                    ? SalesOrderStatus::Delivered
                    : SalesOrderStatus::PartlyDelivered,
            ])->save();
            $order->setRawAttributes($locked->getAttributes(), sync: true);

            $this->outbox->record('finance', 'finance.invoice.issued', 1, "sale:{$sale->id}:invoice", [
                'invoice_number' => $sale->number,
                'branch_id' => $sale->branch_id,
                'customer_reference' => "{$customer->name} ({$customer->number})",
                'currency' => $sale->currency,
                'total_minor' => $total,
                'tax_minor' => 0,
                'due_on' => $sale->due_on?->toDateString(),
                'lines' => $invoiceLines,
            ], $sale->sold_at);

            return $delivery;
        });
    }

    /**
     * Takes the line's stock and records it on the invoice batch by batch,
     * sharing its price and discount across the batches in proportion (the
     * last batch takes what rounding leaves).
     *
     * @return array{description: string, quantity: int, amount_minor: int, revenue_account: null, cost_minor: int}
     *
     * @throws InsufficientStock
     */
    private function invoiceLine(Sale $sale, SalesOrderLine $line, int $quantity, int $amount, User $by): array
    {
        $units = $line->inUnits($quantity);
        $gross = $quantity * $line->unit_price_minor;
        $discount = $gross - $amount;
        $parts = $this->stock->take($line->item, $sale->branch_id, $units, $by, 'sale', $sale->id);

        $cost = 0;
        $grossLeft = $gross;
        $discountLeft = $discount;
        foreach ($parts as $i => $part) {
            $last = $i === array_key_last($parts);
            $partGross = $last ? $grossLeft : intdiv($gross * $part['quantity'], $units);
            $partDiscount = $last ? $discountLeft : intdiv($discount * $part['quantity'], $units);
            $grossLeft -= $partGross;
            $discountLeft -= $partDiscount;

            $cost += $part['quantity'] * $part['batch']->unit_cost_minor;
            $sale->lines()->create([
                'item_id' => $line->item_id,
                'batch_id' => $part['batch']->id,
                'quantity' => $part['quantity'],
                'unit_price_minor' => intdiv($line->unit_price_minor, $line->units_per_pack),
                'discount_minor' => $partDiscount,
                'line_total_minor' => $partGross - $partDiscount,
                'pack_name' => $line->pack_name,
                'units_per_pack' => $line->units_per_pack,
                'pack_price_minor' => $line->pack_name === null ? null : $line->unit_price_minor,
            ]);
        }

        return [
            'description' => $line->pack_name === null ? $line->item->name : "{$line->item->name} ({$quantity} {$line->packLabel()})",
            'quantity' => $units,
            'amount_minor' => $amount,
            'revenue_account' => null,
            'cost_minor' => $cost,
        ];
    }

    private function submitted(string $submissionId): ?DeliveryNote
    {
        return DeliveryNote::query()->where('submission_id', $submissionId)->first();
    }
}
