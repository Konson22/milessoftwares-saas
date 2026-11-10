<?php

namespace App\Petrol;

use App\Models\Attendant;
use App\Models\AttendantHandover;
use App\Models\AttendantStation;
use App\Models\FuelSale;
use App\Models\Nozzle;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftBreak;
use App\Models\ShiftException;
use App\Models\Station;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Time-bounded nozzle responsibility. Closing an attendant's assignment
 * does not close the station shift and does not move their cash.
 */
class Accountability
{
    public function __construct(
        private readonly Audit $audit,
        private readonly Custody $custody,
        private readonly ShiftExceptions $exceptions,
    ) {}

    public function requestHandover(ShiftAssignment $assignment, string $incomingAttendantId, int $closingMilli, int $openingMilli, ?string $eventReason, User $by, ?string $notes): AttendantHandover
    {
        return DB::transaction(function () use ($assignment, $incomingAttendantId, $closingMilli, $openingMilli, $eventReason, $by, $notes) {
            $shift = $this->lockShift($assignment->shift_id);
            $this->openShift($shift);
            $current = $this->lockAssignment($assignment);
            $this->mustBeActive($current);
            $incoming = $this->attendant($shift, $incomingAttendantId);
            if ($incoming->id === $current->attendant_id) {
                throw ValidationException::withMessages(['incoming_attendant_id' => 'Choose the attendant who is taking over.']);
            }
            $this->guardReading($current, $closingMilli, $openingMilli, $eventReason);
            if (AttendantHandover::query()->where('outgoing_assignment_id', $current->id)->whereIn('status', [AttendantHandover::REQUESTED, AttendantHandover::VERIFIED])->exists()) {
                throw ValidationException::withMessages(['assignment' => 'This assignment already has a handover waiting.']);
            }

            $handover = AttendantHandover::query()->create([
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'nozzle_id' => $current->nozzle_id,
                'outgoing_assignment_id' => $current->id,
                'incoming_attendant_id' => $incoming->id,
                'closing_milli' => $closingMilli,
                'opening_milli' => $openingMilli,
                'status' => AttendantHandover::REQUESTED,
                'event_reason' => $this->blank($eventReason),
                'notes' => $this->blank($notes),
                'requested_by' => $by->id,
                'requested_at' => now(),
            ]);
            $this->audit->record($by, 'handover.requested', $handover, null, [
                'outgoing' => $current->attendant_id,
                'incoming' => $incoming->id,
            ], $shift->branch_id);

            return $handover;
        });
    }

    public function confirmHandover(AttendantHandover $handover, User $by): AttendantHandover
    {
        return DB::transaction(function () use ($handover, $by) {
            $locked = $this->lockHandover($handover);
            $this->openShift($this->lockShift($locked->shift_id));
            if ($locked->status !== AttendantHandover::REQUESTED) {
                throw ValidationException::withMessages(['handover' => 'This handover is '.$locked->status.'.']);
            }
            if ($locked->requested_by === $by->id) {
                throw ValidationException::withMessages(['handover' => 'Someone else has to verify the opening reading.']);
            }
            $incoming = Attendant::query()->whereKey($locked->incoming_attendant_id)->firstOrFail();
            if ($incoming->user_id !== null && $incoming->user_id !== $by->id) {
                throw ValidationException::withMessages(['handover' => 'The incoming attendant verifies this reading.']);
            }
            $locked->forceFill([
                'status' => AttendantHandover::VERIFIED,
                'verified_by' => $by->id,
                'verified_at' => now(),
            ])->save();
            $this->audit->record($by, 'handover.verified', $locked, ['status' => AttendantHandover::REQUESTED], ['status' => AttendantHandover::VERIFIED], $locked->branch_id);

            return $locked;
        });
    }

    public function approveHandover(AttendantHandover $handover, User $by): AttendantHandover
    {
        return DB::transaction(function () use ($handover, $by) {
            $locked = $this->lockHandover($handover);
            $shift = $this->lockShift($locked->shift_id);
            $this->openShift($shift);
            if (! in_array($locked->status, [AttendantHandover::REQUESTED, AttendantHandover::VERIFIED], true)) {
                throw ValidationException::withMessages(['handover' => 'This handover is '.$locked->status.'.']);
            }
            if ($locked->requested_by === $by->id || $locked->verified_by === $by->id) {
                throw ValidationException::withMessages(['handover' => 'The person who requested or verified the handover cannot approve it.']);
            }
            $outgoing = $this->lockAssignment(ShiftAssignment::query()->whereKey($locked->outgoing_assignment_id)->firstOrFail());
            $this->mustBeActive($outgoing);
            $incoming = $this->attendant($shift, $locked->incoming_attendant_id);
            $this->finish($outgoing, $locked->closing_milli, $by);
            $this->noteVariance($shift, $outgoing, $by, $locked->id);
            $created = $this->begin($shift, $incoming, $outgoing->nozzle, $locked->opening_milli, $by, $locked->verified_by, 'Took over from the previous attendant.');
            if ($locked->opening_milli !== $locked->closing_milli) {
                $this->exceptions->open($shift, $outgoing->id, $locked->id, 'meter_event', (string) $locked->event_reason, ShiftException::WARNING, $by);
            }
            $locked->forceFill([
                'status' => AttendantHandover::APPROVED,
                'incoming_assignment_id' => $created->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();
            $this->audit->record($by, 'handover.approved', $locked, ['status' => AttendantHandover::VERIFIED], [
                'status' => AttendantHandover::APPROVED,
                'incoming_assignment_id' => $created->id,
            ], $shift->branch_id);

            return $locked;
        });
    }

    public function rejectHandover(AttendantHandover $handover, User $by, string $notes): AttendantHandover
    {
        $notes = trim($notes);
        if ($notes === '') {
            throw ValidationException::withMessages(['notes' => 'Say why the handover was rejected.']);
        }

        return DB::transaction(function () use ($handover, $by, $notes) {
            $locked = $this->lockHandover($handover);
            if (! in_array($locked->status, [AttendantHandover::REQUESTED, AttendantHandover::VERIFIED], true)) {
                throw ValidationException::withMessages(['handover' => 'This handover is '.$locked->status.'.']);
            }
            if ($locked->requested_by === $by->id) {
                throw ValidationException::withMessages(['handover' => 'The person who requested the handover cannot reject it.']);
            }
            $before = $locked->status;
            $locked->forceFill(['status' => AttendantHandover::REJECTED, 'notes' => $notes, 'approved_by' => $by->id, 'approved_at' => now()])->save();
            $this->audit->record($by, 'handover.rejected', $locked, ['status' => $before], ['status' => AttendantHandover::REJECTED], $locked->branch_id);

            return $locked;
        });
    }

    /**
     * A supervisor moves the nozzle in one step. The outgoing cash is not moved.
     */
    public function reassign(ShiftAssignment $assignment, string $incomingAttendantId, int $closingMilli, int $openingMilli, ?string $eventReason, User $by): AttendantHandover
    {
        return DB::transaction(function () use ($assignment, $incomingAttendantId, $closingMilli, $openingMilli, $eventReason, $by) {
            $shift = $this->lockShift($assignment->shift_id);
            $this->openShift($shift);
            $current = $this->lockAssignment($assignment);
            $this->mustBeActive($current);
            $incoming = $this->attendant($shift, $incomingAttendantId);
            if ($incoming->id === $current->attendant_id) {
                throw ValidationException::withMessages(['incoming_attendant_id' => 'Choose a different attendant.']);
            }
            $this->guardReading($current, $closingMilli, $openingMilli, $eventReason);
            $this->finish($current, $closingMilli, $by);
            $this->noteVariance($shift, $current, $by, null);
            $created = $this->begin($shift, $incoming, $current->nozzle, $openingMilli, $by, null, 'Reassigned by a supervisor.');
            $handover = AttendantHandover::query()->create([
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'nozzle_id' => $current->nozzle_id,
                'outgoing_assignment_id' => $current->id,
                'incoming_attendant_id' => $incoming->id,
                'incoming_assignment_id' => $created->id,
                'closing_milli' => $closingMilli,
                'opening_milli' => $openingMilli,
                'status' => AttendantHandover::APPROVED,
                'event_reason' => $this->blank($eventReason),
                'notes' => 'Supervisor reassignment.',
                'requested_by' => $by->id,
                'requested_at' => now(),
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);
            if ($openingMilli !== $closingMilli) {
                $this->exceptions->open($shift, $current->id, $handover->id, 'meter_event', (string) $eventReason, ShiftException::WARNING, $by);
            }
            $this->audit->record($by, 'assignment.reassigned', $handover, null, [
                'outgoing' => $current->attendant_id,
                'incoming' => $incoming->id,
            ], $shift->branch_id);

            return $handover;
        });
    }

    /**
     * The attendant stops dispensing. The station shift stays open, and any
     * cash they still hold stays in their name.
     */
    public function depart(ShiftAssignment $assignment, int $closingMilli, User $by): ShiftAssignment
    {
        return DB::transaction(function () use ($assignment, $closingMilli, $by) {
            $shift = $this->lockShift($assignment->shift_id);
            $this->openShift($shift);
            $current = $this->lockAssignment($assignment);
            $this->mustBeActive($current);
            if ($current->opening_milli !== null && $closingMilli < $current->opening_milli) {
                throw ValidationException::withMessages(['closing' => 'The closing reading cannot be below the opening reading.']);
            }
            $this->finish($current, $closingMilli, $by);
            $this->noteVariance($shift, $current, $by, null);
            $this->noteCash($shift, $current, $by);
            $this->audit->record($by, 'assignment.departed', $current, ['status' => ShiftAssignment::ACTIVE], ['status' => ShiftAssignment::CLOSED], $shift->branch_id);

            return $current;
        });
    }

    public function startBreak(ShiftAssignment $assignment, int $reading, string $reason, bool $nozzleAvailable, ?string $replacementId, User $by): ShiftBreak
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the attendant is leaving the pump.']);
        }

        return DB::transaction(function () use ($assignment, $reading, $reason, $nozzleAvailable, $replacementId, $by) {
            $shift = $this->lockShift($assignment->shift_id);
            $this->openShift($shift);
            $current = $this->lockAssignment($assignment);
            $this->mustBeActive($current);
            if ($current->opening_milli !== null && $reading < $current->opening_milli) {
                throw ValidationException::withMessages(['reading' => 'The reading cannot be below the opening reading.']);
            }
            $this->finish($current, $reading, $by);
            $replacement = null;
            if ($replacementId !== null && $replacementId !== '') {
                $attendant = $this->attendant($shift, $replacementId);
                if ($attendant->id === $current->attendant_id) {
                    throw ValidationException::withMessages(['replacement_id' => 'The replacement has to be someone else.']);
                }
                $replacement = $this->begin($shift, $attendant, $current->nozzle, $reading, $by, null, 'Covering a break.');
            } else {
                $this->exceptions->open($shift, $current->id, null, 'attendant_absence', 'No attendant was responsible for '.$current->nozzle->code.' during this break.', ShiftException::WARNING, $by);
            }
            $break = ShiftBreak::query()->create([
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'assignment_id' => $current->id,
                'nozzle_id' => $current->nozzle_id,
                'attendant_id' => $current->attendant_id,
                'reason' => mb_substr($reason, 0, 32),
                'nozzle_available' => $nozzleAvailable,
                'reading_milli' => $reading,
                'started_at' => now(),
                'replacement_assignment_id' => $replacement?->id,
                'started_by' => $by->id,
            ]);
            $this->audit->record($by, 'break.started', $break, null, ['assignment_id' => $current->id], $shift->branch_id);

            return $break;
        });
    }

    public function endBreak(ShiftBreak $break, int $reading, User $by): ShiftAssignment
    {
        return DB::transaction(function () use ($break, $reading, $by) {
            $locked = ShiftBreak::query()->whereKey($break->id)->lockForUpdate()->firstOrFail();
            $shift = $this->lockShift($locked->shift_id);
            $this->openShift($shift);
            if ($locked->ended_at !== null) {
                throw ValidationException::withMessages(['break' => 'This break has already ended.']);
            }
            if ($locked->replacement_assignment_id !== null) {
                $cover = $this->lockAssignment(ShiftAssignment::query()->whereKey($locked->replacement_assignment_id)->firstOrFail());
                if ($cover->isActive()) {
                    if ($cover->opening_milli !== null && $reading < $cover->opening_milli) {
                        throw ValidationException::withMessages(['reading' => 'The reading cannot be below the cover opening.']);
                    }
                    $this->finish($cover, $reading, $by);
                    $this->noteVariance($shift, $cover, $by, null);
                }
            }
            $attendant = $this->attendant($shift, $locked->attendant_id);
            $nozzle = Nozzle::query()->whereKey($locked->nozzle_id)->firstOrFail();
            $return = $this->begin($shift, $attendant, $nozzle, $reading, $by, null, 'Returned from a break.');
            $locked->forceFill(['ended_at' => now(), 'return_assignment_id' => $return->id])->save();
            $this->audit->record($by, 'break.ended', $locked, null, ['return_assignment_id' => $return->id], $shift->branch_id);

            return $return;
        });
    }

    private function finish(ShiftAssignment $assignment, int $closingMilli, User $by): void
    {
        $assignment->forceFill([
            'status' => ShiftAssignment::CLOSED,
            'ended_at' => now(),
            'closing_milli' => $closingMilli,
            'verified_by' => $assignment->verified_by ?? $by->id,
        ])->save();
    }

    private function begin(Shift $shift, Attendant $attendant, Nozzle $nozzle, int $openingMilli, User $by, ?string $verifiedBy, string $notes): ShiftAssignment
    {
        $this->guardExclusive($shift, $nozzle, $attendant->id);

        return ShiftAssignment::query()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
            'pump_id' => $nozzle->pump_id,
            'attendant_id' => $attendant->id,
            'nozzle_id' => $nozzle->id,
            'status' => ShiftAssignment::ACTIVE,
            'started_at' => now(),
            'opening_milli' => $openingMilli,
            'assigned_by' => $by->id,
            'verified_by' => $verifiedBy,
            'notes' => $notes,
        ]);
    }

    private function guardExclusive(Shift $shift, Nozzle $nozzle, string $attendantId): void
    {
        $open = ShiftAssignment::query()
            ->where('shift_id', $shift->id)
            ->where('nozzle_id', $nozzle->id)
            ->whereNull('ended_at')
            ->where('status', ShiftAssignment::ACTIVE)
            ->get();
        if ($open->contains(fn (ShiftAssignment $row) => $row->attendant_id === $attendantId)) {
            throw ValidationException::withMessages(['attendant_id' => 'That attendant is already on this nozzle.']);
        }
        $shared = (bool) Station::query()->where('branch_id', $shift->branch_id)->value('shared_pumps');
        if ($open->isNotEmpty() && ! $shared) {
            throw ValidationException::withMessages(['nozzle_id' => 'That nozzle already has an attendant. Close or hand over that assignment first.']);
        }
    }

    private function guardReading(ShiftAssignment $assignment, int $closingMilli, int $openingMilli, ?string $eventReason): void
    {
        if ($assignment->opening_milli !== null && $closingMilli < $assignment->opening_milli) {
            throw ValidationException::withMessages(['closing' => 'The closing reading cannot be below the opening reading.']);
        }
        if ($openingMilli !== $closingMilli && $this->blank($eventReason) === null) {
            throw ValidationException::withMessages(['opening' => 'The incoming opening reading has to match the closing reading, unless a meter replacement or correction is recorded.']);
        }
        if ($openingMilli < 0 || $closingMilli < 0) {
            throw ValidationException::withMessages(['closing' => 'Enter the meter reading.']);
        }
    }

    private function noteVariance(Shift $shift, ShiftAssignment $assignment, User $by, ?string $handoverId): void
    {
        if ($assignment->opening_milli === null || $assignment->closing_milli === null) {
            $this->exceptions->open($shift, $assignment->id, $handoverId, 'missing_reading', 'This assignment has no meter interval.', ShiftException::CRITICAL, $by);

            return;
        }
        $dispensed = $assignment->closing_milli - $assignment->opening_milli;
        $sold = (int) FuelSale::query()
            ->where('shift_id', $shift->id)
            ->where('status', FuelSale::POSTED)
            ->where('attendant_id', $assignment->attendant_id)
            ->where('nozzle_id', $assignment->nozzle_id)
            ->where(function ($query) use ($assignment) {
                $query->where('assignment_id', $assignment->id)
                    ->orWhere(function ($fallback) use ($assignment) {
                        $fallback->whereNull('assignment_id')->where('sold_at', '>=', $assignment->started_at);
                    });
            })
            ->sum('quantity_milli');
        if ($dispensed !== $sold) {
            $this->exceptions->open(
                $shift,
                $assignment->id,
                $handoverId,
                'fuel_variance',
                'Metered litres and recorded sales for this assignment differ.',
                ShiftException::WARNING,
                $by,
            );
        }
    }

    private function noteCash(Shift $shift, ShiftAssignment $assignment, User $by): void
    {
        $held = [];
        foreach (app(Currencies::class)->reportCodes() as $code) {
            $amount = $this->custody->attendantHeld($shift->id, $assignment->attendant_id, $code);
            if ($amount > 0) {
                $held[] = Money::format($amount, $code);
            }
        }
        if ($held !== []) {
            $this->exceptions->open(
                $shift,
                $assignment->id,
                null,
                'pending_cash',
                'This attendant stopped dispensing and still holds '.implode(', ', $held).'. Each currency stays with them and is not given to the next attendant.',
                ShiftException::WARNING,
                $by,
            );
        }
    }

    private function attendant(Shift $shift, string $attendantId): Attendant
    {
        $attendant = Attendant::query()->whereKey($attendantId)->where('active', true)->first();
        $rostered = $attendant !== null && AttendantStation::query()
            ->where('attendant_id', $attendant->id)
            ->where('branch_id', $shift->branch_id)
            ->where('active', true)
            ->exists();
        if ($attendant === null || ! $rostered) {
            throw ValidationException::withMessages(['incoming_attendant_id' => 'Choose an attendant of this station.']);
        }

        return $attendant;
    }

    private function mustBeActive(ShiftAssignment $assignment): void
    {
        if (! $assignment->isActive()) {
            throw ValidationException::withMessages(['assignment' => 'That assignment is already '.$assignment->status.'.']);
        }
    }

    private function lockShift(string $shiftId): Shift
    {
        return Shift::query()->whereKey($shiftId)->lockForUpdate()->firstOrFail();
    }

    private function lockAssignment(ShiftAssignment $assignment): ShiftAssignment
    {
        return ShiftAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
    }

    private function lockHandover(AttendantHandover $handover): AttendantHandover
    {
        return AttendantHandover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();
    }

    private function openShift(Shift $shift): void
    {
        if ($shift->status !== Shift::OPEN) {
            throw ValidationException::withMessages(['shift' => 'This shift is '.$shift->status.' and cannot take that step.']);
        }
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
