<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How projects stand: progress (done work, weighted by estimates when
 * tasks have them), actual cost (time at cost plus paid spending;
 * committed spending is not yet spent) and health.
 *
 * Progress counts top-level tasks only: a parent already stands for its
 * subtasks, so their estimates would count twice. A subtask overdue is still
 * overdue.
 *
 * Health is the worse of schedule and budget:
 * - schedule: off track past the due date; at risk when progress trails the
 *   time elapsed by more than the organization's schedule slack (20
 *   points unless set in Administration → Settings), or tasks are overdue;
 * - budget: off track over budget; at risk when the share spent runs more
 *   than its budget slack ahead of progress.
 *
 * @phpstan-type Metrics array{progress: int, tasks: int, tasksDone: int, overdueTasks: int, labourMinor: int, expensesMinor: int, actualMinor: int, minutes: int, health: string, healthReasons: list<string>}
 */
final class ProjectMetrics
{
    public const ON_TRACK = 'on_track';

    public const AT_RISK = 'at_risk';

    public const OFF_TRACK = 'off_track';

    /**
     * @return Metrics
     */
    public static function for(Project $project): array
    {
        return self::forProjects(collect([$project]))[$project->id];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<string, Metrics>
     */
    public static function forProjects(Collection $projects): array
    {
        $ids = $projects->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $tasks = Task::query()->whereIn('project_id', $ids)
            ->get(['project_id', 'parent_id', 'status', 'estimate_hours', 'due_on'])
            ->groupBy('project_id');
        $time = TimeEntry::query()->whereIn('project_id', $ids)
            ->groupBy('project_id')
            ->selectRaw('project_id, sum(cost_minor) as cost, sum(minutes) as minutes')
            ->get()->keyBy('project_id');
        $expenses = Expense::query()->paid()->whereIn('project_id', $ids)
            ->groupBy('project_id')
            ->selectRaw('project_id, sum(amount_minor) as amount')
            ->pluck('amount', 'project_id');
        // How far each organization lets a project slip before "at risk".
        $slack = Organization::query()->whereIn('id', $projects->pluck('organization_id')->unique()->all())
            ->get(['id', 'health_schedule_slack', 'health_budget_slack'])->keyBy('id');

        $metrics = [];
        foreach ($projects as $project) {
            /** @var Collection<int, Task> $own */
            $own = $tasks->get($project->id, collect());
            $labour = (int) ($time->get($project->id)?->getAttribute('cost') ?? 0);
            $spent = (int) ($expenses[$project->id] ?? 0);

            $metrics[$project->id] = self::compute(
                $project,
                $own,
                $labour,
                $spent,
                (int) ($time->get($project->id)?->getAttribute('minutes') ?? 0),
                $slack->get($project->organization_id),
            );
        }

        return $metrics;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return Metrics
     */
    private static function compute(Project $project, Collection $tasks, int $labour, int $spent, int $minutes, ?Organization $organization): array
    {
        $overdue = $tasks->filter(fn (Task $task) => $task->overdue())->count();
        $tasks = $tasks->filter(fn (Task $task) => $task->parent_id === null);
        $done = $tasks->filter(fn (Task $task) => $task->isDone());
        $estimated = $tasks->sum(fn (Task $task) => (float) $task->estimate_hours);
        $progress = match (true) {
            $project->status === Project::COMPLETED => 100,
            $tasks->isEmpty() => 0,
            // Weighted by estimate when the plan has them.
            $estimated > 0 => (int) floor(100 * $done->sum(fn (Task $task) => (float) $task->estimate_hours) / $estimated),
            default => (int) floor(100 * $done->count() / $tasks->count()),
        };
        $actual = $labour + $spent;

        [$health, $reasons] = $project->isOpen()
            ? self::health($project, $progress, $overdue, $actual, $organization->health_schedule_slack ?? Organization::DEFAULT_HEALTH_SLACK, $organization->health_budget_slack ?? Organization::DEFAULT_HEALTH_SLACK)
            : [self::ON_TRACK, []];

        return [
            'progress' => $progress,
            'tasks' => $tasks->count(),
            'tasksDone' => $done->count(),
            'overdueTasks' => $overdue,
            'labourMinor' => $labour,
            'expensesMinor' => $spent,
            'actualMinor' => $actual,
            'minutes' => $minutes,
            'health' => $health,
            'healthReasons' => $reasons,
        ];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function health(Project $project, int $progress, int $overdueTasks, int $actual, int $scheduleSlack, int $budgetSlack): array
    {
        $off = [];
        $risk = [];
        $today = CarbonImmutable::today();

        if ($project->due_on !== null) {
            if ($project->due_on->lt($today)) {
                $off[] = 'Past its due date';
            } elseif ($project->start_on !== null && $project->due_on->gt($project->start_on) && $today->gt($project->start_on)) {
                $elapsed = (int) floor(100 * $project->start_on->diffInDays($today) / $project->start_on->diffInDays($project->due_on));
                if ($elapsed - $progress > $scheduleSlack) {
                    $risk[] = "{$progress}% done with {$elapsed}% of the time gone";
                }
            }
        }
        if ($overdueTasks > 0) {
            $risk[] = $overdueTasks === 1 ? '1 task overdue' : "{$overdueTasks} tasks overdue";
        }

        if ($project->budget_minor > 0) {
            $spentShare = (int) floor(100 * $actual / $project->budget_minor);
            if ($actual > $project->budget_minor) {
                $off[] = "Over budget ({$spentShare}% spent)";
            } elseif ($spentShare - $progress > $budgetSlack) {
                $risk[] = "{$spentShare}% of the budget spent for {$progress}% done";
            }
        }

        return match (true) {
            $off !== [] => [self::OFF_TRACK, [...$off, ...$risk]],
            $risk !== [] => [self::AT_RISK, $risk],
            default => [self::ON_TRACK, []],
        };
    }
}
