<?php

namespace App\Modules\Leave\Models;

use App\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Time off someone asked for, and what became of it. The working days are
 * counted once, when it is requested; later holiday changes do not alter it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $employee_id
 * @property string $leave_type_id
 * @property int $year
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $days
 * @property string|null $reason
 * @property LeaveStatus $status
 * @property string $requested_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property-read Employee $employee
 * @property-read LeaveType $type
 * @property-read User $requester
 * @property-read User|null $decider
 * @property-read User|null $canceller
 */
class LeaveRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'days' => 'integer',
            'status' => LeaveStatus::class,
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
