<?php

namespace App\Logbook\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Start, pause, resume, end, or a time the phone could not record
 * (interrupted, GPS off, permission taken away), as the phone logged it.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $log_track_id
 * @property int $seq
 * @property string $kind
 * @property CarbonImmutable $at
 * @property string|null $note
 * @property CarbonImmutable $received_at
 */
class TrackEvent extends Model
{
    use BelongsToTenant;

    public const KINDS = ['start', 'pause', 'resume', 'end', 'interrupted', 'recovered', 'gps_off', 'gps_on', 'permission_lost'];

    /** Events after which the recorded line does not continue. */
    public const BREAKS = ['pause', 'end', 'interrupted', 'gps_off', 'permission_lost'];

    public $timestamps = false;

    protected $table = 'log_track_events';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['seq' => 'integer', 'at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }
}
