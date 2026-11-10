<?php

namespace App\Models;

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
 * cannot disagree.
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
 * @property-read Supplier $supplier
 * @property-read Expense $expense
 * @property-read PurchaseRequest $request
 */
class PurchaseOrder extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'invoice_on' => 'date'];
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
}
