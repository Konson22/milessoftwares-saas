<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CreatesAttendantSignIns;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Attendant;
use App\Models\AttendantStation;
use App\Models\User;
use App\Petrol\AttendantSignIn;
use App\Petrol\Permissions;
use App\Petrol\StationSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

class AttendantController extends Controller
{
    use CreatesAttendantSignIns;
    use WorksInBranch;

    public function index(): Response
    {
        $branchId = $this->branchId();
        $assigned = AttendantStation::query()->where('branch_id', $branchId)->where('active', true)->pluck('attendant_id');
        $users = User::query()->orderBy('name')->get(['id', 'name', 'email']);
        $signIns = $users->keyBy('id');

        return Inertia::render('attendants/index', [
            'attendants' => Attendant::query()->orderByDesc('active')->orderBy('name')->get()->map(fn (Attendant $attendant) => [
                'id' => $attendant->id,
                'name' => $attendant->name,
                'employeeCode' => $attendant->employee_code,
                'phone' => $attendant->phone,
                'userId' => $attendant->user_id,
                // The email, or the username of an account without one.
                'signIn' => $attendant->user_id === null ? null : $signIns->get($attendant->user_id)?->email,
                'active' => $attendant->active,
                'atStation' => $assigned->contains($attendant->id),
            ]),
            'users' => $users,
            'canManage' => $this->canHere(Permissions::ATTENDANTS_MANAGE),
        ]);
    }

    public function store(Request $request, StationSetup $setup, AttendantSignIn $signIns): RedirectResponse
    {
        $validated = $this->input($request, null);
        $signIn = $this->signInInput($request, $validated['user_id']);

        $attendant = DB::transaction(function () use ($request, $setup, $signIns, $validated, $signIn) {
            $created = $signIn === null ? null : $this->createSignIn($request, $signIns, $validated['name'], $signIn, $this->branchId());

            return $this->save($setup, [...$validated, 'user_id' => $created !== null ? $created->id : $validated['user_id']], null);
        });

        return back()->with('success', "{$attendant->name} added.");
    }

    public function update(Request $request, Attendant $attendant, StationSetup $setup, AttendantSignIn $signIns): RedirectResponse
    {
        $validated = $this->input($request, $attendant);
        $signIn = $this->signInInput($request, $validated['user_id']);

        DB::transaction(function () use ($request, $setup, $signIns, $validated, $signIn, $attendant) {
            $created = $signIn === null ? null : $this->createSignIn($request, $signIns, $validated['name'], $signIn, $this->branchId());

            $this->save($setup, [...$validated, 'user_id' => $created !== null ? $created->id : $validated['user_id']], $attendant);
        });

        return back()->with('success', "{$attendant->name} saved.");
    }

    /**
     * @return array{name: string, employee_code: string, phone: ?string, user_id: ?string, active: bool, at_station: bool}
     */
    private function input(Request $request, ?Attendant $attendant): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Checked here, before a sign-in is made on the platform for them.
            'employee_code' => ['required', 'string', 'max:32', Rule::unique('attendants', 'employee_code')
                ->where('organization_id', $this->organizationId())
                ->ignore($attendant?->id)],
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

    private function organizationId(): string
    {
        return app(CurrentTenant::class)->require()->id;
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
