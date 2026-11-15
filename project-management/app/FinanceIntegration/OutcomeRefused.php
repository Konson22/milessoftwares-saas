<?php

namespace App\FinanceIntegration;

use RuntimeException;

/**
 * Finance's event cannot be applied as sent: answered 4xx (Finance's outbox
 * does not retry it) and kept in finance_inbox_rejections.
 */
final class OutcomeRefused extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }

    public static function invalid(string $reason, string $detail): self
    {
        return new self(422, $reason, $detail);
    }

    public static function conflict(string $reason, string $detail): self
    {
        return new self(409, $reason, $detail);
    }
}
