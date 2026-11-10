<?php

namespace App\Petrol;

use App\Models\Attendant;
use App\Models\AttendantStation;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\FuelSale;
use App\Models\MeterReading;
use App\Models\Nozzle;
use App\Models\PaymentMethod;
use App\Models\Pump;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\Tank;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Fuel sales on the open shift. Payments must add up to the price of the
 * litres. The same idempotency key never posts twice.
 */
class Sales
{
    public function __construct(
        private readonly TankLedger $tanks,
        private readonly Credit $credit,
        private readonly Cashbook $cash,
        private readonly Outbox $outbox,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param  array{nozzle_id: string, litres: ?string, amount: ?string, attendant_id: string, collected_by: string, idempotency_key: ?string, payments: list<mixed>}  $input
     */
    public function record(Station $station, User $by, array $input, bool $sellForOthers): FuelSale
    {
        $key = $input['idempotency_key'];
        $existing = $key === null ? null : FuelSale::query()->where('idempotency_key', $key)->first();

        try {
            return DB::transaction(function () use ($station, $by, $input, $sellForOthers, $key, $existing) {
                if ($existing !== null) {
                    return $this->assertSame($existing, $input);
                }

                $nozzle = Nozzle::query()->with(['pump', 'product', 'currentConnection.tank'])->whereKey($input['nozzle_id'])->first();
                if ($nozzle === null || $nozzle->branch_id !== $station->branch_id || $nozzle->status !== Nozzle::ACTIVE || $nozzle->pump->status !== Pump::ACTIVE) {
                    throw ValidationException::withMessages(['nozzle_id' => 'Choose an active nozzle at this station.']);
                }

                $connection = $nozzle->currentConnection;
                $tank = $connection?->tank;
                if ($tank === null || $tank->status !== Tank::ACTIVE || $tank->fuel_product_id !== $nozzle->fuel_product_id || $tank->branch_id !== $station->branch_id) {
                    throw ValidationException::withMessages(['nozzle_id' => 'This nozzle is not connected to a tank of the same fuel.']);
                }
                if ($tank->water_alert) {
                    throw ValidationException::withMessages(['nozzle_id' => 'This tank is flagged for water. A supervisor has to clear it before dispensing.']);
                }
                if (! $station->dispense_during_receiving && FuelReceipt::query()->where('tank_id', $tank->id)->where('status', FuelReceipt::PENDING)->whereNotNull('started_at')->whereNull('completed_at')->exists()) {
                    throw ValidationException::withMessages(['nozzle_id' => 'This station does not dispense while a tanker is unloading into this tank.']);
                }

                $shift = Shift::query()->where('branch_id', $station->branch_id)->where('status', Shift::OPEN)->lockForUpdate()->first();
                if ($shift === null) {
                    throw ValidationException::withMessages(['nozzle_id' => 'Open a shift before selling.']);
                }

                $attendant = Attendant::query()->whereKey($input['attendant_id'])->where('active', true)->first();
                $rostered = $attendant !== null && AttendantStation::query()
                    ->where('attendant_id', $attendant->id)
                    ->where('branch_id', $station->branch_id)
                    ->where('active', true)
                    ->exists();
                if ($attendant === null || ! $rostered) {
                    throw ValidationException::withMessages(['attendant_id' => 'Choose an attendant of this station.']);
                }

                $own = Attendant::query()->where('user_id', $by->id)->where('active', true)->first();
                if ($own !== null && ! $sellForOthers && $own->id !== $attendant->id) {
                    throw ValidationException::withMessages(['attendant_id' => 'You can sell only as yourself.']);
                }

                $assignment = ShiftAssignment::query()
                    ->where('shift_id', $shift->id)
                    ->where('nozzle_id', $nozzle->id)
                    ->where('attendant_id', $attendant->id)
                    ->whereNull('ended_at')
                    ->where('status', ShiftAssignment::ACTIVE)
                    ->first();
                if ($assignment === null) {
                    throw ValidationException::withMessages(['attendant_id' => 'That attendant is not assigned to this nozzle on the open shift.']);
                }

                if (! MeterReading::query()->where('shift_id', $shift->id)->where('nozzle_id', $nozzle->id)->where('kind', MeterReading::OPENING)->exists()) {
                    throw ValidationException::withMessages(['nozzle_id' => 'This nozzle has no opening meter reading for the shift.']);
                }

                if ($station->collection_mode === Station::ATTENDANT && $input['collected_by'] !== 'attendant') {
                    throw ValidationException::withMessages(['collected_by' => 'This station collects through attendants.']);
                }
                if ($station->collection_mode === Station::CASHIER && $input['collected_by'] !== 'cashier') {
                    throw ValidationException::withMessages(['collected_by' => 'This station collects through the cashier.']);
                }

                $price = FuelPrice::current($station->branch_id, $nozzle->fuel_product_id);
                if ($price === null || $price->selling_price_minor < 1) {
                    throw ValidationException::withMessages(['nozzle_id' => 'Set a selling price for '.$nozzle->product->name.' first.']);
                }

                $currency = $price->currency ?: Money::currency();
                $milli = $this->milli($input['litres'], $input['amount'], $price->selling_price_minor, $currency);
                $total = Volume::amount($milli, $price->selling_price_minor);
                $home = Money::currency();
                $reporting = $total;
                $fxBase = null;
                $fxQuote = null;
                $fxRate = null;
                if ($currency !== $home) {
                    app(Currencies::class)->assertAccepted($station->branch_id, $currency);
                    $rate = ExchangeRate::open($station->branch_id, $currency, $home);
                    if ($rate === null) {
                        throw ValidationException::withMessages(['nozzle_id' => 'Set a '.$currency.' rate for this station before selling at a '.$currency.' price.']);
                    }
                    $reporting = Fx::convert($total, $currency, $home, $rate);
                    $fxBase = $rate->base_currency;
                    $fxQuote = $rate->quote_currency;
                    $fxRate = (int) $rate->rate_minor;
                }
                $parsed = $this->payments($station->branch_id, $input['payments'], $total, $currency);

                $sale = FuelSale::query()->create([
                    'branch_id' => $station->branch_id,
                    'shift_id' => $shift->id,
                    'nozzle_id' => $nozzle->id,
                    'attendant_id' => $attendant->id,
                    'assignment_id' => $assignment->id,
                    'fuel_product_id' => $nozzle->fuel_product_id,
                    'tank_id' => $tank->id,
                    'number' => $this->numbers->next('sale', 'RCP'),
                    'quantity_milli' => $milli,
                    'unit_price_minor' => $price->selling_price_minor,
                    'total_minor' => $total,
                    'currency' => $currency,
                    'fx_base' => $fxBase,
                    'fx_quote' => $fxQuote,
                    'fx_rate_minor' => $fxRate,
                    'reporting_total_minor' => $reporting,
                    'cost_minor' => 0,
                    'collected_by' => $input['collected_by'],
                    'status' => FuelSale::POSTED,
                    'idempotency_key' => $key,
                    'customer_id' => $parsed['customer']?->id,
                    'sold_by' => $by->id,
                    'sold_at' => now(),
                ]);

                $cost = $this->tanks->issue($tank, $milli, $by, $sale);
                $sale->forceFill(['cost_minor' => $cost])->save();

                foreach ($parsed['rows'] as $row) {
                    SalePayment::query()->create([
                        'fuel_sale_id' => $sale->id,
                        'kind' => $row['method']->kind,
                        'amount_minor' => $row['amount'],
                        'currency' => $currency,
                        'reference' => $row['reference'],
                        'customer_id' => $row['customer']?->id,
                    ]);

                    if ($row['method']->kind === PaymentMethod::CASH && $input['collected_by'] === 'cashier') {
                        $this->cash->move($station->branch_id, $shift->id, CashMovement::SALE, $row['amount'], $by, 'sale:'.$sale->id.':cash', null, ['fuel_sale_id' => $sale->id], $currency === $home ? null : $currency);
                    }

                    if ($row['method']->isCredit() && $row['customer'] !== null) {
                        $this->credit->charge($row['customer'], $station->branch_id, $sale, $row['amount'], $by);
                    }
                }

                $this->tellFinance($sale, $nozzle->product, $parsed['rows']);
                $this->audit->record($by, 'sale.posted', $sale, null, [
                    'number' => $sale->number,
                    'quantity_milli' => $milli,
                    'total_minor' => $total,
                ], $station->branch_id);

                return $sale;
            });
        } catch (UniqueConstraintViolationException $e) {
            $replay = $key === null ? null : FuelSale::query()->where('idempotency_key', $key)->first();

            return $replay ?? throw $e;
        }
    }

    public function void(FuelSale $sale, User $by, string $reason): FuelSale
    {
        return DB::transaction(function () use ($sale, $by, $reason) {
            $locked = FuelSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $shift = Shift::query()->whereKey($locked->shift_id)->firstOrFail();

            if (! $locked->isPosted()) {
                throw ValidationException::withMessages(['reason' => 'This sale is already voided.']);
            }

            if ($shift->isApproved()) {
                throw ValidationException::withMessages(['reason' => 'An approved shift cannot be changed.']);
            }

            $payments = $locked->payments()->get();
            $nonCredit = $payments->filter(fn (SalePayment $payment) => $payment->kind !== PaymentMethod::CREDIT);
            if ($nonCredit->count() > 1) {
                throw ValidationException::withMessages(['reason' => 'A sale paid by more than one non-credit method cannot be voided here. Finance can refund only one method.']);
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Give a reason.']);
            }

            $tank = Tank::query()->whereKey($locked->tank_id)->firstOrFail();
            $this->tanks->restore($tank, $locked->quantity_milli, $locked->cost_minor, $by, $locked);
            $this->credit->reverseSale($locked, $by);

            foreach ($payments as $payment) {
                if ($payment->kind === PaymentMethod::CASH && $locked->collected_by === 'cashier') {
                    $home = Money::currency();
                    $cashCurrency = ($locked->currency === null || $locked->currency === $home) ? null : $locked->currency;
                    $this->cash->move($locked->branch_id, $locked->shift_id, CashMovement::SALE_VOID, -$payment->amount_minor, $by, 'void:'.$locked->id.':cash', $reason, ['fuel_sale_id' => $locked->id], $cashCurrency);
                }
            }

            $locked->forceFill([
                'status' => FuelSale::VOIDED,
                'voided_by' => $by->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $refund = null;
            $only = $nonCredit->first();
            if ($only instanceof SalePayment && $only->amount_minor > 0) {
                $method = PaymentMethod::query()->where('branch_id', $locked->branch_id)->where('kind', $only->kind)->first();
                $finance = $method?->financeMethod() ?? 'other';
                $refund = [
                    'method' => $finance,
                    'amount_minor' => $this->reported($locked, $only->amount_minor),
                    'payment_reference' => $locked->number,
                ];
            }

            $this->outbox->record('finance', 'finance.invoice.voided', 1, 'sale:'.$locked->id.':void', [
                'invoice_number' => $locked->number,
                'branch_id' => $locked->branch_id,
                'currency' => Money::currency(),
                'total_minor' => $locked->reporting_total_minor ?? $locked->total_minor,
                'reason' => $reason,
                'refund' => $refund,
            ]);

            $this->audit->record($by, 'sale.voided', $locked, ['status' => FuelSale::POSTED], [
                'status' => FuelSale::VOIDED,
                'reason' => $reason,
            ], $locked->branch_id);

            return $locked;
        });
    }

    /**
     * @param  array{nozzle_id: string, attendant_id: string}  $input
     */
    private function assertSame(FuelSale $sale, array $input): FuelSale
    {
        if ($sale->nozzle_id !== $input['nozzle_id'] || $sale->attendant_id !== $input['attendant_id']) {
            throw ValidationException::withMessages(['idempotency_key' => 'This reference was already used for a different sale.']);
        }

        return $sale;
    }

    private function milli(?string $litres, ?string $amount, int $price, string $currency): int
    {
        $hasLitres = $litres !== null && $litres !== '';
        $hasAmount = $amount !== null && $amount !== '';

        if ($hasLitres === $hasAmount) {
            throw ValidationException::withMessages(['litres' => 'Enter the litres or the amount, not both.']);
        }

        try {
            $milli = $hasLitres
                ? Volume::toMilli((string) $litres)
                : Volume::milliForAmount(Money::toMinor((string) $amount, $currency), $price);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['litres' => $e->getMessage()]);
        }

        if ($milli <= 0) {
            throw ValidationException::withMessages(['litres' => 'The quantity rounds to nothing at this price.']);
        }

        return $milli;
    }

    /**
     * @param  list<mixed>  $payments  Untrusted when they come from a device.
     * @return array{customer: ?Customer, rows: list<array{method: PaymentMethod, amount: int, reference: ?string, customer: ?Customer}>}
     */
    private function payments(string $branchId, array $payments, int $total, string $currency): array
    {
        if ($payments === []) {
            throw ValidationException::withMessages(['payments' => 'Add a payment.']);
        }

        $sum = 0;
        $rows = [];
        $creditCustomer = null;

        $seen = [];
        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                throw ValidationException::withMessages(['payments' => 'Each payment needs a method and an amount.']);
            }
            $methodId = $payment['method_id'] ?? null;
            $typed = $payment['amount'] ?? null;
            if (! is_string($methodId) || ! (is_string($typed) || is_int($typed))) {
                throw ValidationException::withMessages(['payments' => 'Each payment needs a method and an amount.']);
            }
            if (isset($seen[$methodId])) {
                throw ValidationException::withMessages(['payments' => 'Use each payment method once.']);
            }
            $seen[$methodId] = true;
            $customerId = $payment['customer_id'] ?? null;
            $reference = $payment['reference'] ?? null;

            $method = PaymentMethod::query()
                ->whereKey($methodId)
                ->where('branch_id', $branchId)
                ->where('active', true)
                ->first();
            if ($method === null) {
                throw ValidationException::withMessages(['payments' => 'Choose a payment method of this station.']);
            }

            try {
                $minor = Money::toMinor((string) $typed, $currency);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['payments' => $e->getMessage()]);
            }

            if ($minor < 1) {
                throw ValidationException::withMessages(['payments' => 'Each payment must be greater than zero.']);
            }

            $customer = null;
            if ($method->isCredit()) {
                $customer = Customer::query()->whereKey(is_string($customerId) ? $customerId : '')->where('active', true)->first();
                if ($customer === null) {
                    throw ValidationException::withMessages(['payments' => 'A credit sale needs a customer.']);
                }
                if ($creditCustomer !== null && $creditCustomer->id !== $customer->id) {
                    throw ValidationException::withMessages(['payments' => 'A sale can be on one customer\'s account.']);
                }
                $creditCustomer = $customer;
            }

            $sum += $minor;
            $rows[] = [
                'method' => $method,
                'amount' => $minor,
                'reference' => is_scalar($reference) ? (trim((string) $reference) ?: null) : null,
                'customer' => $customer,
            ];
        }

        if ($sum !== $total) {
            throw ValidationException::withMessages(['payments' => 'Payments must add up to '.Money::format($total, $currency).'.']);
        }

        return ['customer' => $creditCustomer, 'rows' => $rows];
    }

    /**
     * @param  list<array{method: PaymentMethod, amount: int, reference: ?string, customer: ?Customer}>  $rows
     */
    private function tellFinance(FuelSale $sale, FuelProduct $product, array $rows): void
    {
        $customer = $sale->customer_id === null ? null : Customer::query()->find($sale->customer_id);

        $this->outbox->record('finance', 'finance.invoice.issued', 1, 'sale:'.$sale->id.':invoice', [
            'invoice_number' => $sale->number,
            'branch_id' => $sale->branch_id,
            'customer_reference' => $customer?->name,
            'currency' => Money::currency(),
            'total_minor' => $sale->reporting_total_minor ?? $sale->total_minor,
            'tax_minor' => 0,
            'due_on' => null,
            'lines' => [[
                'description' => $product->name.' '.Volume::toLitres($sale->quantity_milli).' L',
                'quantity' => (float) Volume::toLitres($sale->quantity_milli),
                'amount_minor' => $sale->reporting_total_minor ?? $sale->total_minor,
                'revenue_account' => null,
                'cost_minor' => $sale->cost_minor,
            ]],
        ], $sale->sold_at);

        $kinds = [];
        foreach ($rows as $row) {
            $finance = $row['method']->financeMethod();
            if ($finance === null || $row['amount'] < 1) {
                continue;
            }

            $paid = $this->reported($sale, $row['amount']);
            // Two methods of one kind (two mobile wallets) need their own keys.
            $kind = $row['method']->kind;
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            $suffix = $kinds[$kind] === 1 ? $kind : $kind.'-'.$kinds[$kind];
            $this->outbox->record('finance', 'finance.payment.received', 1, 'sale:'.$sale->id.':'.$suffix, [
                'payment_reference' => $sale->number.':'.$suffix,
                'invoice_number' => $sale->number,
                'branch_id' => $sale->branch_id,
                'currency' => Money::currency(),
                'amount_minor' => $paid,
                'method' => $finance,
                'processor_reference' => $row['reference'],
            ], $sale->sold_at);
        }
    }

    /**
     * An amount in the sale's currency, in the home currency Finance books.
     * Uses the rate the sale was posted at, so a void reverses what was sent.
     */
    private function reported(FuelSale $sale, int $amount): int
    {
        $reported = (int) ($sale->reporting_total_minor ?? $sale->total_minor);
        $total = (int) $sale->total_minor;
        if ($reported === $total || $total <= 0) {
            return $amount;
        }

        return intdiv($amount * $reported + intdiv($total, 2), $total);
    }
}
