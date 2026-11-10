<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An area of work in a workspace: holds folders, and lists directly.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $workspace_id
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property-read Workspace $workspace
 */
class Space extends Model
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
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    /**
     * @return HasMany<TaskList, $this>
     */
    public function lists(): HasMany
    {
        return $this->hasMany(TaskList::class);
    }
}
