<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A currency the company allows. Deactivating it hides it from new entries.
 * Rows that already used the code are left as they were.
 *
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property int $decimals
 * @property bool $active
 * @property string|null $created_by
 */
class Currency extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'active' => 'boolean',
        ];
    }
}
