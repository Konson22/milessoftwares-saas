<?php

namespace App\Actions;

use App\Models\Driver;
use App\Models\Shipment;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\DocumentNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trips: planned with a vehicle and driver, loaded with shipments,
 * dispatched (the shipments go on the road) and completed once each is
 * delivered or failed. Failed ones are booked again for another trip.
 */
class Trips
{
    public function __construct(
        private readonly Shipments $shipments,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function plan(Vehicle $vehicle, Driver $driver, CarbonImmutable $plannedOn, ?string $route, User $by): Trip
    {
        $this->ensureCanDrive($vehicle, $driver, 'vehicle_id');

        return DB::transaction(fn () => Trip::query()->create([
            'number' => $this->numbers->next('trip', 'TR'),
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'planned_on' => $plannedOn,
            'route' => blank($route) ? null : trim((string) $route),
            'status' => Trip::PLANNED,
            'created_by' => $by->id,
        ]));
    }

    /**
     * Loads booked shipments onto a trip that has not finished. On a trip
     * already on the road they go straight into transit.
     *
     * @param  list<string>  $shipmentIds
     *
     * @throws ValidationException
     */
    public function load(Trip $trip, array $shipmentIds, User $by): int
    {
        return DB::transaction(function () use ($trip, $shipmentIds, $by) {
            $locked = $this->lock($trip);
            $this->expect($locked, [Trip::PLANNED, Trip::IN_PROGRESS], 'shipment_ids', 'Shipments can only be added before the trip is completed.');

            $shipments = Shipment::query()->whereKey($shipmentIds)->lockForUpdate()->get();
            if ($shipments->count() !== count(array_unique($shipmentIds))) {
                throw ValidationException::withMessages(['shipment_ids' => 'Some of these shipments are not in this workspace.']);
            }

            foreach ($shipments as $shipment) {
                $this->shipments->expect($shipment, [Shipment::BOOKED], 'shipment_ids', 'Only booked shipments can be loaded onto a trip.');

                $onTheRoad = $locked->status === Trip::IN_PROGRESS;
                $shipment->forceFill([
                    'trip_id' => $locked->id,
                    'status' => $onTheRoad ? Shipment::IN_TRANSIT : Shipment::ASSIGNED,
                ])->save();
                $this->shipments->record($shipment, $shipment->status, $by, note: ($onTheRoad ? 'Picked up on trip ' : 'Assigned to trip ').$locked->number.'.');
            }

            return $shipments->count();
        });
    }

    /**
     * Takes a shipment off a trip before it leaves.
     *
     * @throws ValidationException
     */
    public function unload(Trip $trip, Shipment $shipment, User $by): void
    {
        DB::transaction(function () use ($trip, $shipment, $by) {
            $locked = $this->lock($trip);
            $this->expect($locked, [Trip::PLANNED], 'shipment', 'Shipments can only be taken off before the trip leaves.');

            $shipment = $this->shipments->lock($shipment);
            abort_unless($shipment->trip_id === $locked->id, 404);

            $shipment->forceFill(['trip_id' => null, 'status' => Shipment::BOOKED])->save();
            $this->shipments->record($shipment, Shipment::BOOKED, $by, note: "Taken off trip {$locked->number}.");
        });
    }

    /**
     * Sends the trip on the road with its shipments.
     *
     * @throws ValidationException
     */
    public function dispatch(Trip $trip, ?int $startOdometerKm, User $by): void
    {
        DB::transaction(function () use ($trip, $startOdometerKm, $by) {
            $locked = $this->lock($trip);
            $this->expect($locked, [Trip::PLANNED], 'start_odometer_km', 'Only planned trips can be dispatched.');

            $vehicle = Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();
            $driver = Driver::query()->findOrFail($locked->driver_id);
            $this->ensureCanDrive($vehicle, $driver, 'start_odometer_km');

            $busy = Trip::query()
                ->where('status', Trip::IN_PROGRESS)
                ->where(fn ($query) => $query->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $driver->id))
                ->first();
            if ($busy !== null) {
                throw ValidationException::withMessages(['start_odometer_km' => "The vehicle or driver is still out on trip {$busy->number}."]);
            }

            $shipments = $locked->shipments()->lockForUpdate()->get();
            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages(['start_odometer_km' => 'Load at least one shipment before dispatching.']);
            }

            $start = $startOdometerKm ?? $vehicle->odometer_km;
            if ($start < $vehicle->odometer_km) {
                throw ValidationException::withMessages(['start_odometer_km' => "The odometer already reads {$vehicle->odometer_km} km."]);
            }

            $locked->forceFill(['status' => Trip::IN_PROGRESS, 'dispatched_at' => now(), 'start_odometer_km' => $start])->save();
            $vehicle->forceFill(['odometer_km' => $start])->save();

            foreach ($shipments as $shipment) {
                $shipment->forceFill(['status' => Shipment::IN_TRANSIT])->save();
                $this->shipments->record($shipment, Shipment::IN_TRANSIT, $by, $shipment->pickup_address, "Picked up on trip {$locked->number}.");
            }
        });
    }

    /**
     * Ends a trip once every shipment on it is delivered or failed. Failed
     * shipments come off the trip, booked again.
     *
     * @throws ValidationException
     */
    public function complete(Trip $trip, ?int $endOdometerKm, User $by): void
    {
        DB::transaction(function () use ($trip, $endOdometerKm, $by) {
            $locked = $this->lock($trip);
            $this->expect($locked, [Trip::IN_PROGRESS], 'end_odometer_km', 'Only trips on the road can be completed.');

            $shipments = $locked->shipments()->lockForUpdate()->get();
            $open = $shipments->where('status', Shipment::IN_TRANSIT);
            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages(['end_odometer_km' => 'Record delivery or failure of '.$open->pluck('number')->join(', ').' first.']);
            }

            if ($endOdometerKm !== null && $endOdometerKm < (int) $locked->start_odometer_km) {
                throw ValidationException::withMessages(['end_odometer_km' => "The trip started at {$locked->start_odometer_km} km."]);
            }

            $locked->forceFill(['status' => Trip::COMPLETED, 'completed_at' => now(), 'end_odometer_km' => $endOdometerKm])->save();
            if ($endOdometerKm !== null) {
                Vehicle::query()->whereKey($locked->vehicle_id)->where('odometer_km', '<', $endOdometerKm)->update(['odometer_km' => $endOdometerKm]);
            }

            foreach ($shipments->where('status', Shipment::FAILED) as $shipment) {
                $shipment->forceFill(['status' => Shipment::BOOKED, 'trip_id' => null])->save();
                $this->shipments->record($shipment, Shipment::BOOKED, $by, note: "Back from trip {$locked->number}: to be delivered again.");
            }
        });
    }

    /**
     * Cancels a trip that has not left; its shipments are booked again.
     *
     * @throws ValidationException
     */
    public function cancel(Trip $trip, string $reason, User $by): void
    {
        DB::transaction(function () use ($trip, $reason, $by) {
            $locked = $this->lock($trip);
            $this->expect($locked, [Trip::PLANNED], 'reason', 'Only trips that have not left can be cancelled.');

            foreach ($locked->shipments()->lockForUpdate()->get() as $shipment) {
                $shipment->forceFill(['status' => Shipment::BOOKED, 'trip_id' => null])->save();
                $this->shipments->record($shipment, Shipment::BOOKED, $by, note: "Trip {$locked->number} cancelled.");
            }

            $locked->forceFill(['status' => Trip::CANCELLED, 'cancel_reason' => trim($reason)])->save();
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanDrive(Vehicle $vehicle, Driver $driver, string $field): void
    {
        if ($vehicle->status !== Vehicle::AVAILABLE) {
            throw ValidationException::withMessages([$field => "{$vehicle->registration} is ".($vehicle->status === Vehicle::RETIRED ? 'retired.' : 'in maintenance.')]);
        }
        if (! $driver->active) {
            throw ValidationException::withMessages([$field => "{$driver->name} is no longer an active driver."]);
        }
        if ($driver->licenceExpired()) {
            throw ValidationException::withMessages([$field => "{$driver->name}'s driving licence has expired."]);
        }
    }

    private function lock(Trip $trip): Trip
    {
        return Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $statuses
     *
     * @throws ValidationException
     */
    private function expect(Trip $trip, array $statuses, string $field, string $message): void
    {
        if (! in_array($trip->status, $statuses, true)) {
            throw ValidationException::withMessages([$field => "{$trip->number}: {$message}"]);
        }
    }
}
