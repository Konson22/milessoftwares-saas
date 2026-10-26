<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One fee on an invoice.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string $description
 * @property int $amount_minor
 */
class InvoiceLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
