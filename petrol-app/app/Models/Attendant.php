<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A pump attendant. user_id, when set, is the platform subject so a
 * signed-in attendant can sell only as themselves.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $phone
 * @property string $employee_code
 * @property bool $active
 */
class Attendant extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return HasMany<AttendantStation, $this>
     */
    public function stations(): HasMany
    {
        return $this->hasMany(AttendantStation::class);
    }
}
