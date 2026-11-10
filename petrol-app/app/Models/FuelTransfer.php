<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Fuel moving between two stations of the same company.
 * Dispatch reduces the source once. Receipt increases the destination once.
 *
 * @property string $id
 * @property string $status
 * @property int $requested_milli
 * @property int|null $dispatched_milli
 * @property int|null $received_milli
 * @property int|null $unit_cost_minor
 * @property int $discrepancy_milli
 * @property string $branch_id
 * @property string|null $destination_branch_id
 * @property string $source_tank_id
 * @property string $destination_tank_id
 * @property string $fuel_product_id
 */
class FuelTransfer extends Model
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
            'discrepancy_milli' => 'integer',
            'transferred_at' => 'datetime',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
