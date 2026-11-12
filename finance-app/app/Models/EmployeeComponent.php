<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A component paid to or taken from one person, between two dates.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $pay_component_id
 * @property int|null $amount_minor
 * @property int|null $rate_bp
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property-read PayComponent $component
 */
class EmployeeComponent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'rate_bp' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<PayComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function appliesBetween(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->starts_on->lte($end) && ($this->ends_on === null || $this->ends_on->gte($start));
    }
}
