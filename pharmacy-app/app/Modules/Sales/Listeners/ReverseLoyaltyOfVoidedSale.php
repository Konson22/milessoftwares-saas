<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Actions\Loyalty;
use App\Modules\Sales\Events\SaleVoided;

/**
 * The points a voided sale earned come off, those it redeemed go back.
 * Runs in the void's transaction (not queued).
 */
final class ReverseLoyaltyOfVoidedSale
{
    public function __construct(private readonly Loyalty $loyalty) {}

    public function handle(SaleVoided $event): void
    {
        $this->loyalty->reverse($event->sale);
    }
}
