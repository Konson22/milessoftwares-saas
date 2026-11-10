<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Money waiting to leave a bank or cash account (App\Finance\PaymentRequests).
 * Not in the ledger until it is disbursed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $kind
 * @property string $status
 * @property string $description
 * @property string|null $payee
 * @property string $account_id the bank or cash account the money leaves
 * @property string|null $other_account_id what it is spent on, or where a transfer goes
 * @property string|null $supplier_id
 * @property string|null $cost_centre_id
 * @property string|null $project_id
 * @property int $amount_minor in the payment's currency
 * @property string|null $currency null: the books'
 * @property int $books_minor in the books' currency, which the approval limit is compared with
 * @property CarbonImmutable $pay_on
 * @property array{allocations?: array<string, int>, reference?: string|null, rate?: string|null, received_minor?: int|null, tax_return_id?: string, lines?: list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}>, draft?: string|null, journal_id?: string, memo?: string, petty_cash_fund_id?: string, voucher_ids?: list<string>}|null $details
 * @property array<string, mixed>|null $policy the approval rules in force when it was raised (PaymentPolicy::snapshot)
 * @property string $prepared_by
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $approved_by null on an approved request: below the approval limit
 * @property CarbonImmutable|null $approved_at
 * @property string|null $disbursed_by
 * @property CarbonImmutable|null $disbursed_at
 * @property string|null $closed_by who rejected or withdrew it
 * @property CarbonImmutable|null $closed_at
 * @property string|null $close_note
 * @property string|null $journal_id
 * @property string|null $supplier_payment_id
 * @property CarbonImmutable $created_at
 * @property-read Account $account
 * @property-read Account|null $otherAccount
 * @property-read Supplier|null $supplier
 * @property-read DimensionValue|null $costCentre
 * @property-read User $preparer
 * @property-read User|null $reviewer
 * @property-read User|null $approver
 * @property-read User|null $disburser
 * @property-read User|null $closer
 * @property-read Journal|null $journal
 */
class PaymentRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const SUPPLIER = 'supplier';

    public const EXPENSE = 'expense';

    public const ADVANCE = 'advance';

    public const TRANSFER = 'transfer';

    /** A tax return's settlement, held when it is settled. */
    public const TAX = 'tax';

    /** A manual journal that takes money out of a bank, held when it is entered or approved. */
    public const JOURNAL = 'journal';

    /** The reversal of a journal that brought money in, held when it is asked for. */
    public const REVERSAL = 'reversal';

    /** What someone can ask for on the form; the rest only come from being held. */
    public const KINDS = [self::SUPPLIER, self::EXPENSE, self::ADVANCE, self::TRANSFER];

    public const PENDING_REVIEW = 'pending_review';

    public const PENDING_APPROVAL = 'pending_approval';

    /** Ready to disburse. */
    public const APPROVED = 'approved';

    public const DISBURSED = 'disbursed';

    public const REJECTED = 'rejected';

    /** Taken back by the person who prepared it. */
    public const WITHDRAWN = 'withdrawn';

    /** Waiting for someone to decide or to pay. */
    public const OPEN = [self::PENDING_REVIEW, self::PENDING_APPROVAL, self::APPROVED];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'pay_on' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'disbursed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'details' => 'array',
            'policy' => 'array',
            'amount_minor' => 'integer',
            'books_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function otherAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'other_account_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<DimensionValue, $this>
     */
    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(DimensionValue::class, 'cost_centre_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
