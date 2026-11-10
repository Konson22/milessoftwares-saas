<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $work_order_id
 * @property string $kind
 * @property string $description
 * @property int $quantity_milli
 * @property int $amount_minor
 * @property string|null $store_id
 * @property string|null $store_product_id
 * @property bool $issued
 */
class WorkOrderLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const SERVICE = 'service';

    public const PART = 'part';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'amount_minor' => 'integer',
            'issued' => 'boolean',
        ];
    }
}
