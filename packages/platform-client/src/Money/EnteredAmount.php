<?php

namespace Miles\PlatformClient\Money;

/**
 * An amount as someone typed it, and the same amount in the workspace
 * currency. Products store baseMinor in their usual columns.
 */
final readonly class EnteredAmount
{
    public function __construct(
        public string $currency,
        public int $amountMinor,
        public ?int $rateMinor,
        public string $baseCurrency,
        public int $baseMinor,
    ) {}

    public function foreign(): bool
    {
        return $this->currency !== $this->baseCurrency;
    }
}
