<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Whether this station accepts a company currency. The workspace currency stays on.
 *
 * @property string $branch_id
 * @property string $code
 * @property bool $enabled
 */
class StationCurrency extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
