<?php

namespace Miles\SharedModuleKit;

use InvalidArgumentException;

final class InvalidEnvelope extends InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid event envelope: '.implode('; ', $errors));
    }
}
