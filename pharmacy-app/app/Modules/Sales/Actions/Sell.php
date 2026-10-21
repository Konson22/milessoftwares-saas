<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\Sale;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * A sale at the counter of a branch: stock taken oldest expiry first,
 * by the unit or by the pack (at the pack's price), priced at today's price
 * less any discount, paid, and reported to Finance
 * as a receipt (finance.invoice.issued) and a payment
 * (finance.payment.received). All in one transaction: a sale either
 * happened completely or not at all.
 *
 * Restricted items (prescription-only medicines) are only sold when the
 * industry module has authorized them for this sale ($authorized). Only
 * items of the industry being served are sold, and the sale is recorded
 * as its own (App\Support\Industry).
 *
 * The money goes into the seller's open till at the branch; without one
 * nothing is sold.
 *
 * On account (PaymentMethod::Account) nothing is paid now: the sale names an
 * account customer, stays within their credit limit (checked with the
 * customer locked), is due after their payment terms, and Finance gets the
 * invoice with its due date but no payment (CustomerAccounts).
 *
 * The branch may require the customer to be named (CustomerRequirement).
 *
 * Stock already taken for a line ($taken, by line: a counter order
 * dispensed before it was paid) is sold as it is, not taken again.
 *
 * A sale submitted with a $submissionId that was already used is that
 * sale again, not a second one (a double click, a retried request).
 */
class Sell
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
        private readonly Tills $tills,
        private readonly ItemPacks $packs,
        private readonly Industry $industry,
        private readonly CustomerAccounts $accounts,
        private readonly CustomerRequirement $customers,
    ) {}

    /**
     * @param  list<array{item_id: string, quantity: int, discount_percent?: int, pack_id?: string|null}>  $items  quantity in packs when a pack is named
     * @param  list<string>  $authorized  restricted item ids allowed in this sale (a verified prescription's)
     * @param  int  $maxDiscountPercent  the most this seller may take off a line (0: no discounts)
     * @param  string|null  $customerId  an account customer of this industry (required on account)
     * @param  array<int, list<array{batch: Batch, quantity: int}>>  $taken  by line index: the batches its stock already came from
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
        array $authorized = [],
        int $maxDiscountPercent = 0,
        ?string $submissionId = null,
        ?string $customerId = null,
        ?string $customerPhone = null,
        array $taken = [],
    ): Sale {
        if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
            return $earlier;
        }

        try {
            return $this->sell($by, $branchId, $items, $method, $paymentReference, $tenderedMinor, $customerName, $authorized, $maxDiscountPercent, $submissionId, $customerId, $customerPhone, $taken);
        } catch (UniqueConstraintViolationException $e) {
            // The same submission, committed by a request running alongside.
            if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
                return $earlier;
            }

            throw $e;
        }
    }

    /**
     * @param  list<array{item_id: string, quantity: int, discount_percent?: int, pack_id?: string|null}>  $items
     * @param  list<string>  $authorized
     * @param  array<int, list<array{batch: Batch, quantity: int}>>  $taken
     */
    private function sell(
        User $by,
        string $branchId,
        array $items,
        PaymentMethod $method,
        ?string $paymentReference,
        ?int $tenderedMinor,
        ?string $customerName,
        array $authorized,
        int $maxDiscountPercent,
        ?string $submissionId,
        ?string $customerId,
        ?string $customerPhone,
        array $taken,
    ): Sale {
        return DB::transaction(function () use ($by, $branchId, $items, $method, $paymentReference, $tenderedMinor, $customerName, $authorized, $maxDiscountPercent, $submissionId, $customerId, $customerPhone, $taken) {
            $till = $this->tills->requireOpen($by, $branchId);

            // Locked until the sale commits: its balance cannot move meanwhile.
            $customer = $customerId === null ? null : $this->accounts->lock($customerId);
            if ($method === PaymentMethod::Account && $customer === null) {
                throw ValidationException::withMessages(['customer_id' => 'Choose the customer this sale goes on.']);
            }

            // Price and check everything before touching stock.
            ['lines' => $priced, 'total' => $total, 'discount' => $discountTotal] = $this->price($items, $authorized, $maxDiscountPercent);

            $this->customers->ensure(
                BranchCounterSettings::forBranch($branchId, $this->industry->code()),
                $customer !== null || trim((string) $customerName) !== '',
                array_any($priced, fn (array $line) => $line[0]->restricted),
                $total,
            );

            if ($method === PaymentMethod::Account) {
                $this->accounts->ensureCredit($customer, $total);
            }

            $change = null;
            if ($method === PaymentMethod::Cash) {
                // No amount given: the exact total was paid.
                $tenderedMinor ??= $total;
                if ($tenderedMinor < $total) {
                    throw ValidationException::withMessages(['amount_tendered' => 'The cash given is less than the total.']);
                }
                $change = $tenderedMinor - $total;
            }

            $sale = Sale::query()->create([
                'branch_id' => $branchId,
                'application' => $this->industry->code(),
                'number' => $this->numbers->next('sale', 'S', 6),
                'status' => Sale::COMPLETED,
                'customer_name' => $customer->name ?? $customerName,
                'customer_id' => $customer?->id,
                'customer_phone' => $customerPhone ?? $customer?->phone,
                'due_on' => $method === PaymentMethod::Account ? today()->addDays($customer->payment_terms_days) : null,
                'currency' => Money::currency(),
                'total_minor' => $total,
                'discount_minor' => $discountTotal,
                'payment_method' => $method,
                'payment_reference' => $paymentReference,
                'amount_tendered_minor' => $method === PaymentMethod::Cash ? $tenderedMinor : null,
                'change_minor' => $change,
                'sold_by' => $by->id,
                'sold_at' => now(),
                'submission_id' => $submissionId,
                'till_session_id' => $till?->id,
            ]);

            $invoiceLines = [];

            foreach ($priced as [$item, $quantity, $gross, $discount, $pack, $packs, $index]) {
                // What the stock sold cost, batch by batch: Finance's cost of
                // goods sold. The price and the discount are shared across the
                // batches in proportion, the last one taking what rounding
                // leaves (by the unit, exactly each batch's units at the price).
                $cost = 0;
                $parts = $taken[$index] ?? $this->stock->take($item, $branchId, $quantity, $by, 'sale', $sale->id);
                $grossLeft = $gross;
                $discountLeft = $discount;

                foreach ($parts as $i => $part) {
                    $last = $i === array_key_last($parts);
                    $partGross = $last ? $grossLeft : intdiv($gross * $part['quantity'], $quantity);
                    $partDiscount = $last ? $discountLeft : intdiv($discount * $part['quantity'], $quantity);
                    $grossLeft -= $partGross;
                    $discountLeft -= $partDiscount;

                    $cost += $part['quantity'] * $part['batch']->unit_cost_minor;
                    $sale->lines()->create([
                        'item_id' => $item->id,
                        'batch_id' => $part['batch']->id,
                        'quantity' => $part['quantity'],
                        'unit_price_minor' => $item->sell_price_minor,
                        'discount_minor' => $partDiscount,
                        'line_total_minor' => $partGross - $partDiscount,
                        'pack_name' => $pack?->name,
                        'units_per_pack' => $pack->units ?? 1,
                        'pack_price_minor' => $pack?->sellPrice($item),
                    ]);
                }

                $invoiceLines[] = [
                    'description' => $pack === null ? $item->name : "{$item->name} ({$packs} {$pack->label()})",
                    'quantity' => $quantity,
                    'amount_minor' => $gross - $discount,
                    'revenue_account' => null,
                    'cost_minor' => $cost,
                ];
            }

            $this->outbox->record('finance', 'finance.invoice.issued', 1, "sale:{$sale->id}:invoice", [
                'invoice_number' => $sale->number,
                'branch_id' => $branchId,
                'customer_reference' => $customer === null ? $customerName : "{$customer->name} ({$customer->number})",
                'currency' => $sale->currency,
                'total_minor' => $total,
                'tax_minor' => 0,
                'due_on' => $sale->due_on?->toDateString(),
                'lines' => $invoiceLines,
            ], $sale->sold_at);

            if ($total > 0 && $method->paidNow()) {
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

    /**
     * Prices and checks a basket without touching stock: only active items
     * of this industry, restricted ones only when authorized, discounts
     * within what the seller may give.
     *
     * @param  list<array{item_id: string, quantity: int, discount_percent?: int, pack_id?: string|null}>  $items
     * @param  list<string>  $authorized
     *                                    Each line is [item, units, gross, discount, pack, quantity (in packs when a pack is named), line index].
     * @return array{lines: list<array{0: Item, 1: int, 2: int, 3: int, 4: ItemPack|null, 5: int, 6: int}>, total: int, discount: int}
     *
     * @throws ValidationException
     */
    public function price(array $items, array $authorized, int $maxDiscountPercent): array
    {
        $priced = [];
        $total = 0;
        $discountTotal = 0;

        foreach ($items as $index => $line) {
            $item = Item::query()->find($line['item_id']);

            if ($item === null || ! $item->active || ! $this->industry->handles($item->kind)) {
                throw ValidationException::withMessages(["items.{$index}.item_id" => 'This item cannot be sold.']);
            }
            if ($item->restricted && ! in_array($item->id, $authorized, true)) {
                throw ValidationException::withMessages(["items.{$index}.item_id" => "{$item->name} needs a verified prescription."]);
            }

            $percent = $line['discount_percent'] ?? 0;
            if ($percent < 0 || $percent > 100) {
                throw ValidationException::withMessages(["items.{$index}.discount_percent" => 'A discount is between 0 and 100%.']);
            }
            if ($percent > $maxDiscountPercent) {
                throw ValidationException::withMessages(["items.{$index}.discount_percent" => $maxDiscountPercent === 0
                    ? 'You may not give discounts.'
                    : "You may give at most {$maxDiscountPercent}% off."]);
            }

            $pack = $this->packs->find($item->id, $line['pack_id'] ?? null, "items.{$index}.pack_id");
            $units = $pack === null ? $line['quantity'] : $line['quantity'] * $pack->units;
            $gross = $line['quantity'] * ($pack === null ? $item->sell_price_minor : $pack->sellPrice($item));
            $discount = intdiv($gross * $percent + 50, 100);

            $priced[] = [$item, $units, $gross, $discount, $pack, $line['quantity'], $index];
            $total += $gross - $discount;
            $discountTotal += $discount;
        }

        return ['lines' => $priced, 'total' => $total, 'discount' => $discountTotal];
    }

    private function submitted(string $submissionId): ?Sale
    {
        return Sale::query()->where('submission_id', $submissionId)->first();
    }
}
