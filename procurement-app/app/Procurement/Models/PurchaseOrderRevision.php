<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * The order as it stood before an amendment.
 *
 * @property string $id
 * @property int $revision
 * @property array<string, mixed> $snapshot
 * @property string $reason
 */
class PurchaseOrderRevision extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'revision' => 'integer'];
    }
}
