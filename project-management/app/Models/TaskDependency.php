<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Task task_id waits for task depends_on_id.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $task_id
 * @property string $depends_on_id
 */
class TaskDependency extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    protected $guarded = ['organization_id'];

    /**
     * Seen only with the project its Task belongs to (App\Access\VisibleThroughProject).
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['task_id', Task::class];
    }
}
