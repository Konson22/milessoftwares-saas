<?php

namespace App\Modules\Sales\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * How customers of one industry earn and spend points
 * (App\Modules\Sales\Actions\Loyalty).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property bool $active
 * @property int $spend_per_point_minor spending this much earns one point
 * @property int $point_value_minor what one point takes off a sale
 * @property string $updated_by
 * @property CarbonImmutable $updated_at
 */
class LoyaltyProgram extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'spend_per_point_minor' => 'integer',
            'point_value_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Points a sale of this total earns.
     */
    public function earns(int $totalMinor): int
    {
        return $this->spend_per_point_minor === 0 ? 0 : intdiv(max(0, $totalMinor), $this->spend_per_point_minor);
    }
}
