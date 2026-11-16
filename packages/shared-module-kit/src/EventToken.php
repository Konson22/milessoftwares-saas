<?php

namespace Miles\SharedModuleKit;

use DateTimeImmutable;

/**
 * A verified, organization-bound token: the platform vouches that
 * $sourceApp may send events about $organizationId to this module (scope
 * events.write), or read this product's integration API for it (scope
 * integration.read, ADR-0019 §9), on behalf of $actingUser when named.
 */
final readonly class EventToken
{
    public function __construct(
        public string $sourceApp,
        public string $organizationId,
        public string $tokenId,
        public DateTimeImmutable $expiresAt,
        public string $scope = 'events.write',
        public ?string $actingUser = null,
    ) {}
}
