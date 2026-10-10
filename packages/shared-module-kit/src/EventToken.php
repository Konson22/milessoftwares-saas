<?php

namespace Miles\SharedModuleKit;

use DateTimeImmutable;

/**
 * A verified, organization-bound event token: the platform vouches that
 * $sourceApp may send events about $organizationId to this module.
 */
final readonly class EventToken
{
    public function __construct(
        public string $sourceApp,
        public string $organizationId,
        public string $tokenId,
        public DateTimeImmutable $expiresAt,
    ) {}
}
