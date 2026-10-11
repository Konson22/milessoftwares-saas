<?php

namespace Miles\PlatformClient\Api;

/**
 * An organization as the platform API shows it to this application
 * (GET /api/v1/organizations/{slug-or-id}).
 */
final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $displayName,
        public string $status,
        public ?string $countryCode,
        public ?string $defaultCurrency,
        public ?string $timezone,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            displayName: (string) ($data['display_name'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            countryCode: isset($data['country_code']) ? (string) $data['country_code'] : null,
            defaultCurrency: isset($data['default_currency']) ? (string) $data['default_currency'] : null,
            timezone: isset($data['timezone']) ? (string) $data['timezone'] : null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'display_name' => $this->displayName,
            'status' => $this->status,
            'country_code' => $this->countryCode,
            'default_currency' => $this->defaultCurrency,
            'timezone' => $this->timezone,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
