<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A result in the framework (an outcome, an output...), under a parent one
 * level up.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string $level_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $statement
 * @property string|null $description
 * @property string|null $assumptions
 * @property string|null $risks
 * @property string|null $means_of_verification
 * @property string|null $responsible_id
 * @property string|null $linked_milestone_id
 * @property int $sort_order
 * @property CarbonImmutable|null $archived_at
 * @property-read Programme $programme
 * @property-read ResultLevel $level
 * @property-read Result|null $parent
 * @property-read User|null $responsible
 * @property-read LinkedMilestone|null $linkedMilestone
 * @property-read Collection<int, Indicator> $indicators
 */
class Result extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    public function label(): string
    {
        return "{$this->code} · {$this->statement}";
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<ResultLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(ResultLevel::class, 'level_id');
    }

    /**
     * @return BelongsTo<Result, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<LinkedMilestone, $this>
     */
    public function linkedMilestone(): BelongsTo
    {
        return $this->belongsTo(LinkedMilestone::class);
    }

    /**
     * @return HasMany<Indicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }
}
