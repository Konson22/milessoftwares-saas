<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Task task_id waits for task depends_on_id.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $task_id
 * @property string $depends_on_id
 */
class TaskDependency extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];
}
