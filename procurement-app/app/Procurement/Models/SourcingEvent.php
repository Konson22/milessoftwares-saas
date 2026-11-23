<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sourcing engine for every competitive method: draft →
 * (pending_approval when a rule asks) → issued → closed → evaluation →
 * awarded / cancelled. Criteria are fixed once issued; quotations are
 * fixed once closed; sealed events hide prices until closed.
 *
 * @property string $id
 * @property string $number
 * @property string $requisition_id
 * @property string $method
 * @property string $title
 * @property bool $sealed
 * @property string $status
 * @property string $created_by
 * @property CarbonImmutable $quotes_due_at
 * @property-read Requisition $requisition
 */
class SourcingEvent extends Model
{
    use TenantModel;

    /** Methods that go through sourcing. Direct purchase goes straight to an order. */
    public const METHODS = ['rfq', 'rfp', 'tender', 'single_source'];

    /** Every method an order or plan item may name. */
    public const ALL_METHODS = ['direct', 'rfq', 'rfp', 'tender', 'single_source', 'framework'];

    public const STATUSES = ['draft', 'pending_approval', 'issued', 'closed', 'evaluation', 'awarded', 'cancelled'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'quotes_due_at' => 'immutable_datetime',
            'required_by' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'sealed' => 'boolean',
        ];
    }

    /** Prices may be shown: unsealed, or no longer taking quotations. */
    public function pricesOpen(): bool
    {
        return ! $this->sealed || ! in_array($this->status, ['draft', 'pending_approval', 'issued'], true);
    }

    /** @return BelongsTo<Requisition, $this> */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /** @return HasMany<SourcingItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SourcingItem::class)->orderBy('line_no');
    }

    /** @return BelongsToMany<Supplier, $this> */
    public function invited(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'sourcing_invitations')->withTimestamps();
    }

    /** @return HasMany<EvaluationCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(EvaluationCriterion::class)->orderBy('sort');
    }

    /** @return HasMany<Quotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /** @return HasMany<Award, $this> */
    public function awards(): HasMany
    {
        return $this->hasMany(Award::class)->latest();
    }
}
