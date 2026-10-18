<?php

namespace App\Modules\Billing\Models;

use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What an organization owes Miles Global Softwares. Issued by staff; paid
 * once confirmed payments cover the total.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $subscription_id
 * @property string $number
 * @property string $status open|paid|void
 * @property string $currency
 * @property int $total_minor
 * @property int $amount_paid_minor
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issued_at
 * @property Carbon $due_on
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property string|null $notes
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, Payment> $payments
 */
class Invoice extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'due_on' => 'date',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function balanceMinor(): int
    {
        return $this->status === 'open' ? max(0, $this->total_minor - $this->amount_paid_minor) : 0;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_on->lt(today());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
