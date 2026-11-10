<?php

namespace App\Petrol;

use App\Models\Attendant;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Applies one device submission through the same services the screens use.
 * The submission id is the idempotency key. A repeated id is handled by the
 * controller before this runs. This does not merge two devices editing the
 * same shift: the domain refuses a second open shift, and that refusal is
 * not stored, so the device can correct it.
 *
 * A payload is untrusted: a field of the wrong shape is a 422 the device can
 * correct, never a server error it would retry forever.
 */
class OfflineInbox
{
    public function __construct(
        private readonly Sales $sales,
        private readonly Credit $credit,
        private readonly Purchasing $purchasing,
        private readonly Cashbook $cashbook,
        private readonly Shifts $shifts,
        private readonly Custody $custody,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(string $kind, string $id, array $payload, Station $station, User $member, string $branchId): array
    {
        return match ($kind) {
            'sale.record' => $this->sale($id, $payload, $station, $member),
            'cash.collect' => $this->collect($id, $payload, $branchId, $member),
            'expense.record' => $this->expense($id, $payload, $branchId, $member),
            'fuel.receive' => $this->receive($id, $payload, $branchId, $member),
            'shift.open' => $this->openShift($payload, $station, $member),
            'cash.remit' => $this->remit($id, $payload, $branchId, $member),
            default => throw ValidationException::withMessages(['kind' => 'This offline action is not accepted.']),
        };
    }

    public function permission(string $kind): ?string
    {
        return match ($kind) {
            'sale.record' => Permissions::SALES_CREATE,
            'cash.collect' => Permissions::CUSTOMERS_MANAGE,
            'expense.record' => Permissions::EXPENSES_MANAGE,
            'fuel.receive' => Permissions::PURCHASES_MANAGE,
            'shift.open' => Permissions::SHIFTS_OPEN,
            'cash.remit' => Permissions::CASH_SUBMIT,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sale(string $id, array $payload, Station $station, User $member): array
    {
        $payments = $payload['payments'] ?? [];
        $sale = $this->sales->record($station, $member, [
            'nozzle_id' => $this->text($payload, 'nozzle_id'),
            'litres' => $this->optional($payload, 'litres'),
            'amount' => $this->optional($payload, 'amount'),
            'attendant_id' => $this->text($payload, 'attendant_id'),
            'collected_by' => $this->text($payload, 'collected_by'),
            'idempotency_key' => $id,
            'payments' => is_array($payments) ? array_values($payments) : [],
        ], $this->canSellForOthers());

        return [
            'sale_id' => $sale->id,
            'number' => $sale->number,
            'total_minor' => $sale->total_minor,
            'currency' => $sale->currency,
            'reporting_total_minor' => $sale->reporting_total_minor,
            'status' => $sale->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function collect(string $id, array $payload, string $branchId, User $member): array
    {
        $customer = Customer::query()->whereKey($this->text($payload, 'customer_id'))->first();
        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'Choose a customer.']);
        }
        $method = PaymentMethod::query()->whereKey($this->text($payload, 'method_id'))->where('branch_id', $branchId)->where('active', true)->first();
        if ($method === null || $method->financeMethod() === null) {
            throw ValidationException::withMessages(['method_id' => 'Choose a payment method.']);
        }
        $currency = $this->currency($payload, $branchId);
        $shift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->first();
        $entry = $this->credit->receive(
            $customer,
            $branchId,
            $shift,
            Money::input($this->text($payload, 'amount'), $currency),
            (string) $method->financeMethod(),
            $member,
            $id,
            $this->optional($payload, 'reference'),
            $currency,
        );

        return ['entry_id' => $entry->id, 'amount_minor' => abs($entry->amount_minor), 'currency' => $entry->currency];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function expense(string $id, array $payload, string $branchId, User $member): array
    {
        $category = ExpenseCategory::query()->whereKey($this->text($payload, 'category_id'))->where('active', true)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Choose an approved category.']);
        }
        $currency = $this->currency($payload, $branchId) ?? Money::currency();
        $paidFrom = $this->optional($payload, 'paid_from') ?? Expense::BANK;
        if (! in_array($paidFrom, [Expense::CASH, Expense::BANK, Expense::PETTY], true)) {
            throw ValidationException::withMessages(['paid_from' => 'Choose where this was paid from.']);
        }
        $minor = Money::input($this->text($payload, 'amount'), $currency);
        $snapshot = app(Rates::class)->snapshot($branchId, $currency, $minor);
        $shift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->first();
        $expense = $this->cashbook->expense(
            $branchId,
            $shift,
            $category->name,
            trim($this->text($payload, 'description')),
            $minor,
            $paidFrom,
            $member,
            $id,
            [
                'category_id' => $category->id,
                'currency' => $snapshot['currency'],
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_amount_minor' => $snapshot['reporting_minor'],
                'payee' => $this->optional($payload, 'payee'),
            ],
        );

        return ['expense_id' => $expense->id, 'amount_minor' => $expense->amount_minor, 'currency' => $expense->currency];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function receive(string $id, array $payload, string $branchId, User $member): array
    {
        $supplier = Supplier::query()->whereKey($this->text($payload, 'supplier_id'))->where('active', true)->first();
        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose a supplier.']);
        }
        $receipt = $this->purchasing->receive($branchId, $supplier, [
            'tank_id' => $this->text($payload, 'tank_id'),
            'declared' => $this->text($payload, 'declared'),
            'measured' => $this->text($payload, 'measured'),
            'unit_cost' => $this->text($payload, 'unit_cost'),
            'currency' => $this->currency($payload, $branchId),
            'tanker' => $this->optional($payload, 'tanker'),
            'driver' => $this->optional($payload, 'driver'),
            'supplier_reference' => $this->optional($payload, 'supplier_reference'),
            'idempotency_key' => $id,
            'purchase_line_id' => $this->optional($payload, 'purchase_line_id'),
        ], $member);

        return [
            'receipt_id' => $receipt->id,
            'number' => $receipt->number,
            'total_minor' => $receipt->total_minor,
            'reporting_total_minor' => $receipt->reporting_total_minor,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function openShift(array $payload, Station $station, User $member): array
    {
        $openings = $payload['openings'] ?? [];
        $shift = $this->shifts->open(
            $station,
            $member,
            Money::input($this->optional($payload, 'opening_float') ?? '0', null, 'opening_float'),
            $this->optional($payload, 'schedule_id'),
            $this->optional($payload, 'handover_note'),
            is_array($openings) ? array_values($openings) : [],
        );

        return ['shift_id' => $shift->id, 'number' => $shift->number, 'status' => $shift->status];
    }

    /**
     * A device can only submit a handover. The cashier confirms it online.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function remit(string $id, array $payload, string $branchId, User $member): array
    {
        $shift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->first();
        if ($shift === null) {
            throw ValidationException::withMessages(['shift' => 'Open a shift first.']);
        }
        $attendant = Attendant::query()->whereKey($this->text($payload, 'attendant_id'))->first();
        if ($attendant === null || ! ShiftAssignment::query()->where('shift_id', $shift->id)->where('attendant_id', $attendant->id)->exists()) {
            throw ValidationException::withMessages(['attendant_id' => 'That attendant is not on this shift.']);
        }
        $currency = $this->currency($payload, $branchId);
        $transfer = $this->custody->submitRemittance(
            $shift,
            $attendant,
            $this->text($payload, 'cashier_id'),
            Money::input($this->text($payload, 'amount'), $currency),
            $currency,
            $member,
            $id,
            $this->optional($payload, 'notes'),
        );

        return [
            'transfer_id' => $transfer->id,
            'number' => $transfer->number,
            'status' => $transfer->status,
        ];
    }

    private function canSellForOthers(): bool
    {
        $access = app(CurrentAccess::class)->get();
        $branchId = app(CurrentBranch::class)->id();

        return $access !== null && (
            $access->allowsIn(Permissions::CASH_MANAGE, branchId: $branchId)
            || $access->allowsIn(Permissions::SHIFTS_APPROVE, branchId: $branchId)
        );
    }

    /**
     * A currency the station accepts, or null for the home currency.
     *
     * @param  array<string, mixed>  $payload
     */
    private function currency(array $payload, string $branchId): ?string
    {
        $currency = $this->optional($payload, 'currency');
        if ($currency === null || $currency === '') {
            return null;
        }
        $currency = strtoupper($currency);
        app(Currencies::class)->assertAccepted($branchId, $currency);

        return $currency;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function text(array $payload, string $key): string
    {
        return $this->optional($payload, $key) ?? '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optional(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            throw ValidationException::withMessages([$key => 'This field has the wrong shape.']);
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
