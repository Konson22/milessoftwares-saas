<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A supplier's credit note (goods returned): owed back, taken off a bill.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $supplier_id
 * @property string $number
 * @property string $source_app
 * @property string|null $bill_id
 * @property string|null $branch_id
 * @property CarbonImmutable $credited_on
 * @property int $total_minor
 * @property int $tax_minor
 * @property int $applied_minor
 * @property string $reason
 * @property list<array{description: string, quantity: int|float|null, amount_minor: int, account: string|null, cost_minor: int|null}> $lines
 * @property string|null $journal_id
 * @property-read Supplier $supplier
 * @property-read Bill|null $bill
 */
class SupplierCredit extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'credited_on' => 'immutable_date',
            'total_minor' => 'integer',
            'tax_minor' => 'integer',
            'applied_minor' => 'integer',
            'lines' => 'array',
        ];
    }

    /**
     * What is left on the supplier's account after the bill.
     */
    public function unapplied(): int
    {
        return $this->total_minor - $this->applied_minor;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
