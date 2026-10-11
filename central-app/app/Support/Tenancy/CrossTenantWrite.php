<?php

namespace App\Support\Tenancy;

use LogicException;

final class CrossTenantWrite extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("Refusing to write {$model} for an organization other than the current tenant.");
    }
}
