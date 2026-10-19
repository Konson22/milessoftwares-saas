<?php

namespace App\Modules\Sales\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A customer of one industry module, who may buy on account up to a credit
 * limit and pays within their payment terms (App\Modules\Sales\Actions\
 * CustomerAccounts).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $number
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property int $credit_limit_minor 0: may not buy on account
 * @property int $payment_terms_days
 * @property string|null $price_list_id null: buys at the shop's own prices
 * @property bool $active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PriceList|null $priceList
 */
#[UseFactory(CustomerFactory::class)]
class Customer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['organization_id'];

    protected $attributes = ['credit_limit_minor' => 0, 'payment_terms_days' => 30, 'active' => true];

    protected function casts(): array
    {
        return [
            'credit_limit_minor' => 'integer',
            'payment_terms_days' => 'integer',
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Sales made to this customer, paid or on account.
     *
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return HasMany<CustomerPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class)->orderByDesc('received_at');
    }

    /**
     * Name, number or phone.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.mb_strtolower($term).'%';
        $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(number) LIKE ?', [$like])
            ->orWhere('phone', 'like', '%'.$term.'%'));
    }
}
