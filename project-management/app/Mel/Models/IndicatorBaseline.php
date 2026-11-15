<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an indicator started, for the programme or one unit. Never edited:
 * a change supersedes this row (App\Mel\Targets).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $indicator_id
 * @property string|null $unit_id
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property CarbonImmutable $measured_on
 * @property string|null $source
 * @property string|null $notes
 * @property string|null $supersedes_id
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $change_reason
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read Indicator $indicator
 * @property-read ProgrammeUnit|null $unit
 * @property-read User $author
 */
class IndicatorBaseline extends Model
{
    protected $table = 'mel_indicator_baselines';

    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'numerator' => 'decimal:6',
            'denominator' => 'decimal:6',
            'measured_on' => 'immutable_date',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * @return BelongsTo<ProgrammeUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProgrammeUnit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
