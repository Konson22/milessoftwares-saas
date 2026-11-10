<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $status
 * @property string $source_store_id
 * @property string $destination_store_id
 * @property string $product_id
 * @property int $requested_milli
 * @property int|null $dispatched_milli
 * @property int|null $received_milli
 * @property int|null $unit_cost_minor
 * @property int|null $variance_milli
 */
class StoreTransfer extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const DISPATCHED = 'dispatched';

    public const RECEIVED = 'received';

    public const COMPLETED = 'completed';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'requested_milli' => 'integer',
            'dispatched_milli' => 'integer',
            'received_milli' => 'integer',
            'unit_cost_minor' => 'integer',
            'variance_milli' => 'integer',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
