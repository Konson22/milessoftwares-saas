<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * What the station paid a supplier. Finance still holds the bill until
 * someone pays it there: there is no supplier-payment event.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $supplier_id
 * @property string|null $fuel_receipt_id
 * @property int $amount_minor
 * @property string $paid_from
 * @property string|null $reference
 * @property string|null $idempotency_key
 * @property string $paid_by
 * @property CarbonImmutable $paid_at
 */
class SupplierPayment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
        ];
    }
}
