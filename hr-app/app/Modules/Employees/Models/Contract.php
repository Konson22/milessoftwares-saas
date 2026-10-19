<?php

namespace App\Modules\Employees\Models;

use App\Models\User;
use App\Modules\Employees\Enums\EmploymentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * The terms someone works under. An active employee has exactly one
 * current contract; recording a new one ends the previous, and leaving
 * ends the last.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $employee_id
 * @property EmploymentType $contract_type
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $probation_ends_on
 * @property int|null $notice_days
 * @property string|null $note
 * @property string $status
 * @property CarbonImmutable|null $ended_on
 * @property string $recorded_by
 * @property-read Employee $employee
 * @property-read User $recorder
 */
class Contract extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const CURRENT = 'current';

    public const ENDED = 'ended';

    protected $table = 'employment_contracts';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'contract_type' => EmploymentType::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'probation_ends_on' => 'immutable_date',
            'ended_on' => 'immutable_date',
            'notice_days' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
