<?php

namespace App\Modules\Procurement\Actions;

use App\Models\User;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Support\DocumentNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders: drafted, sent (ordered), then received in one or more
 * deliveries (ReceiveGoods), or cancelled before anything arrived.
 */
class Purchasing
{
    public function __construct(private readonly DocumentNumbers $numbers) {}

    /**
     * @param  list<array{medicine_id: string, quantity: int, unit_cost_minor: int}>  $lines
     */
    public function createOrder(User $by, string $branchId, Supplier $supplier, array $lines, ?CarbonImmutable $expectedOn, ?string $notes): PurchaseOrder
    {
        return DB::transaction(function () use ($by, $branchId, $supplier, $lines, $expectedOn, $notes) {
            $order = new PurchaseOrder([
                'branch_id' => $branchId,
                'number' => $this->numbers->next('purchase_order', 'PO'),
                'supplier_id' => $supplier->id,
                'expected_on' => $expectedOn,
                'notes' => $notes,
                'created_by' => $by->id,
            ]);
            $order->status = PurchaseOrderStatus::Draft;
            $order->save();

            foreach ($lines as $line) {
                $order->lines()->create([
                    'medicine_id' => $line['medicine_id'],
                    'quantity_ordered' => $line['quantity'],
                    'unit_cost_minor' => $line['unit_cost_minor'],
                ]);
            }

            return $order;
        });
    }

    public function markOrdered(PurchaseOrder $order): void
    {
        $this->transition($order, [PurchaseOrderStatus::Draft], PurchaseOrderStatus::Ordered, ['ordered_at' => now()]);
    }

    /**
     * Only before anything arrived: received stock is never undone here.
     */
    public function cancel(PurchaseOrder $order): void
    {
        $this->transition($order, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], PurchaseOrderStatus::Cancelled, ['cancelled_at' => now()]);
    }

    /**
     * @param  list<PurchaseOrderStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function transition(PurchaseOrder $order, array $from, PurchaseOrderStatus $to, array $attributes): void
    {
        DB::transaction(function () use ($order, $from, $to, $attributes) {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => "Order {$locked->number} is {$locked->status->value}."]);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();
            $order->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }
}
