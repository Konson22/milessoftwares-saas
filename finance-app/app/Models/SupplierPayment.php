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
 * Money paid to a supplier, set against their bills.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $supplier_id
 * @property CarbonImmutable $paid_on
 * @property int $amount_minor
 * @property string $account_id
 * @property string|null $reference
 * @property string|null $memo
 * @property string $journal_id
 * @property string $created_by
 * @property-read Supplier $supplier
 * @property-read Account $account
 * @property-read Collection<int, BillPayment> $allocations
 */
class SupplierPayment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount_minor' => 'integer',
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<BillPayment, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }
}
