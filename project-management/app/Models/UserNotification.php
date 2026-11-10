<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something for one person in one organization to know: on a task (written
 * by App\Actions\Inbox), or an approval or reminder that opens a page
 * (App\Actions\Alerts).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string|null $actor_id
 * @property string $kind
 * @property string|null $project_id
 * @property string|null $task_id
 * @property string|null $url a path in the organization, when it opens no task
 * @property string|null $dedupe_key given once per person (reminders)
 * @property CarbonImmutable|null $emailed_at
 * @property string $title
 * @property string|null $excerpt
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property-read Task $task
 */
class UserNotification extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    public const MENTIONED = 'mentioned';

    public const COMMENTED = 'commented';

    public const ASSIGNED = 'assigned';

    public const ADDED = 'added';

    public const APPROVAL = 'approval';

    public const DECISION = 'decision';

    public const REMINDER = 'reminder';

    public const MESSAGE = 'message';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'emailed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
