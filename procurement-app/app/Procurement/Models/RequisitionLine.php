<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $requisition_id
 * @property int $line_no
 * @property string $kind
 * @property string $description
 * @property string|null $specification
 * @property string $quantity
 * @property string|null $unit
 * @property int $unit_price_minor
 * @property int $amount_minor
 */
class RequisitionLine extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'unit_price_minor' => 'integer', 'amount_minor' => 'integer'];
    }
}
