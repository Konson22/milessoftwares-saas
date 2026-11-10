<?php

namespace App\Petrol;

use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\PurchaseLine;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use App\Models\ShiftException;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tank;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\DocumentNumbers;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * A delivery stays pending until a second person accepts a quantity.
 * Sales during unloading are subtracted from the expected dip, using the
 * stock movements the sales already posted. Meters are not deducted again.
 */
class Deliveries
{
    public function __construct(
        private readonly TankLedger $tanks,
        private readonly Rates $rates,
        private readonly Outbox $outbox,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
        private readonly ShiftExceptions $exceptions,
    ) {}

    /**
     * @param  array{tank_id: string, declared: string, before: string, unit_cost: string, currency: ?string, tanker: ?string, driver: ?string, supplier_reference: ?string, purchase_line_id: ?string, idempotency_key: ?string}  $input
     */
    public function start(string $branchId, Supplier $supplier, array $input, User $by): FuelReceipt
    {
        $key = $input['idempotency_key'];
        if ($key !== null) {
            $existing = FuelReceipt::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($branchId, $supplier, $input, $by, $key) {
            $tank = $this->tank($branchId, $input['tank_id']);
            if (FuelReceipt::query()->where('tank_id', $tank->id)->where('status', FuelReceipt::PENDING)->exists()) {
                throw ValidationException::withMessages(['tank_id' => 'This tank already has a delivery that is not approved.']);
            }
            $line = $this->line($branchId, $supplier, $tank, $input['purchase_line_id']);
            $reference = $input['supplier_reference'];
            if ($reference !== null && FuelReceipt::query()->where('supplier_id', $supplier->id)->where('supplier_reference', $reference)->where('status', '!=', FuelReceipt::REJECTED)->exists()) {
                throw ValidationException::withMessages(['supplier_reference' => 'That delivery reference is already recorded.']);
            }
            try {
                $declared = Volume::toMilli($input['declared']);
                $before = Volume::toMilli($input['before']);
                $currency = strtoupper((string) ($input['currency'] ?? Money::currency()));
                $unitCost = Money::toMinor($input['unit_cost'], $currency);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['declared' => $e->getMessage()]);
            }
            if ($declared < 1) {
                throw ValidationException::withMessages(['declared' => 'Enter the quantity the supplier declared.']);
            }
            $receipt = FuelReceipt::query()->create([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $line?->purchase_order_id,
                'purchase_line_id' => $line?->id,
                'tank_id' => $tank->id,
                'number' => $this->numbers->next('receipt', 'GR'),
                'tanker' => $input['tanker'],
                'driver' => $input['driver'],
                'declared_milli' => $declared,
                'measured_milli' => $before,
                'discrepancy_milli' => 0,
                'status' => FuelReceipt::PENDING,
                'unit_cost_minor' => $unitCost,
                'total_minor' => 0,
                'currency' => $currency,
                'supplier_reference' => $reference,
                'idempotency_key' => $key,
                'received_by' => $by->id,
                'received_at' => now(),
                'started_at' => now(),
                'before_milli' => $before,
                'reconciliation' => 'provisional',
            ]);
            $this->audit->record($by, 'fuel.delivery.started', $receipt, null, ['declared_milli' => $declared], $branchId);

            return $receipt;
        });
    }

    public function complete(FuelReceipt $receipt, ?string $afterLitres, string $acceptedLitres, User $by): FuelReceipt
    {
        return DB::transaction(function () use ($receipt, $afterLitres, $acceptedLitres, $by) {
            $locked = FuelReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FuelReceipt::PENDING || $locked->started_at === null) {
                throw ValidationException::withMessages(['receipt' => 'This delivery is not waiting for a closing measurement.']);
            }
            try {
                $after = $afterLitres === null || $afterLitres === '' ? null : Volume::toMilli($afterLitres);
                $accepted = Volume::toMilli($acceptedLitres);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['accepted' => $e->getMessage()]);
            }
            if ($accepted < 1) {
                throw ValidationException::withMessages(['accepted' => 'Enter the quantity to accept.']);
            }
            $tank = Tank::query()->whereKey($locked->tank_id)->firstOrFail();
            $figures = $after === null ? null : $this->figures($locked, $tank, $accepted, $after);
            $limit = $figures === null ? $tank->quantity_milli + $accepted : $figures['expected'];
            if ($limit > $tank->fillLimit()) {
                throw ValidationException::withMessages(['accepted' => 'That quantity would pass the tank safe fill of '.Volume::toLitres($tank->fillLimit()).' L.']);
            }
            $currency = $locked->currency ?: Money::currency();
            $total = Volume::amount($accepted, (int) $locked->unit_cost_minor);
            $snapshot = $this->rates->snapshot($locked->branch_id, $currency, $total);
            $locked->forceFill([
                'measured_milli' => $after ?? (int) $locked->before_milli,
                'accepted_milli' => $accepted,
                'discrepancy_milli' => $accepted - (int) $locked->declared_milli,
                'total_minor' => $total,
                'completed_at' => now(),
                'after_milli' => $after,
                'dispensed_milli' => $figures['dispensed'] ?? null,
                'expected_milli' => $figures['expected'] ?? null,
                'receiving_variance_milli' => $figures['variance'] ?? null,
                'reconciliation' => $figures === null ? 'provisional' : 'verified',
                'currency' => $snapshot['currency'],
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_total_minor' => $snapshot['reporting_minor'],
                'reporting_unit_cost_minor' => $currency === Money::currency()
                    ? $locked->unit_cost_minor
                    : (int) intdiv($snapshot['reporting_minor'] * 1000 + intdiv($accepted, 2), $accepted),
            ])->save();
            if ($figures === null) {
                $this->flag($locked, 'Delivery '.$locked->number.' has no closing tank measurement, so the receiving check is provisional.', ShiftException::WARNING, $by);
            } elseif ($figures['variance'] !== 0) {
                $this->flag($locked, 'Accepted quantity and the tank measurements differ by '.Volume::toLitres($figures['variance']).' L after sales during unloading.', ShiftException::WARNING, $by);
            }
            $this->audit->record($by, 'fuel.delivery.completed', $locked, null, [
                'accepted_milli' => $accepted,
                'receiving_variance_milli' => $figures['variance'] ?? null,
            ], $locked->branch_id);

            return $locked;
        });
    }

    public function approve(FuelReceipt $receipt, User $by): FuelReceipt
    {
        return DB::transaction(function () use ($receipt, $by) {
            $locked = FuelReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === FuelReceipt::APPROVED) {
                return $locked;
            }
            if ($locked->status !== FuelReceipt::PENDING || $locked->completed_at === null || $locked->accepted_milli === null) {
                throw ValidationException::withMessages(['receipt' => 'Finish the delivery measurements before approving.']);
            }
            if ($locked->received_by === $by->id) {
                throw ValidationException::withMessages(['receipt' => 'Someone else has to approve a delivery you received.']);
            }
            if ($locked->reconciliation !== 'verified') {
                throw ValidationException::withMessages(['receipt' => 'The receiving reconciliation is still provisional.']);
            }
            $tank = Tank::query()->whereKey($locked->tank_id)->firstOrFail();
            $bookUnit = (int) ($locked->reporting_unit_cost_minor ?: $locked->unit_cost_minor);
            $this->tanks->receive($tank, (int) $locked->accepted_milli, $bookUnit, $by, $locked->id);
            $locked->forceFill([
                'status' => FuelReceipt::APPROVED,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();
            $this->closeOrder($locked);
            $product = FuelProduct::query()->whereKey($tank->fuel_product_id)->firstOrFail();
            $supplier = Supplier::query()->whereKey($locked->supplier_id)->firstOrFail();
            $this->outbox->record('finance', 'finance.bill.received', 1, 'goods_receipt:'.$locked->id, [
                'bill_number' => $locked->number,
                'supplier_reference' => $locked->supplier_reference,
                'supplier_name' => $supplier->name,
                'supplier_id' => $supplier->id,
                'branch_id' => $locked->branch_id,
                'currency' => Money::currency(),
                'total_minor' => (int) $locked->reporting_total_minor,
                'tax_minor' => 0,
                'received_on' => BusinessDate::today(),
                'due_on' => null,
                'lines' => [[
                    'description' => $product->name.' '.Volume::toLitres((int) $locked->accepted_milli).' L',
                    'quantity' => (float) Volume::toLitres((int) $locked->accepted_milli),
                    'amount_minor' => (int) $locked->reporting_total_minor,
                    'expense_account' => null,
                ]],
            ], $locked->approved_at);
            $this->audit->record($by, 'fuel.delivery.approved', $locked, null, ['accepted_milli' => $locked->accepted_milli], $locked->branch_id);

            return $locked;
        });
    }

    /**
     * @return array{dispensed: int, expected: int, variance: int}
     */
    private function figures(FuelReceipt $receipt, Tank $tank, int $accepted, int $after): array
    {
        $movements = StockMovement::query()
            ->where('tank_id', $tank->id)
            ->where('occurred_at', '>=', $receipt->started_at)
            ->where('occurred_at', '<=', now())
            ->get();
        $dispensed = 0;
        $net = 0;
        foreach ($movements as $movement) {
            $net += (int) $movement->quantity_milli;
            if ($movement->kind === StockMovement::SALE) {
                $dispensed += -((int) $movement->quantity_milli);
            }
        }
        $before = (int) $receipt->before_milli;
        $expected = $before + $accepted + $net;

        return [
            'dispensed' => $dispensed,
            'expected' => $expected,
            'variance' => $after - $expected,
        ];
    }

    private function tank(string $branchId, string $tankId): Tank
    {
        $tank = Tank::query()->whereKey($tankId)->where('branch_id', $branchId)->where('status', Tank::ACTIVE)->first();
        if ($tank === null) {
            throw ValidationException::withMessages(['tank_id' => 'Choose an active tank at this station.']);
        }

        return $tank;
    }

    private function line(string $branchId, Supplier $supplier, Tank $tank, ?string $lineId): ?PurchaseLine
    {
        if ($lineId === null) {
            return null;
        }
        $line = PurchaseLine::query()->with('order')->whereKey($lineId)->first();
        if ($line === null || $line->order->branch_id !== $branchId || $line->order->supplier_id !== $supplier->id || $line->order->status === PurchaseOrder::CANCELLED) {
            throw ValidationException::withMessages(['purchase_line_id' => 'That order line is not open for this supplier.']);
        }
        if ($line->fuel_product_id !== $tank->fuel_product_id) {
            throw ValidationException::withMessages(['tank_id' => 'The tank does not hold the fuel on this order line.']);
        }

        return $line;
    }

    private function closeOrder(FuelReceipt $receipt): void
    {
        if ($receipt->purchase_line_id === null) {
            return;
        }
        $line = PurchaseLine::query()->with('order.lines')->whereKey($receipt->purchase_line_id)->first();
        if ($line === null) {
            return;
        }
        $line->forceFill(['received_milli' => $line->received_milli + (int) $receipt->accepted_milli])->save();
        $order = $line->order;
        $order->load('lines');
        $open = $order->lines->contains(fn (PurchaseLine $row) => $row->received_milli < $row->quantity_milli);
        $any = $order->lines->contains(fn (PurchaseLine $row) => $row->received_milli > 0);
        $order->forceFill([
            'status' => $open ? ($any ? PurchaseOrder::PARTIAL : PurchaseOrder::ORDERED) : PurchaseOrder::RECEIVED,
        ])->save();
    }

    private function flag(FuelReceipt $receipt, string $description, string $severity, User $by): void
    {
        $shift = Shift::query()->where('branch_id', $receipt->branch_id)->where('status', Shift::OPEN)->first();
        if ($shift === null) {
            return;
        }
        $this->exceptions->open($shift, null, null, 'receiving', $description, $severity, $by, 'fuel_receipt', $receipt->id);
    }
}
