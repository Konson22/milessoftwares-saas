<?php

namespace App\Modules\Leave\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A day nobody works: leave over it does not use a day. Each organization
 * keeps its own list, year by year.
 *
 * @property string $id
 * @property string $organization_id
 * @property CarbonImmutable $date
 * @property string $name
 */
class PublicHoliday extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }
}
