<?php

namespace Miles\PlatformClient\Api;

/**
 * A branch of an organization (GET /api/v1/organizations/{id}/branches).
 * Branch-scoped roles in effective access refer to these ids.
 */
final readonly class Branch
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public ?string $city,
        public string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            code: (string) ($data['code'] ?? ''),
            city: isset($data['city']) ? (string) $data['city'] : null,
            status: (string) ($data['status'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
