<?php

namespace Miles\PlatformClient\Api;

/**
 * A department of an organization (GET /api/v1/organizations/{id}/departments).
 * A null branchId is a company-wide department; department-scoped roles in
 * effective access refer to these ids.
 */
final readonly class Department
{
    public function __construct(
        public string $id,
        public ?string $branchId,
        public ?string $parentDepartmentId,
        public string $name,
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
            branchId: isset($data['branch_id']) ? (string) $data['branch_id'] : null,
            parentDepartmentId: isset($data['parent_department_id']) ? (string) $data['parent_department_id'] : null,
            name: (string) ($data['name'] ?? ''),
            code: (string) ($data['code'] ?? ''),
            status: (string) ($data['status'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
