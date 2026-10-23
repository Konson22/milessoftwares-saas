<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CustomerPayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\TillCashMovement;
use App\Modules\Sales\Models\TillSession;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Cash shifts (till sessions): opened with starting cash, every counter
 * sale taken into the seller's open shift, and closed with a count of the
 * cash. What the drawer should hold is the starting cash plus cash taken
 * minus cash given back (voids and returns), plus cash put in and minus
 * cash taken out during the shift (the safe, the bank, expenses paid).
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
     * Puts cash into the open till or takes it out, with the reason. To or
     * from the safe it stays within the business's cash and nothing goes to
     * Finance; banked, taken from the bank, or paid out for an expense,
     * Finance moves it between cash and the bank or the expense. No more
     * comes out than the drawer should hold. A $submissionId already used
     * answers that movement.
     *
     * @throws ValidationException
     */
    public function move(TillSession $till, User $by, string $direction, int $amountMinor, string $reason, ?string $submissionId = null, string $counterpart = TillCashMovement::SAFE): TillCashMovement
    {
        if ($submissionId !== null && ($earlier = $this->submittedMovement($submissionId)) !== null) {
            return $earlier;
        }
        if (! in_array($direction, [TillCashMovement::IN, TillCashMovement::OUT], true)) {
            throw ValidationException::withMessages(['direction' => 'Say whether the cash goes in or comes out.']);
        }
        if (! in_array($counterpart, TillCashMovement::COUNTERPARTS, true)) {
            throw ValidationException::withMessages(['counterpart' => 'Say where the cash comes from or goes.']);
        }
        if ($counterpart === TillCashMovement::EXPENSE && $direction === TillCashMovement::IN) {
            throw ValidationException::withMessages(['counterpart' => 'Cash paid for an expense only comes out of the drawer.']);
        }
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Say why the cash goes in or comes out.']);
        }

        try {
            return DB::transaction(function () use ($till, $by, $direction, $amountMinor, $reason, $submissionId, $counterpart) {
                $locked = TillSession::query()->whereKey($till->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== TillSession::OPEN) {
                    throw ValidationException::withMessages(['amount' => "Shift {$locked->number} is closed."]);
                }
                if ($direction === TillCashMovement::OUT && $amountMinor > $this->summary($locked)['expectedCashMinor']) {
                    throw ValidationException::withMessages(['amount' => 'That is more cash than the drawer holds.']);
                }

                $place = TillCashMovement::query()->where('till_session_id', $locked->id)->count() + 1;
                $movement = TillCashMovement::query()->create([
                    'till_session_id' => $locked->id,
                    'direction' => $direction,
                    'counterpart' => $counterpart,
                    'amount_minor' => $amountMinor,
                    'reason' => trim($reason),
                    'recorded_by' => $by->id,
                    'submission_id' => $submissionId,
                ]);

                // Out of the business's cash (or into it from the bank): Finance moves it.
                if ($counterpart !== TillCashMovement::SAFE) {
                    $this->outbox->record('finance', 'finance.cash.moved', 1, "till_move:{$movement->id}", [
                        'reference' => "{$locked->number}/{$place}",
                        'branch_id' => $locked->branch_id,
                        'currency' => Money::currency(),
                        'moved_on' => now()->toDateString(),
                        'direction' => $direction,
                        'counterpart' => $counterpart,
                        'amount_minor' => $amountMinor,
                        'description' => $movement->reason,
                        'expense_account' => null,
                        'moved_by' => $by->name,
                    ], now());
                }

                return $movement;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same submission, committed by a request running alongside.
            return ($submissionId !== null ? $this->submittedMovement($submissionId) : null) ?? throw $e;
        }
    }

    /**
     * Cash put in and taken out of a till, oldest first.
     *
     * @return Collection<int, TillCashMovement>
     */
    public function movements(TillSession $till): Collection
    {
        return TillCashMovement::query()
            ->where('till_session_id', $till->id)
            ->with('recorder:id,name')
            ->oldest()
            ->get();
    }

    private function submittedMovement(string $submissionId): ?TillCashMovement
    {
        return TillCashMovement::query()->where('submission_id', $submissionId)->first();
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
     * back, cash put in and taken out, and the cash it should hold.
     *
     * @return array{salesCount: int, takenMinor: array<string, int>, collectedMinor: array<string, int>, collectionsCount: int, cashRefundsMinor: int, refundsCount: int, cashInMinor: int, cashOutMinor: int, movementsCount: int, expectedCashMinor: int}
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

        $moved = TillCashMovement::query()
            ->where('till_session_id', $till->id)
            ->groupBy('direction')
            ->selectRaw('direction, COUNT(*) AS movements, COALESCE(SUM(amount_minor), 0) AS total')
            ->get()
            ->keyBy('direction');
        $cashIn = (int) $moved->get(TillCashMovement::IN)?->getAttribute('total');
        $cashOut = (int) $moved->get(TillCashMovement::OUT)?->getAttribute('total');

        return [
            'salesCount' => (int) $taken->sum(fn ($row) => (int) $row->getAttribute('sales')),
            'takenMinor' => $takenMinor,
            'collectedMinor' => $collectedMinor,
            'collectionsCount' => (int) $collections->sum(fn ($row) => (int) $row->getAttribute('payments')),
            'cashRefundsMinor' => $cashRefunds,
            'refundsCount' => (int) $refunds?->getAttribute('refunds') + (int) $returns?->getAttribute('refunds'),
            'cashInMinor' => $cashIn,
            'cashOutMinor' => $cashOut,
            'movementsCount' => (int) $moved->sum(fn ($row) => (int) $row->getAttribute('movements')),
            'expectedCashMinor' => $till->opening_float_minor + ($takenMinor[PaymentMethod::Cash->value] ?? 0) + ($collectedMinor[PaymentMethod::Cash->value] ?? 0) - $cashRefunds + $cashIn - $cashOut,
        ];
    }
}
