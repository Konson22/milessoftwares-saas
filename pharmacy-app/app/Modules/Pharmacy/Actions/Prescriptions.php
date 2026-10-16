<?php

namespace App\Modules\Pharmacy\Actions;

use App\Models\User;
use App\Modules\Pharmacy\Enums\PrescriptionStatus;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\PrescriptionItem;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Support\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prescriptions: recorded at the counter, verified (or rejected) by a
 * pharmacist, then dispensed in one or more sales (the patient may take part
 * now and the rest later). Only a verified, unexpired prescription lets
 * prescription-only and controlled medicines be sold.
 */
class Prescriptions
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Sell $sell,
    ) {}

    /**
     * @param  array{patient_name: string, patient_age?: int|null, prescriber_name: string, prescriber_registration?: string|null, issued_on: string, notes?: string|null}  $details
     * @param  list<array{medicine_id: string, quantity: int, directions: string}>  $items
     */
    public function record(User $by, string $branchId, array $details, array $items): Prescription
    {
        return DB::transaction(function () use ($by, $branchId, $details, $items) {
            $prescription = new Prescription([
                ...$details,
                'branch_id' => $branchId,
                'number' => $this->numbers->next('prescription', 'RX'),
                'recorded_by' => $by->id,
            ]);
            $prescription->status = PrescriptionStatus::Recorded;
            $prescription->save();

            foreach ($items as $item) {
                $prescription->items()->create($item);
            }

            return $prescription;
        });
    }

    public function verify(Prescription $prescription, User $pharmacist): Prescription
    {
        return $this->transition($prescription, [PrescriptionStatus::Recorded], function (Prescription $locked) use ($pharmacist) {
            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['status' => "Issued on {$locked->issued_on->toDateString()}: older than ".Prescription::VALID_DAYS.' days.']);
            }

            $locked->forceFill([
                'status' => PrescriptionStatus::Verified,
                'verified_by' => $pharmacist->id,
                'verified_at' => now(),
            ]);
        });
    }

    public function reject(Prescription $prescription, User $pharmacist, string $reason): Prescription
    {
        return $this->transition($prescription, [PrescriptionStatus::Recorded, PrescriptionStatus::Verified], fn (Prescription $locked) => $locked->forceFill([
            'status' => PrescriptionStatus::Rejected,
            'verified_by' => $pharmacist->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]));
    }

    /**
     * Withdraws a prescription, or closes one partly dispensed that the
     * patient will not come back for (what was given stays given).
     */
    public function cancel(Prescription $prescription): Prescription
    {
        return $this->transition($prescription, [PrescriptionStatus::Recorded, PrescriptionStatus::Verified, PrescriptionStatus::PartlyDispensed], fn (Prescription $locked) => $locked->forceFill([
            'status' => PrescriptionStatus::Cancelled,
        ]));
    }

    /**
     * Dispenses what is given now as one sale from the branch's stock: all
     * that is left by default, or the quantities asked for (item id =>
     * quantity) when the patient takes part now and the rest later.
     *
     * @param  array<string, int>|null  $quantities
     */
    public function dispense(Prescription $prescription, User $by, PaymentMethod $method, ?string $paymentReference, ?int $tenderedMinor, ?array $quantities = null): Sale
    {
        return DB::transaction(function () use ($prescription, $by, $method, $paymentReference, $tenderedMinor, $quantities) {
            // Locked first: the same quantities are never dispensed twice.
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PrescriptionStatus::Verified, PrescriptionStatus::PartlyDispensed], true)) {
                throw ValidationException::withMessages(['status' => "Prescription {$locked->number} is not ready to dispense ({$locked->status->label()})."]);
            }
            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['status' => "Prescription {$locked->number} expired on {$locked->expiresOn()->toDateString()}."]);
            }

            $items = $locked->items()->get();
            $unknown = array_diff(array_keys($quantities ?? []), $items->pluck('id')->all());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['quantities' => 'A medicine is not on this prescription.']);
            }

            $giving = [];
            foreach ($items as $item) {
                $quantity = $quantities === null ? $item->remaining() : (int) ($quantities[$item->id] ?? 0);

                if ($quantity < 0 || $quantity > $item->remaining()) {
                    throw ValidationException::withMessages(["quantities.{$item->id}" => "Only {$item->remaining()} of {$item->quantity} are left to give."]);
                }
                if ($quantity > 0) {
                    $giving[] = [$item, $quantity];
                }
            }

            if ($giving === []) {
                throw ValidationException::withMessages(['quantities' => 'Give at least one medicine.']);
            }

            $sale = $this->sell->handle(
                by: $by,
                branchId: $locked->branch_id,
                items: array_map(fn (array $part) => ['medicine_id' => $part[0]->medicine_id, 'quantity' => $part[1]], $giving),
                method: $method,
                paymentReference: $paymentReference,
                tenderedMinor: $tenderedMinor,
                customerName: $locked->patient_name,
                prescribed: array_values($items->map(fn (PrescriptionItem $item) => $item->medicine_id)->all()),
            );
            $sale->forceFill(['prescription_id' => $locked->id])->save();

            foreach ($giving as [$item, $quantity]) {
                $item->forceFill(['dispensed_quantity' => $item->dispensed_quantity + $quantity])->save();
            }

            $this->settle($locked);

            return $sale;
        });
    }

    /**
     * A voided sale gives its quantities back to the prescription.
     */
    public function saleVoided(Sale $sale): void
    {
        if ($sale->prescription_id === null) {
            return;
        }

        $prescription = Prescription::query()->whereKey($sale->prescription_id)->lockForUpdate()->firstOrFail();
        $returned = $sale->lines()->get()->groupBy('medicine_id')->map(fn ($lines) => (int) $lines->sum('quantity'));

        foreach ($prescription->items()->get() as $item) {
            $back = (int) ($returned[$item->medicine_id] ?? 0);
            if ($back > 0) {
                $item->forceFill(['dispensed_quantity' => max(0, $item->dispensed_quantity - $back)])->save();
            }
        }

        $this->settle($prescription);
    }

    /**
     * Status from what has been given: none, some, or all of it.
     */
    private function settle(Prescription $prescription): void
    {
        // A closed prescription stays closed, even if a sale from it is voided.
        if (in_array($prescription->status, [PrescriptionStatus::Cancelled, PrescriptionStatus::Rejected], true)) {
            return;
        }

        $items = $prescription->items()->get();
        $given = (int) $items->sum('dispensed_quantity');
        $left = (int) $items->sum(fn (PrescriptionItem $item) => $item->remaining());

        $prescription->forceFill(['status' => match (true) {
            $given === 0 => PrescriptionStatus::Verified,
            $left > 0 => PrescriptionStatus::PartlyDispensed,
            default => PrescriptionStatus::Dispensed,
        }])->save();
    }

    /**
     * @param  list<PrescriptionStatus>  $from
     * @param  callable(Prescription): mixed  $change
     */
    private function transition(Prescription $prescription, array $from, callable $change): Prescription
    {
        return DB::transaction(function () use ($prescription, $from, $change) {
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => "Prescription {$locked->number} is {$locked->status->label()}."]);
            }

            $change($locked);
            $locked->save();

            return $locked;
        });
    }
}
