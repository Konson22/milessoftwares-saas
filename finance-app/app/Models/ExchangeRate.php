<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A rate typed by the organization: books-currency units for one unit of
 * the currency, used from its day until the next rate. See App\Finance\Currencies.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $currency
 * @property CarbonImmutable $rate_on
 * @property string $rate
 * @property string|null $created_by
 * @property-read User|null $creator
 */
class ExchangeRate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'rate_on' => 'immutable_date',
            'rate' => 'decimal:10',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
