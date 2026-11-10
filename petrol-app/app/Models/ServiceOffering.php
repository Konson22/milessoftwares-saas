<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string $code
 * @property int $price_minor
 * @property bool $active
 */
class ServiceOffering extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'active' => 'boolean',
        ];
    }
}
