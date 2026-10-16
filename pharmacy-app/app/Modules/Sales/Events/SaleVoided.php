<?php

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Sale;

/**
 * Dispatched inside the void's transaction, after the stock is back and the
 * sale is marked voided. Listeners run synchronously in that transaction, so
 * one that fails undoes the whole void; they must not be queued.
 */
final class SaleVoided
{
    public function __construct(public readonly Sale $sale) {}
}
