<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's kinds of leave and public holidays
 * (hr.leave.settings, organization-wide).
 *
 * A kind's days per year start each person's entitlement for a year the
 * first time it is needed; changing them does not touch years already
 * started. Kinds with history are archived, never deleted.
 */
class LeaveSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $years = range($today->year - 1, $today->year + 1);
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : $today->year;

        return Inertia::render('leave/settings', [
            'types' => LeaveType::query()
                ->orderByRaw('archived_at is not null')
                ->orderBy('name')
                ->get()
                ->map(fn (LeaveType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'daysPerYear' => $type->days_per_year,
                    'paid' => $type->paid,
                    'allowNegative' => $type->allow_negative,
                    'maxCarryOver' => $type->max_carry_over,
                    'archived' => $type->isArchived(),
                ]),
            'year' => $year,
            'years' => $years,
            'holidays' => PublicHoliday::query()
                ->whereYear('date', $year)
                ->orderBy('date')
                ->get()
                ->map(fn (PublicHoliday $holiday) => [
                    'id' => $holiday->id,
                    'date' => $holiday->date->toDateString(),
                    'name' => $holiday->name,
                ]),
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $validated = $this->validateType($request, null);
        LeaveType::query()->create($validated);

        return back()->with('success', "{$validated['name']} added.");
    }

    public function updateType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $validated = $this->validateType($request, $leaveType);
        $archived = $request->boolean('archived');

        $leaveType->forceFill([
            ...$validated,
            'archived_at' => $archived ? ($leaveType->archived_at ?? now()) : null,
        ])->save();

        return back()->with('success', $archived ? "{$leaveType->name} archived: it takes no new requests." : "{$leaveType->name} saved.");
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $date = CarbonImmutable::parse($validated['date'])->toDateString();

        if (PublicHoliday::query()->whereDate('date', $date)->exists()) {
            throw ValidationException::withMessages(['date' => 'That day is already a public holiday.']);
        }

        PublicHoliday::query()->create(['date' => $date, 'name' => $validated['name']]);

        return back()->with('success', "{$validated['name']} added. Leave already requested keeps its day count.");
    }

    public function destroyHoliday(PublicHoliday $publicHoliday): RedirectResponse
    {
        $publicHoliday->delete();

        return back()->with('success', "{$publicHoliday->name} removed.");
    }

    /**
     * @return array{name: string, days_per_year: int, paid: bool, allow_negative: bool, max_carry_over: int}
     */
    private function validateType(Request $request, ?LeaveType $type): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'days_per_year' => ['required', 'integer', 'min:0', 'max:365'],
            'max_carry_over' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $taken = LeaveType::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->when($type !== null, fn ($query) => $query->whereKeyNot($type->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'There is already a kind of leave with that name.']);
        }

        return [
            'name' => trim($validated['name']),
            'days_per_year' => (int) $validated['days_per_year'],
            'paid' => $request->boolean('paid'),
            'allow_negative' => $request->boolean('allow_negative'),
            'max_carry_over' => (int) $validated['max_carry_over'],
        ];
    }
}
