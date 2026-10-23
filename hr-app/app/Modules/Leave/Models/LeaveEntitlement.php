<?php

namespace App\Modules\Leave\Models;

use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Someone's days of one kind of leave in one year: the year's days (the
 * type's, unless HR changed them) and what was carried over from the year
 * before. Created when first needed (App\Modules\Leave\Actions\LeaveBalances).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $employee_id
 * @property string $leave_type_id
 * @property int $year
 * @property int $days
 * @property int $carried_days
 * @property string|null $updated_by
 * @property-read Employee $employee
 * @property-read LeaveType $type
 */
class LeaveEntitlement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'integer',
            'carried_days' => 'integer',
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
}
