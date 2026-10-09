<?php

namespace App\Modules\Authorization\Actions;

use RuntimeException;

/**
 * A role assignment or revocation was refused. The reason code is stable for
 * audit and tests; the message is safe to show to the grantor.
 */
final class AssignmentDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
