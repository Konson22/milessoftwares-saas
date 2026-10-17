<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * What a customer owes: raised here, or a product's sale or invoice.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $source_app
 * @property string|null $customer_name
 * @property string|null $branch_id
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $due_on
 * @property int $total_minor
 * @property int $tax_minor
 * @property int $paid_minor
 * @property int $credited_minor
 * @property list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null, cost_minor: int|null}> $lines
 * @property string|null $journal_id
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property string|null $void_journal_id
 * @property-read Collection<int, CustomerPayment> $payments
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
            'total_minor' => 'integer',
            'tax_minor' => 'integer',
            'paid_minor' => 'integer',
            'credited_minor' => 'integer',
            'lines' => 'array',
        ];
    }

    public function outstanding(): int
    {
        return $this->voided_at === null ? max(0, $this->total_minor - $this->credited_minor - $this->paid_minor) : 0;
    }

    public function status(): string
    {
        return match (true) {
            $this->voided_at !== null => 'void',
            $this->paid_minor + $this->credited_minor >= $this->total_minor => 'paid',
            $this->paid_minor > 0 => 'partly_paid',
            $this->due_on !== null && $this->due_on->isPast() => 'overdue',
            default => 'open',
        };
    }

    /**
     * @return HasMany<CustomerPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
