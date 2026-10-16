<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Item;
use RuntimeException;

/**
 * Not enough sellable (unexpired) stock in the branch.
 */
class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly Item $item,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Only {$available} of {$item->name} in stock; {$requested} requested.");
    }
}
