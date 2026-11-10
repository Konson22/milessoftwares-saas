<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A fuel grade shared by every station of the organization.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string $unit
 * @property int $cost_minor
 * @property bool $active
 */
class FuelProduct extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'cost_minor' => 'integer',
            'active' => 'boolean',
        ];
    }
}
