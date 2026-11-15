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
     * Paid expenses in each of the last months, oldest first, this month
     * last: projects in the given currency only, never other currencies
     * summed in.
     *
     * @return list<array{month: string, amountMinor: int}>
     */
    public static function paidPerMonth(string $currency): array
    {
        $first = today()->startOfMonth()->subMonths(self::MONTHS - 1);
        $expenses = Expense::query()->paid()->whereBetween('spent_on', [$first->toDateString(), today()->toDateString()])
            ->whereHas('project', fn ($query) => $query->where('currency', $currency))
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
     * The projects with an approved budget, the most used first. Used is
     * what the budget page counts against it: committed, paid, and the
     * estimated staff time on the labour line (ProjectFinance). Each in its
     * project's currency.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, array{currency: string, approvedBudgetMinor: int|null, availableMinor: int|null}>  $figures
     * @return list<array{id: string, code: string, name: string, currency: string, approvedBudgetMinor: int, usedMinor: int, percent: int}>
     */
    public static function budgets(Collection $projects, array $figures): array
    {
        return array_values($projects->filter(fn (Project $project) => ($figures[$project->id]['approvedBudgetMinor'] ?? 0) > 0)
            ->map(function (Project $project) use ($figures) {
                $approved = (int) $figures[$project->id]['approvedBudgetMinor'];
                $used = $approved - (int) $figures[$project->id]['availableMinor'];

                return [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'currency' => $figures[$project->id]['currency'],
                    'approvedBudgetMinor' => $approved,
                    'usedMinor' => $used,
                    'percent' => (int) round(100 * $used / $approved),
                ];
            })
            ->sortByDesc('percent')->take(self::BUDGETS_SHOWN)->all());
    }
}
