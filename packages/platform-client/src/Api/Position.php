<?php

namespace Miles\PlatformClient\Api;

/**
 * A position (job title) of an organization
 * (GET /api/v1/organizations/{id}/positions). Grants no permissions.
 */
final readonly class Position
{
    public function __construct(
        public string $id,
        public ?string $departmentId,
        public string $title,
        public string $code,
        public string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            departmentId: isset($data['department_id']) ? (string) $data['department_id'] : null,
            title: (string) ($data['title'] ?? ''),
            code: (string) ($data['code'] ?? ''),
            status: (string) ($data['status'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
