<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One person's pay on one run, with the person as they were then.
 *
 * @property string $id
 * @property string $payroll_run_id
 * @property string $employee_id
 * @property string $employee_number
 * @property string $employee_name
 * @property string|null $job_title
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $days_paid
 * @property string|null $hours_paid
 * @property int $basic_minor
 * @property int $earnings_minor
 * @property int $taxable_minor
 * @property int $income_tax_minor
 * @property int $social_security_employee_minor
 * @property int $social_security_employer_minor
 * @property int $other_deductions_minor
 * @property int $net_minor
 * @property list<array{kind: string, name: string, amount_minor: int}> $lines
 * @property-read PayrollRun $run
 */
class Payslip extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'basic_minor' => 'integer',
            'earnings_minor' => 'integer',
            'taxable_minor' => 'integer',
            'income_tax_minor' => 'integer',
            'social_security_employee_minor' => 'integer',
            'social_security_employer_minor' => 'integer',
            'other_deductions_minor' => 'integer',
            'net_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }
}
