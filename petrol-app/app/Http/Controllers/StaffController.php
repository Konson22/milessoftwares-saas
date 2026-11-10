<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeesAcrossBranches;
use App\Models\Attendant;
use App\Models\AttendantStation;
use App\Models\FuelSale;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Petrol\Permissions;
use App\Petrol\StationSetup;
use App\Support\Branches;
use App\Support\BusinessDate;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\Branch;

/**
 * The company's pump attendants in one place: the stations each works at,
 * who is on a pump right now, and what each sold today. Their sign-in
 * accounts and roles are the workspace's (Members); here an attendant may be
 * linked to one.
 */
class StaffController extends Controller
{
    use SeesAcrossBranches;

    public function index(Branches $branches): Response
    {
        $allowed = $this->branchesWith($branches, [Permissions::ATTENDANTS_VIEW, Permissions::ATTENDANTS_MANAGE]);
        $ids = array_map(fn (Branch $branch) => $branch->id, $allowed);
        $names = $this->namesOf($allowed);

        $stations = AttendantStation::query()->whereIn('branch_id', $ids)->where('active', true)->get(['attendant_id', 'branch_id'])->groupBy('attendant_id');

        // On a pump of an open shift now.
        $open = Shift::query()->whereIn('branch_id', $ids)->where('status', Shift::OPEN)->get(['id', 'branch_id', 'number'])->keyBy('id');
        $duty = $open->isEmpty() ? collect() : ShiftAssignment::query()->with('nozzle')
            ->whereIn('shift_id', $open->keys())->whereNull('ended_at')->where('status', ShiftAssignment::ACTIVE)
            ->get()->groupBy('attendant_id');

        [$start, $end] = BusinessDate::range(BusinessDate::today(), BusinessDate::today());
        $sold = FuelSale::query()->whereIn('branch_id', $ids)->where('status', FuelSale::POSTED)->whereBetween('sold_at', [$start, $end])
            ->toBase()->groupBy('attendant_id')
            ->selectRaw('attendant_id, count(*) as sales, sum(coalesce(reporting_total_minor, total_minor)) as total, sum(quantity_milli) as milli')
            ->get()->keyBy('attendant_id');

        $users = User::query()->orderBy('name')->get(['id', 'name', 'email']);
        $userNames = $users->pluck('name', 'id');

        // Placed at any station of the company, seen or not.
        $placed = AttendantStation::query()->where('active', true)->distinct()->pluck('attendant_id')->flip();

        $attendants = Attendant::query()->orderBy('name')->get()
            // Someone who works only at stations out of this view is not shown; someone placed nowhere is.
            ->filter(fn (Attendant $attendant) => $stations->has($attendant->id) || ! $placed->has($attendant->id))
            ->map(function (Attendant $attendant) use ($stations, $duty, $open, $names, $sold, $userNames) {
                $on = $duty->get($attendant->id, collect());
                $today = $sold->get($attendant->id);

                return [
                    'id' => $attendant->id,
                    'name' => $attendant->name,
                    'employeeCode' => $attendant->employee_code,
                    'phone' => $attendant->phone,
                    'userId' => $attendant->user_id,
                    'userName' => $attendant->user_id === null ? null : ($userNames[$attendant->user_id] ?? null),
                    'active' => $attendant->active,
                    'stations' => $stations->get($attendant->id, collect())->pluck('branch_id')->values(),
                    'onDuty' => $on->map(fn (ShiftAssignment $row) => [
                        'station' => $names[$open[$row->shift_id]->branch_id] ?? 'Station',
                        'shift' => $open[$row->shift_id]->number,
                        'pump' => $row->nozzle->code,
                    ])->values(),
                    'salesToday' => (int) ($today->sales ?? 0),
                    'salesTodayMinor' => (int) ($today->total ?? 0),
                    'litresToday' => Volume::toLitres((int) ($today->milli ?? 0)),
                ];
            })->values();

        $manage = $this->branchIdsAllowing($allowed, Permissions::ATTENDANTS_MANAGE);

        return Inertia::render('staff/index', [
            'attendants' => $attendants,
            'stations' => array_map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'canManage' => in_array($branch->id, $manage, true),
            ], $allowed),
            'users' => $users,
            'canManage' => $manage !== [],
        ]);
    }

    public function store(Request $request, Branches $branches, StationSetup $setup): RedirectResponse
    {
        [$input, $chosen, $managed] = $this->input($request, $branches, null);
        $attendant = $setup->saveAttendantAcross($input, null, $chosen, $managed);

        return back()->with('success', "{$attendant->name} added.");
    }

    public function update(Request $request, Attendant $attendant, Branches $branches, StationSetup $setup): RedirectResponse
    {
        [$input, $chosen, $managed] = $this->input($request, $branches, $attendant);
        $setup->saveAttendantAcross($input, $attendant, $chosen, $managed);

        return back()->with('success', "{$attendant->name} saved.");
    }

    /**
     * @return array{0: array{name: string, employee_code: string, phone: ?string, user_id: ?string, active: bool}, 1: list<string>, 2: list<string>}
     */
    private function input(Request $request, Branches $branches, ?Attendant $attendant): array
    {
        $managed = $this->branchIdsAllowing($this->branchesWith($branches, [Permissions::ATTENDANTS_MANAGE]), Permissions::ATTENDANTS_MANAGE);
        abort_if($managed === [], 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'phone' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'active' => ['boolean'],
            'stations' => ['array'],
            'stations.*' => ['uuid'],
        ]);

        $chosen = array_values(array_unique($validated['stations'] ?? []));
        foreach ($chosen as $branchId) {
            if (! in_array($branchId, $managed, true)) {
                throw ValidationException::withMessages(['stations' => 'You can place attendants only at stations you manage staff for.']);
            }
        }
        // Someone already working elsewhere may be left unplaced here; a new one needs a station.
        if ($attendant === null && $chosen === []) {
            throw ValidationException::withMessages(['stations' => 'Choose at least one station.']);
        }

        $code = strtoupper(trim($validated['employee_code']));
        $taken = Attendant::query()->where('employee_code', $code)
            ->when($attendant !== null, fn ($query) => $query->whereKeyNot($attendant->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['employee_code' => 'Another attendant has this employee code.']);
        }

        return [[
            'name' => trim($validated['name']),
            'employee_code' => $code,
            'phone' => $this->text($validated['phone'] ?? null),
            'user_id' => $this->text($validated['user_id'] ?? null),
            'active' => (bool) ($validated['active'] ?? true),
        ], $chosen, $managed];
    }
}
