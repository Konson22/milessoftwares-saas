<?php

namespace App\Modules\Procurement\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Goods arriving from a supplier into a branch: one goods received note,
 * each line into stock (a batch), the purchase order's received quantities,
 * and one finance.bill.received for Finance. All in one transaction:
 * nothing is half received.
 *
 * A line counts packs at a cost per pack when it names a pack (against an
 * order: the order line's pack). Stock gets the units, at the pack cost
 * shared over its units; Finance is billed exactly packs x pack cost.
 */
class ReceiveGoods
{
    public function __construct(
        private readonly Stock $stock,
        private readonly ItemPacks $packs,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  list<array{item_id: string, batch_number: string, expires_on: string, quantity: int, unit_cost_minor: int, purchase_order_line_id?: string|null, pack_id?: string|null, location_id?: string|null}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(User $by, string $branchId, Supplier $supplier, ?PurchaseOrder $order, ?string $supplierReference, CarbonImmutable $receivedOn, array $lines): GoodsReceipt
    {
        return DB::transaction(function () use ($by, $branchId, $supplier, $order, $supplierReference, $receivedOn, $lines) {
            if ($order !== null) {
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($order->branch_id !== $branchId || $order->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'That order is for another branch or supplier.']);
                }
                if (! $order->status->canReceive()) {
                    throw ValidationException::withMessages(['purchase_order_id' => "Order {$order->number} is {$order->status->value}."]);
                }
            }

            $receipt = GoodsReceipt::query()->create([
                'branch_id' => $branchId,
                'number' => $this->numbers->next('goods_receipt', 'GRN'),
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'supplier_reference' => $supplierReference,
                'received_on' => $receivedOn,
                'total_minor' => 0,
                'received_by' => $by->id,
            ]);

            $total = 0;
            $billLines = [];

            foreach ($lines as $index => $line) {
                $item = Item::query()->find($line['item_id'])
                    ?? throw ValidationException::withMessages(["lines.{$index}.item_id" => 'Unknown item.']);

                $orderLine = null;
                if (! empty($line['purchase_order_line_id'])) {
                    // Only lines of this order, locked: never a line found by id alone.
                    $orderLine = $order?->lines()->whereKey($line['purchase_order_line_id'])->lockForUpdate()->first()
                        ?? throw ValidationException::withMessages(["lines.{$index}.purchase_order_line_id" => 'Not a line of this order.']);

                    if ($orderLine->item_id !== $item->id) {
                        throw ValidationException::withMessages(["lines.{$index}.item_id" => 'The order line is for another item.']);
                    }
                    if ($line['quantity'] > $orderLine->remaining()) {
                        throw ValidationException::withMessages(["lines.{$index}.quantity" => "Only {$orderLine->remaining()} still expected on the order."]);
                    }
                    [$packName, $units] = [$orderLine->pack_name, $orderLine->units_per_pack];
                } else {
                    $pack = $this->packs->find($item->id, $line['pack_id'] ?? null, "lines.{$index}.pack_id");
                    [$packName, $units] = [$pack?->name, $pack->units ?? 1];
                }

                // Straight onto a shelf of this branch, or left "not put away".
                $location = null;
                if (! empty($line['location_id'])) {
                    $location = StockLocation::query()
                        ->where('branch_id', $branchId)
                        ->where('active', true)
                        ->whereKey($line['location_id'])
                        ->first()
                        ?? throw ValidationException::withMessages(["lines.{$index}.location_id" => 'Not a location in use in this branch.']);
                }

                $batch = $this->stock->add(
                    item: $item,
                    branchId: $branchId,
                    batchNumber: $line['batch_number'],
                    expiresOn: CarbonImmutable::parse($line['expires_on']),
                    quantity: $line['quantity'] * $units,
                    unitCostMinor: (int) round($line['unit_cost_minor'] / $units),
                    user: $by,
                    type: MovementType::Receipt,
                    referenceType: 'goods_receipt',
                    referenceId: $receipt->id,
                    location: $location,
                );

                $lineTotal = $line['quantity'] * $line['unit_cost_minor'];
                $total += $lineTotal;

                $receipt->lines()->create([
                    'item_id' => $item->id,
                    'batch_id' => $batch->id,
                    'purchase_order_line_id' => $orderLine?->id,
                    'batch_number' => $line['batch_number'],
                    'expires_on' => $line['expires_on'],
                    'quantity' => $line['quantity'],
                    'unit_cost_minor' => $line['unit_cost_minor'],
                    'line_total_minor' => $lineTotal,
                    'pack_name' => $packName,
                    'units_per_pack' => $units,
                ]);

                if ($orderLine instanceof PurchaseOrderLine) {
                    $orderLine->forceFill(['quantity_received' => $orderLine->quantity_received + $line['quantity']])->save();
                }

                $billLines[] = [
                    'description' => $item->name.($packName === null ? '' : ", {$packName} of {$units}")." (batch {$line['batch_number']})",
                    'quantity' => $line['quantity'],
                    'amount_minor' => $lineTotal,
                    'expense_account' => null,
                ];
            }

            $receipt->forceFill(['total_minor' => $total])->save();

            if ($order !== null) {
                $remaining = $order->lines()->get()->sum(fn (PurchaseOrderLine $line) => $line->remaining());
                $order->forceFill(['status' => $remaining === 0 ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartlyReceived])->save();
            }

            // What the pharmacy owes the supplier: Finance posts it to payables.
            $this->outbox->record('finance', 'finance.bill.received', 1, 'goods_receipt:'.$receipt->id, [
                'bill_number' => $receipt->number,
                'supplier_reference' => $supplierReference,
                'supplier_name' => $supplier->name,
                'supplier_id' => $supplier->id,
                'branch_id' => $branchId,
                'currency' => Money::currency(),
                'total_minor' => $total,
                'tax_minor' => 0,
                'received_on' => $receivedOn->toDateString(),
                'due_on' => null,
                'lines' => $billLines,
            ], $receipt->created_at);

            return $receipt;
        });
    }
}
