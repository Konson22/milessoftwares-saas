<?php

namespace App\Petrol;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\PurchaseLine;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\SupplierPayment;
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
 * Fuel orders and deliveries. The measured quantity is what enters the tank.
 * Finance hears of the bill; paying the supplier here does not settle it there.
 */
class Purchasing
{
    public function __construct(
        private readonly TankLedger $tanks,
        private readonly Cashbook $cash,
        private readonly Outbox $outbox,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Rates $rates,
    ) {}

    /**
     * @param  list<array{fuel_product_id: string, litres: string, unit_cost: string, currency?: ?string}>  $lines
     */
    public function order(string $branchId, Supplier $supplier, array $lines, ?string $expectedOn, ?string $notes, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($branchId, $supplier, $lines, $expectedOn, $notes, $by) {
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => 'Add a fuel line.']);
            }

            $order = PurchaseOrder::query()->create([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'number' => $this->numbers->next('purchase', 'PO'),
                'status' => PurchaseOrder::ORDERED,
                'ordered_at' => now(),
                'expected_on' => $expectedOn,
                'notes' => $notes,
                'created_by' => $by->id,
            ]);

            foreach ($lines as $line) {
                $product = FuelProduct::query()->whereKey($line['fuel_product_id'])->where('active', true)->first();
                if ($product === null) {
                    throw ValidationException::withMessages(['lines' => 'Choose an active fuel grade.']);
                }

                $currency = strtoupper((string) ($line['currency'] ?? Money::currency()));
                $unit = Money::input($line['unit_cost'], $currency, 'lines');
                $snapshot = $this->rates->snapshot($branchId, $currency, $unit);
                PurchaseLine::query()->create([
                    'purchase_order_id' => $order->id,
                    'fuel_product_id' => $product->id,
                    'quantity_milli' => Volume::toMilli($line['litres']),
                    'unit_cost_minor' => $unit,
                    'currency' => $snapshot['currency'],
                    'fx_base' => $snapshot['fx_base'],
                    'fx_quote' => $snapshot['fx_quote'],
                    'fx_rate_minor' => $snapshot['fx_rate_minor'],
                    'reporting_unit_cost_minor' => $snapshot['reporting_minor'],
                ]);
            }

            $this->audit->record($by, 'purchase.ordered', $order, null, ['number' => $order->number], $branchId);

            return $order;
        });
    }

    /**
     * @param  array{tank_id: string, declared: string, measured: string, unit_cost: string, currency?: ?string, tanker: ?string, driver: ?string, supplier_reference: ?string, idempotency_key: ?string, purchase_line_id: ?string}  $input
     */
    public function receive(string $branchId, Supplier $supplier, array $input, User $by): FuelReceipt
    {
        $key = $input['idempotency_key'];
        if ($key !== null) {
            $existing = FuelReceipt::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($branchId, $supplier, $input, $by, $key) {
            $tank = Tank::query()->whereKey($input['tank_id'])->where('branch_id', $branchId)->where('status', Tank::ACTIVE)->first();
            if ($tank === null) {
                throw ValidationException::withMessages(['tank_id' => 'Choose an active tank at this station.']);
            }

            $line = null;
            if ($input['purchase_line_id'] !== null) {
                $line = PurchaseLine::query()->with('order')->whereKey($input['purchase_line_id'])->first();
                if ($line === null || $line->order->branch_id !== $branchId || $line->order->supplier_id !== $supplier->id || $line->order->status === PurchaseOrder::CANCELLED) {
                    throw ValidationException::withMessages(['purchase_line_id' => 'That order line is not open for this supplier.']);
                }
                if ($line->fuel_product_id !== $tank->fuel_product_id) {
                    throw ValidationException::withMessages(['tank_id' => 'The tank does not hold the fuel on this order line.']);
                }
            }

            $currency = strtoupper((string) ($input['currency'] ?? Money::currency()));
            try {
                $declared = Volume::toMilli($input['declared']);
                $measured = Volume::toMilli($input['measured']);
                $unitCost = Money::toMinor($input['unit_cost'], $currency);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['measured' => $e->getMessage()]);
            }

            if ($measured < 1 || $unitCost < 0) {
                throw ValidationException::withMessages(['measured' => 'Enter the quantity measured in the tank.']);
            }

            $total = Volume::amount($measured, $unitCost);
            $snapshot = $this->rates->snapshot($branchId, $currency, $total);
            $bookUnit = $currency === Money::currency()
                ? $unitCost
                : (int) intdiv($snapshot['reporting_minor'] * 1000 + intdiv($measured, 2), $measured);
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
                'measured_milli' => $measured,
                'discrepancy_milli' => $measured - $declared,
                'status' => FuelReceipt::APPROVED,
                'accepted_milli' => $measured,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'unit_cost_minor' => $unitCost,
                'total_minor' => $total,
                'currency' => $snapshot['currency'],
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_total_minor' => $snapshot['reporting_minor'],
                'reporting_unit_cost_minor' => $bookUnit,
                'supplier_reference' => $input['supplier_reference'],
                'idempotency_key' => $key,
                'received_by' => $by->id,
                'received_at' => now(),
            ]);

            $this->tanks->receive($tank, $measured, $bookUnit, $by, $receipt->id);

            if ($line !== null) {
                $line->forceFill(['received_milli' => $line->received_milli + $measured])->save();
                $order = $line->order;
                $order->load('lines');
                $open = $order->lines->contains(fn (PurchaseLine $row) => $row->received_milli < $row->quantity_milli);
                $any = $order->lines->contains(fn (PurchaseLine $row) => $row->received_milli > 0);
                $order->forceFill([
                    'status' => $open ? ($any ? PurchaseOrder::PARTIAL : PurchaseOrder::ORDERED) : PurchaseOrder::RECEIVED,
                ])->save();
            }

            $product = $tank->product()->firstOrFail();
            $this->outbox->record('finance', 'finance.bill.received', 1, 'goods_receipt:'.$receipt->id, [
                'bill_number' => $receipt->number,
                'supplier_reference' => $receipt->supplier_reference,
                'supplier_name' => $supplier->name,
                'supplier_id' => $supplier->id,
                'branch_id' => $branchId,
                'currency' => Money::currency(),
                'total_minor' => $snapshot['reporting_minor'],
                'tax_minor' => 0,
                'received_on' => BusinessDate::today(),
                'due_on' => null,
                'lines' => [[
                    'description' => $product->name.' '.Volume::toLitres($measured).' L',
                    'quantity' => (float) Volume::toLitres($measured),
                    'amount_minor' => $snapshot['reporting_minor'],
                    'expense_account' => null,
                ]],
            ], $receipt->received_at);

            $this->audit->record($by, 'fuel.received', $receipt, null, [
                'measured_milli' => $measured,
                'discrepancy_milli' => $receipt->discrepancy_milli,
            ], $branchId);

            return $receipt;
        });
    }

    public function pay(string $branchId, Supplier $supplier, ?Shift $shift, int $amount, string $paidFrom, ?string $receiptId, ?string $reference, User $by, ?string $key, ?string $currency = null): SupplierPayment
    {
        return DB::transaction(function () use ($branchId, $supplier, $shift, $amount, $paidFrom, $receiptId, $reference, $by, $key, $currency) {
            if ($key !== null) {
                $existing = SupplierPayment::query()->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
            }

            $currency = strtoupper($currency ?? Money::currency());
            $snapshot = $this->rates->snapshot($branchId, $currency, $amount);
            $outstanding = $this->outstanding($supplier->id, $branchId);
            if ($snapshot['reporting_minor'] > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'That is more than this station owes the supplier.']);
            }
            if ($currency !== Money::currency() && $paidFrom === Expense::CASH) {
                throw ValidationException::withMessages(['paid_from' => 'A foreign-currency payment is paid from the bank, not the drawer.']);
            }

            if ($paidFrom === Expense::CASH && ($shift === null || ! $shift->isOpen())) {
                throw ValidationException::withMessages(['paid_from' => 'Open a shift before paying from the drawer.']);
            }

            $payment = SupplierPayment::query()->create([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'fuel_receipt_id' => $receiptId,
                'amount_minor' => $amount,
                'currency' => $snapshot['currency'],
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_amount_minor' => $snapshot['reporting_minor'],
                'paid_from' => $paidFrom,
                'reference' => $reference,
                'idempotency_key' => $key,
                'paid_by' => $by->id,
                'paid_at' => now(),
            ]);

            if ($paidFrom === Expense::CASH) {
                $this->cash->move($branchId, $shift->id, CashMovement::SUPPLIER_PAYMENT, -$amount, $by, $key === null ? null : $key.':cash', $reference, ['supplier_payment_id' => $payment->id]);
            }

            $this->audit->record($by, 'supplier.paid', $payment, null, ['amount_minor' => $amount], $branchId);

            return $payment;
        });
    }

    public function outstanding(string $supplierId, string $branchId): int
    {
        $billed = (int) FuelReceipt::query()->where('supplier_id', $supplierId)->where('branch_id', $branchId)->sum(DB::raw('coalesce(reporting_total_minor, total_minor)'));
        $paid = (int) SupplierPayment::query()->where('supplier_id', $supplierId)->where('branch_id', $branchId)->sum(DB::raw('coalesce(reporting_amount_minor, amount_minor)'));

        return $billed - $paid;
    }
}
