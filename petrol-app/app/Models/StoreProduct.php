<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $unit
 * @property int|null $reorder_milli
 * @property bool $active
 */
class StoreProduct extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'reorder_milli' => 'integer',
            'active' => 'boolean',
        ];
    }
}
