<?php

namespace App\Pharmacy;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Goods going back to the supplier they came from: out of stock at the
 * cost they were received at, and a credit note for Finance, which takes
 * it off what is owed for that delivery.
 */
class ReturnGoods
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array<string, int>  $quantities  goods receipt line id => quantity going back
     */
    public function handle(User $by, GoodsReceipt $receipt, array $quantities, string $reason, CarbonImmutable $returnedOn): SupplierReturn
    {
        $quantities = array_filter($quantities, fn (int $quantity) => $quantity !== 0);

        if ($quantities === []) {
            throw ValidationException::withMessages(['quantities' => 'Return at least one item.']);
        }

        return DB::transaction(function () use ($by, $receipt, $quantities, $reason, $returnedOn) {
            // Locked: two returns of the same delivery never return it twice.
            $locked = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $lines = $locked->lines()->with('medicine')->get()->keyBy('id');

            $unknown = array_diff(array_keys($quantities), $lines->keys()->all());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['quantities' => 'An item is not on this delivery.']);
            }

            $returned = SupplierReturnLine::query()
                ->whereIn('goods_receipt_line_id', $lines->keys())
                ->groupBy('goods_receipt_line_id')
                ->selectRaw('goods_receipt_line_id, SUM(quantity) AS quantity')
                ->pluck('quantity', 'goods_receipt_line_id');

            $return = SupplierReturn::query()->create([
                'branch_id' => $locked->branch_id,
                'number' => $this->numbers->next('supplier_return', 'RTN'),
                'supplier_id' => $locked->supplier_id,
                'goods_receipt_id' => $locked->id,
                'returned_on' => $returnedOn,
                'reason' => $reason,
                'total_minor' => 0,
                'returned_by' => $by->id,
            ]);

            $total = 0;
            $creditLines = [];

            foreach ($quantities as $lineId => $quantity) {
                /** @var GoodsReceiptLine $line */
                $line = $lines->get($lineId);
                $left = $line->quantity - (int) ($returned[$lineId] ?? 0);

                if ($quantity < 0 || $quantity > $left) {
                    throw ValidationException::withMessages(["quantities.{$lineId}" => "Only {$left} of {$line->quantity} can still go back."]);
                }

                $this->stock->returnToSupplier($line->batch()->firstOrFail(), $quantity, $by, $return->id, "quantities.{$lineId}");

                $lineTotal = $quantity * $line->unit_cost_minor;
                $return->lines()->create([
                    'goods_receipt_line_id' => $line->id,
                    'medicine_id' => $line->medicine_id,
                    'batch_id' => $line->batch_id,
                    'quantity' => $quantity,
                    'unit_cost_minor' => $line->unit_cost_minor,
                    'line_total_minor' => $lineTotal,
                ]);
                $total += $lineTotal;
                $creditLines[] = [
                    'description' => "{$line->medicine->label()} (batch {$line->batch_number})",
                    'quantity' => $quantity,
                    'amount_minor' => $lineTotal,
                    'expense_account' => null,
                ];
            }

            $return->forceFill(['total_minor' => $total])->save();
            $supplier = $locked->supplier()->firstOrFail();

            // Finance takes it off what is owed for that delivery.
            $this->outbox->record('finance', 'finance.credit_note.received', 1, 'supplier_return:'.$return->id, [
                'credit_note_number' => $return->number,
                'bill_number' => $locked->number,
                'supplier_name' => $supplier->name,
                'supplier_id' => $supplier->id,
                'branch_id' => $locked->branch_id,
                'currency' => Money::currency(),
                'total_minor' => $total,
                'tax_minor' => 0,
                'credited_on' => $returnedOn->toDateString(),
                'reason' => $reason,
                'lines' => $creditLines,
            ], $return->created_at);

            return $return;
        });
    }
}
