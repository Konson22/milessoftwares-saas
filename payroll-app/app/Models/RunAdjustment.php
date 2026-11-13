<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A one-off earning or deduction on one person's payslip for one run.
 *
 * @property string $id
 * @property string $payroll_run_id
 * @property string $employee_id
 * @property string $kind
 * @property string $name
 * @property int $amount_minor
 * @property bool $taxable
 * @property string $created_by
 * @property-read Employee $employee
 * @property-read User $creator
 */
class RunAdjustment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'taxable' => 'boolean',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
