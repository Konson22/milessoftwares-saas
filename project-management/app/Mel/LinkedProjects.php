<?php

namespace App\Mel;

use App\Mel\Models\ProgrammeUnit;
use App\Models\Project;
use App\Support\ProjectFinance;
use App\Support\ProjectMetrics;
use Illuminate\Support\Collection;

/**
 * The projects that reporting units are linked to, as Projects itself sees
 * them right now: status and delivery progress, and, for those allowed to
 * see project money, the approved budget and what has been paid
 * (App\Support\ProjectFinance). Operational progress only; it is never
 * mixed into indicator results.
 *
 * Access is decided here, on the server, not in the page:
 *   - nothing at all without projects.view: M&E roles (partners, donor
 *     viewers, evaluators, enumerators) do not see Projects records;
 *   - only projects the member may see (team-only projects stay with their
 *     team: the Project query is narrowed by App\Access\ProjectVisibility);
 *   - money only with projects.finance.view. The budget is the approved
 *     version's total, or null while none is approved; spending is paid
 *     expenses only, never estimated personnel cost.
 */
final class LinkedProjects
{
    /**
     * Figures for each linked project the member may see, by project id.
     *
     * @param  Collection<int, ProgrammeUnit>  $units
     * @return array<string, array{id: string, label: string, status: string, progressPercent: int|null, currency: string, approvedBudgetMinor: int|null, paidMinor: int|null, paidStatus: string|null}>
     */
    public static function figures(Collection $units, Access $access): array
    {
        if (! $access->can('projects.view')) {
            return [];
        }
        $ids = $units->pluck('linked_project_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $projects = Project::query()->whereIn('id', $ids)->get();
        $metrics = ProjectMetrics::forProjects($projects);
        $money = $access->can('projects.finance.view') ? ProjectFinance::forProjects($projects) : [];

        $figures = [];
        foreach ($projects as $project) {
            $figures[$project->id] = [
                'id' => $project->id,
                'label' => "{$project->code} · {$project->name}",
                'status' => $project->status,
                'progressPercent' => isset($metrics[$project->id]) ? max(0, min(100, (int) $metrics[$project->id]['progress'])) : null,
                'currency' => $project->currency,
                'approvedBudgetMinor' => $money[$project->id]['approvedBudgetMinor'] ?? null,
                'paidMinor' => isset($money[$project->id]) ? $money[$project->id]['paidMinor'] : null,
                'paidStatus' => isset($money[$project->id]) ? $money[$project->id]['provenance']['paidStatus'] : null,
            ];
        }

        return $figures;
    }
}
