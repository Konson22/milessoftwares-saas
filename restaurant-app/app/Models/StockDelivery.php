<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Drinks a supplier delivered to a branch, on one delivery note.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $supplier_name
 * @property string|null $supplier_reference
 * @property CarbonImmutable $received_on
 * @property int $total_minor
 * @property string|null $note
 * @property string $received_by
 * @property CarbonImmutable $created_at
 * @property-read User $receiver
 */
class StockDelivery extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'total_minor' => 'integer'];
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'delivery_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
