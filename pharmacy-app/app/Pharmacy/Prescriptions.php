<?php

namespace App\Pharmacy;

use App\Enums\PaymentMethod;
use App\Enums\PrescriptionStatus;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prescriptions: recorded at the counter, verified (or rejected) by a
 * pharmacist, then dispensed in full as one sale. Only a verified, unexpired
 * prescription lets prescription-only and controlled medicines be sold.
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

    public function cancel(Prescription $prescription): Prescription
    {
        return $this->transition($prescription, [PrescriptionStatus::Recorded, PrescriptionStatus::Verified], fn (Prescription $locked) => $locked->forceFill([
            'status' => PrescriptionStatus::Cancelled,
        ]));
    }

    /**
     * Dispenses the whole prescription as one sale from the branch's stock.
     */
    public function dispense(Prescription $prescription, User $by, PaymentMethod $method, ?string $paymentReference, ?int $tenderedMinor): Sale
    {
        return DB::transaction(function () use ($prescription, $by, $method, $paymentReference, $tenderedMinor) {
            // Locked first: the same prescription is never dispensed twice.
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PrescriptionStatus::Verified) {
                throw ValidationException::withMessages(['status' => "Prescription {$locked->number} is not ready to dispense ({$locked->status->label()})."]);
            }
            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['status' => "Prescription {$locked->number} expired on {$locked->expiresOn()->toDateString()}."]);
            }

            $items = $locked->items()->get();

            $sale = $this->sell->handle(
                by: $by,
                branchId: $locked->branch_id,
                items: array_values($items->map(fn (PrescriptionItem $item) => ['medicine_id' => $item->medicine_id, 'quantity' => $item->quantity])->all()),
                method: $method,
                paymentReference: $paymentReference,
                tenderedMinor: $tenderedMinor,
                customerName: $locked->patient_name,
                prescribed: array_values($items->map(fn (PrescriptionItem $item) => $item->medicine_id)->all()),
            );

            $locked->forceFill(['status' => PrescriptionStatus::Dispensed, 'dispensed_sale_id' => $sale->id])->save();

            return $sale;
        });
    }

    /**
     * A voided sale makes its prescription dispensable again.
     */
    public function saleVoided(Sale $sale): void
    {
        Prescription::query()
            ->where('dispensed_sale_id', $sale->id)
            ->update(['status' => PrescriptionStatus::Verified->value, 'dispensed_sale_id' => null, 'updated_at' => now()]);
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
