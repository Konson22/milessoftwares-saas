<?php

namespace Miles\PlatformClient\Api;

/**
 * A workspace this application created for another organization, waiting for
 * its owner to claim it (POST /api/v1/sponsored-organizations).
 */
final readonly class SponsoredOrganization
{
    public function __construct(
        public string $claimId,
        public string $organizationId,
        public string $slug,
        public string $displayName,
        public string $status,
        public string $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $organization = is_array($data['organization'] ?? null) ? $data['organization'] : [];

        return new self(
            claimId: (string) ($data['claim_id'] ?? ''),
            organizationId: (string) ($organization['id'] ?? ''),
            slug: (string) ($organization['slug'] ?? ''),
            displayName: (string) ($organization['display_name'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            expiresAt: (string) ($data['expires_at'] ?? ''),
        );
    }
}
