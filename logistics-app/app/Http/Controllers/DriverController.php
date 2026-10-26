<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drivers trips are given to, and their licences.
 */
class DriverController extends Controller
{
    public function index(Request $request): Response
    {
        $drivers = Driver::query()->withCount(['trips as completed_trips_count' => fn ($query) => $query->where('status', Trip::COMPLETED)])
            ->orderByDesc('active')->orderBy('name')->get();
        $onTrip = Trip::query()->where('status', Trip::IN_PROGRESS)->pluck('number', 'driver_id');

        return Inertia::render('drivers/index', [
            'drivers' => $drivers->map(fn (Driver $driver) => [
                ...$this->summary($driver),
                'licenceExpired' => $driver->licenceExpired(),
                // Due within a month: time to renew.
                'licenceExpiring' => ! $driver->licenceExpired() && $driver->licence_expires_on?->lte(today()->addDays(30)) === true,
                'completedTrips' => (int) $driver->getAttribute('completed_trips_count'),
                'onTrip' => $onTrip->get($driver->id),
            ]),
            'canManage' => $this->can($request, 'logistics.fleet.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('drivers/form', ['driver' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $driver = new Driver;
        $this->save($request, $driver);

        return to_route('drivers.index')->with('success', "{$driver->name} added.");
    }

    public function edit(Driver $driver): Response
    {
        return Inertia::render('drivers/form', ['driver' => $this->summary($driver)]);
    }

    public function update(Request $request, Driver $driver): RedirectResponse
    {
        $this->save($request, $driver);

        return to_route('drivers.index')->with('success', "{$driver->name} saved.");
    }

    private function save(Request $request, Driver $driver): void
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'licence_number' => ['nullable', 'string', 'max:64'],
            'licence_expires_on' => ['nullable', 'date'],
            'active' => ['boolean'],
        ]);

        $driver->fill([
            'name' => trim($validated['name']),
            'phone' => $this->text($validated['phone'] ?? null),
            'licence_number' => $this->text($validated['licence_number'] ?? null),
            'licence_expires_on' => $validated['licence_expires_on'] ?? null,
            'active' => (bool) ($validated['active'] ?? true),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'licenceNumber' => $driver->licence_number,
            'licenceExpiresOn' => $driver->licence_expires_on?->toDateString(),
            'active' => $driver->active,
        ];
    }
}
