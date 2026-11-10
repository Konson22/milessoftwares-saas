<?php

namespace App\Petrol;

use App\Models\CashMovement;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\FuelSale;
use App\Models\Shift;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * What customers owe. A credit sale increases the balance; a payment reduces it.
 * The balance is the sum of the ledger, never a stored total.
 */
class Credit
{
    public function __construct(
        private readonly Cashbook $cash,
        private readonly Outbox $outbox,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Rates $rates,
    ) {}

    public function balance(string $customerId): int
    {
        return (int) CreditEntry::query()->where('customer_id', $customerId)->sum(DB::raw('coalesce(reporting_amount_minor, amount_minor)'));
    }

    public function charge(Customer $customer, string $branchId, FuelSale $sale, int $amount, User $by): void
    {
        $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        $currency = $sale->currency ?: Money::currency();
        if ($sale->fx_rate_minor !== null && $sale->fx_base !== null && $sale->fx_quote !== null && $currency !== Money::currency()) {
            $copied = new ExchangeRate([
                'base_currency' => $sale->fx_base,
                'quote_currency' => $sale->fx_quote,
                'rate_minor' => $sale->fx_rate_minor,
            ]);
            $snapshot = [
                'currency' => $currency,
                'fx_base' => $sale->fx_base,
                'fx_quote' => $sale->fx_quote,
                'fx_rate_minor' => (int) $sale->fx_rate_minor,
                'reporting_minor' => Fx::convert($amount, $currency, Money::currency(), $copied),
            ];
        } else {
            $snapshot = $this->rates->snapshot($branchId, $currency, $amount);
        }
        $available = $locked->credit_limit_minor - $this->balance($locked->id);

        if ($snapshot['reporting_minor'] > $available) {
            throw ValidationException::withMessages([
                'payments' => 'This sale would pass the credit limit. '.Money::format(max(0, $available)).' is still available.',
            ]);
        }

        CreditEntry::query()->create([
            'customer_id' => $locked->id,
            'branch_id' => $branchId,
            'fuel_sale_id' => $sale->id,
            'kind' => CreditEntry::SALE,
            'amount_minor' => $amount,
            'currency' => $snapshot['currency'],
            'fx_base' => $snapshot['fx_base'],
            'fx_quote' => $snapshot['fx_quote'],
            'fx_rate_minor' => $snapshot['fx_rate_minor'],
            'reporting_amount_minor' => $snapshot['reporting_minor'],
            'idempotency_key' => 'sale:'.$sale->id.':credit',
            'recorded_by' => $by->id,
            'occurred_at' => $sale->sold_at,
        ]);
    }

    public function reverseSale(FuelSale $sale, User $by): void
    {
        $entries = CreditEntry::query()->where('fuel_sale_id', $sale->id)->where('kind', CreditEntry::SALE)->get();

        foreach ($entries as $entry) {
            CreditEntry::query()->create([
                'customer_id' => $entry->customer_id,
                'branch_id' => $entry->branch_id,
                'fuel_sale_id' => $sale->id,
                'kind' => CreditEntry::VOID,
                'amount_minor' => -$entry->amount_minor,
                'currency' => $entry->currency,
                'fx_base' => $entry->fx_base,
                'fx_quote' => $entry->fx_quote,
                'fx_rate_minor' => $entry->fx_rate_minor,
                'reporting_amount_minor' => $entry->reporting_amount_minor === null ? null : -$entry->reporting_amount_minor,
                'idempotency_key' => 'void:'.$sale->id.':'.$entry->id,
                'note' => 'Void '.$sale->number,
                'recorded_by' => $by->id,
                'occurred_at' => now(),
            ]);
        }
    }

    public function receive(Customer $customer, string $branchId, ?Shift $shift, int $amount, string $method, User $by, ?string $key, ?string $note, ?string $currency = null): CreditEntry
    {
        return DB::transaction(function () use ($customer, $branchId, $shift, $amount, $method, $by, $key, $note, $currency) {
            if ($key !== null) {
                $existing = CreditEntry::query()->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
            }

            $currency = strtoupper($currency ?? Money::currency());
            $snapshot = $this->rates->snapshot($branchId, $currency, $amount);
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($snapshot['reporting_minor'] > $this->balance($locked->id)) {
                throw ValidationException::withMessages(['amount' => 'That is more than the customer owes.']);
            }

            if ($method === 'cash' && ($shift === null || ! $shift->isOpen())) {
                throw ValidationException::withMessages(['method_id' => 'Open a shift before taking cash.']);
            }

            $number = $this->numbers->next('customer_payment', 'CP');
            $entry = CreditEntry::query()->create([
                'customer_id' => $locked->id,
                'branch_id' => $branchId,
                'kind' => CreditEntry::PAYMENT,
                'amount_minor' => -$amount,
                'currency' => $snapshot['currency'],
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_amount_minor' => -$snapshot['reporting_minor'],
                'idempotency_key' => $key,
                'note' => $note,
                'recorded_by' => $by->id,
                'occurred_at' => now(),
            ]);

            if ($method === 'cash') {
                $this->cash->move($branchId, $shift->id, CashMovement::CUSTOMER_PAYMENT, $amount, $by, $key === null ? null : $key.':cash', $note, ['credit_entry_id' => $entry->id], $currency === Money::currency() ? null : $currency);
            }

            $this->outbox->record('finance', 'finance.payment.received', 1, 'customer_payment:'.$entry->id, [
                'payment_reference' => $number,
                'invoice_number' => null,
                'branch_id' => $branchId,
                'currency' => Money::currency(),
                'amount_minor' => $snapshot['reporting_minor'],
                'method' => $method,
                'processor_reference' => $note,
            ]);

            $this->audit->record($by, 'credit.payment', $entry, null, ['amount_minor' => $amount, 'customer_id' => $locked->id], $branchId);

            return $entry;
        });
    }
}
