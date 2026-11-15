<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A disaggregation a version asks for: by one dimension, or two crossed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $indicator_version_id
 * @property string $dimension_id
 * @property string|null $second_dimension_id
 * @property string $rule
 * @property-read Dimension $dimension
 * @property-read Dimension|null $secondDimension
 */
class IndicatorBreakdown extends Model
{
    protected $table = 'mel_indicator_breakdowns';

    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    /**
     * "Sex", "Sex × Age group".
     */
    public function label(): string
    {
        return $this->dimension->name.($this->secondDimension !== null ? ' × '.$this->secondDimension->name : '');
    }

    /**
     * @return BelongsTo<Dimension, $this>
     */
    public function dimension(): BelongsTo
    {
        return $this->belongsTo(Dimension::class);
    }

    /**
     * @return BelongsTo<Dimension, $this>
     */
    public function secondDimension(): BelongsTo
    {
        return $this->belongsTo(Dimension::class, 'second_dimension_id');
    }
}
