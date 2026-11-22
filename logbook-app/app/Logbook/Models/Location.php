<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place (Main office, Generator room), optionally inside another.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $parent_id
 * @property string $name
 * @property bool $active
 * @property-read Location|null $parent
 */
class Location extends Model
{
    use TenantModel;

    protected $table = 'log_locations';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Location, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /** "Main office › Generator room". */
    public function path(): string
    {
        $names = [$this->name];
        $at = $this->parent;
        $guard = 0;
        while ($at !== null && $guard++ < 8) {
            array_unshift($names, $at->name);
            $at = $at->parent;
        }

        return implode(' › ', $names);
    }
}
