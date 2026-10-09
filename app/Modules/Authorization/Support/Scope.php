<?php

namespace App\Modules\Authorization\Support;

use App\Modules\Authorization\Enums\ScopeType;
use App\Modules\Authorization\Models\RoleAssignment;
use InvalidArgumentException;

/**
 * Where a role assignment applies: the whole organization, one branch, one
 * department, or one application-defined resource.
 */
final readonly class Scope
{
    private function __construct(
        public ScopeType $type,
        public ?string $branchId = null,
        public ?string $departmentId = null,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
    ) {}

    public static function organization(): self
    {
        return new self(ScopeType::Organization);
    }

    public static function branch(string $branchId): self
    {
        return new self(ScopeType::Branch, branchId: $branchId);
    }

    public static function department(string $departmentId): self
    {
        return new self(ScopeType::Department, departmentId: $departmentId);
    }

    public static function resource(string $type, string $id): self
    {
        return new self(ScopeType::Resource, resourceType: $type, resourceId: $id);
    }

    public static function of(RoleAssignment $assignment): self
    {
        return match ($assignment->scope_type) {
            ScopeType::Organization => self::organization(),
            ScopeType::Branch => self::branch((string) $assignment->branch_id),
            ScopeType::Department => self::department((string) $assignment->department_id),
            ScopeType::Resource => self::resource((string) $assignment->resource_type, (string) $assignment->resource_id),
        };
    }

    /**
     * @param  array{scope_type: string, branch_id?: string|null, department_id?: string|null, resource_type?: string|null, resource_id?: string|null}  $input
     */
    public static function fromInput(array $input): self
    {
        $type = ScopeType::tryFrom($input['scope_type']) ?? throw new InvalidArgumentException('Unknown scope type.');

        return match ($type) {
            ScopeType::Organization => self::organization(),
            ScopeType::Branch => self::branch((string) ($input['branch_id'] ?? throw new InvalidArgumentException('A branch is required.'))),
            ScopeType::Department => self::department((string) ($input['department_id'] ?? throw new InvalidArgumentException('A department is required.'))),
            ScopeType::Resource => self::resource(
                (string) ($input['resource_type'] ?? throw new InvalidArgumentException('A resource type is required.')),
                (string) ($input['resource_id'] ?? throw new InvalidArgumentException('A resource id is required.')),
            ),
        };
    }

    /**
     * Column values for a role_assignments row.
     *
     * @return array{scope_type: string, branch_id: string|null, department_id: string|null, resource_type: string|null, resource_id: string|null}
     */
    public function attributes(): array
    {
        return [
            'scope_type' => $this->type->value,
            'branch_id' => $this->branchId,
            'department_id' => $this->departmentId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->attributes() === $other->attributes();
    }
}
