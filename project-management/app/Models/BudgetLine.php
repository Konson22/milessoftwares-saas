<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
use App\Enums\CostCategory;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A line of one budget version (BudgetVersion); a version's
 * lines add up to its total. Only a draft version's lines change.
 *
 * line_key is the line across versions (ADR-0020): a revision copies it, a
 * new line gets its own. Requests and spending charge a line by its key.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $budget_version_id
 * @property CostCategory $category
 * @property string|null $description
 * @property int $amount_minor
 * @property string $line_key
 */
class BudgetLine extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    protected $guarded = ['organization_id'];

    protected static function booted(): void
    {
        static::creating(function (BudgetLine $line) {
            $line->line_key ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['category' => CostCategory::class, 'amount_minor' => 'integer'];
    }
}
