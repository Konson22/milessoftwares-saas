<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One month of a financial year. Locked months take no manual postings.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fiscal_year_id
 * @property int $number
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $locked_at
 * @property string|null $locked_by
 * @property-read FiscalYear $year
 * @property-read User|null $locker
 */
class FiscalPeriod extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function locked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * "Oct 2026", or the dates when the month is not a calendar month.
     */
    public function label(): string
    {
        return $this->starts_on->day === 1 && $this->ends_on->isSameDay($this->starts_on->endOfMonth())
            ? $this->starts_on->format('M Y')
            : $this->starts_on->format('j M Y').' – '.$this->ends_on->format('j M Y');
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function year(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
