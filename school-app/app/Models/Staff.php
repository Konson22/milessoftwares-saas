<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A teacher, instructor, lecturer or other member of staff. HR hears of
 * each one added (hr.employee.joined); pay and personal records live there.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $name
 * @property string $position
 * @property string|null $department_id
 * @property string|null $phone
 * @property string|null $email
 * @property CarbonImmutable $started_on
 * @property bool $active
 * @property-read Department|null $department
 */
class Staff extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'staff';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
