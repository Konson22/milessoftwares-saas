<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One item of a bill, at the price charged and what it cost (a drink at its
 * average cost at the branch; a dish costs nothing until it has a recipe).
 * Tenant-safe only through its bill.
 *
 * @property string $id
 * @property string $bill_id
 * @property string $menu_item_id
 * @property string $name
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $total_minor
 * @property int $cost_minor
 */
class BillLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['bill_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'total_minor' => 'integer',
            'cost_minor' => 'integer',
        ];
    }
}
