<?php

namespace App\Actions;

use App\Models\CashHandover;
use App\Models\TillShift;
use App\Models\User;
use App\Support\DocumentNumbers;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash leaving a till. Once a till is closed and counted, its cashier (or a
 * manager for them) hands the cash over; a manager at the branch counts it
 * and confirms what they received. The one handing over never confirms it.
 * A confirmed amount that differs needs a note, so the gap has an owner.
 *
 * No money moves in Finance here: the takings reached it with each bill,
 * and an over or short drawer when the till was closed. A handover only
 * says who holds the cash now.
 */
class Handovers
{
    public function __construct(private readonly DocumentNumbers $numbers) {}

    /**
     * @throws ValidationException
     */
    public function handOver(TillShift $till, User $by, int $amountMinor, ?string $note): CashHandover
    {
        if ($till->isOpen()) {
            throw ValidationException::withMessages(['amount' => 'Close and count the till before handing its cash over.']);
        }
        if ($amountMinor < 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the cash handed over.']);
        }
        if ($till->counted_cash_minor !== null && $amountMinor !== $till->counted_cash_minor && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say why this is not the cash counted at closing (for example, the float kept in the drawer).']);
        }

        try {
            return DB::transaction(function () use ($till, $by, $amountMinor, $note) {
                if (CashHandover::query()->where('till_shift_id', $till->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['amount' => "The cash of shift {$till->number} was already handed over."]);
                }

                return CashHandover::query()->create([
                    'branch_id' => $till->branch_id,
                    'number' => $this->numbers->next('cash_handover', 'HAND', 5),
                    'till_shift_id' => $till->id,
                    'status' => CashHandover::PENDING,
                    'amount_minor' => $amountMinor,
                    'note' => blank($note) ? null : trim((string) $note),
                    'handed_by' => $by->id,
                    'handed_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['amount' => "The cash of shift {$till->number} was already handed over."]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function receive(CashHandover $handover, User $by, int $receivedMinor, ?string $note): CashHandover
    {
        return DB::transaction(function () use ($handover, $by, $receivedMinor, $note) {
            $locked = CashHandover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['received' => "Handover {$locked->number} was already received."]);
            }
            if ($locked->handed_by === $by->id || TillShift::query()->whereKey($locked->till_shift_id)->value('opened_by') === $by->id) {
                throw ValidationException::withMessages(['received' => 'Someone else must count and receive cash you hand over.']);
            }
            if ($receivedMinor !== $locked->amount_minor && blank($note)) {
                throw ValidationException::withMessages(['receiving_note' => 'Say why what you received differs from what was handed over.']);
            }

            $locked->forceFill([
                'status' => CashHandover::RECEIVED,
                'received_by' => $by->id,
                'received_at' => now(),
                'received_minor' => $receivedMinor,
                'receiving_note' => blank($note) ? null : trim((string) $note),
            ])->save();

            return $locked;
        });
    }
}
