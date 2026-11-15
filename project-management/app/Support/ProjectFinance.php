<?php

namespace App\Support;

use App\Enums\BudgetVersionStatus;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * The canonical project money figures shown outside the project's own
 * finance pages (M&E, partner reports, participant views). One definition
 * each, documented in docs/finance/metric-definitions.md:
 *
 *   approvedBudgetMinor  the total of the project's approved budget version;
 *                        null while no version is approved. Never the
 *                        typed estimate in projects.budget_minor.
 *   paidMinor            expenses recorded as paid against the project
 *                        (Expense::paid). Money that left, nothing else.
 *
 * Estimated personnel cost (time logged × hand-entered hourly rates,
 * ProjectMetrics::labourMinor) is NOT expenditure: it is never added to
 * either figure here. Whoever shows these must check projects.finance.view
 * (or a participant's budget.view) first: this class does no access checks.
 */
final class ProjectFinance
{
    /**
     * @param  Collection<int, Project>  $projects
     * @return array<string, array{approvedBudgetMinor: int|null, paidMinor: int}>
     */
    public static function forProjects(Collection $projects): array
    {
        $ids = $projects->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $approved = BudgetVersion::query()->whereIn('project_id', $ids)
            ->where('status', BudgetVersionStatus::Approved)
            ->pluck('total_minor', 'project_id');
        $paid = Expense::query()->paid()->whereIn('project_id', $ids)
            ->groupBy('project_id')
            ->selectRaw('project_id, sum(amount_minor) as amount')
            ->pluck('amount', 'project_id');

        $figures = [];
        foreach ($ids as $id) {
            $figures[(string) $id] = [
                'approvedBudgetMinor' => $approved->has($id) ? (int) $approved->get($id) : null,
                'paidMinor' => (int) ($paid->get($id) ?? 0),
            ];
        }

        return $figures;
    }
}
