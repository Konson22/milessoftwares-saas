<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * The figures behind the portfolio's charts. Tasks count at the top level,
 * as progress does: a parent already stands for its subtasks. Buckets are
 * filled in PHP so empty weeks and months still show, on any database.
 */
final class PortfolioTrends
{
    public const WEEKS = 12;

    public const MONTHS = 12;

    public const BUDGETS_SHOWN = 6;

    /**
     * Tasks finished in each of the last weeks, oldest first, this week last.
     *
     * @return list<array{start: string, count: int}>
     */
    public static function completedPerWeek(): array
    {
        $first = today()->startOfWeek()->subWeeks(self::WEEKS - 1);
        $counts = Task::query()->topLevel()->whereNotNull('project_id')->where('completed_at', '>=', $first)->where('completed_at', '<', now())
            ->pluck('completed_at')
            ->countBy(fn ($at) => $at->startOfWeek()->toDateString());

        $weeks = [];
        for ($week = 0; $week < self::WEEKS; $week++) {
            $start = $first->addWeeks($week)->toDateString();
            $weeks[] = ['start' => $start, 'count' => (int) $counts->get($start, 0)];
        }

        return $weeks;
    }

    /**
     * Top-level tasks on these projects, by status, in workflow order.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{status: string, count: int}>
     */
    public static function taskStatus(Collection $projects): array
    {
        $counts = Task::query()->topLevel()->whereIn('project_id', $projects->pluck('id')->all())
            ->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');

        return array_map(
            fn (string $status) => ['status' => $status, 'count' => (int) ($counts[$status] ?? 0)],
            [Task::TODO, Task::IN_PROGRESS, Task::REVIEW, Task::DONE],
        );
    }

    /**
     * Paid expenses in each of the last months, oldest first, this month last.
     *
     * @return list<array{month: string, amountMinor: int}>
     */
    public static function paidPerMonth(): array
    {
        $first = today()->startOfMonth()->subMonths(self::MONTHS - 1);
        $expenses = Expense::query()->paid()->whereBetween('spent_on', [$first->toDateString(), today()->toDateString()])
            ->get(['spent_on', 'amount_minor']);
        $amounts = [];
        foreach ($expenses as $expense) {
            $key = $expense->spent_on?->format('Y-m');
            if ($key !== null) {
                $amounts[$key] = ($amounts[$key] ?? 0) + $expense->amount_minor;
            }
        }

        $months = [];
        for ($month = 0; $month < self::MONTHS; $month++) {
            $key = $first->addMonths($month)->format('Y-m');
            $months[] = ['month' => $key, 'amountMinor' => $amounts[$key] ?? 0];
        }

        return $months;
    }

    /**
     * The projects with a budget, the most used first.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, array{actualMinor: int}>  $metrics
     * @return list<array{id: string, code: string, name: string, budgetMinor: int, actualMinor: int, percent: int}>
     */
    public static function budgets(Collection $projects, array $metrics): array
    {
        return array_values($projects->filter(fn (Project $project) => $project->budget_minor > 0)
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'budgetMinor' => (int) $project->budget_minor,
                'actualMinor' => $metrics[$project->id]['actualMinor'],
                'percent' => (int) round(100 * $metrics[$project->id]['actualMinor'] / $project->budget_minor),
            ])
            ->sortByDesc('percent')->take(self::BUDGETS_SHOWN)->all());
    }
}
