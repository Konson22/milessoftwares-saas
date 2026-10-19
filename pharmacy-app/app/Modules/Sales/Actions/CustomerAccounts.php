<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerPayment;
use App\Modules\Sales\Models\Sale;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * What account customers owe, and the payments that settle it.
 *
 * A sale on account is owed its total, less what came back (sale returns)
 * and what was paid against it (allocations). A customer owes the sum over
 * their completed sales on account: the same amounts Finance holds as their
 * receivable (finance.invoice.issued, finance.sales_return.issued and
 * finance.payment.received per sale).
 *
 * A payment settles the oldest sales first (earliest due, then earliest
 * sold), never more than is owed. Each part is reported to Finance as a
 * payment of that sale's invoice. Cash goes into the open till of whoever
 * takes it. Customer rows are locked while their account changes, so a
 * sale on account and a payment never work from a stale balance.
 */
class CustomerAccounts
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
        private readonly Tills $tills,
        private readonly Industry $industry,
    ) {}

    /**
     * What the customer owes now.
     */
    public function balance(Customer $customer): int
    {
        return (int) $this->openSales($customer)->sum('owed_minor');
    }

    /**
     * What each of these customers owes, in a few queries (lists).
     *
     * @param  EloquentCollection<int, Customer>  $customers
     * @return array<string, int> by customer id; customers owing nothing are missing
     */
    public function balances(EloquentCollection $customers): array
    {
        if ($customers->isEmpty()) {
            return [];
        }
        $customerIds = $customers->modelKeys();

        $owing = fn () => Sale::query()
            ->whereIn('sales.customer_id', $customerIds)
            ->where('sales.payment_method', PaymentMethod::Account->value)
            ->where('sales.status', Sale::COMPLETED)
            ->groupBy('sales.customer_id');

        $sold = $owing()->selectRaw('sales.customer_id, SUM(sales.total_minor) AS amount')->pluck('amount', 'customer_id');
        $returned = $owing()->join('sale_returns', 'sale_returns.sale_id', '=', 'sales.id')
            ->selectRaw('sales.customer_id, SUM(sale_returns.total_minor) AS amount')->pluck('amount', 'customer_id');
        $paid = $owing()->join('customer_payment_allocations', 'customer_payment_allocations.sale_id', '=', 'sales.id')
            ->selectRaw('sales.customer_id, SUM(customer_payment_allocations.amount_minor) AS amount')->pluck('amount', 'customer_id');

        $balances = [];
        foreach ($sold as $customerId => $amount) {
            $owed = (int) $amount - (int) ($returned[$customerId] ?? 0) - (int) ($paid[$customerId] ?? 0);
            if ($owed !== 0) {
                $balances[(string) $customerId] = $owed;
            }
        }

        return $balances;
    }

    /**
     * The customer's sales on account still owed something, oldest due first,
     * each with `owed_minor` (total less returns and payments).
     *
     * @return Collection<int, Sale>
     */
    public function openSales(Customer $customer): Collection
    {
        return $this->accountSales($customer)
            ->filter(fn (Sale $sale) => (int) $sale->getAttribute('owed_minor') > 0)
            ->values();
    }

    /**
     * Every completed sale on account of the customer, oldest due first, with
     * `returned_minor`, `paid_minor` and `owed_minor`.
     *
     * @return Collection<int, Sale>
     */
    public function accountSales(Customer $customer): Collection
    {
        return Sale::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', PaymentMethod::Account->value)
            ->where('status', Sale::COMPLETED)
            ->withSum('returns as returned_minor', 'total_minor')
            ->withSum('allocations as paid_minor', 'amount_minor')
            ->orderBy('due_on')
            ->orderBy('sold_at')
            ->get()
            ->each(function (Sale $sale) {
                $returned = (int) $sale->getAttribute('returned_minor');
                $paid = (int) $sale->getAttribute('paid_minor');
                $sale->setAttribute('returned_minor', $returned);
                $sale->setAttribute('paid_minor', $paid);
                $sale->setAttribute('owed_minor', $sale->total_minor - $returned - $paid);
            })
            ->toBase();
    }

    /**
     * What is still owed on one sale on account.
     */
    public function owedOn(Sale $sale): int
    {
        return $sale->total_minor
            - (int) $sale->returns()->sum('total_minor')
            - (int) $sale->allocations()->sum('amount_minor');
    }

    /**
     * Locks the customer for a change to their account, and checks it is
     * this industry's, still active.
     *
     * @throws ValidationException
     */
    public function lock(string $customerId, string $field = 'customer_id'): Customer
    {
        $customer = Customer::query()
            ->where('application', $this->industry->code())
            ->whereKey($customerId)
            ->lockForUpdate()
            ->first();

        if ($customer === null || ! $customer->active) {
            throw ValidationException::withMessages([$field => 'Choose an active customer.']);
        }

        return $customer;
    }

    /**
     * A sale of $totalMinor on account would stay within the credit limit.
     *
     * @throws ValidationException
     */
    public function ensureCredit(Customer $customer, int $totalMinor): void
    {
        $owed = $this->balance($customer);

        if ($owed + $totalMinor > $customer->credit_limit_minor) {
            throw ValidationException::withMessages(['customer_id' => $customer->credit_limit_minor === 0
                ? "{$customer->name} may not buy on account."
                : "Over {$customer->name}'s credit limit of ".Money::format($customer->credit_limit_minor).': they owe '.Money::format($owed).' and this sale is '.Money::format($totalMinor).'.']);
        }
    }

    /**
     * @throws ValidationException
     */
    public function receive(
        string $customerId,
        int $amountMinor,
        PaymentMethod $method,
        User $by,
        string $branchId,
        ?string $reference = null,
        ?string $note = null,
        ?string $submissionId = null,
    ): CustomerPayment {
        if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
            return $earlier;
        }

        try {
            return $this->take($customerId, $amountMinor, $method, $by, $branchId, $reference, $note, $submissionId);
        } catch (UniqueConstraintViolationException $e) {
            // The same submission, committed by a request running alongside.
            if ($submissionId !== null && ($earlier = $this->submitted($submissionId)) !== null) {
                return $earlier;
            }

            throw $e;
        }
    }

    private function take(
        string $customerId,
        int $amountMinor,
        PaymentMethod $method,
        User $by,
        string $branchId,
        ?string $reference,
        ?string $note,
        ?string $submissionId,
    ): CustomerPayment {
        if (! $method->paidNow()) {
            throw ValidationException::withMessages(['method' => 'Choose how the customer paid.']);
        }
        if ($amountMinor < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter what the customer paid.']);
        }

        return DB::transaction(function () use ($customerId, $amountMinor, $method, $by, $branchId, $reference, $note, $submissionId) {
            $customer = $this->lock($customerId);
            $open = $this->openSales($customer);
            $owed = (int) $open->sum('owed_minor');

            if ($amountMinor > $owed) {
                throw ValidationException::withMessages(['amount' => $owed === 0
                    ? "{$customer->name} owes nothing."
                    : "{$customer->name} owes only ".Money::format($owed).'.']);
            }

            $till = $method === PaymentMethod::Cash
                ? $this->tills->requireOpen($by, $branchId, 'amount')
                : $this->tills->openFor($by, $branchId);

            $payment = CustomerPayment::query()->create([
                'application' => $this->industry->code(),
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'number' => $this->numbers->next('customer_payment', 'CP'),
                'method' => $method,
                'reference' => $reference,
                'amount_minor' => $amountMinor,
                'note' => $note,
                'till_session_id' => $till?->id,
                'received_by' => $by->id,
                'received_at' => now(),
                'submission_id' => $submissionId,
            ]);

            $left = $amountMinor;
            foreach ($open as $sale) {
                if ($left === 0) {
                    break;
                }

                $part = min($left, (int) $sale->getAttribute('owed_minor'));
                $left -= $part;
                $payment->allocations()->create(['sale_id' => $sale->id, 'amount_minor' => $part]);

                // Finance settles the sale's invoice by this much.
                $this->outbox->record('finance', 'finance.payment.received', 1, "customer_payment:{$payment->id}:{$sale->id}", [
                    'payment_reference' => "{$payment->number}/{$sale->number}",
                    'invoice_number' => $sale->number,
                    'branch_id' => $branchId,
                    'currency' => Money::currency(),
                    'amount_minor' => $part,
                    'method' => $method->value,
                    'processor_reference' => $reference,
                ], $payment->received_at);
            }

            return $payment;
        });
    }

    private function submitted(string $submissionId): ?CustomerPayment
    {
        return CustomerPayment::query()->where('submission_id', $submissionId)->first();
    }
}
