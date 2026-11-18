<?php

namespace Miles\PlatformClient\Money;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An amount typed in another currency, and the rate it was converted at.
 *
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $field
 * @property string $currency
 * @property int $amount_minor
 * @property int $rate_minor
 * @property string $base_currency
 * @property int $base_minor
 * @property string|null $created_by
 */
class EnteredAmountRecord extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'entered_amounts';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'rate_minor' => 'integer', 'base_minor' => 'integer'];
    }
}
