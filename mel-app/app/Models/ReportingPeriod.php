<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of time results are reported for, due by a date. Closed periods
 * take no new submissions (App\Mel\Periods).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string $frequency
 * @property string $label
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable $due_on
 * @property string $status
 * @property-read Programme $programme
 */
class ReportingPeriod extends Model
{
    use TenantModel;

    public const FREQUENCIES = ['monthly', 'quarterly', 'semiannual', 'annual', 'custom'];

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'due_on' => 'immutable_date',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }
}
