<?php

namespace Miles\PlatformClient\Integration;

use DateTimeImmutable;

/**
 * One read of a paired product's integration API: its status, the data when
 * it answered, the error code it gave otherwise, and the correlation id
 * both sides logged.
 */
final readonly class IntegrationResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public IntegrationStatus $status,
        public array $data,
        public ?string $error,
        public string $correlationId,
        public DateTimeImmutable $retrievedAt,
    ) {}

    public function ok(): bool
    {
        return $this->status->ok();
    }
}
