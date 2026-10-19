<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Goods that left a branch against a sales order, and their invoice: a
 * sale on account of exactly these goods (App\Modules\Sales\Actions\Deliver).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $branch_id
 * @property string $sales_order_id
 * @property string $number
 * @property string $sale_id
 * @property string|null $received_by_name
 * @property string|null $note
 * @property string $delivered_by
 * @property CarbonImmutable $delivered_at
 * @property string|null $submission_id
 * @property-read SalesOrder $order
 * @property-read Sale $sale
 * @property-read User $deliverer
 * @property-read Collection<int, DeliveryNoteLine> $lines
 */
class DeliveryNote extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * @return HasMany<DeliveryNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('id');
    }
}
