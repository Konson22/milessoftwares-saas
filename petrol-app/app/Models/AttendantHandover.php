<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A request to pass one nozzle from one attendant to another.
 * Approving it closes one assignment and opens the next. Cash stays
 * with the attendant who collected it.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $shift_id
 * @property string $nozzle_id
 * @property string $outgoing_assignment_id
 * @property string $incoming_attendant_id
 * @property string|null $incoming_assignment_id
 * @property int $closing_milli
 * @property int $opening_milli
 * @property string $status
 * @property string|null $event_reason
 * @property string $requested_by
 */
class AttendantHandover extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const REQUESTED = 'requested';

    public const VERIFIED = 'verified';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'closing_milli' => 'integer',
            'opening_milli' => 'integer',
            'requested_at' => 'datetime',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShiftAssignment, $this>
     */
    public function outgoing(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'outgoing_assignment_id');
    }

    /**
     * @return BelongsTo<Attendant, $this>
     */
    public function incomingAttendant(): BelongsTo
    {
        return $this->belongsTo(Attendant::class, 'incoming_attendant_id');
    }

    /**
     * @return BelongsTo<Nozzle, $this>
     */
    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(Nozzle::class);
    }
}
