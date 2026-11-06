<?php

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Support\Tenancy\TenantContext;

/**
 * The branch a new business starts with, so its products have somewhere to
 * work from the first sign-in. Call it in the transaction that creates the
 * business: the two together, or neither.
 */
final class AddFirstBranch
{
    public const DEFAULT_NAME = 'Main branch';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Organization $organization, User $creator, string $name = self::DEFAULT_NAME, ?string $city = null): Branch
    {
        $branch = $this->tenant->run($organization, fn (): Branch => Branch::query()->create([
            'name' => $name,
            'code' => 'MAIN',
            'city' => $city,
            'country_code' => $organization->country_code,
        ]));

        $this->audit->record('branch.created', $branch, ['changes' => $branch->getAttributes()], actor: $creator, organizationId: $organization->id);

        return $branch;
    }
}
