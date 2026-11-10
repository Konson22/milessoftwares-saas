<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An attendant on one nozzle for one shift. Not a permanent pump posting.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $shift_id
 * @property string $attendant_id
 * @property string $nozzle_id
 * @property string|null $branch_id
 * @property string|null $pump_id
 * @property string $status
 * @property int|null $opening_milli
 * @property int|null $closing_milli
 * @property int|null $counted_cash_minor
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property-read Attendant $attendant
 * @property-read Nozzle $nozzle
 */
class ShiftAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    public const BREAK = 'break';

    public const CLOSED = 'closed';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'counted_cash_minor' => 'integer',
            'opening_milli' => 'integer',
            'closing_milli' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->ended_at === null;
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Attendant, $this>
     */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(Attendant::class);
    }

    /**
     * @return BelongsTo<Nozzle, $this>
     */
    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(Nozzle::class);
    }
}
