<?php

namespace App\Modules\Employees\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An employee's personal details: identity numbers, date of birth, home
 * contacts, next of kin and bank account. Stored encrypted as one array
 * (FIELDS), behind hr.personal.view / hr.personal.manage; every view and
 * change is written to the HR audit log by field name, never by value.
 *
 * @property string $employee_id
 * @property string $organization_id
 * @property array<string, string|null> $details
 * @property string $updated_by
 */
class PersonalDetails extends Model
{
    use BelongsToTenant;

    /**
     * Every field, with its label, in the order forms show them.
     */
    public const FIELDS = [
        'date_of_birth' => 'Date of birth',
        'gender' => 'Gender',
        'nationality' => 'Nationality',
        'national_id' => 'National ID number',
        'passport_number' => 'Passport number',
        'tax_id' => 'Tax identification number',
        'social_security_number' => 'Social security number',
        'personal_phone' => 'Personal phone',
        'personal_email' => 'Personal email',
        'home_address' => 'Home address',
        'next_of_kin_name' => 'Next of kin',
        'next_of_kin_relationship' => 'Relationship',
        'next_of_kin_phone' => 'Next of kin phone',
        'bank_name' => 'Bank',
        'bank_branch' => 'Bank branch',
        'bank_account_name' => 'Account name',
        'bank_account_number' => 'Account number',
    ];

    protected $table = 'employee_personal_details';

    protected $primaryKey = 'employee_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }

    /**
     * Every field, empty ones as null.
     *
     * @return array<string, string|null>
     */
    public function values(): array
    {
        $details = $this->details;

        return array_map(fn (string $field) => isset($details[$field]) && $details[$field] !== '' ? (string) $details[$field] : null, array_combine(array_keys(self::FIELDS), array_keys(self::FIELDS)));
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
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
