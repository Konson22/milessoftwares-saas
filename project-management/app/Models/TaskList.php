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
 * A list of tasks, in a space (and maybe a folder). A list holds tasks of one
 * project, or of no project: the organization's day-to-day work. A list is
 * archived only once its tasks are done.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $space_id
 * @property string|null $folder_id
 * @property string|null $project_id
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property-read Space $space
 * @property-read Folder|null $folder
 * @property-read Project|null $project
 */
class TaskList extends Model
{
    use Archivable;
    use BelongsToOrganization;
    use HasUuids;

    protected $table = 'task_lists';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'archived_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Its own names for steps of its work, in order.
     *
     * @return HasMany<ListStatus, $this>
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(ListStatus::class, 'list_id')->orderBy('position')->orderBy('name');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'list_id');
    }
}
