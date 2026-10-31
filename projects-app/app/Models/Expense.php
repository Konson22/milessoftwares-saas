<?php

namespace App\Models;

use App\Enums\CostCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Money spent on a project other than people's time.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property CostCategory $category
 * @property string $description
 * @property string|null $supplier
 * @property int $amount_minor
 * @property CarbonImmutable $spent_on
 * @property string $recorded_by
 * @property-read User $recorder
 */
class Expense extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['category' => CostCategory::class, 'amount_minor' => 'integer', 'spent_on' => 'date'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
