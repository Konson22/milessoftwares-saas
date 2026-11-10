<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $goods_receipt_id
 * @property string $purchase_request_line_id
 * @property string $quantity
 */
class GoodsReceiptLine extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }
}
