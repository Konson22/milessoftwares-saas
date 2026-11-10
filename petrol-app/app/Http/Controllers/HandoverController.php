<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\AttendantHandover;
use App\Models\AttendantStation;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftBreak;
use App\Models\ShiftException;
use App\Petrol\Accountability;
use App\Petrol\Permissions;
use App\Petrol\ShiftExceptions;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HandoverController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        abort_unless($this->canHere(Permissions::ASSIGNMENTS_VIEW) || $this->canHere(Permissions::HANDOVERS_INITIATE) || $this->canHere(Permissions::SHIFTS_VIEW), 403);
        $branchId = $this->branchId();
        $shift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->first();
        $assignments = $shift === null ? collect() : ShiftAssignment::query()->with(['attendant', 'nozzle.pump'])->where('shift_id', $shift->id)->orderBy('started_at')->get();

        return Inertia::render('handovers/index', [
            'shift' => $shift === null ? null : ['id' => $shift->id, 'number' => $shift->number],
            'assignments' => $assignments->map(fn (ShiftAssignment $row) => [
                'id' => $row->id,
                'attendant' => $row->attendant->name,
                'attendantId' => $row->attendant_id,
                'pump' => $row->nozzle->pump->code,
                'nozzle' => $row->nozzle->code,
                'status' => $row->status,
                'startedAt' => $row->started_at?->toIso8601String(),
                'endedAt' => $row->ended_at?->toIso8601String(),
                'opening' => $row->opening_milli === null ? null : Volume::toLitres($row->opening_milli),
                'closing' => $row->closing_milli === null ? null : Volume::toLitres($row->closing_milli),
                'active' => $row->isActive(),
            ]),
            'handovers' => AttendantHandover::query()->with(['nozzle', 'incomingAttendant'])->where('branch_id', $branchId)->latest()->limit(30)->get()->map(fn (AttendantHandover $row) => [
                'id' => $row->id,
                'nozzle' => $row->nozzle->code,
                'status' => $row->status,
                'incoming' => $row->incomingAttendant->name,
                'closing' => Volume::toLitres($row->closing_milli),
                'opening' => Volume::toLitres($row->opening_milli),
                'event' => $row->event_reason,
            ]),
            'breaks' => ShiftBreak::query()->where('branch_id', $branchId)->latest()->limit(20)->get(['id', 'reason', 'ended_at', 'nozzle_available']),
            'attendants' => AttendantStation::query()->where('branch_id', $branchId)->where('active', true)->with('attendant')->get()
                ->filter(fn (AttendantStation $row) => $row->attendant->active)
                ->map(fn (AttendantStation $row) => ['id' => $row->attendant->id, 'name' => $row->attendant->name])->values(),
            'canInitiate' => $this->canHere(Permissions::HANDOVERS_INITIATE),
            'canConfirm' => $this->canHere(Permissions::HANDOVERS_CONFIRM),
            'canApprove' => $this->canHere(Permissions::HANDOVERS_APPROVE),
            'canReassign' => $this->canHere(Permissions::ASSIGNMENTS_REASSIGN),
        ]);
    }

    public function store(Request $request, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::HANDOVERS_INITIATE), 403);
        $validated = $request->validate([
            'assignment_id' => ['required', 'uuid'],
            'incoming_attendant_id' => ['required', 'uuid'],
            'closing' => ['required', Volume::rule()],
            'opening' => ['required', Volume::rule()],
            'event_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $assignment = $this->assignment($validated['assignment_id']);
        $accountability->requestHandover(
            $assignment,
            $validated['incoming_attendant_id'],
            Volume::toMilli($validated['closing']),
            Volume::toMilli($validated['opening']),
            $validated['event_reason'] ?? null,
            $this->member($request),
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Handover requested. The outgoing attendant still holds their own cash.');
    }

    public function confirm(Request $request, AttendantHandover $handover, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::HANDOVERS_CONFIRM), 403);
        $this->ensureHere($handover->branch_id);
        $accountability->confirmHandover($handover, $this->member($request));

        return back()->with('success', 'Opening reading verified.');
    }

    public function approve(Request $request, AttendantHandover $handover, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::HANDOVERS_APPROVE), 403);
        $this->ensureHere($handover->branch_id);
        $accountability->approveHandover($handover, $this->member($request));

        return back()->with('success', 'Handover approved. The shift is still open.');
    }

    public function reject(Request $request, AttendantHandover $handover, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::HANDOVERS_APPROVE), 403);
        $this->ensureHere($handover->branch_id);
        $validated = $request->validate(['notes' => ['required', 'string', 'max:255']]);
        $accountability->rejectHandover($handover, $this->member($request), $validated['notes']);

        return back()->with('success', 'Handover rejected. The assignment is unchanged.');
    }

    public function reassign(Request $request, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::ASSIGNMENTS_REASSIGN), 403);
        $validated = $request->validate([
            'assignment_id' => ['required', 'uuid'],
            'incoming_attendant_id' => ['required', 'uuid'],
            'closing' => ['required', Volume::rule()],
            'opening' => ['required', Volume::rule()],
            'event_reason' => ['nullable', 'string', 'max:255'],
        ]);
        $accountability->reassign(
            $this->assignment($validated['assignment_id']),
            $validated['incoming_attendant_id'],
            Volume::toMilli($validated['closing']),
            Volume::toMilli($validated['opening']),
            $validated['event_reason'] ?? null,
            $this->member($request),
        );

        return back()->with('success', 'Attendant reassigned. Earlier sales stay with the first attendant.');
    }

    public function depart(Request $request, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::ASSIGNMENTS_REASSIGN), 403);
        $validated = $request->validate([
            'assignment_id' => ['required', 'uuid'],
            'closing' => ['required', Volume::rule()],
        ]);
        $accountability->depart($this->assignment($validated['assignment_id']), Volume::toMilli($validated['closing']), $this->member($request));

        return back()->with('success', 'The attendant has stopped dispensing. The station shift is still open.');
    }

    public function startBreak(Request $request, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::ASSIGNMENTS_REASSIGN), 403);
        $validated = $request->validate([
            'assignment_id' => ['required', 'uuid'],
            'reading' => ['required', Volume::rule()],
            'reason' => ['required', 'string', 'max:32'],
            'nozzle_available' => ['boolean'],
            'replacement_id' => ['nullable', 'uuid'],
        ]);
        $accountability->startBreak(
            $this->assignment($validated['assignment_id']),
            Volume::toMilli($validated['reading']),
            $validated['reason'],
            (bool) ($validated['nozzle_available'] ?? false),
            $validated['replacement_id'] ?? null,
            $this->member($request),
        );

        return back()->with('success', 'Break started. Fuel during the gap is not given to the absent attendant.');
    }

    public function endBreak(Request $request, ShiftBreak $shiftBreak, Accountability $accountability): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::ASSIGNMENTS_REASSIGN), 403);
        $this->ensureHere($shiftBreak->branch_id);
        $validated = $request->validate(['reading' => ['required', Volume::rule()]]);
        $accountability->endBreak($shiftBreak, Volume::toMilli($validated['reading']), $this->member($request));

        return back()->with('success', 'The attendant is back on a new assignment.');
    }

    public function exceptions(): Response
    {
        abort_unless($this->canHere(Permissions::EXCEPTIONS_REVIEW) || $this->canHere(Permissions::SHIFTS_VIEW), 403);
        $branchId = $this->branchId();

        return Inertia::render('exceptions/index', [
            'exceptions' => ShiftException::query()->where('branch_id', $branchId)->latest()->limit(40)->get()->map(fn (ShiftException $row) => [
                'id' => $row->id,
                'type' => $row->type,
                'description' => $row->description,
                'severity' => $row->severity,
                'status' => $row->status,
                'resolution' => $row->resolution,
                'shiftId' => $row->shift_id,
            ]),
            'shifts' => Shift::query()->where('branch_id', $branchId)->latest('opened_at')->limit(10)->get(['id', 'number']),
            'canReview' => $this->canHere(Permissions::EXCEPTIONS_REVIEW),
            'canResolve' => $this->canHere(Permissions::EXCEPTIONS_RESOLVE),
        ]);
    }

    public function storeException(Request $request, ShiftExceptions $exceptions): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::EXCEPTIONS_REVIEW) || $this->canHere(Permissions::HANDOVERS_INITIATE), 403);
        $validated = $request->validate([
            'shift_id' => ['required', 'uuid'],
            'type' => ['required', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:250'],
            'severity' => ['required', 'in:warning,critical'],
            'assignment_id' => ['nullable', 'uuid'],
        ]);
        $shift = Shift::query()->whereKey($validated['shift_id'])->firstOrFail();
        $this->ensureHere($shift->branch_id);
        $exceptions->open($shift, $validated['assignment_id'] ?? null, null, $validated['type'], $validated['description'], $validated['severity'], $this->member($request));

        return back()->with('success', 'Exception recorded.');
    }

    public function reviewException(Request $request, ShiftException $exception, ShiftExceptions $exceptions): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::EXCEPTIONS_REVIEW), 403);
        $this->ensureHere($exception->branch_id);
        $exceptions->review($exception, $this->member($request));

        return back();
    }

    public function resolveException(Request $request, ShiftException $exception, ShiftExceptions $exceptions): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::EXCEPTIONS_RESOLVE), 403);
        $this->ensureHere($exception->branch_id);
        $validated = $request->validate(['resolution' => ['required', 'string', 'max:250']]);
        $exceptions->resolve($exception, $this->member($request), $validated['resolution']);

        return back()->with('success', 'Exception resolved. It stays on the record.');
    }

    public function closeException(Request $request, ShiftException $exception, ShiftExceptions $exceptions): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::EXCEPTIONS_RESOLVE), 403);
        $this->ensureHere($exception->branch_id);
        $exceptions->close($exception, $this->member($request));

        return back();
    }

    public function reopenException(Request $request, ShiftException $exception, ShiftExceptions $exceptions): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::EXCEPTIONS_RESOLVE), 403);
        $this->ensureHere($exception->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $exceptions->reopen($exception, $this->member($request), $validated['reason']);

        return back()->with('success', 'Exception reopened.');
    }

    private function assignment(string $id): ShiftAssignment
    {
        $assignment = ShiftAssignment::query()->whereKey($id)->firstOrFail();
        $shift = Shift::query()->whereKey($assignment->shift_id)->firstOrFail();
        $this->ensureHere($shift->branch_id);

        return $assignment;
    }
}
