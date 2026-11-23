<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $sourcing_event_id
 * @property string|null $requisition_line_id
 * @property int $line_no
 * @property string $kind
 * @property string $description
 * @property string|null $specification
 * @property string $quantity
 * @property string|null $unit
 */
class SourcingItem extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }
}
