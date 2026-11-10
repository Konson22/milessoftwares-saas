<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step on a task's checklist. Lighter than a subtask: no assignee, no
 * dates, and it does not hold the task back from being done.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $task_id
 * @property string $body
 * @property int $position
 * @property CarbonImmutable|null $done_at
 * @property string|null $done_by
 * @property-read User|null $completer
 */
class TaskChecklistItem extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'done_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
