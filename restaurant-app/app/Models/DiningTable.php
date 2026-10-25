<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A table guests sit at in a branch, in an area (the terrace, inside).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string|null $area
 * @property int $seats
 * @property bool $active
 * @property-read Order|null $openOrder
 */
class DiningTable extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'active' => 'boolean'];
    }

    /**
     * The order being served at it, if any: never more than one.
     *
     * @return HasOne<Order, $this>
     */
    public function openOrder(): HasOne
    {
        return $this->hasOne(Order::class)->where('status', Order::OPEN);
    }
}
