<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Money received from a customer (receipt) or given back (refund).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $source_app
 * @property string $kind
 * @property string|null $invoice_id
 * @property string|null $invoice_number
 * @property PaymentMethod $method
 * @property int $amount_minor
 * @property CarbonImmutable $received_on
 * @property string $account_id
 * @property string|null $processor_reference
 * @property string|null $memo
 * @property string $journal_id
 * @property string|null $created_by
 * @property-read Invoice|null $invoice
 * @property-read Account $account
 */
class CustomerPayment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const RECEIPT = 'receipt';

    public const REFUND = 'refund';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount_minor' => 'integer',
            'received_on' => 'immutable_date',
        ];
    }

    /**
     * What it does to the invoice's paid amount.
     */
    public function signedAmount(): int
    {
        return $this->kind === self::REFUND ? -$this->amount_minor : $this->amount_minor;
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
