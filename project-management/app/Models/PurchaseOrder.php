<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
use App\Enums\ExpenseStatus;
use App\Support\Quantity;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order to a supplier. Its commitment is its expense: where that stands
 * (awaiting approval, approved, paid) is where the order stands, so the two
 * cannot disagree. amount_minor is what was ordered and never changes; the
 * expense is the commitment, which closing short brings down to what was
 * invoiced (ADR-0020).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_request_id
 * @property string $purchase_quote_id
 * @property string $supplier_id
 * @property string $expense_id
 * @property string $number
 * @property int $amount_minor
 * @property string|null $terms
 * @property string|null $invoice_reference
 * @property CarbonImmutable|null $invoice_on
 * @property string $created_by
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property string|null $close_reason
 * @property-read Supplier $supplier
 * @property-read Expense $expense
 * @property-read PurchaseRequest $request
 */
class PurchaseOrder extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'invoice_on' => 'date', 'closed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('received_on')->orderBy('created_at');
    }

    /**
     * @return HasMany<SupplierInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class)->orderBy('invoice_on')->orderBy('created_at');
    }

    /** Live supplier invoices against this order, in total. */
    public function invoicedMinor(): int
    {
        return (int) $this->invoices()->live()->sum('amount_minor');
    }

    /** Recorded before supplier invoices were kept: one reference, taken as the whole order. */
    public function hasLegacyInvoice(): bool
    {
        return $this->invoice_reference !== null && ! $this->invoices()->exists();
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /** Everything ordered has arrived, or the order was closed with what did. */
    public function isComplete(): bool
    {
        return $this->isClosed() || $this->isFullyReceived();
    }

    /**
     * Payable: complete, and billed for exactly the commitment (its expense).
     * Legacy orders with a single reference stay payable as before.
     */
    public function isPayable(): bool
    {
        if (! $this->isComplete()) {
            return false;
        }
        if ($this->hasLegacyInvoice()) {
            return true;
        }

        return $this->invoicedMinor() === $this->expense->amount_minor;
    }

    public function isLive(): bool
    {
        return ! in_array($this->expense->status, [ExpenseStatus::Rejected, ExpenseStatus::Cancelled], true);
    }

    /**
     * Quantity received so far, by request line.
     *
     * @return array<string, string>
     */
    public function receivedByLine(): array
    {
        $received = [];
        foreach ($this->receipts()->with('lines')->get() as $receipt) {
            foreach ($receipt->lines as $line) {
                $received[$line->purchase_request_line_id] = Quantity::add($received[$line->purchase_request_line_id] ?? '0', (string) $line->quantity);
            }
        }

        return $received;
    }

    public function isFullyReceived(): bool
    {
        $received = $this->receivedByLine();
        $lines = PurchaseRequestLine::query()->where('purchase_request_id', $this->purchase_request_id)->get();

        return $lines->every(fn (PurchaseRequestLine $line) => Quantity::compare($received[$line->id] ?? '0', (string) $line->quantity) >= 0);
    }

    /**
     * Seen only with the project its PurchaseRequest belongs to (App\Access\VisibleThroughProject).
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['purchase_request_id', PurchaseRequest::class];
    }
}
