<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A journal posted on a schedule (App\Finance\RecurringJournals).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $memo
 * @property string $frequency
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $next_on null once it has no more to post
 * @property int $posted_count
 * @property bool $reverses each journal is reversed the next day (accruals)
 * @property bool $active
 * @property list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}> $lines
 * @property string|null $last_journal_id
 * @property string|null $last_error why the latest attempt did not post
 * @property string|null $created_by
 * @property string|null $updated_by who last added or changed it (it cannot approve itself)
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at null: not approved since it was last changed
 * @property-read Journal|null $lastJournal
 * @property-read User|null $approver
 */
class RecurringJournal extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const HALF_YEARLY = 'half_yearly';

    public const YEARLY = 'yearly';

    /** Frequency => label. */
    public const FREQUENCIES = [
        self::WEEKLY => 'Every week',
        self::MONTHLY => 'Every month',
        self::QUARTERLY => 'Every quarter',
        self::HALF_YEARLY => 'Every six months',
        self::YEARLY => 'Every year',
    ];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'next_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'posted_count' => 'integer',
            'reverses' => 'boolean',
            'active' => 'boolean',
            'lines' => 'array',
        ];
    }

    /**
     * The day occurrence $n (from 0) is dated: the start plus n intervals,
     * counted from the start so a month end stays a month end.
     */
    public function occurrenceOn(int $n): CarbonImmutable
    {
        return match ($this->frequency) {
            self::WEEKLY => $this->starts_on->addWeeks($n),
            self::QUARTERLY => $this->monthsFromStart(3 * $n),
            self::HALF_YEARLY => $this->monthsFromStart(6 * $n),
            self::YEARLY => $this->monthsFromStart(12 * $n),
            default => $this->monthsFromStart($n),
        };
    }

    /**
     * The day occurrence $n is dated, or null when it falls after the end.
     */
    public function nextAfter(int $n): ?CarbonImmutable
    {
        $on = $this->occurrenceOn($n);

        return $this->ends_on !== null && $on->greaterThan($this->ends_on) ? null : $on;
    }

    public function total(): int
    {
        return (int) array_sum(array_column($this->lines, 'debit_minor'));
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function lastJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'last_journal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<Journal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /**
     * Months after the start; a start on the last day of its month stays on
     * the last day (31 Jan, 28 Feb, 31 Mar).
     */
    private function monthsFromStart(int $months): CarbonImmutable
    {
        $on = $this->starts_on->addMonthsNoOverflow($months);

        return $this->starts_on->isLastOfMonth() ? $on->endOfMonth()->startOfDay() : $on;
    }
}
