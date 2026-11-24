<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An asset category (Vehicles, Generators…), kept per workspace. Never
 * deleted: switched off instead, so assets keep naming it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $key the default it started as (vehicles, generators…), null for the workspace's own
 * @property string $name
 * @property string|null $description
 * @property list<string>|null $types the types suggested in it (Car, Pickup…)
 * @property int $sort
 * @property bool $active
 */
class ResourceCategory extends Model
{
    use TenantModel;

    protected $table = 'log_resource_categories';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer', 'types' => 'array'];
    }

    /** @return HasMany<LogResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(LogResource::class, 'category_id');
    }
}
