<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of one budget version (BudgetVersion); a version's
 * lines add up to its total. Only a draft version's lines change.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $budget_version_id
 * @property CostCategory $category
 * @property string|null $description
 * @property int $amount_minor
 */
class BudgetLine extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['category' => CostCategory::class, 'amount_minor' => 'integer'];
    }
}
