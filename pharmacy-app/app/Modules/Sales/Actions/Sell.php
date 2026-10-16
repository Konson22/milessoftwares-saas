<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * A sale at the counter of a branch: stock taken oldest expiry first,
 * priced at today's price, paid, and reported to Finance as a receipt
 * (finance.invoice.issued) and a payment (finance.payment.received). All
 * in one transaction: a sale either happened completely or not at all.
 *
 * Prescription-only medicines (controlled ones included) are only sold when
 * a verified prescription allows them ($prescribed).
 */
class Sell
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  list<array{medicine_id: string, quantity: int}>  $items
     * @param  list<string>  $prescribed  medicine ids a verified prescription allows
     *
     * @throws ValidationException
     * @throws InsufficientStock
     */
    public function handle(
        User $by,
        string $branchId,
        array $items,
        PaymentMethod $method,
        ?string $paymentReference = null,
        ?int $tenderedMinor = null,
        ?string $customerName = null,
        array $prescribed = [],
    ): Sale {
        return DB::transaction(function () use ($by, $branchId, $items, $method, $paymentReference, $tenderedMinor, $customerName, $prescribed) {
            // Price and check everything before touching stock.
            $priced = [];
            $total = 0;

            foreach ($items as $index => $item) {
                $medicine = Medicine::query()->find($item['medicine_id']);

                if ($medicine === null || ! $medicine->active) {
                    throw ValidationException::withMessages(["items.{$index}.medicine_id" => 'This medicine cannot be sold.']);
                }
                if ($medicine->prescription_only && ! in_array($medicine->id, $prescribed, true)) {
                    throw ValidationException::withMessages(["items.{$index}.medicine_id" => "{$medicine->label()} needs a verified prescription."]);
                }

                $priced[] = [$medicine, $item['quantity']];
                $total += $item['quantity'] * $medicine->sell_price_minor;
            }

            $change = null;
            if ($method === PaymentMethod::Cash) {
                if ($tenderedMinor === null || $tenderedMinor < $total) {
                    throw ValidationException::withMessages(['amount_tendered' => 'The cash given is less than the total.']);
                }
                $change = $tenderedMinor - $total;
            }

            $sale = Sale::query()->create([
                'branch_id' => $branchId,
                'number' => $this->numbers->next('sale', 'S', 6),
                'status' => Sale::COMPLETED,
                'customer_name' => $customerName,
                'currency' => Money::currency(),
                'total_minor' => $total,
                'payment_method' => $method,
                'payment_reference' => $paymentReference,
                'amount_tendered_minor' => $method === PaymentMethod::Cash ? $tenderedMinor : null,
                'change_minor' => $change,
                'sold_by' => $by->id,
                'sold_at' => now(),
            ]);

            $invoiceLines = [];

            foreach ($priced as [$medicine, $quantity]) {
                // What the stock sold cost, batch by batch: Finance's cost of goods sold.
                $cost = 0;
                foreach ($this->stock->take($medicine, $branchId, $quantity, $by, 'sale', $sale->id) as $part) {
                    $cost += $part['quantity'] * $part['batch']->unit_cost_minor;
                    $sale->lines()->create([
                        'medicine_id' => $medicine->id,
                        'batch_id' => $part['batch']->id,
                        'quantity' => $part['quantity'],
                        'unit_price_minor' => $medicine->sell_price_minor,
                        'line_total_minor' => $part['quantity'] * $medicine->sell_price_minor,
                    ]);
                }

                $invoiceLines[] = [
                    'description' => $medicine->label(),
                    'quantity' => $quantity,
                    'amount_minor' => $quantity * $medicine->sell_price_minor,
                    'revenue_account' => null,
                    'cost_minor' => $cost,
                ];
            }

            $this->outbox->record('finance', 'finance.invoice.issued', 1, "sale:{$sale->id}:invoice", [
                'invoice_number' => $sale->number,
                'branch_id' => $branchId,
                'customer_reference' => $customerName,
                'currency' => $sale->currency,
                'total_minor' => $total,
                'tax_minor' => 0,
                'due_on' => null,
                'lines' => $invoiceLines,
            ], $sale->sold_at);

            if ($total > 0) {
                $this->outbox->record('finance', 'finance.payment.received', 1, "sale:{$sale->id}:payment", [
                    'payment_reference' => $sale->number,
                    'invoice_number' => $sale->number,
                    'branch_id' => $branchId,
                    'currency' => $sale->currency,
                    'amount_minor' => $total,
                    'method' => $method->value,
                    'processor_reference' => $paymentReference,
                ], $sale->sold_at);
            }

            return $sale;
        });
    }
}
