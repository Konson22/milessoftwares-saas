<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A physical count against one custodian and one currency.
 *
 * @property int $expected_minor
 * @property int $counted_minor
 * @property int $difference_minor
 */
class CashCount extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'expected_minor' => 'integer',
            'counted_minor' => 'integer',
            'difference_minor' => 'integer',
            'counted_at' => 'datetime',
        ];
    }
}
