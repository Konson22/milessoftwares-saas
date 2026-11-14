<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $dimension_id
 * @property string $name
 * @property int $sort_order
 * @property CarbonImmutable|null $archived_at
 * @property-read Dimension $dimension
 */
class DimensionCategory extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Dimension, $this>
     */
    public function dimension(): BelongsTo
    {
        return $this->belongsTo(Dimension::class);
    }
}
