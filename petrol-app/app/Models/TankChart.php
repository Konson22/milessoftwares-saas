<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One version of a tank's depth-to-volume chart. A later version does not
 * rewrite volumes already stored on measurements.
 *
 * @property string $id
 * @property string $tank_id
 * @property string $reference
 * @property int $version
 * @property string $status
 * @property CarbonImmutable $effective_from
 */
class TankChart extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tank, $this>
     */
    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }

    /**
     * @return HasMany<TankChartPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(TankChartPoint::class, 'chart_id');
    }
}
