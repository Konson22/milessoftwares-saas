<?php

namespace App\Actions;

use App\Models\Shipment;
use App\Models\User;
use App\Support\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shipments from booking to delivery, each step kept in the tracking
 * history. Trips move them on and off the road (App\Actions\Trips).
 */
class Shipments
{
    public function __construct(private readonly DocumentNumbers $numbers) {}

    /**
     * @param  array<string, mixed>  $details  the shipment's columns
     */
    public function book(array $details, User $by): Shipment
    {
        return DB::transaction(function () use ($details, $by) {
            $shipment = Shipment::query()->create([
                ...$details,
                'number' => $this->numbers->next('shipment', 'SH'),
                'status' => Shipment::BOOKED,
                'booked_by' => $by->id,
            ]);
            $this->record($shipment, Shipment::BOOKED, $by, location: $shipment->pickup_address, note: 'Booked.');

            return $shipment;
        });
    }

    /**
     * A tracking update on the road: where it is now, the status unchanged.
     *
     * @throws ValidationException
     */
    public function track(Shipment $shipment, User $by, ?string $location, ?string $note): void
    {
        DB::transaction(function () use ($shipment, $by, $location, $note) {
            $locked = $this->lock($shipment);
            $this->expect($locked, [Shipment::IN_TRANSIT], 'location', 'Only shipments on the road get tracking updates.');

            $this->record($locked, $locked->status, $by, $location, $note);
        });
    }

    /**
     * Proof of delivery: who received it, when, and any note.
     *
     * @throws ValidationException
     */
    public function deliver(Shipment $shipment, User $by, string $receivedBy, ?string $note): void
    {
        DB::transaction(function () use ($shipment, $by, $receivedBy, $note) {
            $locked = $this->lock($shipment);
            $this->expect($locked, [Shipment::IN_TRANSIT], 'received_by', 'Only shipments on the road can be delivered.');

            $locked->forceFill([
                'status' => Shipment::DELIVERED,
                'delivered_at' => now(),
                'received_by' => trim($receivedBy),
                'delivery_note' => $this->clean($note),
            ])->save();
            $this->record($locked, Shipment::DELIVERED, $by, $locked->delivery_address, "Received by {$locked->received_by}.".($locked->delivery_note === null ? '' : " {$locked->delivery_note}"));
        });
    }

    /**
     * A delivery attempt that did not work out; booked again when the trip ends.
     *
     * @throws ValidationException
     */
    public function fail(Shipment $shipment, User $by, string $reason): void
    {
        DB::transaction(function () use ($shipment, $by, $reason) {
            $locked = $this->lock($shipment);
            $this->expect($locked, [Shipment::IN_TRANSIT], 'reason', 'Only shipments on the road can fail delivery.');

            $locked->forceFill(['status' => Shipment::FAILED, 'delivery_note' => trim($reason)])->save();
            $this->record($locked, Shipment::FAILED, $by, $locked->delivery_address, trim($reason));
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(Shipment $shipment, User $by, string $reason): void
    {
        DB::transaction(function () use ($shipment, $by, $reason) {
            $locked = $this->lock($shipment);
            $this->expect($locked, [Shipment::BOOKED], 'reason', 'Take the shipment off its trip before cancelling it.');

            $locked->forceFill(['status' => Shipment::CANCELLED, 'cancel_reason' => trim($reason)])->save();
            $this->record($locked, Shipment::CANCELLED, $by, note: trim($reason));
        });
    }

    /**
     * Adds a step to the shipment's tracking history.
     */
    public function record(Shipment $shipment, string $status, User $by, ?string $location = null, ?string $note = null): void
    {
        $shipment->events()->create([
            'status' => $status,
            'location' => $this->clean($location),
            'note' => $this->clean($note),
            'recorded_by' => $by->id,
            'occurred_at' => now(),
        ]);
    }

    public function lock(Shipment $shipment): Shipment
    {
        return Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $statuses
     *
     * @throws ValidationException
     */
    public function expect(Shipment $shipment, array $statuses, string $field, string $message): void
    {
        if (! in_array($shipment->status, $statuses, true)) {
            throw ValidationException::withMessages([$field => "{$shipment->number}: {$message}"]);
        }
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
