<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A category defined for the whole company. Stations pick from this list.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $default_type
 * @property bool $active
 */
class ExpenseCategory extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const RECURRING = 'recurring';

    public const ONCE = 'once';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
