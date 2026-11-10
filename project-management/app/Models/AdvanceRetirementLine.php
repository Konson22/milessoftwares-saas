<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt in a retirement.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $retirement_id
 * @property CarbonImmutable $spent_on
 * @property int $amount_minor
 * @property string $description
 * @property string|null $payee
 * @property string|null $project_id
 * @property CostCategory|null $category
 * @property string|null $expense_id
 * @property string $receipt_name
 * @property string $receipt_path
 * @property string $receipt_mime
 * @property int $receipt_size
 * @property-read AdvanceRetirement $retirement
 * @property-read Project|null $project
 */
class AdvanceRetirementLine extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'spent_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'category' => CostCategory::class,
            'receipt_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AdvanceRetirement, $this>
     */
    public function retirement(): BelongsTo
    {
        return $this->belongsTo(AdvanceRetirement::class, 'retirement_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
