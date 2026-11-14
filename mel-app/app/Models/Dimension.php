<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A way of breaking a value down (Sex, Age group, ...).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property bool $is_sensitive
 * @property CarbonImmutable|null $archived_at
 * @property-read Collection<int, DimensionCategory> $categories
 */
class Dimension extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<DimensionCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(DimensionCategory::class)->orderBy('sort_order')->orderBy('name');
    }
}
