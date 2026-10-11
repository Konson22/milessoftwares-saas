<?php

namespace App\Modules\Integration\Support;

use RuntimeException;

final class EventTokenRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
