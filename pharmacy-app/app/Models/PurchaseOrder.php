<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $supplier_id
 * @property PurchaseOrderStatus $status
 * @property CarbonImmutable|null $expected_on
 * @property string|null $notes
 * @property string $created_by
 * @property CarbonImmutable|null $ordered_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property-read Supplier $supplier
 * @property-read Collection<int, PurchaseOrderLine> $lines
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_on' => 'immutable_date',
            'ordered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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
     * Lines are reached through their order only (they carry no tenant of
     * their own): never look a PurchaseOrderLine up by id on its own.
     *
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        // Ids are time-ordered (UUID v7): lines in the order they were entered.
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    public function totalMinor(): int
    {
        return (int) $this->lines->sum(fn (PurchaseOrderLine $line) => $line->quantity_ordered * $line->unit_cost_minor);
    }
}
