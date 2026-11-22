<?php

namespace App\Logbook;

use RuntimeException;

/**
 * A change made from an old view of a task or exception: someone changed
 * it meanwhile (reassigned, cancelled, completed). The API answers 409
 * with the code; the web shows the message on the form. Never retried by
 * the phone on its own.
 */
final class StaleRecord extends RuntimeException
{
    public function __construct(public readonly int $revision, string $message, public readonly string $reason = 'stale')
    {
        parent::__construct($message);
    }
}
