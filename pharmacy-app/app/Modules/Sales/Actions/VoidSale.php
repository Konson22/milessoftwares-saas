<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Sales\Events\SaleVoided;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Cancels a sale: its stock goes back into the batches it came from, the
 * payment is refunded the same way, and Finance reverses both
 * (finance.invoice.voided). A sale is voided once.
 */
class VoidSale
{
    public function __construct(
        private readonly Stock $stock,
        private readonly Outbox $outbox,
        private readonly Dispatcher $events,
    ) {}

    public function handle(Sale $sale, User $by, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $by, $reason) {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Sale::COMPLETED) {
                throw ValidationException::withMessages(['reason' => "Sale {$locked->number} is already voided."]);
            }

            foreach ($locked->lines()->with('batch')->get() as $line) {
                /** @var SaleLine $line */
                $this->stock->putBack($line->batch, $line->quantity, $by, 'sale', $locked->id);
            }

            $locked->forceFill([
                'status' => Sale::VOIDED,
                'voided_by' => $by->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            // Industry modules undo their part (a dispensed prescription can
            // be dispensed again), in this transaction.
            $this->events->dispatch(new SaleVoided($locked));

            $this->outbox->record('finance', 'finance.invoice.voided', 1, "sale:{$locked->id}:void", [
                'invoice_number' => $locked->number,
                'branch_id' => $locked->branch_id,
                'currency' => $locked->currency,
                'total_minor' => $locked->total_minor,
                'reason' => $reason,
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
