<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Goods sent back to a supplier from one of their deliveries.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $supplier_id
 * @property string $goods_receipt_id
 * @property CarbonImmutable $returned_on
 * @property string $reason
 * @property int $total_minor
 * @property string $returned_by
 * @property CarbonImmutable $created_at
 * @property-read Supplier $supplier
 * @property-read GoodsReceipt $receipt
 * @property-read User $returner
 * @property-read Collection<int, SupplierReturnLine> $lines
 */
class SupplierReturn extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'returned_on' => 'immutable_date',
            'total_minor' => 'integer',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * @return HasMany<SupplierReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class)->orderBy('id');
    }
}
