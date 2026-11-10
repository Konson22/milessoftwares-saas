<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An authorised break in a nozzle meter's continuity. Completed sales stay as they were.
 *
 * @property string $id
 * @property string|null $shift_id
 * @property string $nozzle_id
 * @property string $type
 * @property int $previous_milli
 * @property int $next_milli
 * @property CarbonImmutable $effective_at
 * @property CarbonImmutable $approved_at
 */
class NozzleEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const REPLACEMENT = 'replacement';

    public const RESET = 'reset';

    public const ROLLOVER = 'rollover';

    public const CALIBRATION = 'calibration';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'previous_milli' => 'integer',
            'next_milli' => 'integer',
            'effective_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
