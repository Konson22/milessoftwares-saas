<?php

namespace App\Actions;

use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Time worked, costed at the person's hourly rate on the day it is logged
 * (a later change of rate leaves past entries alone).
 */
class Timesheets
{
    /** Nobody logs more than a day's worth in one go. */
    public const MAX_MINUTES = 24 * 60;

    /**
     * @throws ValidationException
     */
    public function log(Project $project, ?Task $task, Person $person, CarbonImmutable $workedOn, int $minutes, ?string $note, User $by): TimeEntry
    {
        if ($task !== null && $task->project_id !== $project->id) {
            throw ValidationException::withMessages(['task_id' => 'Choose a task of this project.']);
        }
        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['hours' => 'Between a minute and 24 hours.']);
        }
        if ($project->status === Project::CANCELLED) {
            throw ValidationException::withMessages(['hours' => "{$project->code} is cancelled."]);
        }

        return TimeEntry::query()->create([
            'project_id' => $project->id,
            'task_id' => $task?->id,
            'person_id' => $person->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'note' => blank($note) ? null : trim((string) $note),
            'cost_minor' => intdiv($minutes * $person->hourly_cost_minor, 60),
            'created_by' => $by->id,
        ]);
    }

    /**
     * "1.5" or "1:30" → 90 minutes; null when it is neither.
     */
    public static function minutes(string $hours): ?int
    {
        $hours = trim($hours);
        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $hours, $m) === 1) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $hours) === 1) {
            return (int) round((float) $hours * 60);
        }

        return null;
    }
}
