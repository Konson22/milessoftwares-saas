<?php

namespace App\Mel\Models;

use App\Mel\Measures;
use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One definition of an indicator. Never changed once a submission uses it:
 * a revision is a new version, so every report keeps the definition its
 * values were reported under.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $indicator_id
 * @property int $version
 * @property string $name
 * @property string|null $definition
 * @property string $type
 * @property string $measure
 * @property string|null $unit_label
 * @property int $decimals
 * @property string|null $numerator_label
 * @property string|null $denominator_label
 * @property int|null $rate_multiplier
 * @property string|null $calculation_method
 * @property string $direction
 * @property string $time_aggregation
 * @property string $unit_aggregation
 * @property string|null $data_source
 * @property string|null $collection_method
 * @property string|null $means_of_verification
 * @property string|null $change_reason
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read Indicator $indicator
 * @property-read User $author
 * @property-read Collection<int, IndicatorBreakdown> $breakdowns
 */
class IndicatorVersion extends Model
{
    protected $table = 'mel_indicator_versions';

    use TenantModel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'decimals' => 'integer',
            'rate_multiplier' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isFraction(): bool
    {
        return Measures::isFraction($this->measure);
    }

    public function scale(): int
    {
        return Measures::scale($this->measure, $this->rate_multiplier);
    }

    /**
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<IndicatorBreakdown, $this>
     */
    public function breakdowns(): HasMany
    {
        return $this->hasMany(IndicatorBreakdown::class);
    }
}
