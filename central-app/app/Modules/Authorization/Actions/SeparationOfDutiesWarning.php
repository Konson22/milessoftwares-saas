<?php

namespace App\Modules\Authorization\Actions;

use RuntimeException;

/**
 * The assignment would combine permissions the catalog flags with a warning.
 * Retry with the warning acknowledged to go ahead.
 */
final class SeparationOfDutiesWarning extends RuntimeException
{
    /**
     * @param  list<string>  $conflicts  human-readable descriptions
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('This assignment combines permissions that should normally be held by different people.');
    }
}
