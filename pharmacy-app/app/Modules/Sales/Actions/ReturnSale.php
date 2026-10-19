<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleReturn;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Part of a sale brought back: up to what was sold on each line, less what
 * already came back. The refund is what those units were sold for
 * (discount included), worked out on the line's total so that returns in
 * parts add up to it exactly; it is given the way the sale was paid, cash
 * from the open till of whoever takes the return. Units fit to sell again
 * go back into their batch. Finance reverses the revenue, the payment and,
 * for restocked units, the cost of goods sold (finance.sales_return.issued).
 *
 * On a sale on account nothing is refunded: the return takes the amount off
 * what the customer still owes on that sale, and never more than that.
 */
class ReturnSale
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
        private readonly Tills $tills,
        private readonly CustomerAccounts $accounts,
    ) {}

    /**
     * @param  list<array{sale_line_id: string, quantity: int, restock: bool}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(Sale $sale, User $by, array $lines, string $reason): SaleReturn
    {
        return DB::transaction(function () use ($sale, $by, $lines, $reason) {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Sale::COMPLETED) {
                throw ValidationException::withMessages(['lines' => "Sale {$locked->number} is voided: nothing can be returned."]);
            }

            /** @var Collection<string, SaleLine> $saleLines */
            $saleLines = $locked->lines()->with(['item', 'batch'])->get()->keyBy('id');
            $already = DB::table('sale_return_lines')
                ->whereIn('sale_line_id', $saleLines->keys())
                ->groupBy('sale_line_id')
                ->selectRaw('sale_line_id, SUM(quantity) AS quantity, SUM(amount_minor) AS amount')
                ->get();
            $returnedQuantities = $already->pluck('quantity', 'sale_line_id');
            $returnedAmounts = $already->pluck('amount', 'sale_line_id');

            $returning = [];
            foreach ($lines as $index => $line) {
                if ($line['quantity'] === 0) {
                    continue;
                }

                $saleLine = $saleLines->get($line['sale_line_id']);
                if ($saleLine === null) {
                    throw ValidationException::withMessages(["lines.{$index}.sale_line_id" => 'This line is not part of the sale.']);
                }

                $returnedQuantity = (int) $returnedQuantities->get($saleLine->id, 0);
                $returnedAmount = (int) $returnedAmounts->get($saleLine->id, 0);
                $left = $saleLine->quantity - $returnedQuantity;

                if ($line['quantity'] < 0 || $line['quantity'] > $left) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "Only {$left} of {$saleLine->item->name} can still be returned."]);
                }

                // What the line has given back once these come back, rounded
                // half up; this return is the step from what it gave so far.
                $after = $returnedQuantity + $line['quantity'];
                $amount = intdiv(2 * $saleLine->line_total_minor * $after + $saleLine->quantity, 2 * $saleLine->quantity) - $returnedAmount;

                $returning[] = [$saleLine, $line['quantity'], $amount, $line['restock']];
            }

            if ($returning === []) {
                throw ValidationException::withMessages(['lines' => 'Return at least one item.']);
            }

            $total = array_sum(array_column($returning, 2));
            if ($locked->onAccount() && $total > ($owed = $this->accounts->owedOn($locked))) {
                throw ValidationException::withMessages(['lines' => 'The customer owes only '.Money::format($owed)." on sale {$locked->number}: a return cannot take off more than is still owed."]);
            }
            $till = $locked->payment_method === PaymentMethod::Cash && $total > 0
                ? $this->tills->requireOpen($by, $locked->branch_id, 'lines')
                : $this->tills->openFor($by, $locked->branch_id);

            $return = SaleReturn::query()->create([
                'branch_id' => $locked->branch_id,
                'sale_id' => $locked->id,
                'number' => $this->numbers->next('sale_return', 'R', 6),
                'reason' => $reason,
                'currency' => $locked->currency,
                'total_minor' => $total,
                'refund_method' => $locked->payment_method,
                'till_session_id' => $till?->id,
                'returned_by' => $by->id,
                'returned_at' => now(),
            ]);

            $eventLines = [];
            foreach ($returning as [$saleLine, $quantity, $amount, $restock]) {
                $cost = $quantity * $saleLine->batch->unit_cost_minor;

                $return->lines()->create([
                    'sale_line_id' => $saleLine->id,
                    'item_id' => $saleLine->item_id,
                    'batch_id' => $saleLine->batch_id,
                    'quantity' => $quantity,
                    'amount_minor' => $amount,
                    'cost_minor' => $cost,
                    'restocked' => $restock,
                ]);

                if ($restock) {
                    $this->stock->putBack($saleLine->batch, $quantity, $by, 'sale_return', $return->id, MovementType::SaleReturn);
                }

                $eventLines[] = [
                    'description' => $saleLine->item->name,
                    'quantity' => $quantity,
                    'amount_minor' => $amount,
                    'revenue_account' => null,
                    // Only what goes back on the shelf returns to inventory;
                    // the rest stays a cost.
                    'cost_minor' => $restock ? $cost : 0,
                ];
            }

            $this->outbox->record('finance', 'finance.sales_return.issued', 1, "sale_return:{$return->id}", [
                'return_number' => $return->number,
                'invoice_number' => $locked->number,
                'branch_id' => $locked->branch_id,
                'currency' => $return->currency,
                'total_minor' => $total,
                'tax_minor' => 0,
                'reason' => $reason,
                'lines' => $eventLines,
                'refund' => $total > 0 && $locked->payment_method->paidNow() ? [
                    'method' => $locked->payment_method->value,
                    'amount_minor' => $total,
                    'payment_reference' => $return->number,
                ] : null,
            ], $return->returned_at);

            return $return;
        });
    }
}
