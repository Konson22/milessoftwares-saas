<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A financial year and its months. Created by App\Finance\Periods, closed
 * by App\Finance\YearEnd.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property string|null $closing_journal_id
 * @property-read Collection<int, FiscalPeriod> $periods
 * @property-read User|null $closer
 * @property-read Journal|null $closingJournal
 */
class FiscalYear extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function closed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * @return HasMany<FiscalPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('number');
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
    public function closingJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'closing_journal_id');
    }
}
