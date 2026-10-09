<?php

namespace App\Modules\Structure\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organizational unit. A null branch_id means the department serves the whole
 * organization (e.g. Finance, HR, Procurement). Not an authorization role.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string|null $parent_department_id
 * @property string $name
 * @property string $code
 * @property string $status
 */
#[Fillable(['branch_id', 'parent_department_id', 'name', 'code', 'status'])]
#[UseFactory(DepartmentFactory::class)]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isCompanyWide(): bool
    {
        return $this->branch_id === null;
    }
}
