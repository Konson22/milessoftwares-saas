<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Fuel that left a tank for a purpose other than a customer sale.
 *
 * @property string $id
 * @property string $tank_id
 * @property string|null $nozzle_id
 * @property string|null $shift_id
 * @property int $quantity_milli
 * @property int $returned_milli
 * @property string $purpose
 * @property string $status
 * @property CarbonImmutable $occurred_at
 */
class FuelDraw extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const TEST = 'test';

    public const RECORDED = 'recorded';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'returned_milli' => 'integer',
            'occurred_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
