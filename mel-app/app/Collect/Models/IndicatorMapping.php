<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Mel\Models\Indicator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How a form's accepted submissions make one indicator's value
 * (ADR-0019 §26). Once a run sent with it, it is never edited: changing it
 * retires it and starts a new one that supersedes it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $form_id
 * @property string $indicator_id
 * @property string $calculation
 * @property string|null $question
 * @property array<string, mixed>|null $condition
 * @property array<string, mixed>|null $numerator_condition
 * @property string|null $distinct_by
 * @property string|null $note
 * @property string $status
 * @property string|null $supersedes_id
 * @property string $created_by
 * @property string|null $retired_by
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable $created_at
 * @property-read Form $form
 * @property-read Indicator $indicator
 * @property-read User $author
 * @property-read Collection<int, CalculationRun> $runs
 */
class IndicatorMapping extends Model
{
    use TenantModel;

    protected $table = 'collect_indicator_mappings';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    public const COUNT = 'count';

    public const SUM = 'sum';

    public const AVERAGE = 'average';

    public const PERCENTAGE = 'percentage';

    public const CALCULATIONS = [self::COUNT, self::SUM, self::AVERAGE, self::PERCENTAGE];

    /** The indicator measures each calculation can report. */
    public const MEASURES = [
        self::COUNT => ['count', 'number'],
        self::SUM => ['count', 'number', 'currency'],
        // Sum over count: kept as numerator and denominator, never as an averaged number.
        self::AVERAGE => ['ratio', 'rate'],
        self::PERCENTAGE => ['percentage'],
    ];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'numerator_condition' => 'array',
            'retired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
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
     * @return HasMany<CalculationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(CalculationRun::class, 'mapping_id')->orderByDesc('created_at');
    }
}
