<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Pharmacy\Models\Medicine;
use RuntimeException;

/**
 * Not enough sellable (unexpired) stock in the branch.
 */
class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly Medicine $medicine,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Only {$available} of {$medicine->label()} in stock; {$requested} requested.");
    }
}
