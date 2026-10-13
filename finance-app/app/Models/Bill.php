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
 * @property list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null}> $lines
 * @property string|null $memo
 * @property string|null $journal_id
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
        ];
    }

    public function outstanding(): int
    {
        return $this->total_minor - $this->paid_minor;
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
