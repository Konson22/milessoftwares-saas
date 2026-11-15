<?php

namespace App\Collaboration;

use App\Actions\Budgets;
use App\Collaboration\Models\Participant;
use App\Enums\CostCategory;
use App\Models\Milestone;
use App\Models\Project;
use App\Support\ProjectFinance;
use App\Support\ProjectMetrics;
use App\Tenancy\CurrentOrganization;

/**
 * Everything a participating organization reads of the owner's project, and
 * nothing else: reviewed queries returning named fields, run inside the
 * owner's organization. Left out on purpose: tasks and who does them, the
 * team and what people cost, expenses, suppliers, approvals, grants and
 * funders, risks, documents, notes, and every other project of the owner.
 * The budget only with budget.view, and only once one is approved.
 */
final class ParticipantProjection
{
    /**
     * @param  list<string>  $capabilities  the participation's effective capabilities
     * @return array<string, mixed>
     */
    public static function project(Participant $participant, array $capabilities): array
    {
        return app(CurrentOrganization::class)->run($participant->owner, function () use ($participant, $capabilities) {
            $project = Project::query()->findOrFail($participant->project_id);
            $metrics = ProjectMetrics::for($project);

            return [
                'code' => $project->code,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'startOn' => $project->start_on?->toDateString(),
                'dueOn' => $project->due_on?->toDateString(),
                'currency' => $project->currency,
                'progress' => $metrics['progress'],
                'owner' => $participant->owner->name,
                'milestones' => array_values($project->milestones()->orderBy('due_on')->get()->map(fn (Milestone $milestone) => [
                    'name' => $milestone->name,
                    'dueOn' => $milestone->due_on->toDateString(),
                    'reached' => $milestone->completed_at !== null,
                ])->all()),
                'budget' => in_array(Capability::BUDGET_VIEW, $capabilities, true) ? self::budget($project) : null,
            ];
        });
    }

    /**
     * The approved budget by category, what is committed and what was paid
     * against the project (ProjectFinance::shareable), with where the
     * figures come from. Never the estimated staff cost. Nothing until a
     * budget is approved. spentMinor is the paid amount, kept under its
     * earlier name.
     *
     * @return array{approvedMinor: int, spentMinor: int, paidMinor: int, committedMinor: int, currency: string, provenance: array<string, mixed>, categories: list<array{category: string, amountMinor: int}>}|null
     */
    private static function budget(Project $project): ?array
    {
        $figures = ProjectFinance::shareable(ProjectFinance::for($project));
        if ($figures['approvedBudgetMinor'] === null) {
            return null;
        }

        $categories = [];
        foreach (Budgets::approvedByCategory($project) as $category => $amount) {
            $categories[] = ['category' => CostCategory::from($category)->label(), 'amountMinor' => (int) $amount];
        }

        return [
            'approvedMinor' => $figures['approvedBudgetMinor'],
            'spentMinor' => $figures['paidMinor'],
            'paidMinor' => $figures['paidMinor'],
            'committedMinor' => $figures['committedMinor'],
            'currency' => $figures['currency'],
            'provenance' => $figures['provenance'],
            'categories' => $categories,
        ];
    }
}
