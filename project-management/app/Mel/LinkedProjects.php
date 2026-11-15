<?php

namespace App\Mel;

use App\Mel\Models\ProgrammeUnit;
use App\Models\Project;
use App\Support\ProjectMetrics;
use Illuminate\Support\Collection;

/**
 * The projects that reporting units are linked to, as Projects itself sees
 * them right now: status, delivery progress and spending against the
 * approved budget (App\Support\ProjectMetrics). Operational progress only;
 * it is never mixed into indicator results.
 */
final class LinkedProjects
{
    /**
     * Figures for each linked project, by project id.
     *
     * @param  Collection<int, ProgrammeUnit>  $units
     * @return array<string, array{id: string, label: string, status: string, progressPercent: int|null, currency: string, approvedBudgetMinor: int, spentMinor: int}>
     */
    public static function figures(Collection $units): array
    {
        $ids = $units->pluck('linked_project_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $projects = Project::query()->whereIn('id', $ids)->get();
        $metrics = ProjectMetrics::forProjects($projects);

        $figures = [];
        foreach ($projects as $project) {
            $figures[$project->id] = [
                'id' => $project->id,
                'label' => "{$project->code} · {$project->name}",
                'status' => $project->status,
                'progressPercent' => isset($metrics[$project->id]) ? max(0, min(100, (int) $metrics[$project->id]['progress'])) : null,
                'currency' => $project->currency,
                'approvedBudgetMinor' => $project->budget_minor,
                'spentMinor' => (int) ($metrics[$project->id]['actualMinor'] ?? 0),
            ];
        }

        return $figures;
    }
}
