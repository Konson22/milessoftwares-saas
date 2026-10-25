<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\TillShift;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Cashiers' shifts. A cashier opens their till at a branch with a float,
 * takes bills into it, and closes it with a count of the cash drawer. One
 * open till per cashier per branch. A count that differs from what the
 * drawer should hold needs a note, and Finance is told of the difference
 * (finance.cash.counted); the takings themselves reach it with each bill.
 */
class Tills
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @throws ValidationException when the cashier's till is already open
     */
    public function open(User $by, string $branchId, int $floatMinor): TillShift
    {
        if ($this->openFor($by, $branchId) !== null) {
            throw ValidationException::withMessages(['opening_float' => 'Your till is already open at this branch.']);
        }

        try {
            return DB::transaction(fn () => TillShift::query()->create([
                'branch_id' => $branchId,
                'number' => $this->numbers->next('till_shift', 'SHIFT', 5),
                'status' => TillShift::OPEN,
                'opening_float_minor' => $floatMinor,
                'opened_by' => $by->id,
                'opened_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['opening_float' => 'Your till is already open at this branch.']);
        }
    }

    public function openFor(User $user, string $branchId): ?TillShift
    {
        return TillShift::query()
            ->where('branch_id', $branchId)
            ->where('opened_by', $user->id)
            ->where('status', TillShift::OPEN)
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public function close(TillShift $till, User $by, int $countedMinor, ?string $note): TillShift
    {
        return DB::transaction(function () use ($till, $by, $countedMinor, $note) {
            $locked = TillShift::query()->whereKey($till->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['counted_cash' => "Shift {$locked->number} is already closed."]);
            }

            $expected = $this->summary($locked)['expectedCashMinor'];
            if ($countedMinor !== $expected && blank($note)) {
                throw ValidationException::withMessages(['closing_note' => 'Say why the count differs from what the drawer should hold.']);
            }

            $locked->forceFill([
                'status' => TillShift::CLOSED,
                'closed_by' => $by->id,
                'closed_at' => now(),
                'expected_cash_minor' => $expected,
                'counted_cash_minor' => $countedMinor,
                'closing_note' => blank($note) ? null : trim((string) $note),
            ])->save();

            // Over or short: Finance moves the difference out of (or into) cash.
            if ($countedMinor !== $expected) {
                $this->outbox->record('finance', 'finance.cash.counted', 1, "till:{$locked->id}:count", [
                    'reference' => $locked->number,
                    'branch_id' => $locked->branch_id,
                    'currency' => Money::currency(),
                    'counted_on' => now()->toDateString(),
                    'expected_minor' => $expected,
                    'counted_minor' => $countedMinor,
                    'difference_minor' => $countedMinor - $expected,
                    'counted_by' => $by->name,
                    'note' => $locked->closing_note,
                ], now());
            }

            return $locked;
        });
    }

    /**
     * What went through a till: bills and takings per payment method, cash
     * given back for voided bills, and the cash it should hold.
     *
     * @return array{billsCount: int, takenMinor: array<string, int>, cashRefundsMinor: int, refundsCount: int, expectedCashMinor: int}
     */
    public function summary(TillShift $till): array
    {
        // Voided bills count here too: the money was taken, and given back
        // as a refund (from whichever till the void was done at).
        $taken = Bill::query()
            ->where('till_shift_id', $till->id)
            ->toBase()
            ->selectRaw('payment_method, COUNT(*) as bills, SUM(total_minor) as total')
            ->groupBy('payment_method')
            ->get();

        $takenMinor = array_fill_keys(array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::cases()), 0);
        $billsCount = 0;
        foreach ($taken as $row) {
            $takenMinor[(string) $row->payment_method] = (int) $row->total;
            $billsCount += (int) $row->bills;
        }

        $refunds = Bill::query()
            ->where('refund_till_shift_id', $till->id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->toBase()
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(total_minor), 0) as total')
            ->first();
        $cashRefunds = (int) ($refunds->total ?? 0);

        return [
            'billsCount' => $billsCount,
            'takenMinor' => $takenMinor,
            'cashRefundsMinor' => $cashRefunds,
            'refundsCount' => (int) ($refunds->bills ?? 0),
            'expectedCashMinor' => $till->opening_float_minor + $takenMinor[PaymentMethod::Cash->value] - $cashRefunds,
        ];
    }
}
