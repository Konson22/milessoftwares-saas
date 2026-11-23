<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $receipt_id
 * @property string $purchase_order_line_id
 * @property string $received_quantity
 * @property string $accepted_quantity
 * @property string $rejected_quantity
 * @property string|null $rejection_reason
 */
class ReceiptLine extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];
}
