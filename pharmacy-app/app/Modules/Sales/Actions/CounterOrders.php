<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Sales\Enums\CounterWorkflow;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CounterOrder;
use App\Modules\Sales\Models\CounterOrderLine;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders at a counter that works in steps (CounterWorkflow): taken at one
 * counter, paid at the cashier and handed over (dispensed or collected) at
 * another, in the order the branch's workflow says.
 *
 * Paying makes the order's sale (Sell): the till, Finance, the receipt, voids
 * and returns all work as for any sale. When the goods are dispensed before
 * payment, their stock is taken at dispensing and the sale sells exactly
 * that stock, so nothing is taken twice. An unpaid order can be cancelled
 * (dispensed stock goes back); a paid one is undone by voiding its sale.
 *
 * A counter that sells directly can also hand the goods over unpaid
 * ($payLater): the order is dispensed at once, its stock taken, and waits
 * in the queue to be paid at the cashier.
 *
 * Every step locks the order, so a double click or two counters acting at
 * once do one thing: a step already done is not done again.
 */
class CounterOrders
{
    public function __construct(
        private readonly Sell $sell,
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Industry $industry,
        private readonly CustomerRequirement $customers,
    ) {}

    /**
     * @param  list<array{item_id: string, quantity: int, discount_percent?: int, pack_id?: string|null}>  $items  quantity in packs when a pack is named
     *
     * @throws ValidationException
     */
    public function create(
        User $by,
        string $branchId,
        array $items,
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?string $customerId = null,
        int $maxDiscountPercent = 0,
        ?string $submissionId = null,
        bool $payLater = false,
    ): CounterOrder {
        if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
            return $earlier;
        }

        $settings = BranchCounterSettings::forBranch($branchId, $this->industry->code());
        if ($payLater && $settings->workflow->takesOrders()) {
            throw ValidationException::withMessages(['items' => 'This branch takes an order first: it is paid from the order queue.']);
        }
        if ($payLater && ! $settings->sellsUnpaid()) {
            throw ValidationException::withMessages(['items' => 'This branch takes payment at the counter.']);
        }
        if (! $payLater && ! $settings->workflow->takesOrders()) {
            throw ValidationException::withMessages(['items' => 'This branch sells directly at the counter; it does not take orders.']);
        }
        // Handed over now, paid later.
        $workflow = $payLater ? CounterWorkflow::OrderDispensePay : $settings->workflow;

        // Prescription-only medicines are dispensed from a verified
        // prescription, never from a counter order.
        ['lines' => $priced, 'total' => $total] = $this->sell->price($items, [], $maxDiscountPercent, $branchId);

        $customer = $customerId === null ? null : $this->customer($customerId);
        $customerName = $this->clean($customerName);
        $this->customers->ensure($settings, $customer !== null || $customerName !== null, false, $total);

        foreach ($priced as [$item, $units, , , , , $index]) {
            if (($available = $this->stock->sellable($item, $branchId)) < $units) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => (new InsufficientStock($item, $units, $available))->getMessage()]);
            }
        }

        try {
            return DB::transaction(function () use ($by, $branchId, $items, $priced, $total, $workflow, $payLater, $customer, $customerName, $customerPhone, $submissionId) {
                $order = CounterOrder::query()->create([
                    'application' => $this->industry->code(),
                    'branch_id' => $branchId,
                    'number' => $this->numbers->next('counter_order', 'O', 6),
                    'workflow' => $workflow,
                    'status' => CounterOrder::OPEN,
                    'customer_name' => $customer->name ?? $customerName,
                    'customer_phone' => $this->clean($customerPhone) ?? $customer?->phone,
                    'customer_id' => $customer?->id,
                    'currency' => Money::currency(),
                    'total_minor' => $total,
                    'submission_id' => $submissionId,
                    'ordered_by' => $by->id,
                    'ordered_at' => now(),
                ]);

                foreach ($priced as [$item, , $gross, $discount, $pack, $quantity, $index]) {
                    $order->lines()->create([
                        'item_id' => $item->id,
                        'item_pack_id' => $pack?->id,
                        'quantity' => $quantity,
                        'discount_percent' => (int) ($items[$index]['discount_percent'] ?? 0),
                        'line_total_minor' => $gross - $discount,
                    ]);
                }

                return $payLater ? $this->handOver($order, $by) : $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
                return $earlier;
            }

            throw $e;
        }
    }

    /**
     * Takes the payment: the order's sale, in the cashier's till. Paying an
     * order already paid gives back its sale. An order taken without a
     * customer can be given one now (the branch may need it for the sale).
     *
     * @throws ValidationException
     * @throws InsufficientStock
     */
    public function pay(
        CounterOrder $order,
        User $by,
        PaymentMethod $method,
        ?string $paymentReference = null,
        ?int $tenderedMinor = null,
        ?string $customerName = null,
        ?string $customerPhone = null,
    ): Sale {
        return DB::transaction(function () use ($order, $by, $method, $paymentReference, $tenderedMinor, $customerName, $customerPhone): Sale {
            $locked = $this->lock($order);

            if ($locked->isPaid() && $locked->sale !== null) {
                return $locked->sale;
            }
            if ($locked->customer_id === null && $locked->customer_name === null && ($customerName = $this->clean($customerName)) !== null) {
                $locked->forceFill(['customer_name' => $customerName, 'customer_phone' => $this->clean($customerPhone) ?? $locked->customer_phone]);
            }
            if (! $locked->awaitsPayment()) {
                throw ValidationException::withMessages(['payment_method' => $this->notNow($locked, 'paid')]);
            }

            $lines = $locked->lines()->get()->values();
            $taken = [];
            foreach ($lines as $index => $line) {
                if ($line->taken !== null) {
                    $batches = Batch::query()->findMany(array_column($line->taken, 'batch_id'))->keyBy('id');
                    $taken[$index] = array_map(fn (array $part) => ['batch' => $batches[$part['batch_id']], 'quantity' => $part['quantity']], $line->taken);
                }
            }

            $sale = $this->sell->handle(
                by: $by,
                branchId: $locked->branch_id,
                items: array_values($lines->map(fn (CounterOrderLine $line) => [
                    'item_id' => $line->item_id,
                    'quantity' => $line->quantity,
                    'discount_percent' => $line->discount_percent,
                    'pack_id' => $line->item_pack_id,
                ])->all()),
                method: $method,
                paymentReference: $paymentReference,
                tenderedMinor: $tenderedMinor,
                customerName: $locked->customer_name,
                // Discounts were given, within the orderer's limit, when the order was taken.
                maxDiscountPercent: 100,
                // One sale per order, however often payment is submitted.
                submissionId: $locked->id,
                customerId: $locked->customer_id,
                customerPhone: $locked->customer_phone,
                taken: $taken,
            );

            $locked->forceFill([
                'sale_id' => $sale->id,
                'paid_at' => $sale->sold_at,
                'total_minor' => $sale->total_minor,
                'status' => $locked->isHandedOver() ? CounterOrder::COMPLETED : CounterOrder::PAID,
            ])->save();

            return $sale;
        });
    }

    /**
     * Dispenses or hands over the order. Dispensed before payment, its stock
     * is taken now (soonest expiry first) and held against the order.
     *
     * @throws ValidationException
     * @throws InsufficientStock
     */
    public function handOver(CounterOrder $order, User $by): CounterOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = $this->lock($order);

            if ($locked->isHandedOver()) {
                return $locked;
            }
            if (! $locked->awaitsHandOver()) {
                throw ValidationException::withMessages(['order' => $this->notNow($locked, $locked->workflow->handOver() === 'collection' ? 'collected' : 'dispensed')]);
            }

            if (! $locked->isPaid()) {
                foreach ($locked->lines()->with(['item', 'pack'])->get() as $line) {
                    /** @var CounterOrderLine $line */
                    $units = $line->quantity * ($line->pack->units ?? 1);
                    $parts = $this->stock->take($line->item, $locked->branch_id, $units, $by, 'counter_order', $locked->id);
                    $line->forceFill(['taken' => array_map(fn (array $part) => ['batch_id' => $part['batch']->id, 'quantity' => $part['quantity']], $parts)])->save();
                }
            }

            $locked->forceFill([
                'handed_over_by' => $by->id,
                'handed_over_at' => now(),
                'status' => $locked->isPaid() ? CounterOrder::COMPLETED : CounterOrder::HANDED_OVER,
            ])->save();

            return $locked;
        });
    }

    /**
     * Cancels an order not yet paid; stock dispensed for it goes back.
     *
     * @throws ValidationException
     */
    public function cancel(CounterOrder $order, User $by, string $reason): CounterOrder
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $locked = $this->lock($order);

            if ($locked->status === CounterOrder::CANCELLED) {
                return $locked;
            }
            if ($locked->isPaid()) {
                throw ValidationException::withMessages(['reason' => "Order {$locked->number} is paid: void sale {$locked->sale?->number} instead."]);
            }

            foreach ($locked->lines()->get() as $line) {
                foreach ($line->taken ?? [] as $part) {
                    $this->stock->putBack(Batch::query()->findOrFail($part['batch_id']), $part['quantity'], $by, 'counter_order', $locked->id);
                }
                $line->forceFill(['taken' => null])->save();
            }

            $this->markCancelled($locked, $by->id, $reason);

            return $locked;
        });
    }

    /**
     * The order's sale was voided (its stock is already back): the order is
     * cancelled with it. Runs in the void's transaction.
     */
    public function saleVoided(Sale $sale): void
    {
        $order = CounterOrder::query()->where('sale_id', $sale->id)->lockForUpdate()->first();

        if ($order !== null && $order->status !== CounterOrder::CANCELLED) {
            // The stock went back with the sale's lines; none is held for the order.
            $order->lines()->update(['taken' => null]);
            $this->markCancelled($order, (string) $sale->voided_by, "Sale {$sale->number} voided: {$sale->void_reason}");
        }
    }

    private function markCancelled(CounterOrder $order, string $by, string $reason): void
    {
        $order->forceFill([
            'status' => CounterOrder::CANCELLED,
            'cancelled_by' => $by,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();
    }

    private function lock(CounterOrder $order): CounterOrder
    {
        return CounterOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function notNow(CounterOrder $order, string $step): string
    {
        return match (true) {
            $order->status === CounterOrder::CANCELLED => "Order {$order->number} was cancelled.",
            $order->status === CounterOrder::COMPLETED => "Order {$order->number} is already completed.",
            $step === 'paid' => "Order {$order->number} is paid after it is dispensed.",
            default => "Order {$order->number} is {$step} after it is paid.",
        };
    }

    /**
     * A registered customer of this industry, still active.
     */
    private function customer(string $customerId): Customer
    {
        $customer = Customer::query()->where('application', $this->industry->code())->whereKey($customerId)->first();

        if ($customer === null || ! $customer->active) {
            throw ValidationException::withMessages(['customer_id' => 'Choose an active customer.']);
        }

        return $customer;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function submitted(string $submissionId): ?CounterOrder
    {
        return CounterOrder::query()->where('submission_id', $submissionId)->first();
    }
}
