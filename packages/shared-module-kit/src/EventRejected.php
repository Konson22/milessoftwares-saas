<?php

namespace Miles\SharedModuleKit;

use RuntimeException;

/**
 * The event and its token disagree. Answer 403 and do not store anything.
 */
final class EventRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
