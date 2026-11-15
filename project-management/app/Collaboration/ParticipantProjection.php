<?php

namespace App\Collaboration;

use App\Actions\Budgets;
use App\Collaboration\Models\Participant;
use App\Enums\CostCategory;
use App\Models\Milestone;
use App\Models\Project;
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
                'budget' => in_array(Capability::BUDGET_VIEW, $capabilities, true) ? self::budget($project, $metrics['expensesMinor']) : null,
            ];
        });
    }

    /**
     * The approved budget by category and what has been spent against the
     * project, in total. Nothing until a budget is approved.
     *
     * @return array{approvedMinor: int, spentMinor: int, categories: list<array{category: string, amountMinor: int}>}|null
     */
    private static function budget(Project $project, int $spentMinor): ?array
    {
        if (Budgets::approved($project) === null) {
            return null;
        }

        $categories = [];
        $approved = 0;
        foreach (Budgets::approvedByCategory($project) as $category => $amount) {
            $categories[] = ['category' => CostCategory::from($category)->label(), 'amountMinor' => (int) $amount];
            $approved += (int) $amount;
        }

        return ['approvedMinor' => $approved, 'spentMinor' => $spentMinor, 'categories' => $categories];
    }
}
