<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A physical dip compared with the calculated stock.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $tank_id
 * @property string|null $shift_id
 * @property string $method
 * @property int|null $depth_mm
 * @property string|null $temperature_c
 * @property int|null $water_mm
 * @property int $measured_milli
 * @property int $book_milli
 * @property int $variance_milli
 * @property string $status
 * @property string $measured_by
 * @property string|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $measured_at
 * @property string|null $notes
 * @property string|null $idempotency_key
 */
class TankMeasurement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const VOLUME = 'volume';

    public const DIPSTICK = 'dipstick';

    public const GAUGE = 'gauge';

    public const RECORDED = 'recorded';

    public const VERIFIED = 'verified';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'depth_mm' => 'integer',
            'water_mm' => 'integer',
            'measured_milli' => 'integer',
            'book_milli' => 'integer',
            'variance_milli' => 'integer',
            'measured_at' => 'datetime',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
