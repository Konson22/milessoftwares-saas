<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Attendant;
use App\Models\AttendantStation;
use App\Models\User;
use App\Petrol\Permissions;
use App\Petrol\StationSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendantController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $branchId = $this->branchId();
        $assigned = AttendantStation::query()->where('branch_id', $branchId)->where('active', true)->pluck('attendant_id');

        return Inertia::render('attendants/index', [
            'attendants' => Attendant::query()->orderBy('name')->get()->map(fn (Attendant $attendant) => [
                'id' => $attendant->id,
                'name' => $attendant->name,
                'employeeCode' => $attendant->employee_code,
                'phone' => $attendant->phone,
                'userId' => $attendant->user_id,
                'active' => $attendant->active,
                'atStation' => $assigned->contains($attendant->id),
            ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'canManage' => $this->canHere(Permissions::ATTENDANTS_MANAGE),
        ]);
    }

    public function store(Request $request, StationSetup $setup): RedirectResponse
    {
        $validated = $this->input($request);
        $attendant = $this->save($setup, $validated, null);

        return back()->with('success', "{$attendant->name} added.");
    }

    public function update(Request $request, Attendant $attendant, StationSetup $setup): RedirectResponse
    {
        $validated = $this->input($request);
        $this->save($setup, $validated, $attendant);

        return back()->with('success', "{$attendant->name} saved.");
    }

    /**
     * @return array{name: string, employee_code: string, phone: ?string, user_id: ?string, active: bool, at_station: bool}
     */
    private function input(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'uuid'],
            'active' => ['boolean'],
            'at_station' => ['boolean'],
        ]);

        return [
            'name' => trim($validated['name']),
            'employee_code' => strtoupper(trim($validated['employee_code'])),
            'phone' => $this->text($validated['phone'] ?? null),
            'user_id' => $this->text($validated['user_id'] ?? null),
            'active' => (bool) ($validated['active'] ?? true),
            'at_station' => (bool) ($validated['at_station'] ?? true),
        ];
    }

    /**
     * @param  array{name: string, employee_code: string, phone: ?string, user_id: ?string, active: bool, at_station: bool}  $input
     */
    private function save(StationSetup $setup, array $input, ?Attendant $attendant): Attendant
    {
        $saved = $setup->saveAttendant($input, $attendant, $this->branchId());
        if (! $input['at_station']) {
            AttendantStation::query()
                ->where('attendant_id', $saved->id)
                ->where('branch_id', $this->branchId())
                ->update(['active' => false]);
        }

        return $saved;
    }
}
