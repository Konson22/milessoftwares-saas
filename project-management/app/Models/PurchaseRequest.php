<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Enums\PurchaseRequestStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone asks to buy something for a project. Written by
 * App\Actions\Procurement.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $grant_id
 * @property string $number
 * @property string $title
 * @property string $justification
 * @property CostCategory $category
 * @property CarbonImmutable|null $needed_by
 * @property PurchaseRequestStatus $status
 * @property int $estimate_minor
 * @property string $requested_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $selected_quote_id
 * @property string|null $selection_reason
 * @property string|null $selected_by
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 * @property-read Grant|null $grant
 * @property-read User $requester
 * @property-read User|null $decider
 */
class PurchaseRequest extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'category' => CostCategory::class,
            'status' => PurchaseRequestStatus::class,
            'needed_by' => 'date',
            'estimate_minor' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Grant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<PurchaseRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<PurchaseQuote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(PurchaseQuote::class)->orderBy('amount_minor');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The order still standing. One whose spending was rejected or cancelled
     * leaves the request free to order again.
     */
    public function liveOrder(): ?PurchaseOrder
    {
        return $this->orders()->with('expense')->get()->first(fn (PurchaseOrder $order) => $order->isLive());
    }

    /**
     * Quotes can be taken and one chosen.
     */
    public function isBuying(): bool
    {
        return $this->status === PurchaseRequestStatus::Approved
            || ($this->status === PurchaseRequestStatus::Ordered && $this->liveOrder() === null);
    }
}
