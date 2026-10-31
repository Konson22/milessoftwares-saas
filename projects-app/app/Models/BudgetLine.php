<?php

namespace App\Models;

use App\Enums\CostCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Part of a project's budget; the lines add up to projects.budget_minor.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property CostCategory $category
 * @property string|null $description
 * @property int $amount_minor
 */
class BudgetLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['category' => CostCategory::class, 'amount_minor' => 'integer'];
    }
}
