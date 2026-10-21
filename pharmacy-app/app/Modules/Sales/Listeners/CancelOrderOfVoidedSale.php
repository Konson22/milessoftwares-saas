<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Actions\CounterOrders;
use App\Modules\Sales\Events\SaleVoided;

/**
 * A counter order is cancelled with its sale. Runs in the void's
 * transaction (not queued).
 */
final class CancelOrderOfVoidedSale
{
    public function __construct(private readonly CounterOrders $orders) {}

    public function handle(SaleVoided $event): void
    {
        $this->orders->saleVoided($event->sale);
    }
}
