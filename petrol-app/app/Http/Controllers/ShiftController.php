<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\AttendantHandover;
use App\Models\AttendantStation;
use App\Models\FuelReceipt;
use App\Models\MeterReading;
use App\Models\Nozzle;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftException;
use App\Models\ShiftSchedule;
use App\Models\Tank;
use App\Petrol\Currencies;
use App\Petrol\Permissions;
use App\Petrol\Reconciliation;
use App\Petrol\Shifts;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $branchId = $this->branchId();

        return Inertia::render('shifts/index', [
            'shifts' => Shift::query()->where('branch_id', $branchId)->latest('opened_at')->limit(40)->get()->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'number' => $shift->number,
                'status' => $shift->status,
                'businessDate' => $shift->business_date->toDateString(),
                'openedAt' => $shift->opened_at->toIso8601String(),
            ]),
            'schedules' => ShiftSchedule::query()->where('branch_id', $branchId)->where('active', true)->orderBy('name')->get(['id', 'name', 'starts_at', 'ends_at']),
            'canOpen' => $this->canHere(Permissions::SHIFTS_OPEN),
            'currencies' => app(Currencies::class)->accepted($branchId),
            'books' => Money::currency(),
        ]);
    }

    public function show(Shift $shift, Reconciliation $reconciliation): Response
    {
        $this->ensureHere($shift->branch_id);
        $detail = $reconciliation->detail($shift);
        $branchId = $shift->branch_id;

        return Inertia::render('shifts/show', [
            'shift' => [
                'id' => $shift->id,
                'number' => $shift->number,
                'status' => $shift->status,
                'businessDate' => $shift->business_date->toDateString(),
                'openingFloatMinor' => $shift->opening_float_minor,
                'countedDrawerMinor' => $shift->counted_drawer_minor,
                'expectedCashMinor' => $shift->expected_cash_minor,
                'cashDifferenceMinor' => $shift->cash_difference_minor,
                'closingNote' => $shift->closing_note,
                'rejectionReason' => $shift->rejection_reason,
                'submittedBy' => $shift->submitted_by,
            ],
            'detail' => $this->present($detail),
            'attendants' => AttendantStation::query()->where('branch_id', $branchId)->where('active', true)->with('attendant')->get()
                ->filter(fn (AttendantStation $row) => $row->attendant->active)
                ->map(fn (AttendantStation $row) => ['id' => $row->attendant->id, 'name' => $row->attendant->name])->values(),
            'nozzles' => Nozzle::query()->where('branch_id', $branchId)->where('status', Nozzle::ACTIVE)->orderBy('code')->get(['id', 'name', 'code']),
            'canOpen' => $this->canHere(Permissions::SHIFTS_OPEN),
            'canClose' => $this->canHere(Permissions::SHIFTS_CLOSE),
            'canApprove' => $this->canHere(Permissions::SHIFTS_APPROVE),
            'canCorrect' => $this->canHere(Permissions::METERS_CORRECT),
            'currencies' => app(Currencies::class)->accepted($branchId),
            'books' => Money::currency(),
            'accountability' => [
                'attendants' => ShiftAssignment::query()->where('shift_id', $shift->id)->distinct()->count('attendant_id'),
                'assignments' => ShiftAssignment::query()->where('shift_id', $shift->id)->count(),
                'openAssignments' => ShiftAssignment::query()->where('shift_id', $shift->id)->whereNull('ended_at')->where('status', ShiftAssignment::ACTIVE)->count(),
                'pendingHandovers' => AttendantHandover::query()->where('shift_id', $shift->id)->whereIn('status', [AttendantHandover::REQUESTED, AttendantHandover::VERIFIED])->count(),
                'completedHandovers' => AttendantHandover::query()->where('shift_id', $shift->id)->where('status', AttendantHandover::APPROVED)->count(),
                'openExceptions' => ShiftException::query()->where('shift_id', $shift->id)->whereIn('status', [ShiftException::OPEN, ShiftException::UNDER_REVIEW])->count(),
                'pendingDeliveries' => FuelReceipt::query()->where('branch_id', $branchId)->where('status', FuelReceipt::PENDING)->count(),
                'waterAlerts' => Tank::query()->where('branch_id', $branchId)->where('water_alert', true)->count(),
            ],
        ]);
    }

    public function assignments(): RedirectResponse|Response
    {
        $shift = Shift::query()->where('branch_id', $this->branchId())->where('status', Shift::OPEN)->first();

        if ($shift === null) {
            return Inertia::render('assignments/index', ['shift' => null]);
        }

        return redirect()->route('shifts.show', $shift);
    }

    public function store(Request $request, Shifts $shifts): RedirectResponse
    {
        $validated = $request->validate([
            'opening_float' => ['required', Money::rule()],
            'schedule_id' => ['nullable', 'uuid'],
            'handover_note' => ['nullable', 'string', 'max:255'],
            'openings' => ['nullable', 'array'],
            'openings.*.nozzle_id' => ['required', 'uuid'],
            'openings.*.reading' => ['required', Volume::rule()],
            'foreign_floats' => ['nullable', 'array'],
            'foreign_floats.*.currency' => ['required', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'foreign_floats.*.amount' => ['required', 'string', 'max:16'],
        ]);

        $shift = $shifts->open(
            $this->station(),
            $this->member($request),
            Money::toMinor($validated['opening_float']),
            $this->text($validated['schedule_id'] ?? null),
            $this->text($validated['handover_note'] ?? null),
            $validated['openings'] ?? [],
            $validated['foreign_floats'] ?? [],
        );

        return to_route('shifts.show', $shift)->with('success', "Shift {$shift->number} opened.");
    }

    public function assign(Request $request, Shift $shift, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        $validated = $request->validate([
            'nozzle_id' => ['required', 'uuid'],
            'attendant_id' => ['required', 'uuid'],
        ]);
        $shifts->assign($shift, $validated['attendant_id'], $validated['nozzle_id'], $this->member($request));

        return back()->with('success', 'Nozzle assigned.');
    }

    public function unassign(Request $request, Shift $shift, ShiftAssignment $assignment, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        abort_unless($assignment->shift_id === $shift->id, 404);
        $shifts->unassign($assignment, $this->member($request));

        return back()->with('success', 'Assignment removed.');
    }

    public function submit(Request $request, Shift $shift, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        $validated = $request->validate([
            'counted_drawer' => ['required', Money::rule()],
            'note' => ['nullable', 'string', 'max:255'],
            'closings' => ['required', 'array', 'min:1'],
            'closings.*.nozzle_id' => ['required', 'uuid'],
            'closings.*.reading' => ['required', Volume::rule()],
            'counts' => ['nullable', 'array'],
            'counts.*.id' => ['required', 'uuid'],
            'counts.*.amount' => ['required', Money::rule()],
            'foreign_counts' => ['nullable', 'array'],
            'foreign_counts.*.currency' => ['required', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'foreign_counts.*.amount' => ['required', Money::rule()],
        ]);

        $shifts->submit(
            $shift,
            $this->member($request),
            $validated['counted_drawer'],
            $validated['closings'],
            $validated['counts'] ?? [],
            $this->text($validated['note'] ?? null),
            $validated['foreign_counts'] ?? [],
        );

        return back()->with('success', 'Closing submitted.');
    }

    public function review(Request $request, Shift $shift, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        $shifts->review($shift, $this->member($request));

        return back()->with('success', 'Shift is under review.');
    }

    public function approve(Request $request, Shift $shift, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        $validated = $request->validate(['exception_note' => ['nullable', 'string', 'max:255']]);
        $shifts->approve($shift, $this->member($request), $validated['exception_note'] ?? null);

        return back()->with('success', 'Shift approved.');
    }

    public function reject(Request $request, Shift $shift, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $shifts->reject($shift, $this->member($request), $validated['reason']);

        return back()->with('success', 'Shift rejected.');
    }

    public function reopen(Request $request, Shift $shift, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        $shifts->reopen($shift, $this->member($request));

        return back()->with('success', 'Shift reopened.');
    }

    public function correct(Request $request, Shift $shift, MeterReading $reading, Shifts $shifts): RedirectResponse
    {
        $this->ensureHere($shift->branch_id);
        abort_unless($reading->shift_id === $shift->id, 404);
        $validated = $request->validate([
            'reading' => ['required', Volume::rule()],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $shifts->correct($reading, Volume::toMilli($validated['reading']), $this->member($request), $validated['reason']);

        return back()->with('success', 'Meter reading corrected.');
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function present(array $detail): array
    {
        $detail['nozzles'] = array_map(function (array $row): array {
            $row['openingLitres'] = Volume::toLitres($row['opening']);
            $row['closingLitres'] = $row['closing'] === null ? null : Volume::toLitres($row['closing']);
            $row['dispensedLitres'] = $row['dispensed'] === null ? null : Volume::toLitres($row['dispensed']);
            $row['soldLitres'] = Volume::toLitres($row['sold']);
            $row['varianceLitres'] = $row['variance'] === null ? null : Volume::toLitres($row['variance']);

            return $row;
        }, $detail['nozzles']);

        return $detail;
    }
}
