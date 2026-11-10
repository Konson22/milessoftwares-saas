<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $fuel_sale_id
 * @property string $kind
 * @property int $amount_minor
 * @property string|null $reference
 * @property string|null $customer_id
 */
class SalePayment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
