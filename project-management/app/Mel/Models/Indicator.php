<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An indicator's identity: code, result, who answers for it and how often
 * it is reported. Its definition lives in versions (App\Mel\Indicators).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string $result_id
 * @property string $code
 * @property string $status
 * @property string $frequency
 * @property string|null $responsible_id
 * @property string|null $current_version_id
 * @property int $sort_order
 * @property-read Programme $programme
 * @property-read Result $result
 * @property-read User|null $responsible
 * @property-read IndicatorVersion|null $currentVersion
 * @property-read Collection<int, IndicatorVersion> $versions
 * @property-read Collection<int, IndicatorBaseline> $baselines
 * @property-read Collection<int, IndicatorTarget> $targets
 */
class Indicator extends Model
{
    protected $table = 'mel_indicators';

    use TenantModel;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function label(): string
    {
        return $this->code.' · '.($this->currentVersion->name ?? '');
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<IndicatorVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(IndicatorVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<IndicatorVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(IndicatorVersion::class)->orderByDesc('version');
    }

    /**
     * Live baselines (one per unit, or for the whole programme).
     *
     * @return HasMany<IndicatorBaseline, $this>
     */
    public function baselines(): HasMany
    {
        return $this->hasMany(IndicatorBaseline::class)->whereNull('superseded_at');
    }

    /**
     * Live targets.
     *
     * @return HasMany<IndicatorTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(IndicatorTarget::class)->whereNull('superseded_at');
    }
}
