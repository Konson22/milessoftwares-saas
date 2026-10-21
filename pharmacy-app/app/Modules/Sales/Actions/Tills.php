<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CustomerPayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\TillSession;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Cash shifts (till sessions): opened with starting cash, every counter
 * sale taken into the seller's open shift, and closed with a count of the
 * cash. What the drawer should hold is the starting cash plus cash taken
 * minus cash given back (voids and returns).
 *
 * The branch decides (BranchCounterSettings): whether money is taken only
 * in an open shift, the usual starting cash, and whether cashiers count
 * blind (without seeing what the drawer should hold; a difference then
 * needs no reason from them).
 */
class Tills
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
        private readonly Industry $industry,
    ) {}

    public function settings(string $branchId): BranchCounterSettings
    {
        return BranchCounterSettings::forBranch($branchId, $this->industry->code());
    }

    public function openFor(User $user, string $branchId): ?TillSession
    {
        return TillSession::query()
            ->where('branch_id', $branchId)
            ->where('opened_by', $user->id)
            ->where('status', TillSession::OPEN)
            ->first();
    }

    /**
     * The open shift a sale or refund goes into; none where the branch
     * does not use shifts and nobody opened one.
     *
     * @throws ValidationException when the branch needs one and there is none
     */
    public function requireOpen(User $user, string $branchId, string $field = 'till'): ?TillSession
    {
        $open = $this->openFor($user, $branchId);
        if ($open === null && $this->settings($branchId)->shifts_required) {
            throw ValidationException::withMessages([$field => 'Start your shift before taking or giving back money.']);
        }

        return $open;
    }

    /**
     * @throws ValidationException when this person already has a till open here
     */
    public function open(User $user, string $branchId, int $floatMinor): TillSession
    {
        if ($floatMinor < 0) {
            throw ValidationException::withMessages(['opening_float' => 'The starting cash cannot be negative.']);
        }

        try {
            return DB::transaction(function () use ($user, $branchId, $floatMinor) {
                if ($this->openFor($user, $branchId) !== null) {
                    throw ValidationException::withMessages(['opening_float' => 'Your shift is already open.']);
                }

                return TillSession::query()->create([
                    'branch_id' => $branchId,
                    'number' => $this->numbers->next('till', 'T', 5),
                    'status' => TillSession::OPEN,
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                    'opening_float_minor' => $floatMinor,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['opening_float' => 'Your shift is already open.']);
        }
    }

    /**
     * Counts the cash and closes the shift. A count that differs from what
     * the drawer should hold needs a note, unless counted blind.
     *
     * @throws ValidationException
     */
    public function close(TillSession $till, User $by, int $countedMinor, ?string $note, bool $blind = false): TillSession
    {
        return DB::transaction(function () use ($till, $by, $countedMinor, $note, $blind) {
            $locked = TillSession::query()->whereKey($till->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TillSession::OPEN) {
                throw ValidationException::withMessages(['counted_cash' => "Shift {$locked->number} is already closed."]);
            }
            if ($countedMinor < 0) {
                throw ValidationException::withMessages(['counted_cash' => 'The count cannot be negative.']);
            }

            $expected = $this->summary($locked)['expectedCashMinor'];
            if ($countedMinor !== $expected && blank($note) && ! $blind) {
                throw ValidationException::withMessages(['closing_note' => 'Say why the count differs from what the drawer should hold.']);
            }

            $locked->forceFill([
                'status' => TillSession::CLOSED,
                'closed_by' => $by->id,
                'closed_at' => now(),
                'expected_cash_minor' => $expected,
                'counted_cash_minor' => $countedMinor,
                'closing_note' => blank($note) ? null : $note,
            ])->save();

            // Over or short: Finance moves the difference out of (or into) cash.
            if ($countedMinor !== $expected) {
                $this->outbox->record('finance', 'finance.cash.counted', 1, "till:{$locked->id}:count", [
                    'reference' => $locked->number,
                    'branch_id' => $locked->branch_id,
                    'currency' => Money::currency(),
                    'counted_on' => $locked->closed_at?->toDateString() ?? now()->toDateString(),
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
     * What went through a till: takings per payment method (sales on account
     * take no money), payments from account customers per method, cash given
     * back, and the cash it should hold.
     *
     * @return array{salesCount: int, takenMinor: array<string, int>, collectedMinor: array<string, int>, collectionsCount: int, cashRefundsMinor: int, refundsCount: int, expectedCashMinor: int}
     */
    public function summary(TillSession $till): array
    {
        // Voided sales count here too: the money was taken, and given back
        // as a refund (from whichever till the void was done at).
        $taken = Sale::query()
            ->where('till_session_id', $till->id)
            ->groupBy('payment_method')
            ->selectRaw('payment_method, COUNT(*) AS sales, COALESCE(SUM(total_minor), 0) AS total')
            ->get();

        $refunds = Sale::query()
            ->where('void_till_session_id', $till->id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->selectRaw('COUNT(*) AS refunds, COALESCE(SUM(total_minor), 0) AS total')
            ->first();

        $takenMinor = [];
        foreach ($taken as $row) {
            $method = $row->getRawOriginal('payment_method');
            $takenMinor[(string) $method] = (int) $row->getAttribute('total');
        }

        $returns = SaleReturn::query()
            ->where('till_session_id', $till->id)
            ->where('refund_method', PaymentMethod::Cash->value)
            ->selectRaw('COUNT(*) AS refunds, COALESCE(SUM(total_minor), 0) AS total')
            ->first();

        $cashRefunds = (int) $refunds?->getAttribute('total') + (int) $returns?->getAttribute('total');

        $collections = CustomerPayment::query()
            ->where('till_session_id', $till->id)
            ->groupBy('method')
            ->selectRaw('method, COUNT(*) AS payments, COALESCE(SUM(amount_minor), 0) AS total')
            ->get();
        $collectedMinor = [];
        foreach ($collections as $row) {
            $collectedMinor[(string) $row->getRawOriginal('method')] = (int) $row->getAttribute('total');
        }

        return [
            'salesCount' => (int) $taken->sum(fn ($row) => (int) $row->getAttribute('sales')),
            'takenMinor' => $takenMinor,
            'collectedMinor' => $collectedMinor,
            'collectionsCount' => (int) $collections->sum(fn ($row) => (int) $row->getAttribute('payments')),
            'cashRefundsMinor' => $cashRefunds,
            'refundsCount' => (int) $refunds?->getAttribute('refunds') + (int) $returns?->getAttribute('refunds'),
            'expectedCashMinor' => $till->opening_float_minor + ($takenMinor[PaymentMethod::Cash->value] ?? 0) + ($collectedMinor[PaymentMethod::Cash->value] ?? 0) - $cashRefunds,
        ];
    }
}
