<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Opening or closing meter for one nozzle on one shift. Approved shifts
 * are immutable; earlier corrections overwrite the reading and are audited.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $shift_id
 * @property string $nozzle_id
 * @property string $kind
 * @property int $reading_milli
 * @property string $recorded_by
 * @property CarbonImmutable $recorded_at
 * @property-read Nozzle $nozzle
 */
class MeterReading extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPENING = 'opening';

    public const CLOSING = 'closing';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'reading_milli' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Nozzle, $this>
     */
    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(Nozzle::class);
    }
}
