<?php

namespace App\Petrol;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Drawer and petty-cash movements. Money between the drawer and the petty
 * box stays inside cash, so it is not sent to Finance. An expense paid
 * from the drawer is.
 */
class Cashbook
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Audit $audit,
    ) {}

    public function drawer(string $shiftId, ?string $currency = null): int
    {
        $books = Money::currency();
        $currency = $currency === null ? $books : strtoupper($currency);
        $query = CashMovement::query()
            ->where('shift_id', $shiftId)
            ->whereIn('kind', CashMovement::DRAWER);

        if ($currency === $books) {
            $query->where(fn ($row) => $row->whereNull('currency')->orWhere('currency', $books));
        } else {
            $query->where('currency', $currency);
        }

        return (int) $query->sum('amount_minor');
    }

    /**
     * Physical cash in the drawer, one total per currency. Null on old rows is the workspace currency.
     *
     * @return array<string, int>
     */
    public function totals(string $shiftId): array
    {
        $books = Money::currency();
        $totals = [];
        $rows = CashMovement::query()
            ->where('shift_id', $shiftId)
            ->whereIn('kind', CashMovement::DRAWER)
            ->get(['currency', 'amount_minor']);

        foreach ($rows as $row) {
            $currency = $row->currency ?: $books;
            $totals[$currency] = ($totals[$currency] ?? 0) + (int) $row->amount_minor;
        }

        return $totals;
    }

    public function petty(string $branchId): int
    {
        return (int) CashMovement::query()
            ->where('branch_id', $branchId)
            ->whereIn('kind', [CashMovement::PETTY_IN, CashMovement::PETTY_EXPENSE])
            ->sum('amount_minor');
    }

    /**
     * @param  array{fuel_sale_id?: string|null, expense_id?: string|null, supplier_payment_id?: string|null, credit_entry_id?: string|null}  $links
     */
    public function move(string $branchId, ?string $shiftId, string $kind, int $amount, User $by, ?string $key, ?string $note, array $links = [], ?string $currency = null): CashMovement
    {
        if ($key !== null) {
            $existing = CashMovement::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        app(Currencies::class)->assertAccepted($branchId, $currency);

        return CashMovement::query()->create([
            'branch_id' => $branchId,
            'shift_id' => $shiftId,
            'kind' => $kind,
            'amount_minor' => $amount,
            'fuel_sale_id' => $links['fuel_sale_id'] ?? null,
            'expense_id' => $links['expense_id'] ?? null,
            'supplier_payment_id' => $links['supplier_payment_id'] ?? null,
            'credit_entry_id' => $links['credit_entry_id'] ?? null,
            'idempotency_key' => $key,
            'note' => $note,
            'currency' => $currency === null || strtoupper($currency) === Money::currency() ? null : strtoupper($currency),
            'recorded_by' => $by->id,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array{category_id?: string, currency?: string, fx_base?: string, fx_quote?: string, fx_rate_minor?: int, reporting_amount_minor?: int, payee?: string, schedule_id?: string}  $extra
     */
    public function expense(string $branchId, ?Shift $shift, string $category, string $description, int $amount, string $paidFrom, User $by, ?string $key, array $extra = []): Expense
    {
        return DB::transaction(function () use ($branchId, $shift, $category, $description, $amount, $paidFrom, $by, $key, $extra) {
            if ($key !== null) {
                $existing = Expense::query()->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
            }

            $currency = strtoupper((string) ($extra['currency'] ?? Money::currency()));
            app(Currencies::class)->assertAccepted($branchId, $currency);
            if ($currency !== Money::currency() && in_array($paidFrom, [Expense::CASH, Expense::PETTY], true)) {
                throw ValidationException::withMessages(['paid_from' => 'A foreign-currency expense is paid from the bank, not the drawer.']);
            }

            if ($paidFrom === Expense::CASH && ($shift === null || ! $shift->isOpen())) {
                throw ValidationException::withMessages(['paid_from' => 'Open a shift before paying an expense from the drawer.']);
            }

            if ($paidFrom === Expense::PETTY && $this->petty($branchId) < $amount) {
                throw ValidationException::withMessages(['amount' => 'Petty cash does not hold that much.']);
            }

            $expense = Expense::query()->create([
                'branch_id' => $branchId,
                'shift_id' => $shift?->id,
                'category' => $category,
                'description' => $description,
                'amount_minor' => $amount,
                'paid_from' => $paidFrom,
                'incurred_on' => BusinessDate::today(),
                'idempotency_key' => $key,
                'recorded_by' => $by->id,
                'category_id' => $extra['category_id'] ?? null,
                'schedule_id' => $extra['schedule_id'] ?? null,
                'currency' => $extra['currency'] ?? null,
                'fx_base' => $extra['fx_base'] ?? null,
                'fx_quote' => $extra['fx_quote'] ?? null,
                'fx_rate_minor' => $extra['fx_rate_minor'] ?? null,
                'reporting_amount_minor' => $extra['reporting_amount_minor'] ?? $amount,
                'payee' => $extra['payee'] ?? null,
                'approval_status' => 'recorded',
                'payment_status' => $paidFrom === Expense::BANK ? 'unpaid' : 'paid',
            ]);

            if ($paidFrom === Expense::CASH) {
                $this->move($branchId, $shift->id, CashMovement::EXPENSE, -$amount, $by, $key === null ? null : $key.':drawer', $description, ['expense_id' => $expense->id]);
                $this->outbox->record('finance', 'finance.cash.moved', 1, 'expense:'.$expense->id, [
                    'reference' => $expense->id,
                    'branch_id' => $branchId,
                    'currency' => Money::currency(),
                    'moved_on' => BusinessDate::today(),
                    'direction' => 'out',
                    'counterpart' => 'expense',
                    'amount_minor' => $amount,
                    'description' => $category.': '.$description,
                    'expense_account' => null,
                    'moved_by' => $by->name,
                ]);
            }

            if ($paidFrom === Expense::PETTY) {
                $this->move($branchId, $shift?->id, CashMovement::PETTY_EXPENSE, -$amount, $by, $key === null ? null : $key.':petty', $description, ['expense_id' => $expense->id]);
            }

            $this->audit->record($by, 'expense.recorded', $expense, null, [
                'amount_minor' => $amount,
                'paid_from' => $paidFrom,
            ], $branchId);

            return $expense;
        });
    }

    /**
     * Move cash from the open drawer into the petty box. Finance is not told:
     * the cash has not left the business.
     */
    public function fundPetty(Shift $shift, int $amount, User $by, ?string $note): void
    {
        DB::transaction(function () use ($shift, $amount, $by, $note) {
            if (! $shift->isOpen()) {
                throw ValidationException::withMessages(['amount' => 'The shift is not open.']);
            }

            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
            }

            if ($this->drawer($shift->id) < $amount) {
                throw ValidationException::withMessages(['amount' => 'The drawer does not hold that much.']);
            }

            $this->move($shift->branch_id, $shift->id, CashMovement::PETTY_TRANSFER, -$amount, $by, null, $note);
            $this->move($shift->branch_id, $shift->id, CashMovement::PETTY_IN, $amount, $by, null, $note);
            $this->audit->record($by, 'petty.funded', $shift, null, ['amount_minor' => $amount], $shift->branch_id);
        });
    }
}
