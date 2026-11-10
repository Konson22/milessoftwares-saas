<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $supplier_id
 * @property string $number
 * @property string $status
 * @property CarbonImmutable $ordered_at
 * @property CarbonImmutable|null $expected_on
 * @property string|null $notes
 * @property string $created_by
 * @property-read Supplier $supplier
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ORDERED = 'ordered';

    public const PARTIAL = 'partial';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'expected_on' => 'date',
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
     * @return HasMany<PurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /**
     * @return HasMany<FuelReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(FuelReceipt::class);
    }
}
