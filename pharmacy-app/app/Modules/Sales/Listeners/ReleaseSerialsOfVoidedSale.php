<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Actions\Serials;
use App\Modules\Sales\Events\SaleVoided;

/**
 * The serial numbers a voided sale sold are back in stock. Runs in the
 * void's transaction (not queued).
 */
final class ReleaseSerialsOfVoidedSale
{
    public function __construct(private readonly Serials $serials) {}

    public function handle(SaleVoided $event): void
    {
        $this->serials->release($event->sale);
    }
}
