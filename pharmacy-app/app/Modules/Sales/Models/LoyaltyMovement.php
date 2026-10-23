<?php

namespace App\Modules\Sales\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Points a customer earned or redeemed on a sale, or that a void or a
 * return took back. Signed: what it did to their balance.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string|null $sale_id
 * @property string|null $sale_return_id
 * @property string $kind earned|redeemed|reversed|returned
 * @property int $points
 * @property CarbonImmutable $created_at
 * @property-read Sale|null $sale
 */
class LoyaltyMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const EARNED = 'earned';

    public const REDEEMED = 'redeemed';

    public const REVERSED = 'reversed';

    public const RETURNED = 'returned';

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
