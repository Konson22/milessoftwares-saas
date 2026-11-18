<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\MenuItem;
use App\Models\StockDelivery;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Drinks at a branch: how many are on hand and at what average cost. Only
 * drinks keep stock; a dish is made to order. Every change is a movement,
 * and a change Finance must know of (stock found, counted or written off)
 * is told to it as finance.stock.adjusted at the average cost.
 *
 * A bill takes its drinks and a voided bill puts them back, inside the
 * bill's transaction (Bills). The drinks were served before the bill, so a
 * bill is never refused for stock: one selling more than the branch holds
 * takes it below zero, and the stock pages ask for a count. Counts and
 * write-offs never take it below zero.
 *
 * A supplier's delivery adds drinks at what they cost and tells Finance
 * what is owed to the supplier (finance.bill.received), not an adjustment.
 */
class Stock
{
    /** Why a count changed stock: counted corrects either way, the rest write off. */
    public const REASONS = [
        'count' => 'Counted',
        'damaged' => 'Damaged or spilt',
        'expired' => 'Expired',
        'missing' => 'Missing',
    ];

    public function __construct(
        private readonly Outbox $outbox,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * Drinks already at the branch when it starts, or found: added at the
     * cost they were bought at.
     *
     * @throws ValidationException
     */
    public function opening(MenuItem $item, string $branchId, int $quantity, int $unitCostMinor, User $by, ?string $note = null): StockMovement
    {
        $this->ensureStocked($item, 'quantity');
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Add at least one.']);
        }

        return DB::transaction(function () use ($item, $branchId, $quantity, $unitCostMinor, $by, $note) {
            $level = $this->lock($item, $branchId);
            $this->receive($level, $quantity, $unitCostMinor);

            $movement = $this->record($level, StockMovement::OPENING, $quantity, $unitCostMinor, $by, note: $note);
            $this->toFinance($item, $branchId, $quantity, $unitCostMinor, 'opening', 'Opening stock'.($note ? ": {$note}" : ''));

            return $movement;
        });
    }

    /**
     * Drinks a supplier delivered, each at what one cost. The average cost
     * moves towards it; Finance hears of the supplier's bill.
     *
     * @param  list<array{item: MenuItem, quantity: int, unit_cost_minor: int}>  $lines
     *
     * @throws ValidationException
     */
    public function deliver(string $branchId, string $supplierName, ?string $supplierReference, array $lines, User $by, ?string $note = null): StockDelivery
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one drink.']);
        }
        foreach ($lines as $line) {
            $this->ensureStocked($line['item'], 'lines');
            if ($line['quantity'] < 1) {
                throw ValidationException::withMessages(['lines' => "Add at least one {$line['item']->unit} of {$line['item']->name}."]);
            }
        }

        return DB::transaction(function () use ($branchId, $supplierName, $supplierReference, $lines, $by, $note) {
            $total = array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_cost_minor'], $lines));
            $delivery = StockDelivery::query()->create([
                'branch_id' => $branchId,
                'number' => $this->numbers->next('stock_delivery', 'DEL', 6),
                'supplier_name' => trim($supplierName),
                'supplier_reference' => blank($supplierReference) ? null : trim((string) $supplierReference),
                'received_on' => now()->toDateString(),
                'total_minor' => $total,
                'note' => blank($note) ? null : trim((string) $note),
                'received_by' => $by->id,
            ]);

            foreach ($lines as $line) {
                $level = $this->lock($line['item'], $branchId);
                $this->receive($level, $line['quantity'], $line['unit_cost_minor']);
                $this->record($level, StockMovement::DELIVERY, $line['quantity'], $line['unit_cost_minor'], $by, note: $delivery->supplier_name, delivery: $delivery);
            }

            if ($total > 0) {
                $this->outbox->record('finance', 'finance.bill.received', 1, "stock_delivery:{$delivery->id}", [
                    'bill_number' => $delivery->number,
                    'supplier_reference' => $delivery->supplier_reference,
                    'supplier_name' => mb_substr($delivery->supplier_name, 0, 255),
                    'supplier_id' => null,
                    'branch_id' => $branchId,
                    'currency' => Money::currency(),
                    'total_minor' => $total,
                    'tax_minor' => 0,
                    'received_on' => $delivery->received_on->toDateString(),
                    'due_on' => null,
                    'lines' => array_map(fn (array $line) => [
                        'description' => mb_substr($line['item']->name, 0, 255),
                        'quantity' => $line['quantity'],
                        'amount_minor' => $line['quantity'] * $line['unit_cost_minor'],
                        'expense_account' => null,
                    ], $lines),
                ], now());
            }

            return $delivery;
        });
    }

    /**
     * A count or a write-off at the average cost: counted corrects either
     * way, damaged, expired and missing drinks only go down.
     *
     * @throws ValidationException
     */
    public function adjust(MenuItem $item, string $branchId, int $change, string $reason, User $by, ?string $note = null): StockMovement
    {
        $this->ensureStocked($item, 'change');
        if (! array_key_exists($reason, self::REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why the stock changed.']);
        }
        if ($change === 0) {
            throw ValidationException::withMessages(['change' => 'Enter how many more or fewer there are.']);
        }
        if ($reason !== 'count' && $change > 0) {
            throw ValidationException::withMessages(['change' => self::REASONS[$reason].' drinks only go down: enter a negative number.']);
        }

        return DB::transaction(function () use ($item, $branchId, $change, $reason, $by, $note) {
            $level = $this->lock($item, $branchId);
            if ($level->quantity + $change < 0) {
                throw ValidationException::withMessages(['change' => "Only {$level->quantity} {$item->unit} of {$item->name} on hand."]);
            }

            $level->forceFill(['quantity' => $level->quantity + $change])->save();
            $kind = $reason === 'count' ? StockMovement::COUNT : StockMovement::WRITE_OFF;
            $movement = $this->record($level, $kind, $change, $level->average_cost_minor, $by, reason: $reason, note: $note);
            $this->toFinance($item, $branchId, $change, $level->average_cost_minor, $kind, self::REASONS[$reason].($note ? ": {$note}" : ''));

            return $movement;
        });
    }

    /**
     * Takes a bill's drinks; call inside the bill's transaction. Returns
     * what they cost. More than the branch holds takes it below zero (a
     * count is due), never refused: the drinks have been served.
     */
    public function take(Bill $bill, MenuItem $item, int $quantity, User $by): int
    {
        $level = $this->lock($item, $bill->branch_id);
        $level->forceFill(['quantity' => $level->quantity - $quantity])->save();
        $this->record($level, StockMovement::SALE, -$quantity, $level->average_cost_minor, $by, bill: $bill);

        return $quantity * $level->average_cost_minor;
    }

    /**
     * Puts a voided bill's drinks back at what they cost it.
     */
    public function putBack(Bill $bill, MenuItem $item, int $quantity, int $unitCostMinor, User $by): void
    {
        $level = $this->lock($item, $bill->branch_id);
        $this->receive($level, $quantity, $unitCostMinor);
        $this->record($level, StockMovement::BILL_VOID, $quantity, $unitCostMinor, $by, bill: $bill);
    }

    private function ensureStocked(MenuItem $item, string $field): void
    {
        if (! $item->tracksStock()) {
            throw ValidationException::withMessages([$field => "{$item->name} is made to order: it keeps no stock."]);
        }
    }

    private function lock(MenuItem $item, string $branchId): StockLevel
    {
        StockLevel::query()->firstOrCreate(['branch_id' => $branchId, 'menu_item_id' => $item->id]);

        return StockLevel::query()
            ->where('branch_id', $branchId)
            ->where('menu_item_id', $item->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * More of a drink at a cost: the average moves towards it.
     */
    private function receive(StockLevel $level, int $quantity, int $unitCostMinor): void
    {
        $before = max($level->quantity, 0);
        $average = intdiv($before * $level->average_cost_minor + $quantity * $unitCostMinor + intdiv($before + $quantity, 2), $before + $quantity);

        $level->forceFill([
            'quantity' => $level->quantity + $quantity,
            'average_cost_minor' => $average,
        ])->save();
    }

    private function record(StockLevel $level, string $type, int $quantity, int $unitCostMinor, User $by, ?string $reason = null, ?string $note = null, ?Bill $bill = null, ?StockDelivery $delivery = null): StockMovement
    {
        return StockMovement::query()->create([
            'branch_id' => $level->branch_id,
            'menu_item_id' => $level->menu_item_id,
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost_minor' => $unitCostMinor,
            'reason' => $reason,
            'note' => blank($note) ? null : trim((string) $note),
            'bill_id' => $bill?->id,
            'delivery_id' => $delivery?->id,
            'created_by' => $by->id,
        ]);
    }

    /**
     * A change at a cost, signed: positive stock gained.
     *
     * @param  'opening'|'count'|'write_off'  $kind
     */
    private function toFinance(MenuItem $item, string $branchId, int $quantity, int $unitCostMinor, string $kind, string $reason): void
    {
        $amount = $quantity * $unitCostMinor;
        if ($amount === 0) {
            return;
        }

        $reference = $this->numbers->next('stock_adjustment', 'ADJ', 6);
        $this->outbox->record('finance', 'finance.stock.adjusted', 1, "stock_adjustment:{$kind}:{$reference}", [
            'reference' => $reference,
            'branch_id' => $branchId,
            'currency' => Money::currency(),
            'adjusted_on' => now()->toDateString(),
            'kind' => $kind,
            'amount_minor' => $amount,
            'reason' => mb_substr($reason, 0, 255),
            'lines' => [[
                'description' => $item->name,
                'quantity' => $quantity,
                'amount_minor' => $amount,
            ]],
        ], now());
    }
}
