<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier's offer on a sourcing event, in the supplier's currency, with
 * the rate it was compared at and its total in the workspace currency.
 *
 * @property string $id
 * @property string $sourcing_event_id
 * @property string $supplier_id
 * @property string $currency
 * @property int|null $rate_minor
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $delivery_minor
 * @property int $total_minor
 * @property int $total_base_minor
 * @property int|null $delivery_days
 * @property bool $late
 * @property string $status
 * @property-read Supplier $supplier
 * @property-read SourcingEvent $event
 * @property-read Collection<int, QuotationLine> $lines
 * @property-read Collection<int, EvaluationScore> $scores
 */
class Quotation extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'quoted_on' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'received_at' => 'immutable_datetime',
            'late' => 'boolean',
            'rate_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'delivery_minor' => 'integer',
            'total_minor' => 'integer',
            'total_base_minor' => 'integer',
            'delivery_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<SourcingEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SourcingEvent::class, 'sourcing_event_id');
    }

    /** @return HasMany<QuotationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    /** @return HasMany<EvaluationScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }
}
