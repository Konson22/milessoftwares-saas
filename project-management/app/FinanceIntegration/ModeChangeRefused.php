<?php

namespace App\FinanceIntegration;

use RuntimeException;

/**
 * A Finance mode switch was refused; nothing changed.
 */
final class ModeChangeRefused extends RuntimeException
{
    /**
     * @param  list<array{key: string, label: string, count: int, passed: bool, blocking: bool, detail: string}>  $checks
     */
    public function __construct(string $message, public readonly array $checks)
    {
        parent::__construct($message);
    }
}
