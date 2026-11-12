<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Hours someone worked on a day, as a product reported them
 * (payroll.timesheet.submitted). A resubmitted day replaces the earlier one.
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $work_date
 * @property string $hours
 * @property string $kind
 * @property string|null $branch_id
 * @property string $source_app
 * @property string $source_reference
 */
class TimesheetEntry extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['regular', 'overtime', 'leave', 'holiday'];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
        ];
    }
}
