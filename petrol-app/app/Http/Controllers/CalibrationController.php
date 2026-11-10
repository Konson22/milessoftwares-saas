<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelDraw;
use App\Models\Nozzle;
use App\Models\NozzleEvent;
use App\Models\PumpCalibration;
use App\Models\Tank;
use App\Petrol\Permissions;
use App\Petrol\PumpCare;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CalibrationController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        abort_unless($this->canHere(Permissions::CALIBRATION_RECORD) || $this->canHere(Permissions::EQUIPMENT_MANAGE) || $this->canHere(Permissions::PUMPS_VIEW), 403);
        $branchId = $this->branchId();

        return Inertia::render('calibration/index', [
            'calibrations' => PumpCalibration::query()->where('branch_id', $branchId)->latest()->limit(20)->get()->map(fn (PumpCalibration $row) => [
                'id' => $row->id,
                'on' => $row->calibrated_on->toDateString(),
                'technician' => $row->technician,
                'indicated' => Volume::toLitres($row->indicated_milli),
                'measured' => Volume::toLitres($row->measured_milli),
                'difference' => Volume::toLitres($row->difference_milli),
                'outcome' => $row->outcome,
                'nextDue' => $row->next_due?->toDateString(),
            ]),
            'draws' => FuelDraw::query()->where('branch_id', $branchId)->latest()->limit(20)->get()->map(fn (FuelDraw $row) => [
                'id' => $row->id,
                'litres' => Volume::toLitres($row->quantity_milli),
                'status' => $row->status,
                'purpose' => $row->purpose,
            ]),
            'events' => NozzleEvent::query()->where('branch_id', $branchId)->latest()->limit(20)->get()->map(fn (NozzleEvent $row) => [
                'type' => $row->type,
                'previous' => Volume::toLitres($row->previous_milli),
                'next' => Volume::toLitres($row->next_milli),
                'at' => $row->effective_at->toIso8601String(),
            ]),
            'nozzles' => Nozzle::query()->where('branch_id', $branchId)->orderBy('code')->get(['id', 'code', 'name']),
            'tanks' => Tank::query()->where('branch_id', $branchId)->where('status', Tank::ACTIVE)->orderBy('code')->get(['id', 'code']),
            'canRecord' => $this->canHere(Permissions::CALIBRATION_RECORD),
            'canApprove' => $this->canHere(Permissions::CALIBRATION_APPROVE),
            'canEquipment' => $this->canHere(Permissions::EQUIPMENT_MANAGE),
        ]);
    }

    public function store(Request $request, PumpCare $care): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::CALIBRATION_RECORD), 403);
        $validated = $request->validate([
            'nozzle_id' => ['required', 'uuid'],
            'calibrated_on' => ['required', 'date'],
            'technician' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:64'],
            'test' => ['required', Volume::rule()],
            'indicated' => ['required', Volume::rule()],
            'measured' => ['required', Volume::rule()],
            'outcome' => ['required', Rule::in([PumpCalibration::PASS, PumpCalibration::FAIL])],
            'certificate_note' => ['nullable', 'string', 'max:255'],
            'next_due' => ['nullable', 'date'],
        ]);
        $nozzle = $this->nozzle($validated['nozzle_id']);
        $care->calibrate($nozzle, [
            'calibrated_on' => $validated['calibrated_on'],
            'technician' => trim($validated['technician']),
            'reference' => $this->text($validated['reference'] ?? null),
            'test' => $validated['test'],
            'indicated' => $validated['indicated'],
            'measured' => $validated['measured'],
            'outcome' => $validated['outcome'],
            'certificate_note' => $this->text($validated['certificate_note'] ?? null),
            'next_due' => $validated['next_due'] ?? null,
        ], $this->member($request));

        return back()->with('success', 'Pump calibration recorded. It is not a customer sale.');
    }

    public function draw(Request $request, PumpCare $care): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::CALIBRATION_RECORD), 403);
        $validated = $request->validate([
            'nozzle_id' => ['required', 'uuid'],
            'tank_id' => ['required', 'uuid'],
            'litres' => ['required', Volume::rule()],
        ]);
        $nozzle = $this->nozzle($validated['nozzle_id']);
        $tank = Tank::query()->whereKey($validated['tank_id'])->where('branch_id', $nozzle->branch_id)->first();
        abort_if($tank === null, 422);
        $care->testDraw($nozzle, $tank, $validated['litres'], FuelDraw::TEST, $this->member($request));

        return back()->with('success', 'Test dispensing recorded. Stock moves when a supervisor approves it.');
    }

    public function approveDraw(Request $request, FuelDraw $draw, PumpCare $care): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::CALIBRATION_APPROVE), 403);
        $this->ensureHere($draw->branch_id);
        $care->approveDraw($draw, $this->member($request));

        return back()->with('success', 'Test fuel taken from the tank once.');
    }

    public function returnDraw(Request $request, FuelDraw $draw, PumpCare $care): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::CALIBRATION_APPROVE), 403);
        $this->ensureHere($draw->branch_id);
        $care->returnDraw($draw, $this->member($request));

        return back()->with('success', 'Test fuel returned to the tank once.');
    }

    public function event(Request $request, PumpCare $care): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::EQUIPMENT_MANAGE), 403);
        $validated = $request->validate([
            'nozzle_id' => ['required', 'uuid'],
            'type' => ['required', Rule::in([NozzleEvent::REPLACEMENT, NozzleEvent::RESET, NozzleEvent::ROLLOVER, NozzleEvent::CALIBRATION])],
            'previous' => ['required', Volume::rule()],
            'next' => ['required', Volume::rule()],
            'technician' => ['required', 'string', 'max:255'],
            'evidence' => ['nullable', 'string', 'max:255'],
        ]);
        $care->equipment($this->nozzle($validated['nozzle_id']), [
            'type' => $validated['type'],
            'previous' => $validated['previous'],
            'next' => $validated['next'],
            'technician' => trim($validated['technician']),
            'evidence' => $this->text($validated['evidence'] ?? null),
        ], $this->member($request));

        return back()->with('success', 'Equipment event recorded. Past sales were not recalculated.');
    }

    private function nozzle(string $id): Nozzle
    {
        $nozzle = Nozzle::query()->whereKey($id)->firstOrFail();
        $this->ensureHere($nozzle->branch_id);

        return $nozzle;
    }
}
