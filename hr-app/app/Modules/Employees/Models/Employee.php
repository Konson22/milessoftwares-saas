<?php

namespace App\Modules\Employees\Models;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Employees\Enums\EmploymentType;
use App\Modules\Employees\Enums\LeavingReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Someone who works, or worked, for the organization, whether or not they
 * also sign in. Work details only: personal details are a separate,
 * encrypted record (PersonalDetails) behind their own permission.
 *
 * Branch, department and position are where they work now, kept in step
 * with their latest employment record (App\Modules\Employees\Actions\Employment).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $employee_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $preferred_name
 * @property string|null $work_email
 * @property string|null $work_phone
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $position_id
 * @property string|null $job_title
 * @property string|null $manager_id
 * @property EmploymentType $employment_type
 * @property string $status
 * @property CarbonImmutable $hired_on
 * @property CarbonImmutable|null $left_on
 * @property LeavingReason|null $leaving_reason
 * @property string|null $membership_id
 * @property string $created_by
 * @property-read Employee|null $manager
 * @property-read PersonalDetails|null $personalDetails
 */
class Employee extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    public const LEFT = 'left';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'hired_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'leaving_reason' => LeavingReason::class,
        ];
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]));
    }

    /**
     * How colleagues know them: the preferred first name, if any.
     */
    public function displayName(): string
    {
        return ($this->preferred_name ?: $this->first_name).' '.$this->last_name;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /**
     * @return HasOne<PersonalDetails, $this>
     */
    public function personalDetails(): HasOne
    {
        return $this->hasOne(PersonalDetails::class);
    }

    /**
     * @return HasMany<EmploymentRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class);
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
