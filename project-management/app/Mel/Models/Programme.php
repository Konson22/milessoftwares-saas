<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An M&E programme: what it aims at, its reporting units and periods, its
 * results framework and indicators. Owned by M&E; it never needs a Projects
 * subscription. Archived, never deleted (App\Mel\Programmes).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $objective
 * @property string|null $description
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $status
 * @property string $currency
 * @property int|null $budget_minor
 * @property string|null $funding_summary
 * @property string|null $manager_id
 * @property string $created_by
 * @property CarbonImmutable|null $archived_at
 * @property-read User|null $manager
 * @property-read Collection<int, ProgrammeUnit> $units
 * @property-read Collection<int, ReportingPeriod> $periods
 * @property-read Collection<int, ResultLevel> $levels
 * @property-read Collection<int, Result> $results
 * @property-read Collection<int, Indicator> $indicators
 * @property-read Collection<int, Location> $locations
 */
class Programme extends Model
{
    protected $table = 'mel_programmes';

    use TenantModel;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::CLOSED];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'budget_minor' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * "WASH · Clean water for Jonglei".
     */
    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return HasMany<ProgrammeUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProgrammeUnit::class)->orderBy('code');
    }

    /**
     * @return HasMany<ReportingPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(ReportingPeriod::class)->orderBy('starts_on')->orderBy('frequency');
    }

    /**
     * @return HasMany<ResultLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ResultLevel::class)->orderBy('depth');
    }

    /**
     * @return HasMany<Result, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /**
     * @return HasMany<Indicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    /**
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'mel_programme_locations')->withPivot('organization_id');
    }
}
