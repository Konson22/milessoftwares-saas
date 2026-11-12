<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Basic pay from a date: a monthly salary, or an hourly rate for someone
 * paid by the hour. Never changed: a raise is a new row.
 *
 * @property string $id
 * @property string $employee_id
 * @property CarbonImmutable $effective_from
 * @property int $amount_minor
 * @property string|null $note
 * @property string $created_by
 * @property-read User $creator
 */
class EmployeePay extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'employee_pay';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
