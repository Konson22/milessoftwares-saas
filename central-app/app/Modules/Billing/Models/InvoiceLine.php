<?php

namespace App\Modules\Billing\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string|null $plan_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $amount_minor
 */
class InvoiceLine extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'amount_minor' => 'integer',
        ];
    }
}
