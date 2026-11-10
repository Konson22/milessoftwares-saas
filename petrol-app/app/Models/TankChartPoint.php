<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $chart_id
 * @property int $depth_mm
 * @property int $volume_milli
 */
class TankChartPoint extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'tank_calibration_points';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'depth_mm' => 'integer',
            'volume_milli' => 'integer',
        ];
    }
}
