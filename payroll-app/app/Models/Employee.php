<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Someone Payroll pays. Entered in Payroll, or kept in step by HR (or any
 * product) through payroll.employee.* events, which carry no pay: pay and
 * payment details are entered here only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $employee_number
 * @property string $first_name
 * @property string $last_name
 * @property string|null $work_email
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $job_title
 * @property string $pay_basis
 * @property string $status
 * @property CarbonImmutable $hired_on
 * @property CarbonImmutable|null $left_on
 * @property array<string, string|null>|null $payment_details
 * @property string|null $source_app
 * @property string|null $source_reference
 * @property string|null $created_by
 */
class Employee extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    public const LEFT = 'left';

    public const MONTHLY = 'monthly';

    public const HOURLY = 'hourly';

    protected $guarded = ['organization_id'];

    protected $hidden = ['payment_details'];

    protected function casts(): array
    {
        return [
            'hired_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'payment_details' => 'encrypted:array',
        ];
    }

    public function name(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Kept in step by another application: its work details change there. */
    public function isLinked(): bool
    {
        return $this->source_app !== null;
    }

    /**
     * @return HasMany<EmployeePay, $this>
     */
    public function pay(): HasMany
    {
        return $this->hasMany(EmployeePay::class);
    }

    /**
     * @return HasMany<EmployeeComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(EmployeeComponent::class);
    }

    /**
     * Basic pay in force on a date (null: none set yet).
     */
    public function payOn(CarbonImmutable $date): ?EmployeePay
    {
        return $this->pay()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }
}
