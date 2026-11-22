<?php

namespace App\Logbook\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One position as the phone observed it. Never changed after it arrives.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $log_track_id
 * @property int $seq
 * @property string $latitude
 * @property string $longitude
 * @property float|null $accuracy_m
 * @property float|null $speed_mps
 * @property float|null $altitude_m
 * @property CarbonImmutable $captured_at
 * @property CarbonImmutable $received_at
 */
class TrackPoint extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'log_track_points';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'accuracy_m' => 'float',
            'speed_mps' => 'float',
            'altitude_m' => 'float',
            'captured_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
