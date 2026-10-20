<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * What the organization owes a supplier for one delivery or invoice.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $supplier_id
 * @property string $number
 * @property string|null $supplier_reference
 * @property string $source_app
 * @property string|null $branch_id
 * @property CarbonImmutable $bill_date
 * @property CarbonImmutable|null $due_on
 * @property int $total_minor
 * @property int $tax_minor
 * @property int $paid_minor
 * @property list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null, cost_minor: int|null}> $lines
 * @property string|null $memo
 * @property string|null $journal_id
 * @property string|null $currency set when billed in another currency than the books'
 * @property string|null $exchange_rate books-currency units per unit of the currency, at the bill date
 * @property int|null $currency_total_minor the total in the currency
 * @property int $currency_paid_minor paid so far, in the currency
 * @property-read Supplier $supplier
 */
class Bill extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'bill_date' => 'immutable_date',
            'due_on' => 'immutable_date',
            'total_minor' => 'integer',
            'tax_minor' => 'integer',
            'paid_minor' => 'integer',
            'lines' => 'array',
            'currency_total_minor' => 'integer',
            'currency_paid_minor' => 'integer',
            'exchange_rate' => 'decimal:10',
        ];
    }

    public function outstanding(): int
    {
        return $this->total_minor - $this->paid_minor;
    }

    /**
     * What is left to pay in the bill's own currency (the books' for a bill
     * in the books' currency).
     */
    public function currencyOutstanding(): int
    {
        return $this->currency === null ? $this->outstanding() : (int) $this->currency_total_minor - $this->currency_paid_minor;
    }

    public function status(): string
    {
        return match (true) {
            $this->paid_minor >= $this->total_minor => 'paid',
            $this->paid_minor > 0 => 'partly_paid',
            $this->due_on !== null && $this->due_on->isPast() => 'overdue',
            default => 'open',
        };
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
