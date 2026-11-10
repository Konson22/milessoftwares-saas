<?php

namespace App\Tenancy;

use LogicException;

/**
 * A write would put a record into, or move it to, another organization.
 */
final class CrossOrganizationWrite extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("Refused to write {$model} into an organization other than the current one.");
    }
}
