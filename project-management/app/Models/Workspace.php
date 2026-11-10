<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The top of the work structure: a country office, a department. Holds
 * spaces. Changes go through App\Actions\WorkStructure.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 */
class Workspace extends Model
{
    use Archivable;
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'archived_at' => 'datetime'];
    }

    /**
     * @return HasMany<Space, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
