<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Time worked on a project (and maybe one of its tasks), costed at the
 * person's hourly rate when it was logged.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $task_id
 * @property string $person_id
 * @property CarbonImmutable $worked_on
 * @property int $minutes
 * @property string|null $note
 * @property int $cost_minor
 * @property string $created_by
 * @property-read Project $project
 * @property-read Task|null $task
 * @property-read Person $person
 */
class TimeEntry extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['worked_on' => 'date', 'minutes' => 'integer', 'cost_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
