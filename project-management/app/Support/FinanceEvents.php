<?php

namespace App\Support;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Miles\PlatformClient\Api\Organization as PlatformOrganization;
use Miles\PlatformClient\Events\Outbox;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The books are Finance's (the shared module): what Projects pays, receives
 * and advances goes there as finance.* events, through the platform outbox.
 * An event is written in the caller's transaction, so it exists exactly when
 * the payment does, and is delivered after commit; an organization without
 * Finance has it skipped. contracts/events has each event's schema.
 */
final class FinanceEvents
{
    public const AUDIENCE = 'finance';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly CurrentTenant $tenant,
        private readonly CurrentOrganization $organization,
    ) {}

    /**
     * @param  string  $transactionId  stable for this fact: Finance applies it once
     * @param  array<string, mixed>  $payload
     */
    public function send(string $type, string $transactionId, array $payload, ?CarbonImmutable $on = null): void
    {
        $record = fn () => $this->outbox->record(self::AUDIENCE, $type, 1, $transactionId, $payload, $on);

        if ($this->tenant->has()) {
            $record();

            return;
        }

        // Jobs, the console and seeders run for the local mirror only.
        $this->tenant->run($this->platformOrganization($this->organization->require()), $record);
    }

    private function platformOrganization(Organization $organization): PlatformOrganization
    {
        return new PlatformOrganization(
            id: $organization->id,
            slug: (string) $organization->slug,
            displayName: (string) $organization->name,
            status: 'active',
            countryCode: null,
            defaultCurrency: $organization->currency,
            timezone: $organization->timezone,
        );
    }
}
