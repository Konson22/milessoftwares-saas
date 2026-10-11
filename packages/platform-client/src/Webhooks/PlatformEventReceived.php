<?php

namespace Miles\PlatformClient\Webhooks;

/**
 * Dispatched once per platform webhook (duplicates are dropped before).
 * Cached access for the organization is already gone when listeners run.
 *
 * Types: entitlement.changed, access.changed, membership.revoked,
 * membership.reactivated, organization.suspended, organization.reactivated
 * (docs/integration.md section 3).
 */
final readonly class PlatformEventReceived
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $organizationId,
        public array $data,
    ) {}
}
