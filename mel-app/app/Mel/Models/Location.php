<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place programmes work in, inside a parent (country > state > county).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $parent_id
 * @property string $name
 * @property string|null $code
 * @property CarbonImmutable|null $archived_at
 * @property-read Location|null $parent
 */
class Location extends Model
{
    protected $table = 'mel_locations';

    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /**
     * "South Sudan › Central Equatoria › Juba", for pickers.
     */
    public function path(): string
    {
        $names = [$this->name];
        $seen = [$this->id => true];
        $parent = $this->parent;

        while ($parent !== null && ! isset($seen[$parent->id])) {
            array_unshift($names, $parent->name);
            $seen[$parent->id] = true;
            $parent = $parent->parent;
        }

        return implode(' › ', $names);
    }
}
