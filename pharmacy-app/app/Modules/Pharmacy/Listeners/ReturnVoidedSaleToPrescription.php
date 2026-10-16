<?php

namespace App\Modules\Pharmacy\Listeners;

use App\Modules\Pharmacy\Actions\Prescriptions;
use App\Modules\Sales\Events\SaleVoided;

/**
 * A dispensed prescription can be dispensed again once its sale is voided.
 * Runs in the void's transaction (not queued).
 */
final class ReturnVoidedSaleToPrescription
{
    public function __construct(private readonly Prescriptions $prescriptions) {}

    public function handle(SaleVoided $event): void
    {
        $this->prescriptions->saleVoided($event->sale);
    }
}
