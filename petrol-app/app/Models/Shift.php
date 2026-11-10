<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One worked shift at a station.
 *
 * open → closing_submitted → under_review → approved, or rejected and
 * submitted again. Approving one's own closing is refused in Shifts.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string|null $schedule_id
 * @property string $number
 * @property CarbonImmutable $business_date
 * @property string $status
 * @property int $opening_float_minor
 * @property string $opened_by
 * @property CarbonImmutable $opened_at
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property int|null $counted_drawer_minor
 * @property int|null $expected_cash_minor
 * @property int|null $cash_difference_minor
 * @property string|null $closing_note
 * @property string|null $handover_note
 * @property array<string, int>|null $counted_by_currency
 * @property-read User $opener
 */
class Shift extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const CLOSING_SUBMITTED = 'closing_submitted';

    public const UNDER_REVIEW = 'under_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_float_minor' => 'integer',
            'opened_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'counted_drawer_minor' => 'integer',
            'counted_by_currency' => 'array',
            'expected_cash_minor' => 'integer',
            'cash_difference_minor' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return HasMany<ShiftAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * @return HasMany<MeterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * @return HasMany<FuelSale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(FuelSale::class);
    }
}
