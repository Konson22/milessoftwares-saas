<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Signed: a credit sale increases what the customer owes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string $branch_id
 * @property string|null $fuel_sale_id
 * @property string $kind
 * @property int $amount_minor
 * @property string|null $idempotency_key
 * @property string|null $note
 * @property string $recorded_by
 * @property CarbonImmutable $occurred_at
 */
class CreditEntry extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const SALE = 'sale';

    public const PAYMENT = 'payment';

    public const VOID = 'void';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
