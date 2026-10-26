<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A student's fee invoice for a term (App\Actions\Fees): ISSUED, PAID once
 * payments cover it, or VOIDED (only while unpaid). Finance hears of each
 * step.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $student_id
 * @property string|null $term_id
 * @property string $currency
 * @property int $total_minor
 * @property int $paid_minor
 * @property string $status
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $due_on
 * @property string $issued_by
 * @property string|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property-read Student $student
 * @property-read Term|null $term
 * @property-read User $issuer
 * @property-read User|null $voider
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const VOIDED = 'voided';

    public const STATUSES = [self::ISSUED, self::PAID, self::VOIDED];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'issued_on' => 'date',
            'due_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('description');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function balanceMinor(): int
    {
        return $this->status === self::VOIDED ? 0 : $this->total_minor - $this->paid_minor;
    }

    public function overdue(): bool
    {
        return $this->status === self::ISSUED && $this->due_on !== null && $this->due_on->lt(today());
    }
}
