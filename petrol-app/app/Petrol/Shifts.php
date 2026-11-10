<?php

namespace App\Petrol;

use App\Models\Attendant;
use App\Models\AttendantHandover;
use App\Models\AttendantStation;
use App\Models\CashMovement;
use App\Models\FuelSale;
use App\Models\MeterReading;
use App\Models\Nozzle;
use App\Models\NozzleEvent;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftException;
use App\Models\ShiftSchedule;
use App\Models\Station;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\DocumentNumbers;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Shift lifecycle. The person who submitted the closing cannot approve it.
 */
class Shifts
{
    public function __construct(
        private readonly Reconciliation $reconciliation,
        private readonly Cashbook $cash,
        private readonly Outbox $outbox,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param  list<array{nozzle_id: string, reading: string}>  $openings
     * @param  list<array{currency?: string, amount?: string}>  $foreignFloats
     */
    public function open(Station $station, User $by, int $float, ?string $scheduleId, ?string $handoverNote, array $openings, array $foreignFloats = []): Shift
    {
        try {
            return DB::transaction(function () use ($station, $by, $float, $scheduleId, $handoverNote, $openings, $foreignFloats) {
                if (Shift::query()->where('branch_id', $station->branch_id)->where('status', Shift::OPEN)->exists()) {
                    throw ValidationException::withMessages(['opening_float' => 'This station already has an open shift.']);
                }

                if ($scheduleId !== null) {
                    $schedule = ShiftSchedule::query()->whereKey($scheduleId)->where('branch_id', $station->branch_id)->where('active', true)->first();
                    if ($schedule === null) {
                        throw ValidationException::withMessages(['schedule_id' => 'Choose a shift schedule of this station.']);
                    }
                }

                $shift = Shift::query()->create([
                    'branch_id' => $station->branch_id,
                    'schedule_id' => $scheduleId,
                    'number' => $this->numbers->next('shift', 'SH'),
                    'business_date' => BusinessDate::today(),
                    'status' => Shift::OPEN,
                    'opening_float_minor' => $float,
                    'opened_by' => $by->id,
                    'opened_at' => now(),
                    'handover_note' => $handoverNote,
                ]);

                $this->cash->move($station->branch_id, $shift->id, CashMovement::FLOAT, $float, $by, 'shift:'.$shift->id.':float', $handoverNote);
                foreach ($foreignFloats as $row) {
                    $code = strtoupper((string) ($row['currency'] ?? ''));
                    app(Currencies::class)->assertAccepted($station->branch_id, $code);
                    if ($code === '' || $code === Money::currency()) {
                        continue;
                    }
                    try {
                        $minor = Money::toMinor((string) ($row['amount'] ?? '0'), $code);
                    } catch (\InvalidArgumentException $e) {
                        throw ValidationException::withMessages(['foreign_floats' => $e->getMessage()]);
                    }
                    if ($minor < 1) {
                        continue;
                    }
                    $this->cash->move($station->branch_id, $shift->id, CashMovement::FLOAT, $minor, $by, 'shift:'.$shift->id.':float:'.$code, $handoverNote, [], $code);
                }

                $given = [];
                foreach ($openings as $opening) {
                    try {
                        $given[$opening['nozzle_id']] = Volume::toMilli($opening['reading']);
                    } catch (\InvalidArgumentException $e) {
                        throw ValidationException::withMessages(['openings' => $e->getMessage()]);
                    }
                }

                $nozzles = Nozzle::query()
                    ->where('branch_id', $station->branch_id)
                    ->where('status', Nozzle::ACTIVE)
                    ->whereHas('currentConnection')
                    ->get();

                foreach ($nozzles as $nozzle) {
                    $reading = $given[$nozzle->id] ?? $this->lastReading($nozzle->id);
                    $this->writeReading($shift, $nozzle, MeterReading::OPENING, $reading, $by);
                }

                $this->audit->record($by, 'shift.opened', $shift, null, ['number' => $shift->number], $station->branch_id);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['opening_float' => 'This station already has an open shift.']);
        }
    }

    public function assign(Shift $shift, string $attendantId, string $nozzleId, User $by): ShiftAssignment
    {
        return DB::transaction(function () use ($shift, $attendantId, $nozzleId, $by) {
            $locked = $this->lock($shift);
            $this->mustBe($locked, [Shift::OPEN]);

            $attendant = Attendant::query()->whereKey($attendantId)->where('active', true)->first();
            $rostered = $attendant !== null && AttendantStation::query()
                ->where('attendant_id', $attendant->id)
                ->where('branch_id', $locked->branch_id)
                ->where('active', true)
                ->exists();
            if ($attendant === null || ! $rostered) {
                throw ValidationException::withMessages(['attendant_id' => 'Choose an attendant of this station.']);
            }

            $nozzle = Nozzle::query()->whereKey($nozzleId)->where('branch_id', $locked->branch_id)->where('status', Nozzle::ACTIVE)->first();
            if ($nozzle === null) {
                throw ValidationException::withMessages(['nozzle_id' => 'Choose an active nozzle.']);
            }

            $open = ShiftAssignment::query()
                ->where('shift_id', $locked->id)
                ->where('nozzle_id', $nozzle->id)
                ->whereNull('ended_at')
                ->where('status', ShiftAssignment::ACTIVE)
                ->get();
            if ($open->contains(fn (ShiftAssignment $row) => $row->attendant_id === $attendant->id)) {
                throw ValidationException::withMessages(['attendant_id' => 'That attendant is already on this nozzle.']);
            }
            $shared = (bool) Station::query()->where('branch_id', $locked->branch_id)->value('shared_pumps');
            if ($open->isNotEmpty() && ! $shared) {
                throw ValidationException::withMessages(['nozzle_id' => 'That nozzle already has an attendant this shift.']);
            }

            if (! MeterReading::query()->where('shift_id', $locked->id)->where('nozzle_id', $nozzle->id)->where('kind', MeterReading::OPENING)->exists()) {
                $this->writeReading($locked, $nozzle, MeterReading::OPENING, $this->lastReading($nozzle->id), $by);
            }

            $assignment = ShiftAssignment::query()->create([
                'branch_id' => $locked->branch_id,
                'shift_id' => $locked->id,
                'pump_id' => $nozzle->pump_id,
                'attendant_id' => $attendant->id,
                'nozzle_id' => $nozzle->id,
                'status' => ShiftAssignment::ACTIVE,
                'started_at' => now(),
                'opening_milli' => $this->lastReading($nozzle->id),
                'assigned_by' => $by->id,
            ]);

            $this->audit->record($by, 'shift.assigned', $assignment, null, [
                'attendant_id' => $attendant->id,
                'nozzle_id' => $nozzle->id,
            ], $locked->branch_id);

            return $assignment;
        });
    }

    public function unassign(ShiftAssignment $assignment, User $by): void
    {
        DB::transaction(function () use ($assignment, $by) {
            $shift = $this->lock($assignment->shift);
            $this->mustBe($shift, [Shift::OPEN]);

            $sold = FuelSale::query()
                ->where('shift_id', $shift->id)
                ->where('nozzle_id', $assignment->nozzle_id)
                ->where('attendant_id', $assignment->attendant_id)
                ->where('status', FuelSale::POSTED)
                ->when($assignment->started_at !== null, fn ($query) => $query->where('sold_at', '>=', $assignment->started_at))
                ->exists();
            if ($sold) {
                throw ValidationException::withMessages(['assignment' => 'This nozzle already has sales on the shift.']);
            }

            $this->audit->record($by, 'shift.unassigned', $assignment, [
                'attendant_id' => $assignment->attendant_id,
                'nozzle_id' => $assignment->nozzle_id,
            ], null, $shift->branch_id);
            $assignment->delete();
        });
    }

    public function correct(MeterReading $reading, int $milli, string $reason, User $by): MeterReading
    {
        return DB::transaction(function () use ($reading, $milli, $reason, $by) {
            $shift = $this->lock($reading->shift);
            if ($shift->isApproved()) {
                throw ValidationException::withMessages(['reading' => 'An approved shift cannot be changed.']);
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Give a reason.']);
            }

            $opening = MeterReading::query()->where('shift_id', $shift->id)->where('nozzle_id', $reading->nozzle_id)->where('kind', MeterReading::OPENING)->first();
            $closing = MeterReading::query()->where('shift_id', $shift->id)->where('nozzle_id', $reading->nozzle_id)->where('kind', MeterReading::CLOSING)->first();

            if ($reading->kind === MeterReading::OPENING && $closing !== null && $milli > $closing->reading_milli) {
                throw ValidationException::withMessages(['reading' => 'The opening reading cannot pass the closing reading.']);
            }
            if ($reading->kind === MeterReading::CLOSING && $opening !== null && $milli < $opening->reading_milli) {
                throw ValidationException::withMessages(['reading' => 'The closing reading cannot be below the opening reading.']);
            }

            $before = $reading->reading_milli;
            $reading->forceFill(['reading_milli' => $milli, 'recorded_by' => $by->id])->save();
            $this->audit->record($by, 'meter.corrected', $reading, ['reading_milli' => $before], [
                'reading_milli' => $milli,
                'reason' => $reason,
            ], $shift->branch_id);

            return $reading;
        });
    }

    /**
     * @param  list<array{nozzle_id: string, reading: string}>  $closings
     * @param  list<array{id: string, amount: string}>  $counts
     * @param  list<array{currency?: string, amount: string}>  $foreignCounts
     */
    public function submit(Shift $shift, User $by, string $countedDrawer, array $closings, array $counts, ?string $note, array $foreignCounts = []): Shift
    {
        return DB::transaction(function () use ($shift, $by, $countedDrawer, $closings, $counts, $note, $foreignCounts) {
            $locked = $this->lock($shift);
            $this->mustBe($locked, [Shift::OPEN, Shift::REJECTED]);

            $openings = MeterReading::query()->where('shift_id', $locked->id)->where('kind', MeterReading::OPENING)->get();
            $given = [];
            foreach ($closings as $closing) {
                try {
                    $given[$closing['nozzle_id']] = Volume::toMilli($closing['reading']);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['closings' => $e->getMessage()]);
                }
            }

            foreach (ShiftAssignment::query()->where('shift_id', $locked->id)->whereNull('ended_at')->where('status', ShiftAssignment::ACTIVE)->get() as $assignment) {
                $floor = $this->meterFloor($locked->id, $assignment->nozzle_id, $assignment->opening_milli);
                if (isset($given[$assignment->nozzle_id]) && $floor !== null && $given[$assignment->nozzle_id] < $floor) {
                    throw ValidationException::withMessages(['closings' => 'A closing reading cannot be below the assignment opening.']);
                }
            }

            foreach ($openings as $opening) {
                if (! isset($given[$opening->nozzle_id])) {
                    throw ValidationException::withMessages(['closings' => 'Every nozzle needs a closing reading.']);
                }
                $floor = $this->meterFloor($locked->id, $opening->nozzle_id, $opening->reading_milli);
                if ($given[$opening->nozzle_id] < $floor) {
                    throw ValidationException::withMessages(['closings' => 'A closing reading cannot be below its opening reading.']);
                }

                MeterReading::query()->updateOrCreate(
                    ['shift_id' => $locked->id, 'nozzle_id' => $opening->nozzle_id, 'kind' => MeterReading::CLOSING],
                    [
                        'branch_id' => $locked->branch_id,
                        'reading_milli' => $given[$opening->nozzle_id],
                        'recorded_by' => $by->id,
                        'recorded_at' => now(),
                    ],
                );
            }

            $countById = [];
            foreach ($counts as $count) {
                try {
                    $countById[$count['id']] = Money::toMinor($count['amount']);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['counts' => $e->getMessage()]);
                }
            }

            $locked->load(['assignments', 'sales.payments']);
            $posted = $locked->sales->filter(fn (FuelSale $sale) => $sale->isPosted());
            $expectedByAssignment = $this->reconciliation->expectedByAssignment($posted, $locked);
            foreach ($locked->assignments as $assignment) {
                $expected = $expectedByAssignment[$assignment->id] ?? 0;
                if ($expected > 0 && ! isset($countById[$assignment->id])) {
                    throw ValidationException::withMessages(['counts' => 'Count the cash each attendant collected.']);
                }
                if (isset($countById[$assignment->id])) {
                    $assignment->forceFill(['counted_cash_minor' => $countById[$assignment->id]])->save();
                }
            }

            try {
                $drawer = Money::toMinor($countedDrawer);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['counted_drawer' => $e->getMessage()]);
            }

            $byCurrency = [];
            foreach ($foreignCounts as $count) {
                $code = strtoupper((string) ($count['currency'] ?? ''));
                if (! in_array($code, app(Currencies::class)->acceptedCodes($locked->branch_id), true) || $code === Money::currency()) {
                    continue;
                }
                try {
                    $byCurrency[$code] = Money::toMinor((string) $count['amount'], $code);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['foreign_counts' => $e->getMessage()]);
                }
            }

            $before = $locked->status;
            $locked->forceFill([
                'status' => Shift::CLOSING_SUBMITTED,
                'submitted_by' => $by->id,
                'submitted_at' => now(),
                'counted_drawer_minor' => $drawer,
                'counted_by_currency' => $byCurrency === [] ? null : $byCurrency,
                'closing_note' => $note,
            ])->save();

            $this->audit->record($by, 'shift.submitted', $locked, ['status' => $before], ['status' => $locked->status], $locked->branch_id);

            return $locked;
        });
    }

    public function review(Shift $shift, User $by): Shift
    {
        return $this->transition($shift, $by, [Shift::CLOSING_SUBMITTED], Shift::UNDER_REVIEW, 'shift.reviewed', [
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);
    }

    public function approve(Shift $shift, User $by, ?string $exceptionNote = null): Shift
    {
        return DB::transaction(function () use ($shift, $by, $exceptionNote) {
            $locked = $this->lock($shift);
            $this->mustBe($locked, [Shift::UNDER_REVIEW]);

            if ($locked->submitted_by === $by->id) {
                throw ValidationException::withMessages(['shift' => 'Someone else has to approve a closing you submitted.']);
            }

            $pendingHandovers = AttendantHandover::query()
                ->where('shift_id', $locked->id)
                ->whereIn('status', [AttendantHandover::REQUESTED, AttendantHandover::VERIFIED])
                ->count();
            $critical = ShiftException::query()
                ->where('shift_id', $locked->id)
                ->where('severity', ShiftException::CRITICAL)
                ->whereIn('status', [ShiftException::OPEN, ShiftException::UNDER_REVIEW])
                ->count();
            $exceptionNote = trim((string) $exceptionNote);
            if (($pendingHandovers > 0 || $critical > 0) && $exceptionNote === '') {
                throw ValidationException::withMessages(['exception_note' => 'A handover or a critical exception is still open. Approve it with a reason, or resolve it first.']);
            }

            $figures = $this->reconciliation->detail($locked);
            if ($figures['difference_minor'] === null || $figures['counted_minor'] === null) {
                throw ValidationException::withMessages(['shift' => 'The cash count is incomplete.']);
            }
            foreach ($figures['cashByCurrency'] as $row) {
                if ($row['currency'] !== Money::currency() && $row['expected_minor'] !== 0 && $row['counted_minor'] === null) {
                    throw ValidationException::withMessages(['shift' => 'Count the '.$row['currency'].' cash separately.']);
                }
            }

            foreach (ShiftAssignment::query()->where('shift_id', $locked->id)->whereNull('ended_at')->get() as $assignment) {
                $closing = MeterReading::query()->where('shift_id', $locked->id)->where('nozzle_id', $assignment->nozzle_id)->where('kind', MeterReading::CLOSING)->value('reading_milli');
                $assignment->forceFill([
                    'status' => ShiftAssignment::CLOSED,
                    'ended_at' => now(),
                    'closing_milli' => $assignment->closing_milli ?? $closing,
                ])->save();
            }

            $before = $locked->status;
            $locked->forceFill([
                'status' => Shift::APPROVED,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'expected_cash_minor' => $figures['expected_minor'],
                'cash_difference_minor' => $figures['difference_minor'],
            ])->save();

            if ($figures['difference_minor'] !== 0) {
                $this->outbox->record('finance', 'finance.cash.counted', 1, 'shift:'.$locked->id.':count', [
                    'reference' => $locked->number,
                    'branch_id' => $locked->branch_id,
                    'currency' => Money::currency(),
                    'counted_on' => BusinessDate::today(),
                    'expected_minor' => $figures['expected_minor'],
                    'counted_minor' => $figures['counted_minor'],
                    'difference_minor' => $figures['difference_minor'],
                    'counted_by' => $by->name,
                    'note' => $locked->closing_note,
                ]);
            }

            $this->audit->record($by, 'shift.approved', $locked, ['status' => $before], [
                'status' => Shift::APPROVED,
                'difference_minor' => $figures['difference_minor'],
                'exception_note' => $exceptionNote === '' ? null : $exceptionNote,
            ], $locked->branch_id);

            return $locked;
        });
    }

    public function reject(Shift $shift, User $by, string $reason): Shift
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason.']);
        }

        return $this->transition($shift, $by, [Shift::CLOSING_SUBMITTED, Shift::UNDER_REVIEW], Shift::REJECTED, 'shift.rejected', [
            'rejected_by' => $by->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function reopen(Shift $shift, User $by): Shift
    {
        return DB::transaction(function () use ($shift, $by) {
            $locked = $this->lock($shift);
            $this->mustBe($locked, [Shift::REJECTED]);

            if (Shift::query()->where('branch_id', $locked->branch_id)->where('status', Shift::OPEN)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Close the open shift before reopening this one.']);
            }

            return $this->apply($locked, $by, Shift::OPEN, 'shift.reopened', []);
        });
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(Shift $shift, User $by, array $from, string $to, string $action, array $extra): Shift
    {
        return DB::transaction(function () use ($shift, $by, $from, $to, $action, $extra) {
            $locked = $this->lock($shift);
            $this->mustBe($locked, $from);

            return $this->apply($locked, $by, $to, $action, $extra);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function apply(Shift $locked, User $by, string $to, string $action, array $extra): Shift
    {
        $before = $locked->status;
        $locked->forceFill(['status' => $to] + $extra)->save();
        $this->audit->record($by, $action, $locked, ['status' => $before], ['status' => $to] + $extra, $locked->branch_id);

        return $locked;
    }

    private function lock(Shift $shift): Shift
    {
        return Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function mustBe(Shift $shift, array $allowed): void
    {
        if (! in_array($shift->status, $allowed, true)) {
            throw ValidationException::withMessages(['shift' => 'This shift is '.$shift->status.' and cannot take that step.']);
        }
    }

    private function lastReading(string $nozzleId): int
    {
        return (int) (MeterReading::query()->where('nozzle_id', $nozzleId)->orderByDesc('recorded_at')->value('reading_milli') ?? 0);
    }

    /**
     * An approved meter replacement, reset, or rollover lets the next reading start again.
     */
    private function meterFloor(string $shiftId, string $nozzleId, ?int $opening): ?int
    {
        $event = NozzleEvent::query()->where('shift_id', $shiftId)->where('nozzle_id', $nozzleId)->whereNotNull('approved_at')->first();
        if ($event !== null) {
            return (int) $event->next_milli;
        }

        return $opening;
    }

    private function writeReading(Shift $shift, Nozzle $nozzle, string $kind, int $milli, User $by): void
    {
        MeterReading::query()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
            'nozzle_id' => $nozzle->id,
            'kind' => $kind,
            'reading_milli' => $milli,
            'recorded_by' => $by->id,
            'recorded_at' => now(),
        ]);
    }
}
