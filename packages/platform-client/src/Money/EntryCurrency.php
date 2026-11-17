<?php

namespace Miles\PlatformClient\Money;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A currency people may type amounts in besides the workspace currency,
 * with its current rate. Changing the rate never rewrites what was saved.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property int $decimals
 * @property int $rate_minor
 * @property string|null $updated_by
 */
class EntryCurrency extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['decimals' => 'integer', 'rate_minor' => 'integer'];
    }
}
