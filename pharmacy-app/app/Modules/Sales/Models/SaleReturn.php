<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Part of a sale brought back, and the money given back for it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $sale_id
 * @property string $number
 * @property string $reason
 * @property string $currency
 * @property int $total_minor
 * @property PaymentMethod $refund_method
 * @property string|null $till_session_id
 * @property string $returned_by
 * @property CarbonImmutable $returned_at
 * @property-read Sale $sale
 * @property-read User $returner
 * @property-read Collection<int, SaleReturnLine> $lines
 */
class SaleReturn extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'refund_method' => PaymentMethod::class,
            'total_minor' => 'integer',
            'returned_at' => 'immutable_datetime',
        ];
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
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * @return HasMany<SaleReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class)->orderBy('id');
    }
}
