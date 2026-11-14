<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A read-only copy of a project kept in Tatook Projects, as of synced_at.
 * Written only by Projects' mel.project.updated events; never edited here.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $external_id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property int|null $progress_percent
 * @property string|null $currency
 * @property int|null $approved_budget_minor
 * @property int|null $spent_minor
 * @property CarbonImmutable $synced_at
 * @property-read Collection<int, LinkedMilestone> $milestones
 */
class LinkedProject extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'progress_percent' => 'integer',
            'approved_budget_minor' => 'integer',
            'spent_minor' => 'integer',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }

    /**
     * @return HasMany<LinkedMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(LinkedMilestone::class)->orderBy('due_on');
    }
}
