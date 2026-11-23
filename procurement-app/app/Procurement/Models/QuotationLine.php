<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $quotation_id
 * @property string $sourcing_item_id
 * @property string $quantity
 * @property int $unit_price_minor
 * @property int $amount_minor
 * @property bool $compliant
 * @property string|null $note
 */
class QuotationLine extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer', 'amount_minor' => 'integer', 'compliant' => 'boolean'];
    }
}
