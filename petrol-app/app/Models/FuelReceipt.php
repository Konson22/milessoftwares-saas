<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Fuel received into a tank. Stock increases by the accepted quantity when the delivery is approved, once.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $supplier_id
 * @property string|null $purchase_order_id
 * @property string|null $purchase_line_id
 * @property string $tank_id
 * @property string $number
 * @property string|null $tanker
 * @property string|null $driver
 * @property int $declared_milli
 * @property int $measured_milli
 * @property int $discrepancy_milli
 * @property string $status
 * @property int|null $accepted_milli
 * @property int $unit_cost_minor
 * @property int $total_minor
 * @property string|null $supplier_reference
 * @property string|null $idempotency_key
 * @property string $received_by
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $received_at
 * @property-read Supplier $supplier
 * @property-read Tank $tank
 */
class FuelReceipt extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'declared_milli' => 'integer',
            'measured_milli' => 'integer',
            'discrepancy_milli' => 'integer',
            'accepted_milli' => 'integer',
            'unit_cost_minor' => 'integer',
            'total_minor' => 'integer',
            'received_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Tank, $this>
     */
    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }
}
