<?php

namespace App\Modules\Employees\Models;

use App\Models\User;
use App\Modules\Employees\Enums\RecordKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One step in someone's employment: hired, moved, promoted, left. Never
 * changed once written; a mistake is put right by a newer record.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $employee_id
 * @property RecordKind $kind
 * @property CarbonImmutable $effective_on
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $position_id
 * @property string|null $job_title
 * @property string|null $note
 * @property string $recorded_by
 * @property CarbonImmutable $created_at
 * @property-read User $recorder
 */
class EmploymentRecord extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'kind' => RecordKind::class,
            'effective_on' => 'immutable_date',
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
