<?php

namespace App\Models\Collect;

use App\Models\Concerns\TenantModel;
use App\Models\ProgrammeUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person collecting with a form (ADR-0012 §7, §9). Their phone gets the
 * form while the assignment is active and within its dates. Closing it
 * stops new forms but still accepts finished ones until closes_at;
 * revoking refuses everything.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $form_id
 * @property string $user_id
 * @property string|null $programme_unit_id
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string $status
 * @property CarbonImmutable|null $closes_at
 * @property string $assigned_by
 * @property string|null $ended_by
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable $created_at
 * @property-read Form $form
 * @property-read User $user
 * @property-read ProgrammeUnit|null $unit
 * @property-read User $assigner
 */
class Assignment extends Model
{
    use TenantModel;

    protected $table = 'collect_assignments';

    public const ACTIVE = 'active';

    public const CLOSING = 'closing';

    public const REVOKED = 'revoked';

    /** Days after an assignment's last day during which finished forms still arrive. */
    public const LATE_DAYS = 7;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closes_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether the person may start new forms today.
     */
    public function allowsCollecting(?CarbonImmutable $now = null): bool
    {
        $today = ($now ?? CarbonImmutable::now())->startOfDay();

        return $this->status === self::ACTIVE
            && ($this->starts_on === null || $this->starts_on->lessThanOrEqualTo($today))
            && ($this->ends_on === null || $this->ends_on->greaterThanOrEqualTo($today));
    }

    /**
     * Whether a finished submission from the person is still accepted.
     * Decided by the server's clock and the assignment, never the phone's.
     */
    public function acceptsSubmissions(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return match ($this->status) {
            // Past its last day, forms finished in time still arrive for a week.
            self::ACTIVE => $this->ends_on === null || $this->ends_on->endOfDay()->addDays(self::LATE_DAYS)->greaterThanOrEqualTo($now),
            self::CLOSING => $this->closes_at !== null && $this->closes_at->greaterThan($now),
            default => false,
        };
    }

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', '<>', self::REVOKED);
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ProgrammeUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProgrammeUnit::class, 'programme_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
