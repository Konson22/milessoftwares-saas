<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wholesale quotations and the sales orders they become.
 *
 * A quotation prices each line at the customer's price list (PriceLists)
 * less any discount the quoting member may give, and holds those prices
 * until it is valid. Confirming it before then makes it a sales order
 * with its own number; the goods then leave in delivery notes (Deliver).
 * Nothing is reserved: stock and credit are checked when delivering.
 */
class SalesOrders
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly ItemPacks $packs,
        private readonly PriceLists $prices,
        private readonly CustomerAccounts $accounts,
        private readonly Industry $industry,
    ) {}

    /**
     * Quantities count packs when a line names one, units otherwise.
     *
     * @param  list<array{item_id: string, pack_id?: string|null, quantity: int, discount_percent?: int}>  $lines
     * @param  int  $maxDiscountPercent  the most this member may take off a line (0: no discounts)
     *
     * @throws ValidationException
     */
    public function quote(
        User $by,
        string $branchId,
        string $customerId,
        array $lines,
        CarbonImmutable $validUntil,
        ?string $customerReference,
        ?string $notes,
        int $maxDiscountPercent,
    ): SalesOrder {
        if ($validUntil->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['valid_until' => 'A quotation is valid until today or later.']);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add what the customer is buying.']);
        }

        return DB::transaction(function () use ($by, $branchId, $customerId, $lines, $validUntil, $customerReference, $notes, $maxDiscountPercent) {
            $customer = $this->accounts->lock($customerId);
            $list = $customer->priceList;

            $order = new SalesOrder([
                'application' => $this->industry->code(),
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'price_list_id' => $list?->active ? $list->id : null,
                'quote_number' => $this->numbers->next('quotation', 'QT'),
                'valid_until' => $validUntil,
                'customer_reference' => $customerReference,
                'notes' => $notes,
                'total_minor' => 0,
                'created_by' => $by->id,
            ]);
            $order->status = SalesOrderStatus::Quote;
            $order->save();

            $total = 0;
            foreach ($lines as $index => $line) {
                $item = Item::query()->find($line['item_id']);
                if ($item === null || ! $item->active || ! $this->industry->handles($item->kind)) {
                    throw ValidationException::withMessages(["lines.{$index}.item_id" => 'This item cannot be sold.']);
                }
                if ($item->restricted) {
                    throw ValidationException::withMessages(["lines.{$index}.item_id" => "{$item->name} is not sold on order."]);
                }

                $percent = $line['discount_percent'] ?? 0;
                if ($percent < 0 || $percent > 100) {
                    throw ValidationException::withMessages(["lines.{$index}.discount_percent" => 'A discount is between 0 and 100%.']);
                }
                if ($percent > $maxDiscountPercent) {
                    throw ValidationException::withMessages(["lines.{$index}.discount_percent" => $maxDiscountPercent === 0
                        ? 'You may not give discounts.'
                        : "You may give at most {$maxDiscountPercent}% off."]);
                }

                $pack = $this->packs->find($item->id, $line['pack_id'] ?? null, "lines.{$index}.pack_id");

                $orderLine = $order->lines()->make([
                    'item_id' => $item->id,
                    'pack_name' => $pack?->name,
                    'units_per_pack' => $pack->units ?? 1,
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $this->prices->price($list, $item, $pack),
                    'discount_percent' => $percent,
                    'line_total_minor' => 0,
                ]);
                $orderLine->line_total_minor = $orderLine->amountUpTo($orderLine->quantity);
                $orderLine->save();

                $total += $orderLine->line_total_minor;
            }

            $order->forceFill(['total_minor' => $total])->save();

            return $order;
        });
    }

    /**
     * The customer accepted the quotation: it becomes a sales order.
     *
     * @throws ValidationException
     */
    public function confirm(SalesOrder $order, User $by): void
    {
        DB::transaction(function () use ($order, $by) {
            $locked = $this->lock($order, [SalesOrderStatus::Quote]);

            if ($locked->valid_until->lessThan(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['status' => "Quotation {$locked->quote_number} expired on {$locked->valid_until->toDateString()}: quote again at today's prices."]);
            }
            $this->accounts->lock($locked->customer_id, 'status');

            $locked->forceFill([
                'status' => SalesOrderStatus::Confirmed,
                'order_number' => $this->numbers->next('sales_order', 'SO'),
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ])->save();
            $order->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * Only before anything was delivered.
     *
     * @throws ValidationException
     */
    public function cancel(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = $this->lock($order, [SalesOrderStatus::Quote, SalesOrderStatus::Confirmed]);
            $locked->forceFill(['status' => SalesOrderStatus::Cancelled, 'closed_at' => now()])->save();
            $order->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * A partly delivered order the rest of which will not be delivered.
     *
     * @throws ValidationException
     */
    public function close(SalesOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = $this->lock($order, [SalesOrderStatus::PartlyDelivered]);
            $locked->forceFill(['status' => SalesOrderStatus::Closed, 'closed_at' => now()])->save();
            $order->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * @param  list<SalesOrderStatus>  $from
     *
     * @throws ValidationException
     */
    private function lock(SalesOrder $order, array $from): SalesOrder
    {
        $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $from, true)) {
            throw ValidationException::withMessages(['status' => "{$locked->reference()} is ".mb_strtolower($locked->status->label()).'.']);
        }

        return $locked;
    }
}
