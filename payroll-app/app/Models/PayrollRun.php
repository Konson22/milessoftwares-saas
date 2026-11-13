<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One pay period's payroll:
 *
 *   draft      prepared, recalculated as often as needed
 *   reviewed   checked by someone other than the preparer (optional);
 *              recalculating sends it back to draft
 *   approved   by someone other than the preparer: locked, and Finance gets
 *              one summarised journal
 *   part_paid  some payslips paid
 *   paid       every payslip paid
 *   reversed   approved by mistake and taken back before any pay went out;
 *              Finance reverses the journal and the month is free again
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $pay_date
 * @property string $status
 * @property string|null $currency
 * @property int $headcount
 * @property int $earnings_minor
 * @property int $income_tax_minor
 * @property int $social_security_employee_minor
 * @property int $social_security_employer_minor
 * @property int $other_deductions_minor
 * @property int $net_minor
 * @property int $paid_minor
 * @property string $prepared_by
 * @property CarbonImmutable $prepared_at
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $paid_by
 * @property CarbonImmutable|null $paid_at
 * @property string|null $payment_method
 * @property string|null $reversed_by
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversal_reason
 * @property string|null $note
 * @property-read User $preparer
 * @property-read User|null $reviewer
 * @property-read User|null $approver
 * @property-read User|null $payer
 * @property-read User|null $reverser
 */
class PayrollRun extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const DRAFT = 'draft';

    public const REVIEWED = 'reviewed';

    public const APPROVED = 'approved';

    public const PART_PAID = 'part_paid';

    public const PAID = 'paid';

    public const REVERSED = 'reversed';

    public const PAYMENT_METHODS = ['bank', 'cash', 'mobile_money'];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'pay_date' => 'immutable_date',
            'prepared_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'headcount' => 'integer',
            'earnings_minor' => 'integer',
            'income_tax_minor' => 'integer',
            'social_security_employee_minor' => 'integer',
            'social_security_employer_minor' => 'integer',
            'other_deductions_minor' => 'integer',
            'net_minor' => 'integer',
            'paid_minor' => 'integer',
        ];
    }

    /** Still being worked on: payslips can be recalculated. */
    public function isDraft(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REVIEWED], true);
    }

    /** Approved and not reversed: pay can go out. */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PART_PAID], true);
    }

    public function label(): string
    {
        return $this->period_start->format('F Y');
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return HasMany<PayrollPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
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
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
