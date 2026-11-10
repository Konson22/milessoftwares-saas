<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string $starts_at
 * @property string $ends_at
 * @property bool $active
 */
class ShiftSchedule extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function crossesMidnight(): bool
    {
        return $this->ends_at <= $this->starts_at;
    }
}
