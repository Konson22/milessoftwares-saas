<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\TillSession;
use App\Support\DocumentNumbers;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cashier tills: opened with a float, every counter sale taken into the
 * seller's open till, and closed with a count of the cash. What the till
 * should hold is the float plus cash taken minus cash given back.
 */
class Tills
{
    public function __construct(private readonly DocumentNumbers $numbers) {}

    public function openFor(User $user, string $branchId): ?TillSession
    {
        return TillSession::query()
            ->where('branch_id', $branchId)
            ->where('opened_by', $user->id)
            ->where('status', TillSession::OPEN)
            ->first();
    }

    /**
     * The open till a sale or refund goes into.
     *
     * @throws ValidationException when there is none
     */
    public function requireOpen(User $user, string $branchId, string $field = 'till'): TillSession
    {
        return $this->openFor($user, $branchId)
            ?? throw ValidationException::withMessages([$field => 'Open your till before taking or giving back money.']);
    }

    /**
     * @throws ValidationException when this person already has a till open here
     */
    public function open(User $user, string $branchId, int $floatMinor): TillSession
    {
        if ($floatMinor < 0) {
            throw ValidationException::withMessages(['opening_float' => 'The float cannot be negative.']);
        }

        try {
            return DB::transaction(function () use ($user, $branchId, $floatMinor) {
                if ($this->openFor($user, $branchId) !== null) {
                    throw ValidationException::withMessages(['opening_float' => 'Your till is already open.']);
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
            throw ValidationException::withMessages(['opening_float' => 'Your till is already open.']);
        }
    }

    /**
     * Counts the till and closes it. A count that differs from what the till
     * should hold needs a note.
     *
     * @throws ValidationException
     */
    public function close(TillSession $till, User $by, int $countedMinor, ?string $note): TillSession
    {
        return DB::transaction(function () use ($till, $by, $countedMinor, $note) {
            $locked = TillSession::query()->whereKey($till->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TillSession::OPEN) {
                throw ValidationException::withMessages(['counted_cash' => "Till {$locked->number} is already closed."]);
            }
            if ($countedMinor < 0) {
                throw ValidationException::withMessages(['counted_cash' => 'The count cannot be negative.']);
            }

            $expected = $this->summary($locked)['expectedCashMinor'];
            if ($countedMinor !== $expected && blank($note)) {
                throw ValidationException::withMessages(['closing_note' => 'Say why the count differs from what the till should hold.']);
            }

            $locked->forceFill([
                'status' => TillSession::CLOSED,
                'closed_by' => $by->id,
                'closed_at' => now(),
                'expected_cash_minor' => $expected,
                'counted_cash_minor' => $countedMinor,
                'closing_note' => blank($note) ? null : $note,
            ])->save();

            return $locked;
        });
    }

    /**
     * What went through a till: takings per payment method, cash given back,
     * and the cash it should hold.
     *
     * @return array{salesCount: int, takenMinor: array<string, int>, cashRefundsMinor: int, refundsCount: int, expectedCashMinor: int}
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

        $cashRefunds = (int) $refunds?->getAttribute('total');

        return [
            'salesCount' => (int) $taken->sum(fn ($row) => (int) $row->getAttribute('sales')),
            'takenMinor' => $takenMinor,
            'cashRefundsMinor' => $cashRefunds,
            'refundsCount' => (int) $refunds?->getAttribute('refunds'),
            'expectedCashMinor' => $till->opening_float_minor + ($takenMinor[PaymentMethod::Cash->value] ?? 0) - $cashRefunds,
        ];
    }
}
